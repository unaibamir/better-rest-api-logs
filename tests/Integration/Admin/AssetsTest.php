<?php
/**
 * Integration tests for conditional asset enqueueing.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-07
 * implements includes/admin/assets.php. Asserts that brl_* script and style
 * handles are NOT registered on unrelated admin pages (index.php, edit.php,
 * users.php) and ARE registered on the plugin's own list screen.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Admin;

use BetterRestApiLogs\Admin\Assets;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-07) — Assets class does not exist yet.

final class AssetsTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * Assert no brl_* handles appear in the registered scripts/styles global.
	 *
	 * @param string $screen_id WP screen ID to simulate.
	 */
	private function assert_no_brl_handles_on_screen( string $screen_id ): void {
		$screen = \set_current_screen( $screen_id );
		\do_action( 'current_screen', $screen );

		global $wp_scripts, $wp_styles;

		$script_handles = array_filter(
			array_keys( $wp_scripts->registered ?? [] ),
			static function ( string $handle ): bool {
				return 0 === strpos( $handle, 'brl-' );
			}
		);

		$style_handles = array_filter(
			array_keys( $wp_styles->registered ?? [] ),
			static function ( string $handle ): bool {
				return 0 === strpos( $handle, 'brl-' );
			}
		);

		$this->assertEmpty(
			$script_handles,
			"brl_* script handles must not be registered on screen '{$screen_id}'. Found: " . implode( ', ', $script_handles )
		);

		$this->assertEmpty(
			$style_handles,
			"brl_* style handles must not be registered on screen '{$screen_id}'. Found: " . implode( ', ', $style_handles )
		);
	}

	public function test_no_brl_handles_on_dashboard(): void {
		$this->assert_no_brl_handles_on_screen( 'index' );
	}

	public function test_no_brl_handles_on_post_list(): void {
		$this->assert_no_brl_handles_on_screen( 'edit-post' );
	}

	public function test_no_brl_handles_on_users_list(): void {
		$this->assert_no_brl_handles_on_screen( 'users' );
	}

	public function test_brl_admin_list_handle_registered_on_plugin_list_screen(): void {
		$screen = \set_current_screen( 'tools_page_better-rest-api-logs' );
		\do_action( 'current_screen', $screen );

		global $wp_scripts;

		$brl_handles = array_filter(
			array_keys( $wp_scripts->registered ?? [] ),
			static function ( string $handle ): bool {
				return 0 === strpos( $handle, 'brl-' );
			}
		);

		$this->assertNotEmpty(
			$brl_handles,
			'At least one brl-* script handle must be registered on the plugin list screen.'
		);
	}
}
