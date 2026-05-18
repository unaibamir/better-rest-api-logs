<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Domain\Entry;

/**
 * Writes batches of captured REST log entries to {$wpdb->prefix}brl_logs.
 *
 * D-07: A single multi-row INSERT keeps the flush path synchronous at shutdown
 * without hitting the DB once per entry. D-08: body-spill rows land in the
 * secondary table after this call returns the assigned IDs, so the parent row
 * always exists before its child. D-27: the return shape is an ordered array
 * of inserted IDs matching the input index — Flusher uses position [i] to link
 * the i-th entry's spill row.
 *
 * Sole call site for {@see Database::logs_table()} outside the Schema; the
 * table-naming CI gate verifies this. Insert path is O(rows-in-batch) —
 * no SELECT COUNT(*), no full-table scan — sizes correctly for REL-03
 * admin-list scalability.
 *
 * @package BetterRestApiLogs\DB
 */
final class LogRepository {

	/**
	 * Column names in the exact order they appear in the DDL and in Entry::to_array().
	 * The sequence is the single source of truth for both the column list and the
	 * per-row placeholder string — changing one without the other would silently
	 * misalign values.
	 *
	 * @var string[]
	 */
	private const COLUMNS = [
		'created_at',
		'created_at_micros',
		'method',
		'route',
		'route_prefix',
		'query_string',
		'status',
		'status_class',
		'duration_ms',
		'user_id',
		'ip_resolved',
		'ip_raw_remote',
		'request_content_type',
		'request_headers',
		'request_body',
		'request_body_bytes',
		'request_body_truncated',
		'response_content_type',
		'response_headers',
		'response_body',
		'response_body_bytes',
		'response_body_truncated',
		'bodies_spilled',
		'migration_source_id',
	];

	/**
	 * WPDb format token per column — %s for string/binary/nullable, %d for integers.
	 * Null-safe values are handled in build_row_sql, which substitutes the SQL NULL
	 * literal and skips the binding rather than letting wpdb::prepare convert null
	 * to an empty string (which violates UNIQUE KEY constraints on nullable columns).
	 *
	 * @var array<string,string>
	 */
	private const FORMAT = [
		'created_at'              => '%s',
		'created_at_micros'       => '%d',
		'method'                  => '%s',
		'route'                   => '%s',
		'route_prefix'            => '%s',
		'query_string'            => '%s',
		'status'                  => '%d',
		'status_class'            => '%d',
		'duration_ms'             => '%d',
		'user_id'                 => '%d',
		'ip_resolved'             => '%s',
		'ip_raw_remote'           => '%s',
		'request_content_type'    => '%s',
		'request_headers'         => '%s',
		'request_body'            => '%s',
		'request_body_bytes'      => '%d',
		'request_body_truncated'  => '%d',
		'response_content_type'   => '%s',
		'response_headers'        => '%s',
		'response_body'           => '%s',
		'response_body_bytes'     => '%d',
		'response_body_truncated' => '%d',
		'bodies_spilled'          => '%d',
		'migration_source_id'     => '%s',
	];

	/**
	 * Insert a batch of entries in a single multi-row prepared INSERT.
	 *
	 * Returns the auto-increment IDs in input order. On InnoDB with the default
	 * autoinc-lock-mode=1, a multi-row INSERT hands out contiguous IDs — the
	 * first is $wpdb->insert_id; the rest are first+1, first+2, … (Pitfall 3).
	 * An empty input or a DB error both return [].
	 *
	 * Null handling: $wpdb->prepare converts null %s args to '' (empty string),
	 * which breaks UNIQUE KEY constraints on nullable columns. We substitute the
	 * SQL NULL literal for null values and skip their binding entirely so the
	 * actual NULL reaches the storage engine.
	 *
	 * @param  array<int, Entry> $entries Entries to persist.
	 * @return array<int, int>   Inserted IDs in input order; empty on failure.
	 */
	public function insert_batch( array $entries ): array {
		if ( [] === $entries ) {
			return [];
		}

		global $wpdb;

		$row_sqls = [];
		$args     = [];

		foreach ( $entries as $entry ) {
			$row        = $entry->to_array();
			$row_sqls[] = $this->build_row_sql( $row, $args );
		}

		$logs_table = Database::logs_table();
		$col_list   = \implode( ',', self::COLUMNS );
		$values_sql = \implode( ',', $row_sqls );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column list and NULL literals come from our static constants; only non-null user values flow through $wpdb->prepare via $args.
		$sql = "INSERT INTO {$logs_table} ({$col_list}) VALUES {$values_sql}";

		if ( [] === $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- all values are NULL literals, no user data in $sql.
			$result = $wpdb->query( $sql );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only NULL literals + prepare placeholders; no user input in the non-placeholder portions.
			$prepared = $wpdb->prepare( $sql, $args );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared is wpdb::prepare output; canonical multi-row batch INSERT.
			$result = $wpdb->query( $prepared );
		}

		if ( false === $result || '' !== $wpdb->last_error ) {
			return [];
		}

		$first_id = (int) $wpdb->insert_id;
		$ids      = [];
		for ( $i = 0, $n = \count( $entries ); $i < $n; $i++ ) {
			$ids[] = $first_id + $i;
		}

		return $ids;
	}

	/**
	 * Build the SQL fragment for a single row, appending non-null bindings to $args.
	 *
	 * Each column either contributes a wpdb placeholder (non-null) or the SQL NULL
	 * literal (null). This lets wpdb::prepare handle escaping only for actual values
	 * while true SQL NULL lands in the query for null fields.
	 *
	 * @param  array<string,mixed> $row  Column values from Entry::to_array().
	 * @param  array<mixed>        $args Accumulated prepare() arguments (passed by reference).
	 * @return string SQL fragment like "(%s,%d,NULL,%s,…)".
	 */
	private function build_row_sql( array $row, array &$args ): string {
		$tokens = [];
		foreach ( self::COLUMNS as $col ) {
			$value = $row[ $col ] ?? null;
			if ( null === $value ) {
				$tokens[] = 'NULL';
			} else {
				$tokens[] = self::FORMAT[ $col ];
				$args[]   = $value;
			}
		}
		return '(' . \implode( ',', $tokens ) . ')';
	}
}
