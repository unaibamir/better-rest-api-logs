<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Conditional asset enqueuer for the admin UI (D-33..D-36, UI-08, T-04-35).
 *
 * The maybe_enqueue() entry point is called on the current_screen action and
 * slug-matches $screen->id against our three known screens. Nothing is
 * enqueued on any other admin page, keeping wp_scripts->registered clean for
 * unrelated pages.
 *
 * The detail_screen_id is set dynamically by Admin::register_menus() from the
 * return value of add_submenu_page() (T-04-36 — do not hard-code the slug).
 *
 * bin/check-asset-enqueue.sh enforces that every wp_enqueue_* call in this file
 * lives inside a function that also contains a $screen->id comparison.
 *
 * @package BetterRestApiLogs\Admin
 */
final class Assets {

	/** @var string Screen ID for the Tools → REST API Logs list page. */
	private const SCREEN_LIST = 'tools_page_better-rest-api-logs';

	/** @var string Screen ID for the Settings → REST API Logs settings page. */
	private const SCREEN_SETTINGS = 'settings_page_better-rest-api-logs-settings';

	/**
	 * Dynamic screen ID for the hidden detail submenu.
	 * Populated by set_detail_screen_id() from add_submenu_page() return value.
	 *
	 * @var string
	 */
	private $detail_screen_id = '';

	/**
	 * Receive the dynamic screen id that WP assigns to the hidden detail submenu.
	 *
	 * Must be called before the current_screen action fires — Admin::register_menus()
	 * calls this immediately after add_submenu_page() returns.
	 *
	 * @param  string $id Screen ID returned by add_submenu_page().
	 * @return void
	 */
	public function set_detail_screen_id( string $id ): void {
		$this->detail_screen_id = $id;
	}

	/**
	 * Conditionally enqueue assets for the current screen (UI-08 / SC #5).
	 *
	 * Every wp_enqueue_* call in this method body is guarded by a $screen->id
	 * comparison — required by bin/check-asset-enqueue.sh.
	 *
	 * @param  \WP_Screen|null $screen Current admin screen. WP can fire current_screen
	 *                                 before a screen object exists (e.g. early ajax,
	 *                                 some test setups); we have nothing to enqueue then.
	 * @return void
	 */
	public function maybe_enqueue( ?\WP_Screen $screen = null ): void {
		if ( null === $screen ) {
			return;
		}

		if ( self::SCREEN_LIST === $screen->id ) {
			\wp_enqueue_style(
				'brl-admin-list',
				\plugins_url( 'assets/css/admin-list.css', \BRL_FILE ),
				[],
				\BRL_VERSION
			);
			return;
		}

		if ( self::SCREEN_SETTINGS === $screen->id ) {
			// Settings CSS — lands in Plan 04-08; skip gracefully if file absent.
			$settings_css = \plugin_dir_path( \BRL_FILE ) . 'assets/css/admin-settings.css';
			if ( \file_exists( $settings_css ) ) {
				\wp_enqueue_style(
					'brl-admin-settings',
					\plugins_url( 'assets/css/admin-settings.css', \BRL_FILE ),
					[],
					\BRL_VERSION
				);
			}
			return;
		}

		// Detail page — dynamic screen_id comparison (T-04-36).
		if ( '' !== $this->detail_screen_id && $screen->id === $this->detail_screen_id ) {
			// highlight.js style + script.
			\wp_enqueue_style(
				'brl-vendor-highlight',
				\plugins_url( 'assets/vendor/highlight/default.min.css', \BRL_FILE ),
				[],
				'11.11.1'
			);
			\wp_enqueue_script(
				'brl-vendor-highlight',
				\plugins_url( 'assets/vendor/highlight/highlight.min.js', \BRL_FILE ),
				[],
				'11.11.1',
				true
			);

			// clipboard.js.
			\wp_enqueue_script(
				'brl-vendor-clipboard',
				\plugins_url( 'assets/vendor/clipboard/clipboard.min.js', \BRL_FILE ),
				[],
				'2.0.11',
				true
			);

			// Our detail CSS + JS init (depends on both vendor handles).
			\wp_enqueue_style(
				'brl-admin-detail',
				\plugins_url( 'assets/css/admin-detail.css', \BRL_FILE ),
				[ 'brl-vendor-highlight' ],
				\BRL_VERSION
			);
			\wp_enqueue_script(
				'brl-admin-detail',
				\plugins_url( 'assets/js/admin-detail.js', \BRL_FILE ),
				[ 'brl-vendor-highlight', 'brl-vendor-clipboard' ],
				\BRL_VERSION,
				true
			);
		}
	}

	/**
	 * Add 'brl-admin' to the admin body class on our three screens (D-35).
	 *
	 * Scopes all plugin CSS under .brl-admin to prevent global style leakage.
	 *
	 * @param  string $classes Existing body class string.
	 * @return string          Possibly-augmented body class string.
	 */
	public function add_body_class( $classes ): string {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		if ( null === $screen ) {
			return (string) $classes;
		}

		$our_screens = [ self::SCREEN_LIST, self::SCREEN_SETTINGS ];
		if ( '' !== $this->detail_screen_id ) {
			$our_screens[] = $this->detail_screen_id;
		}

		if ( \in_array( $screen->id, $our_screens, true ) ) {
			return \trim( (string) $classes . ' brl-admin' );
		}

		return (string) $classes;
	}
}
