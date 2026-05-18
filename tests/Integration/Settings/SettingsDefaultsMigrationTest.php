<?php
/**
 * Integration test scaffold for forward-compatible defaults overlay.
 *
 * RED-bar baseline (Wave 0): targets SET-03/SET-05. When the stored option
 * is from an older schema and lacks newer keys, Registry must surface the
 * registered default for the missing key without writing back to the DB.
 *
 * Plan 02-05 turns this green via array_replace_recursive overlay.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Settings;

use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

final class SettingsDefaultsMigrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'capture', 'privacy', 'retention', 'network', 'advanced' ) as $tab ) {
			\delete_option( "brl_settings_{$tab}" );
		}
	}

	public function test_missing_default_key_surfaces_from_overlay(): void {
		// Simulate an older stored shape: only one key, others missing.
		\update_option( 'brl_settings_capture', array( 'enabled' => true ) );

		$registry = Plugin::instance()->container()->get( Registry::class );

		$cap_bytes = $registry->get_setting( 'capture.body_size_cap_bytes' );

		$this->assertSame(
			65536,
			$cap_bytes,
			'SET-03: missing key overlays from Defaults without a DB write.'
		);

		// And the original stored value still wins for the key that IS stored.
		$this->assertTrue( $registry->get_setting( 'capture.enabled' ) );
	}
}
