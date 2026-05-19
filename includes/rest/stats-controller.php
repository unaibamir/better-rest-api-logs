<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Rest;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Rest\Shapers\StatsShaper;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;

/**
 * Handles GET /better-rest-api-logs/v1/stats.
 *
 * Returns a best-effort aggregate snapshot (D-26). The snapshot is cached in
 * brl_internal.stats_snapshot for up to 5 minutes to avoid a repeated GROUP BY
 * on every request. A transient-based lock prevents simultaneous recomputes
 * (T-04-25 stampede mitigation).
 *
 * When the lock is held by a concurrent recompute the stale snapshot is served
 * as-is — a cache_age_seconds above the TTL is an honest signal, not a failure.
 * Persistence of a fresh snapshot is best-effort (try/catch \Throwable) matching
 * the Breaker::set_internal pattern (T-04-26).
 */
final class StatsController {

	private const TTL_SECONDS    = 300;
	private const LOCK_TRANSIENT = 'brl_stats_lock';
	private const LOCK_TTL       = 30;

	private LogRepository $repo;
	private SettingsRegistry $registry;

	public function __construct( LogRepository $repo, SettingsRegistry $registry ) {
		$this->repo     = $repo;
		$this->registry = $registry;
	}

	/**
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$snapshot = (array) $this->registry->get_internal( 'stats_snapshot' );
		$now      = \time();
		$age      = isset( $snapshot['computed_at'] )
			? \max( 0, $now - (int) $snapshot['computed_at'] )
			: self::TTL_SECONDS + 1;

		if ( $age > self::TTL_SECONDS ) {
			if ( false === \get_transient( self::LOCK_TRANSIENT ) ) {
				\set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );
				try {
					$snapshot = $this->refresh_snapshot( $now );
					$age      = 0;
				} finally {
					\delete_transient( self::LOCK_TRANSIENT );
				}
			}
			// Concurrent request holds the lock — fall through to the stale snapshot.
			// cache_age_seconds will be > TTL, which is an honest staleness signal.
		}

		$payload = StatsShaper::shape( $snapshot, $age );

		/** @var array<string,mixed> $payload */
		$payload = \apply_filters( 'brl_rest_response', $payload, '/stats', $request );

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Recompute the snapshot from the DB, persist it, and return it.
	 *
	 * Persistence is best-effort — a write failure only means the next request
	 * will recompute rather than serving stale data. No exception surfaces to
	 * the REST client.
	 *
	 * @param  int $now Unix timestamp of the computation moment.
	 * @return array<string,mixed>
	 */
	private function refresh_snapshot( int $now ): array {
		$by_class      = $this->repo->count_by_status_class();
		$by_method     = $this->repo->count_by_method();
		$oldest_newest = $this->repo->oldest_newest();
		$size_bytes    = $this->table_size_bytes();
		$total         = (int) \array_sum( $by_class );

		// Store computed_at as $now - 1 so any subsequent request in the same
		// wall-clock second still observes cache_age_seconds >= 1.  A client
		// reading cache_age_seconds = 0 would be indistinguishable from a fresh
		// computation; subtracting one second is conservative and accurate.
		$snapshot = [
			'computed_at'      => $now - 1,
			'total'            => $total,
			'by_status_class'  => $by_class,
			'by_method'        => $by_method,
			'oldest'           => (string) ( $oldest_newest['oldest'] ?? '' ),
			'newest'           => (string) ( $oldest_newest['newest'] ?? '' ),
			'table_size_bytes' => $size_bytes,
		];

		try {
			$this->registry->set_internal( 'stats_snapshot', $snapshot );
		} catch ( \Throwable $e ) {
			// Persistence is best-effort. Snapshot still returned for this request.
			unset( $e );
		}

		return $snapshot;
	}

	/**
	 * Query information_schema for the logs table storage footprint in bytes.
	 *
	 * Requires SELECT privileges on information_schema.TABLES. Returns 0 when
	 * the privilege is absent or the query returns null (e.g. some shared hosts).
	 * A missing size value does not fail the stats endpoint (T-04-26 / Pitfall 6).
	 *
	 * @return int
	 */
	private function table_size_bytes(): int {
		global $wpdb;

		$logs_table = Database::logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from accessor; schema and table name flow through prepare.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT (DATA_LENGTH + INDEX_LENGTH) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				\DB_NAME,
				$logs_table
			),
			ARRAY_A
		);

		return null === $row ? 0 : (int) $row['bytes'];
	}
}
