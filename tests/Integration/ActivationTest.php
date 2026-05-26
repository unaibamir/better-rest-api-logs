<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration;

use BetterRestApiLogs\Activator;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\Deactivator;
use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Clean slate per test.
		\delete_option( 'brl_db_version' );
		\delete_option( 'brl_settings_delete_on_uninstall' );
	}

	public function tear_down(): void {
		// activate() schedules brl_purge_tick when retention is active; clear it
		// so the committed cron event does not bleed into later cron tests.
		\wp_clear_scheduled_hook( 'brl_purge_tick' );
		parent::tear_down();
	}

	public function test_activator_seeds_db_version_option(): void {
		Activator::activate();
		$this->assertSame( '0', \get_option( 'brl_db_version' ) );
	}

	public function test_activator_seeds_opt_in_with_default_off(): void {
		Activator::activate();
		$value = \get_option( 'brl_settings_delete_on_uninstall' );
		$this->assertSame( '', $value, 'Opt-in flag must default to empty string (falsy) per D-12.' );
	}

	public function test_activator_preserves_existing_user_preference_on_reactivation(): void {
		\update_option( 'brl_settings_delete_on_uninstall', '1' );
		Activator::activate();
		$this->assertSame(
			'1',
			\get_option( 'brl_settings_delete_on_uninstall' ),
			'add_option (not update_option) means existing preference survives reactivation per D-13.'
		);
	}

	/**
	 * Passing $network_wide on a non-multisite install must still seed the
	 * current site (the is_multisite() guard falls through to the single path).
	 */
	public function test_activator_network_wide_arg_falls_through_on_single_site(): void {
		if ( \is_multisite() ) {
			$this->markTestSkipped( 'Single-site fall-through path; multisite covered separately.' );
		}

		Activator::activate( true );

		$this->assertSame( '0', \get_option( 'brl_db_version' ), 'Network arg must still seed the current site on single-site.' );
	}

	/**
	 * On a real network activation, every existing sub-site must get its own
	 * tables so capture works on each blog immediately. Runs only in a multisite
	 * test cell.
	 */
	public function test_network_activation_creates_tables_on_each_site(): void {
		if ( ! \is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite test environment.' );
		}

		$blog_id = (int) $this->factory->blog->create();

		Activator::activate( true );

		\switch_to_blog( $blog_id );
		global $wpdb;
		$table  = Database::logs_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		\restore_current_blog();

		$this->assertSame( $table, $exists, 'Network activation must create the logs table on each sub-site.' );
	}

	public function test_deactivator_clears_scheduled_events(): void {
		\wp_schedule_event( \time() + 60, 'hourly', 'brl_purge_tick' );
		$this->assertNotFalse( \wp_next_scheduled( 'brl_purge_tick' ) );

		Deactivator::deactivate();

		$this->assertFalse( \wp_next_scheduled( 'brl_purge_tick' ) );
	}

	public function test_uninstall_opt_in_branch_runs_when_flag_truthy(): void {
		// Set the opt-in.
		\update_option( 'brl_settings_delete_on_uninstall', '1' );
		\update_option( 'brl_db_version', '1.0.0' );

		// Simulate WP's uninstall.php invocation.
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// Assert cleanup ran.
		$this->assertFalse( \get_option( 'brl_db_version' ), 'brl_db_version removed.' );
		$this->assertFalse(
			\get_option( 'brl_settings_delete_on_uninstall' ),
			'opt-in flag removed last per D-15.'
		);
	}

	public function test_uninstall_no_op_when_flag_off(): void {
		\update_option( 'brl_settings_delete_on_uninstall', '' );
		\update_option( 'brl_db_version', '1.0.0' );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// Data should be untouched when opt-in is OFF.
		$this->assertSame( '1.0.0', \get_option( 'brl_db_version' ) );
		$this->assertSame( '', \get_option( 'brl_settings_delete_on_uninstall' ) );
	}
}
