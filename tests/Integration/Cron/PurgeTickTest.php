<?php
/**
 * Integration tests for Cron\Purge::tick() — PURGE-02..07.
 *
 * RED-bar scaffold (Wave 0): Cron\Purge and DB\PurgeRepository do not exist
 * until Wave 1. Each test is marked incomplete with a Wave 1 reason so the
 * suite discovers them without fatalling on the absent class.
 *
 * Harness traps baked in per LESSONS-LEARNED:
 *  - ob_get_level() snapshot + restore (#CLAUDE.md)
 *  - Schema::install() + real TRUNCATE (no temp-table filter) for reliable
 *    row counts
 *  - $wpdb->suppress_errors() around intentional-failure assertions (#6)
 *  - admin user + wp_set_current_user in set_up (#3)
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cron;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * Covers bounded batch deletes, transient lock, re-enqueue, rows_affected, and
 * body-spill cascade (PURGE-02..07). All tests are incomplete pending Wave 1.
 */
final class PurgeTickTest extends WP_UnitTestCase {

	/** @var int Snapshot ob_get_level before each test. */
	private int $ob_level_before = 0;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	/** @var int Subscriber user ID (cap-denial). */
	private int $subscriber_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		// Clear the purge lock at the top of each test so a prior test that set
		// the transient and bailed before tear_down could remove it doesn't bleed
		// into this test's run. TRUNCATE below commits the transaction implicitly
		// on InnoDB, but the lock may have been written in a prior test's
		// transaction that was committed before tearDown ran.
		\delete_transient( 'brl_purge_running' );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id      = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\delete_transient( 'brl_purge_running' );
		\wp_clear_scheduled_hook( 'brl_purge_tick' );
		\wp_set_current_user( 0 );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert N rows with a given created_at timestamp.
	 *
	 * @param int    $count     Number of rows to insert.
	 * @param string $created_at MySQL DATETIME string.
	 * @return list<int> Inserted IDs.
	 */
	private function insert_rows( int $count, string $created_at ): array {
		$entries = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$req               = new RequestSnapshot();
			$req->route        = '/wp/v2/posts';
			$req->method       = 'GET';
			$req->content_type = 'application/json';

			$res               = new ResponseSnapshot();
			$res->status       = 200;
			$res->status_class = 2;
			$res->content_type = 'application/json';

			$entry     = Entry::from_snapshots( $req, $res, [ 'created_at' => $created_at ] );
			$entries[] = $entry;
		}
		$repo = new LogRepository();
		return $repo->insert_batch( $entries );
	}

	/**
	 * Insert a log row with a spilled body.
	 *
	 * @param string $created_at MySQL DATETIME string.
	 * @return int Inserted log ID.
	 */
	private function insert_row_with_spill( string $created_at ): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/media';
		$req->method       = 'POST';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 201;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                 = Entry::from_snapshots( $req, $res, [ 'created_at' => $created_at ] );
		$entry->bodies_spilled = true;
		$entry->request_body   = null;

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );

		$body_repo = new BodyRepository();
		$body_repo->insert_spilled( $ids[0], '{"req":"data"}', '{"res":"data"}' );

		return $ids[0];
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * PURGE-02/03 bounds: tick deletes rows in bounded batches and stops when
	 * either the row or time bound is reached.
	 *
	 * Filter token: PurgeTickBounds
	 */
	public function test_purge_tick_bounds_PurgeTickBounds(): void {
		global $wpdb;
		$old_date   = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );
		$recent     = \gmdate( 'Y-m-d H:i:s', \strtotime( '-1 day' ) );
		$batch_size = 5;

		// Set a tiny batch size so bounds kick in quickly.
		// Uses Registry::get_setting('retention.purge_batch_size') path.
		\update_option(
			'brl_settings_retention',
			[
				'retention_days'         => 30,
				'purge_batch_size'       => $batch_size,
				'purge_max_tick_seconds' => 10,
			]
		);

		// Seed rows: 8 past cutoff, 3 recent (must be untouched).
		$this->insert_rows( 8, $old_date );
		$this->insert_rows( 3, $recent );

		// Fire one tick — must delete at most $batch_size rows.
		\ob_start();
		( new \BetterRestApiLogs\Cron\Purge() )->tick();
		\ob_end_clean();

		$remaining = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		// After one bounded tick: (11 - batch_size) or (11 - remaining_old) remain.
		// At minimum the 3 recent rows survive.
		$this->assertGreaterThanOrEqual( 3, $remaining, 'Recent rows must not be purged.' );
		// At most $batch_size rows were deleted this tick.
		$this->assertGreaterThanOrEqual( 11 - $batch_size, $remaining, 'Bounded batch must not exceed batch_size.' );
	}

	/**
	 * PURGE-03 lock: a second concurrent tick bails when brl_purge_running transient is set.
	 *
	 * Filter token: PurgeLock
	 */
	public function test_purge_lock_PurgeLock(): void {
		global $wpdb;
		$old_date = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );
		$this->insert_rows( 5, $old_date );

		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );

		// Simulate a concurrent tick already running.
		\set_transient( 'brl_purge_running', 1, 5 * MINUTE_IN_SECONDS );

		\ob_start();
		( new \BetterRestApiLogs\Cron\Purge() )->tick();
		\ob_end_clean();

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( $before, $after, 'Tick must bail with zero deletions when lock transient is set.' );
	}

	/**
	 * PURGE-04 re-enqueue: a full batch causes an immediate follow-up single event.
	 *
	 * Filter token: PurgeReenqueue
	 */
	public function test_purge_reenqueue_PurgeReenqueue(): void {
		$batch_size = 3;
		\update_option(
			'brl_settings_retention',
			[
				'retention_days'         => 30,
				'purge_batch_size'       => $batch_size,
				'purge_max_tick_seconds' => 10,
			]
		);

		$old_date = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );
		// Seed exactly batch_size + 1 old rows so the first tick gets a full batch.
		$this->insert_rows( $batch_size + 1, $old_date );

		\ob_start();
		( new \BetterRestApiLogs\Cron\Purge() )->tick();
		\ob_end_clean();

		// A follow-up brl_purge_tick event must be scheduled.
		$cron_option = \_get_cron_array();
		$scheduled   = false;
		foreach ( $cron_option as $events_at_time ) {
			if ( isset( $events_at_time['brl_purge_tick'] ) ) {
				$scheduled = true;
				break;
			}
		}
		$this->assertTrue( $scheduled, 'Full batch must schedule an immediate brl_purge_tick follow-up.' );
	}

	/**
	 * PURGE-05 rows_affected: the returned deleted count must equal $wpdb->rows_affected
	 * from the actual DELETE, not a pre-SELECT estimate.
	 *
	 * Filter token: PurgeAffectedRows
	 */
	public function test_purge_affected_rows_PurgeAffectedRows(): void {
		global $wpdb;
		$old_date = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );
		$ids      = $this->insert_rows( 4, $old_date );

		// delete_older_than must return the actual rows_affected from the DELETE.
		$cutoff  = \gmdate( 'Y-m-d H:i:s', \strtotime( '-30 days' ) );
		$repo    = new \BetterRestApiLogs\DB\PurgeRepository();
		$deleted = $repo->delete_older_than( $cutoff, 10 );

		$this->assertSame( \count( $ids ), $deleted, 'Returned count must match the actual rows affected by DELETE.' );

		$remaining = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 0, $remaining, 'All old rows must have been deleted.' );
	}

	/**
	 * PURGE-07 cascade: body-spill rows are deleted with their parent batch;
	 * no orphan body rows remain after purge.
	 *
	 * Filter token: PurgeCascade
	 */
	public function test_purge_cascade_PurgeCascade(): void {
		global $wpdb;
		$old_date = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );

		// Insert 3 spilled rows past cutoff.
		$spill_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$spill_ids[] = $this->insert_row_with_spill( $old_date );
		}

		$bodies_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::bodies_table() );
		$this->assertSame( 3, $bodies_before, 'Test precondition: 3 spilled body rows exist.' );

		\ob_start();
		( new \BetterRestApiLogs\Cron\Purge() )->tick();
		\ob_end_clean();

		// After purge: no orphan body rows must remain for those log IDs.
		$orphans = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Database::bodies_table() .
			' WHERE log_id IN (' . \implode( ',', \array_map( 'intval', $spill_ids ) ) . ')'
		);
		$this->assertSame( 0, $orphans, 'Purge must cascade-delete spilled body rows; no orphans allowed.' );
	}
}
