<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for cron-batched retention purge (PURGE-01..08).
 *
 * Deletes log rows in bounded, deterministic batches. Body-spill rows are
 * removed before their parent log rows in the same batch so a mid-batch
 * failure never orphans a bodies row (D-06 / Phase 4 D-22).
 */
final class PurgeRepository {

	/**
	 * Delete up to $limit log rows older than $cutoff_at, cascading body-spill
	 * rows in the same batch.
	 *
	 * Selects the batch deterministically (ORDER BY id ASC LIMIT N) to avoid
	 * the non-determinism of DELETE ... LIMIT without an ORDER BY on InnoDB.
	 * Cascade order: bodies first, then logs — mirrors the Phase 4 D-22
	 * delete_many_returning_ids() pattern so a partial failure never leaves
	 * orphaned brl_logs_bodies rows.
	 *
	 * Returns the $wpdb->rows_affected value of the LOGS DELETE (not a
	 * pre-SELECT COUNT) so the caller can drive re-enqueue decisions off
	 * the actual row count removed (PURGE-05).
	 *
	 * @param  string $cutoff_at MySQL DATETIME string; rows with created_at
	 *                            strictly before this value are candidates.
	 * @param  int    $limit      Maximum rows to delete in this batch.
	 * @return int                Rows deleted from brl_logs (0 when nothing matched).
	 */
	public function delete_older_than( string $cutoff_at, int $limit ): int {
		global $wpdb;

		$logs   = Database::logs_table();
		$bodies = Database::bodies_table();

		// Deterministic batch — DELETE ... LIMIT without ORDER BY is non-deterministic
		// on InnoDB (RESEARCH Pitfall 5). Selecting IDs first lets us build a stable
		// IN-list for both deletes.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs from Database accessor; $cutoff_at and $limit flow through $wpdb->prepare.
		$raw_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs from Database::logs_table() (STOR-04); cutoff and limit flow through %s/%d placeholders.
				"SELECT id FROM {$logs} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
				$cutoff_at,
				$limit
			)
		);

		$ids = \array_map( 'intval', \is_array( $raw_ids ) ? $raw_ids : [] );

		if ( [] === $ids ) {
			return 0;
		}

		$ph = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );

		// D-06 cascade order — bodies first, logs second.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies from Database accessor; $ph is a static '%d' string built from intval'd IDs; integer values flow through $wpdb->prepare splat.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$bodies} WHERE log_id IN ({$ph})", ...$ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs from Database accessor; $ph is a static '%d' string built from intval'd IDs; integer values flow through $wpdb->prepare splat.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs} WHERE id IN ({$ph})", ...$ids ) );

		// PURGE-05: return rows_affected of the LOGS delete, not a pre-SELECT estimate.
		return (int) $wpdb->rows_affected;
	}
}
