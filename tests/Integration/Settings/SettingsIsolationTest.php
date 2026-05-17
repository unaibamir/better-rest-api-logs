<?php
/**
 * Integration test scaffold for per-tab option-group isolation.
 *
 * RED-bar baseline (Wave 0): targets SET-04. Plan 02-05 + 02-06 land the
 * Registry::register_with_wp() per-tab register_setting calls; this file
 * turns green then.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Settings;

use WP_UnitTestCase;

final class SettingsIsolationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'capture', 'privacy', 'retention', 'network', 'advanced' ) as $tab ) {
			\delete_option( "brl_settings_{$tab}" );
		}
	}

	public function test_saving_capture_does_not_touch_privacy(): void {
		\update_option( 'brl_settings_capture', array( 'enabled' => false ) );
		\update_option( 'brl_settings_privacy', array( 'anonymize_ip' => true ) );

		$privacy_before = \get_option( 'brl_settings_privacy' );

		\update_option( 'brl_settings_capture', array( 'enabled' => true ) );

		$privacy_after = \get_option( 'brl_settings_privacy' );

		$this->assertSame(
			$privacy_before,
			$privacy_after,
			'SET-04: per-tab option group isolation — saving capture leaves privacy byte-identical.'
		);
	}
}
