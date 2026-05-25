<?php
/**
 * Integration tests for Cron\Scheduler schedule-failure surface — PURGE-06.
 *
 * RED-bar scaffold (Wave 0): Cron\Scheduler does not exist until Wave 1.
 * Tests are marked incomplete with a Wave 1 reason so the suite discovers
 * them without fatalling on the absent class.
 *
 * Mirrors the Logger\Breaker admin-notice + Site Health rendering pattern
 * per D-07. A forced false return from wp_schedule_event (via the
 * pre_schedule_event filter) must write purge_state.schedule_error and
 * surface the admin notice and a failing Site Health entry.
 *
 * Harness traps baked in per LESSONS-LEARNED (#3, #4, #5, #6).
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cron;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * Covers the schedule-failure error marker, admin notice, and Site Health
 * entry written when wp_schedule_event returns false (PURGE-06).
 * All tests are incomplete pending Wave 1.
 */
final class ScheduleFailureTest extends WP_UnitTestCase {

	/** @var int Snapshot ob_get_level before each test. */
	private int $ob_level_before = 0;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		Plugin::instance()->boot();

		// This suite exercises the "not yet scheduled" path. Earlier tests (the
		// activator, the purge follow-up) commit a brl_purge_tick event that
		// bleeds across the disabled temporary-table rollback, so start from a
		// guaranteed-unscheduled state.
		\wp_clear_scheduled_hook( 'brl_purge_tick' );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\wp_set_current_user( 0 );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * PURGE-06: force wp_schedule_event to return false via the pre_schedule_event
	 * filter and assert Scheduler::schedule() propagates the false return.
	 *
	 * Filter token: ScheduleFailure
	 */
	public function test_schedule_failure_returns_false_ScheduleFailure(): void {
		\add_filter( 'pre_schedule_event', '__return_false' );
		try {
			$result = ( new \BetterRestApiLogs\Cron\Scheduler() )->schedule();
			$this->assertFalse( $result, 'Scheduler::schedule() must return false when wp_schedule_event fails.' );
		} finally {
			\remove_filter( 'pre_schedule_event', '__return_false' );
		}
	}

	/**
	 * PURGE-06: schedule() failure writes purge_state.schedule_error = true
	 * into brl_internal via Registry::set_internal().
	 *
	 * Filter token: ScheduleFailure
	 */
	public function test_schedule_failure_writes_error_marker_ScheduleFailure(): void {
		// Clear any prior marker.
		Registry::set_internal( 'purge_state', [] );

		\add_filter( 'pre_schedule_event', '__return_false' );
		try {
			( new \BetterRestApiLogs\Cron\Scheduler() )->schedule();
		} finally {
			\remove_filter( 'pre_schedule_event', '__return_false' );
		}

		$purge_state = Registry::get_internal( 'purge_state' );
		$this->assertIsArray( $purge_state, 'purge_state must be an array.' );
		$this->assertArrayHasKey( 'schedule_error', $purge_state, 'purge_state must carry a schedule_error key.' );
		$this->assertTrue( (bool) $purge_state['schedule_error'], 'schedule_error must be true after failure.' );
	}

	/**
	 * PURGE-06: after a schedule failure the admin_notices callback renders
	 * a non-dismissible error banner (mirrors Breaker::maybe_render_armed_notice).
	 *
	 * Filter token: ScheduleFailure
	 */
	public function test_schedule_failure_renders_admin_notice_ScheduleFailure(): void {
		// Seed the error marker directly so the notice path is exercised
		// without triggering the scheduling path.
		Registry::set_internal( 'purge_state', [ 'schedule_error' => true ] );

		// is_admin() returns true only when a screen is set; this mirrors the
		// pattern in SchemaBrokenTest (established codebase convention).
		\set_current_screen( 'dashboard' );

		\ob_start();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing a WP core hook, not registering one.
		\do_action( 'admin_notices' );
		$output = \ob_get_clean();

		$this->assertNotFalse( $output );
		$this->assertStringContainsString( 'notice-error', $output, 'A notice-error div must appear when schedule_error is set.' );
		$this->assertStringContainsString( 'brl', \strtolower( $output ), 'Notice must mention the plugin.' );
	}

	/**
	 * PURGE-06: the site_status_tests filter registers a failing direct test
	 * when schedule_error is set (mirrors Breaker site-health wiring).
	 *
	 * Filter token: ScheduleFailure
	 */
	public function test_schedule_failure_registers_site_health_test_ScheduleFailure(): void {
		// Seed error marker.
		Registry::set_internal( 'purge_state', [ 'schedule_error' => true ] );

		// Collect site status tests registered via the filter.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing a WP core filter, not registering one.
		$tests = \apply_filters( 'site_status_tests', [] );

		// At least one 'direct' test with a 'brl' key must be registered.
		$found_brl_test = false;
		if ( isset( $tests['direct'] ) && \is_array( $tests['direct'] ) ) {
			foreach ( \array_keys( $tests['direct'] ) as $key ) {
				if ( false !== \strpos( (string) $key, 'brl' ) ) {
					$found_brl_test = true;
					break;
				}
			}
		}
		$this->assertTrue( $found_brl_test, 'A direct Site Health test with a brl_* key must be registered when schedule_error is set.' );
	}
}
