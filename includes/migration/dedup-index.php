<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Migration;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\Domain\Entry;

/**
 * Idempotent insert seam backed by the migration_source_id UNIQUE constraint.
 *
 * A re-run of the importer collides on the UNIQUE key and is silently ignored
 * (ON DUPLICATE KEY UPDATE id=id), guaranteeing zero duplicate rows (D-05,
 * MIG-04). The migration_source_id column is VARCHAR(64) NULL with a UNIQUE KEY
 * defined in the schema; the DB enforces dedup atomically and race-free.
 *
 * brl_ tables are named only via Database::logs_table() per STOR-04.
 */
final class DedupIndex {

	/**
	 * Insert one mapped Entry into brl_logs with idempotency on migration_source_id.
	 *
	 * Returns true when a new row was inserted, false when the row already existed
	 * (the UNIQUE constraint fired) so the Importer can track imported vs skipped.
	 *
	 * @param  Entry $entry Fully mapped and re-redacted entry.
	 * @return bool True = newly inserted; false = duplicate skipped.
	 */
	public function insert( Entry $entry ): bool {
		global $wpdb;

		$logs_table = Database::logs_table();

		$columns = [
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

		$formats = [
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

		$row      = $entry->to_array();
		$tokens   = [];
		$bindings = [];

		foreach ( $columns as $col ) {
			$value = $row[ $col ] ?? null;
			if ( null === $value ) {
				$tokens[] = 'NULL';
			} else {
				$tokens[]   = $formats[ $col ];
				$bindings[] = $value;
			}
		}

		$col_list   = \implode( ',', $columns );
		$values_sql = '(' . \implode( ',', $tokens ) . ')';

		// ON DUPLICATE KEY UPDATE id=id is the canonical no-op for the migration_source_id
		// UNIQUE constraint — a re-run leaves existing rows untouched (D-05, MIG-04).
		$sql = "INSERT INTO {$logs_table} ({$col_list}) VALUES {$values_sql} ON DUPLICATE KEY UPDATE id=id";

		if ( [] === $bindings ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- all values are NULL literals; $logs_table from Database::logs_table() (STOR-04).
			$result = $wpdb->query( $sql );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from static column list and NULL/placeholder literals; user values flow through $wpdb->prepare via $bindings.
			$prepared = $wpdb->prepare( $sql, $bindings );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $prepared is $wpdb->prepare output; idempotent migration INSERT.
			$result = $wpdb->query( $prepared );
		}

		if ( false === $result ) {
			return false;
		}

		// rows_affected = 1 for a genuine INSERT; 0 when ON DUPLICATE KEY fired (no change).
		return (int) $wpdb->rows_affected === 1;
	}
}
