<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Writes overflow body payloads to {$wpdb->prefix}brl_logs_bodies (D-08, D-28).
 *
 * Single-row insert; failure does NOT arm the breaker — body-spill failure is
 * recoverable because the parent brl_logs row already landed. Flusher fires the
 * `brl_body_spill_failed` action on a false return rather than invoking Breaker::guard.
 *
 * @package BetterRestApiLogs\DB
 */
final class BodyRepository {

	/**
	 * Write one spill row for the given parent log entry.
	 *
	 * Stores the request and response bodies that were too large to keep inline
	 * in brl_logs. Both body arguments are nullable — the caller may spill only
	 * the request, only the response, or both.
	 *
	 * @param  int         $log_id    Primary key of the parent brl_logs row.
	 * @param  string|null $req_body  Raw request body bytes, or null to skip.
	 * @param  string|null $resp_body Raw response body bytes, or null to skip.
	 * @return bool True on success; false when $wpdb reports an error.
	 */
	public function insert_spilled( int $log_id, ?string $req_body, ?string $resp_body ): bool {
		global $wpdb;

		$bodies_table = Database::bodies_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- write-only spill insert; no caching applicable.
		$result = $wpdb->insert(
			$bodies_table,
			[
				'log_id'        => $log_id,
				'request_body'  => $req_body,
				'response_body' => $resp_body,
			],
			[ '%d', '%s', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Fetch the spilled body payloads for a log entry.
	 *
	 * Returns null when no spill row exists (e.g. bodies_spilled=0 on the parent row).
	 * Both body fields in the returned array may be null when the corresponding payload
	 * was not spilled (only one side was over the inline threshold).
	 *
	 * @param  int $log_id Primary key of the parent brl_logs row.
	 * @return array{request_body:string|null,response_body:string|null}|null
	 */
	public function find_by_log_id( int $log_id ): ?array {
		global $wpdb;

		$bodies_table = Database::bodies_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies_table from Database::bodies_table() (STOR-04); $log_id flows through $wpdb->prepare.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $bodies_table from Database::bodies_table() (STOR-04); $log_id flows through %d placeholder.
				"SELECT request_body, response_body FROM {$bodies_table} WHERE log_id = %d LIMIT 1",
				$log_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return [
			'request_body'  => isset( $row['request_body'] ) ? (string) $row['request_body'] : null,
			'response_body' => isset( $row['response_body'] ) ? (string) $row['response_body'] : null,
		];
	}

	/**
	 * Fetch spilled body payloads for a set of log entries in one query.
	 *
	 * Resolves bodies for an entire batch in a single WHERE log_id IN (...)
	 * rather than one round-trip per row, keeping NDJSON export free of N+1
	 * queries (D-13). The return map is keyed by log_id so the caller can
	 * look up each row's payload in O(1) without another query.
	 *
	 * Empty input returns [] without touching the database.
	 *
	 * @param  array<mixed> $log_ids Parent log IDs; mixed types accepted.
	 * @return array<int, array{request_body:string|null,response_body:string|null}>
	 *         Map of log_id → body row. IDs with no spill row are absent.
	 */
	public function find_by_log_ids( array $log_ids ): array {
		$ids = \array_values(
			\array_filter(
				\array_map( 'intval', $log_ids ),
				static function ( int $i ): bool {
					return $i > 0;
				}
			)
		);

		if ( [] === $ids ) {
			return [];
		}

		global $wpdb;

		$bodies_table = Database::bodies_table();
		$ph           = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies_table from Database accessor; $ph is a static '%d' string built from intval'd IDs; integer values flow through $wpdb->prepare splat.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $bodies_table from Database::bodies_table() (STOR-04); $ph is static '%d' placeholders built from intval'd IDs.
				"SELECT log_id, request_body, response_body FROM {$bodies_table} WHERE log_id IN ({$ph})",
				...$ids
			),
			ARRAY_A
		);

		$map = [];
		foreach ( \is_array( $rows ) ? $rows : [] as $row ) {
			$map[ (int) $row['log_id'] ] = [
				'request_body'  => isset( $row['request_body'] ) ? (string) $row['request_body'] : null,
				'response_body' => isset( $row['response_body'] ) ? (string) $row['response_body'] : null,
			];
		}

		return $map;
	}
}
