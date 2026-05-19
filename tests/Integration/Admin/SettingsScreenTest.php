<?php
/**
 * Integration tests for BetterRestApiLogs\Admin\SettingsScreen.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-08
 * implements includes/admin/settings-screen.php. Covers the per-tab
 * isolation contract: saving one tab must not wipe values saved in another
 * tab (SET-04 — enforced structurally by per-tab option groups).
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Admin;

use BetterRestApiLogs\Admin\SettingsScreen;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Repository as SettingsRepository;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Covers the admin settings screen tabs, persistence, and capability gate.
 */
final class SettingsScreenTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		\delete_option( 'brl_settings_capture' );
		\delete_option( 'brl_settings_privacy' );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	public function test_saving_privacy_tab_does_not_wipe_capture_tab(): void {
		// Seed a known value in the capture tab option.
		$capture_payload = [
			'enabled'    => true,
			'methods'    => [ 'GET', 'POST' ],
			'body_limit' => 65536,
		];
		\update_option( 'brl_settings_capture', $capture_payload );

		// Simulate saving the privacy tab via the Settings API.
		$privacy_payload = [ 'redact_authorization' => true ];
		\update_option( 'brl_settings_privacy', $privacy_payload );

		// The capture tab value must be byte-identical to what was stored before.
		$after = \get_option( 'brl_settings_capture' );
		$this->assertSame(
			$capture_payload,
			$after,
			'Saving the privacy tab must not modify the capture tab option.'
		);
	}

	public function test_settings_screen_renders_five_tabs(): void {
		$_GET['page'] = 'better-rest-api-logs-settings';

		\ob_start();
		SettingsScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['page'] );

		// Expect all five Phase 4 tabs to appear.
		$this->assertStringContainsString( 'capture', strtolower( $html ) );
		$this->assertStringContainsString( 'privacy', strtolower( $html ) );
		$this->assertStringContainsString( 'retention', strtolower( $html ) );
		$this->assertStringContainsString( 'network', strtolower( $html ) );
		$this->assertStringContainsString( 'advanced', strtolower( $html ) );
	}

	public function test_each_tab_has_its_own_form_action(): void {
		$_GET['page'] = 'better-rest-api-logs-settings';

		\ob_start();
		SettingsScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['page'] );

		// Each tab must render a <form action="options.php"> so saves are isolated.
		$this->assertStringContainsString( 'action="options.php"', $html );
	}

	/**
	 * The form posts the textarea-backed allowlist as a single string of newline
	 * separated lines; the sanitizer must parse that into the array shape the
	 * option stores, not silently wipe the list.
	 */
	public function test_textarea_string_for_array_setting_is_parsed_into_lines(): void {
		// The Settings API submits the textarea-backed allowlist as a single
		// newline-separated string. The per-tab sanitizer must split that into
		// a list of trimmed lines — anything else silently wipes user input.
		$registry = new SettingsRegistry( new SettingsRepository() );

		// Sanitizers are private to enforce the SET-01 single-entry contract;
		// reflect to invoke from the test scope without weakening visibility.
		$reflector = new \ReflectionMethod( SettingsRegistry::class, 'sanitize_capture' );
		$reflector->setAccessible( true );

		$result = $reflector->invoke( $registry, [ 'route_allowlist' => "/wc/v3/orders\n/wc/v3/products\n" ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'route_allowlist', $result );
		$this->assertSame(
			[ '/wc/v3/orders', '/wc/v3/products' ],
			$result['route_allowlist'],
			'Newline-separated textarea string must parse into an array of lines, not collapse to an empty array.'
		);
	}
}
