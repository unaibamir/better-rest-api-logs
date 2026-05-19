<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Query\Paginator;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\DB\Query\QueryBuilder;
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
 * Read path (Phase 4): find/search/count_by_{status_class,method}/oldest_newest/delete/delete_many
 * share a single QueryBuilder; capability checks are the CALLER's responsibility
 * (BulkActionHandler, REST permission_callback, CLI DeleteCommand) — this class
 * is a low-level data API.
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

	/** @var QueryBuilder */
	private $query_builder;

	/**
	 * @param QueryBuilder|null $query_builder Injected for testability; defaults to a new instance.
	 */
	public function __construct( ?QueryBuilder $query_builder = null ) {
		$this->query_builder = $query_builder ?? new QueryBuilder();
	}

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

	// -------------------------------------------------------------------------
	// Read path (Phase 4 — D-44 / D-45)
	// -------------------------------------------------------------------------

	/**
	 * Fetch a single log entry by its primary key.
	 *
	 * Joins the bodies table when bodies_spilled is set so the caller always
	 * receives a fully-populated Entry regardless of spill state.
	 *
	 * @param  int $id Primary key.
	 * @return Entry|null Null when the row does not exist.
	 */
	public function find( int $id ): ?Entry {
		global $wpdb;

		$logs_table = Database::logs_table();
		$col_list   = 'id, ' . implode( ', ', self::COLUMNS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs_table from Database::logs_table() (STOR-04); $col_list from private COLUMNS const; $id flows through $wpdb->prepare.
		$sql = $wpdb->prepare( "SELECT {$col_list} FROM {$logs_table} WHERE id = %d LIMIT 1", $id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the return of $wpdb->prepare on line 198; direct fetch by PK, no caching layer.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( null === $row ) {
			return null;
		}

		$entry = Entry::from_array( $row );

		if ( $entry->bodies_spilled ) {
			$body_repo = new BodyRepository();
			$bodies    = $body_repo->find_by_log_id( $entry->id );
			if ( null !== $bodies ) {
				$entry->request_body  = $bodies['request_body'];
				$entry->response_body = $bodies['response_body'];
			}
		}

		return $entry;
	}

	/**
	 * Search the logs table using the validated QueryArgs filter set.
	 *
	 * Returns N+1 detection for has_more without a second COUNT(*) query.
	 * Cursor token for the next page is encoded only when there are more rows.
	 *
	 * @param  QueryArgs $args Validated filter + pagination arguments.
	 * @return array{rows:list<Entry>,has_more:bool,next_cursor:string|null}
	 * @throws \InvalidArgumentException When the cursor token in $args is malformed (from Paginator::decode).
	 */
	public function search( QueryArgs $args ): array {
		global $wpdb;

		$result   = $this->query_builder->build( $args );
		$bindings = $result['bindings'];

		$prepared = empty( $bindings )
			? $result['sql']
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $result['sql'] composed by QueryBuilder using Database accessors + Sort::validate whitelist; placeholders embedded by the builder, user values flow through $bindings only.
			: $wpdb->prepare( $result['sql'], $bindings );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared is either the QueryBuilder-composed static SQL (no bindings branch) or $wpdb->prepare output; paginated list query, no caching at this layer.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = [];
		}

		$has_more    = false;
		$next_cursor = null;

		if ( count( $rows ) > $args->limit ) {
			$has_more = true;
			array_pop( $rows ); // Discard the N+1 probe row.
		}

		$entries = [];
		foreach ( $rows as $row ) {
			$entries[] = Entry::from_array( $row );
		}

		if ( $has_more && [] !== $entries ) {
			$last        = end( $entries );
			$next_cursor = Paginator::encode( $last->created_at_micros, $last->id );
		}

		return [
			'rows'        => $entries,
			'has_more'    => $has_more,
			'next_cursor' => $next_cursor,
		];
	}

	/**
	 * Count log entries by HTTP status class over the most recent 10k rows.
	 *
	 * Bounded scan avoids a full-table aggregate. Missing classes default to 0.
	 * status_class is stored as TINYINT (1..5) — keys are normalised to the
	 * human-readable '1xx'..'5xx' form in the return value.
	 *
	 * @return array<string,int> e.g. ['1xx' => 0, '2xx' => 9876, '3xx' => 12, '4xx' => 234, '5xx' => 5]
	 */
	public function count_by_status_class(): array {
		global $wpdb;

		$logs_table = Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs_table from Database::logs_table() (STOR-04); outer query fully static; no user input.
		$sql = "SELECT status_class, COUNT(*) AS n FROM ( SELECT status_class FROM {$logs_table} ORDER BY id DESC LIMIT 10000 ) sub GROUP BY status_class";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is fully static apart from $logs_table from accessor; bounded 10k-row aggregate for stats display.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = [
			'1xx' => 0,
			'2xx' => 0,
			'3xx' => 0,
			'4xx' => 0,
			'5xx' => 0,
		];

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$class_int = (int) $row['status_class'];
			$key       = $class_int . 'xx';
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * Count log entries by HTTP method over the most recent 10k rows.
	 *
	 * Same bounded-scan strategy as count_by_status_class. Methods not present
	 * in the table are absent from the result — no zero-fill.
	 *
	 * @return array<string,int> e.g. ['GET' => 8123, 'POST' => 1456]
	 */
	public function count_by_method(): array {
		global $wpdb;

		$logs_table = Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs_table from Database::logs_table() (STOR-04); outer query fully static.
		$sql = "SELECT method, COUNT(*) AS n FROM ( SELECT method FROM {$logs_table} ORDER BY id DESC LIMIT 10000 ) sub GROUP BY method";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is fully static apart from $logs_table from accessor; bounded 10k-row aggregate.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = [];

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$out[ (string) $row['method'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Return the oldest and newest log timestamps as ISO-8601 strings.
	 *
	 * Two single-aggregate queries rather than one combined query for
	 * predictable index use (Pitfall 3 — single MIN+MAX can pick a full scan
	 * when statistics are stale). Returns null values when the table is empty.
	 *
	 * @return array{oldest:string|null,newest:string|null}
	 */
	public function oldest_newest(): array {
		global $wpdb;

		$logs_table = Database::logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database::logs_table() (STOR-04); static MIN aggregate on indexed column.
		$oldest = $wpdb->get_var( "SELECT MIN(created_at_micros) FROM {$logs_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database::logs_table() (STOR-04); static MAX aggregate on indexed column.
		$newest = $wpdb->get_var( "SELECT MAX(created_at_micros) FROM {$logs_table}" );

		return [
			'oldest' => null === $oldest ? null : self::micros_to_iso8601( (int) $oldest ),
			'newest' => null === $newest ? null : self::micros_to_iso8601( (int) $newest ),
		];
	}

	/**
	 * Delete a single log entry and its associated body-spill row (D-22 cascade order).
	 *
	 * Bodies row deleted first so no orphan row lingers when the parent delete succeeds.
	 * Capability check is the caller's responsibility.
	 *
	 * @param  int $id Primary key of the row to delete.
	 * @return bool True when the logs row was deleted; false when the row did not exist.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$bodies_table = Database::bodies_table();
		$logs_table   = Database::logs_table();

		// D-22: cascade order — bodies first, logs second.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies_table from Database::bodies_table() (STOR-04); $id flows through $wpdb->prepare.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$bodies_table} WHERE log_id = %d", $id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database::logs_table() (STOR-04); $id flows through $wpdb->prepare.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs_table} WHERE id = %d", $id ) );

		return $wpdb->rows_affected > 0;
	}

	/**
	 * Delete multiple log entries and their associated body-spill rows (D-22 cascade order).
	 *
	 * Non-positive IDs are silently filtered. Returns the affected count from the
	 * primary logs table. Caller (BulkActionHandler / CLI) limits selection size;
	 * the IN(...) clause grows linearly with $ids but is practically bounded by
	 * HTTP request size and the 200-row server cap.
	 *
	 * @param  array<mixed> $ids List of primary-key ints to delete.
	 * @return int Rows affected in the primary logs table (0 when $ids is empty or rows absent).
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( int $i ): bool {
					return $i > 0;
				}
			)
		);

		if ( [] === $ids ) {
			return 0;
		}

		global $wpdb;

		$bodies_table = Database::bodies_table();
		$logs_table   = Database::logs_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// D-22: cascade order — bodies first, logs second.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies_table from Database accessor; $placeholders is a static '%d' string built from intval'd IDs; integer values flow through $wpdb->prepare splat.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$bodies_table} WHERE log_id IN ({$placeholders})", ...$ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor; $placeholders is a static '%d' string built from intval'd IDs; integer values flow through $wpdb->prepare splat.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs_table} WHERE id IN ({$placeholders})", ...$ids ) );

		return (int) $wpdb->rows_affected;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a created_at_micros value (int, microseconds since epoch) to ISO-8601 UTC.
	 *
	 * @param  int $micros Microseconds since Unix epoch.
	 * @return string      ISO-8601 date-time with Z suffix, e.g. '2026-05-19T11:55:00Z'.
	 */
	private static function micros_to_iso8601( int $micros ): string {
		$seconds = intdiv( $micros, 1_000_000 );
		return gmdate( 'Y-m-d\\TH:i:s\\Z', $seconds );
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
