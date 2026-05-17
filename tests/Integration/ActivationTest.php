<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration;

use BetterRestApiLogs\Activator;
use BetterRestApiLogs\Deactivator;
use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Clean slate per test.
		delete_option( 'brl_db_version' );
		delete_option( 'brl_settings_delete_on_uninstall' );
	}

	public function test_activator_seeds_db_version_option(): void {
		Activator::activate();
		$this->assertSame( '0', get_option( 'brl_db_version' ) );
	}

	public function test_activator_seeds_opt_in_with_default_off(): void {
		Activator::activate();
		$value = get_option( 'brl_settings_delete_on_uninstall' );
		$this->assertSame( '', $value, 'Opt-in flag must default to empty string (falsy) per D-12.' );
	}

	public function test_activator_preserves_existing_user_preference_on_reactivation(): void {
		update_option( 'brl_settings_delete_on_uninstall', '1' );
		Activator::activate();
		$this->assertSame(
			'1',
			get_option( 'brl_settings_delete_on_uninstall' ),
			'add_option (not update_option) means existing preference survives reactivation per D-13.'
		);
	}

	public function test_deactivator_clears_scheduled_events(): void {
		wp_schedule_event( time() + 60, 'hourly', 'brl_purge_tick' );
		$this->assertNotFalse( wp_next_scheduled( 'brl_purge_tick' ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'brl_purge_tick' ) );
	}

	public function test_uninstall_opt_in_branch_runs_when_flag_truthy(): void {
		// Set the opt-in.
		update_option( 'brl_settings_delete_on_uninstall', '1' );
		update_option( 'brl_db_version', '1.0.0' );

		// Simulate WP's uninstall.php invocation.
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// Assert cleanup ran.
		$this->assertFalse( get_option( 'brl_db_version' ), 'brl_db_version removed.' );
		$this->assertFalse(
			get_option( 'brl_settings_delete_on_uninstall' ),
			'opt-in flag removed last per D-15.'
		);
	}

	public function test_uninstall_no_op_when_flag_off(): void {
		update_option( 'brl_settings_delete_on_uninstall', '' );
		update_option( 'brl_db_version', '1.0.0' );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// Data should be untouched when opt-in is OFF.
		$this->assertSame( '1.0.0', get_option( 'brl_db_version' ) );
		$this->assertSame( '', get_option( 'brl_settings_delete_on_uninstall' ) );
	}
}
