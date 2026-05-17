<?php
/**
 * Integration test scaffold for option autoload flags.
 *
 * RED-bar baseline (Wave 0): brl_internal must be `autoload=no` (Pitfall 4
 * — migration state can grow large; autoloading bloats every page load).
 * Per-tab brl_settings_* and brl_db_version stay autoloaded.
 *
 * Plan 02-05 (Defaults::seed_all_tabs) + Plan 02-07 (Activator extension)
 * turn this green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Settings;

use BetterRestApiLogs\Activator;
use WP_UnitTestCase;

final class SettingsAutoloadTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		\delete_option( 'brl_internal' );
		\delete_option( 'brl_db_version' );
		foreach ( array( 'capture', 'privacy', 'retention', 'network', 'advanced' ) as $tab ) {
			\delete_option( "brl_settings_{$tab}" );
		}
	}

	/**
	 * @param string $option_name Option key to inspect.
	 * @return string|null Raw autoload column value, or null when option absent.
	 */
	private function autoload_for( string $option_name ): ?string {
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
		return null === $row ? null : (string) $row;
	}

	/**
	 * WP 6.6+ widened the autoload column. Per wp_determine_option_autoload_value:
	 *   - 'no' / 'off' / bool false  -> stored as 'off' (explicit no-autoload)
	 *   - 'yes' / 'on' / bool true   -> stored as 'on'  (explicit autoload)
	 *   - null / unrecognised        -> heuristic 'auto'|'auto-on'|'auto-off'
	 * Pre-6.6 stored the literal 'no'/'yes' markers. Plugin's WP floor is 6.4
	 * so tests must accept either generation's canonical "off" / "on" value.
	 */
	private const AUTOLOAD_OFF_VALUES = array( 'no', 'off' );
	private const AUTOLOAD_ON_VALUES  = array( 'yes', 'on', 'auto-on', 'auto' );

	public function test_brl_internal_is_not_autoloaded(): void {
		Activator::activate();

		$this->assertContains(
			$this->autoload_for( 'brl_internal' ),
			self::AUTOLOAD_OFF_VALUES,
			'Pitfall 4: brl_internal must be non-autoloaded — migration state can grow large.'
		);
	}

	public function test_brl_db_version_is_autoloaded(): void {
		Activator::activate();

		$this->assertNotContains(
			$this->autoload_for( 'brl_db_version' ),
			self::AUTOLOAD_OFF_VALUES,
			'brl_db_version must autoload — boot-critical option read on every page load.'
		);
	}

	public function test_brl_settings_capture_is_autoloaded(): void {
		Activator::activate();

		$this->assertNotContains(
			$this->autoload_for( 'brl_settings_capture' ),
			self::AUTOLOAD_OFF_VALUES,
			'Per-tab settings must autoload — Registry reads them on every admin page.'
		);
	}

	public function test_brl_settings_privacy_is_autoloaded(): void {
		Activator::activate();

		$this->assertNotContains(
			$this->autoload_for( 'brl_settings_privacy' ),
			self::AUTOLOAD_OFF_VALUES
		);
	}
}
