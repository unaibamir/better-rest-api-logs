<?php
/**
 * Integration tests that save a settings tab through the real Settings API
 * pipeline — register_setting() wires sanitize_option_{$name}, and a plain
 * update_option() fires that filter exactly as a form Save on options.php does.
 *
 * This is the path a real admin takes. WP_Hook dispatches the registered
 * callback through call_user_func, which cannot reach a private method: on
 * PHP 8 that is a hard Error, so a private sanitizer fatals every tab Save.
 * A test that calls the sanitizer directly (or via reflection) never exercises
 * that dispatch and so cannot catch it.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Settings;

use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Repository as SettingsRepository;
use WP_UnitTestCase;

/**
 * Drives a real options.php-style Save for every settings tab.
 */
final class SettingsSaveTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->boot();

		// register_with_wp() is normally fired on admin_init. Call it directly so
		// the sanitize_option_{$tab} filter is wired without pulling in WP core's
		// own admin_init listeners (some hit the network / send headers under PHPUnit).
		( new SettingsRegistry( new SettingsRepository() ) )->register_with_wp();
	}

	public function tear_down(): void {
		foreach ( [ 'capture', 'privacy', 'retention', 'network', 'advanced' ] as $tab ) {
			\delete_option( "brl_settings_{$tab}" );
		}
		parent::tear_down();
	}

	/**
	 * Saving the capture tab through update_option() must run the registered
	 * sanitizer via the sanitize_option filter and persist a sanitized array —
	 * no PHP Error. Fatals on the pre-fix private method.
	 */
	public function test_saving_capture_tab_through_settings_api_persists_sanitized(): void {
		$raw = [
			'enabled'         => '1',
			'route_allowlist' => "/wc/v3/orders\n/wc/v3/products",
		];

		\update_option( 'brl_settings_capture', $raw );

		$stored = \get_option( 'brl_settings_capture' );

		$this->assertIsArray( $stored, 'The capture option must persist as a sanitized array.' );
		$this->assertTrue( $stored['enabled'], 'enabled must coerce to a real boolean.' );
		$this->assertSame(
			[ '/wc/v3/orders', '/wc/v3/products' ],
			$stored['route_allowlist'],
			'The newline-separated textarea value must parse into a list of lines.'
		);
	}

	/**
	 * Every tab's registered sanitize callback must be invokable through the
	 * Settings API. A private method would throw an Error from WP_Hook on the
	 * first Save; reaching the assertion at all proves the callback dispatched.
	 *
	 * @dataProvider tab_provider
	 *
	 * @param string $tab Settings tab slug under test.
	 */
	public function test_every_tab_saves_through_settings_api_without_error( string $tab ): void {
		\update_option( "brl_settings_{$tab}", [] );

		$stored = \get_option( "brl_settings_{$tab}" );

		$this->assertIsArray(
			$stored,
			"Saving the {$tab} tab through the Settings API must return a sanitized array, not fatal."
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function tab_provider(): array {
		return [
			'capture'   => [ 'capture' ],
			'privacy'   => [ 'privacy' ],
			'retention' => [ 'retention' ],
			'network'   => [ 'network' ],
			'advanced'  => [ 'advanced' ],
		];
	}
}
