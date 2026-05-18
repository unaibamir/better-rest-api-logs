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
}
