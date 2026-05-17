<?php
/**
 * Integration test scaffold for Registry cache invalidation via updated_option.
 *
 * RED-bar baseline (Wave 0): targets SET-05 part 2 (hook invalidation under
 * real WP options API). Plan 02-06 turns this green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Settings;

use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

final class SettingsCacheInvalidationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'capture', 'privacy', 'retention', 'network', 'advanced' ) as $tab ) {
			\delete_option( "brl_settings_{$tab}" );
		}
		\delete_option( 'brl_internal' );
	}

	public function test_updated_option_hook_flushes_registry_cache(): void {
		// The container resolves a singleton Registry; Plugin::boot() registers
		// the updated_option hook on it.
		Plugin::instance()->boot();

		\update_option( 'brl_settings_capture', array( 'enabled' => true ) );

		$registry = Plugin::instance()->container()->get( Registry::class );

		$first = $registry->get_setting( 'capture.enabled' );
		$this->assertTrue( $first );

		\update_option( 'brl_settings_capture', array( 'enabled' => false ) );

		$second = $registry->get_setting( 'capture.enabled' );
		$this->assertFalse(
			$second,
			'SET-05: updated_option fires Registry::invalidate_cache_on_option_change so the next read reflects the new value.'
		);
	}
}
