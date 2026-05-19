<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Admin\Assets;
use BetterRestApiLogs\Admin\DetailScreen;
use BetterRestApiLogs\Admin\ListScreen;

/**
 * Admin boot-entry façade — registers the Tools and Settings menu items and
 * wires the conditional asset enqueuer (D-01..D-04, D-55).
 *
 * Instantiated by the container in Plugin::boot(); its hooks register at
 * admin_menu priority 10. The Settings page is handled by Admin\SettingsScreen
 * (Plan 04-08) via SettingsScreen::register_menu() called from register_menus().
 *
 * @package BetterRestApiLogs
 */
final class Admin {

	/** @var ListScreen */
	private $list;

	/** @var DetailScreen */
	private $detail;

	/** @var Assets */
	private $assets;

	/**
	 * Screen ID returned by add_submenu_page() for the hidden detail page.
	 *
	 * Populated in register_menus(); exposed via detail_screen_id() so
	 * AssetsTest can use the dynamic value (T-04-36).
	 *
	 * @var string
	 */
	private $screen_id = '';

	/**
	 * @param ListScreen   $list   List page render callback.
	 * @param DetailScreen $detail Detail page render callback.
	 * @param Assets       $assets Conditional asset enqueuer.
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.listFound -- the parameter names the ListScreen collaborator and pairs with $detail; renaming would obscure the role.
	public function __construct( ListScreen $list, DetailScreen $detail, Assets $assets ) {
		$this->list   = $list;
		$this->detail = $detail;
		$this->assets = $assets;
	}

	/**
	 * Register the admin menu pages — called on admin_menu priority 10.
	 *
	 * Two pages in this plan: the list page (Tools → REST API Logs) and the
	 * hidden detail submenu. The Settings page is registered separately by
	 * Admin\SettingsScreen (Plan 04-08).
	 *
	 * The return value of add_submenu_page() is captured and forwarded to
	 * Assets::set_detail_screen_id() so the asset enqueuer can do an exact
	 * string match instead of hard-coding the WP-mangled slug (T-04-36).
	 *
	 * @return void
	 */
	public function register_menus(): void {
		$cap = (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' );

		\add_management_page(
			\__( 'REST API Logs', 'better-rest-api-logs' ),
			\__( 'REST API Logs', 'better-rest-api-logs' ),
			$cap,
			'better-rest-api-logs',
			[ $this->list, 'render_page' ]
		);

		// Hidden submenu (parent slug = '') — not rendered in sidebar, but
		// gets a proper screen_id and capability gate from WP core.
		$hook = \add_submenu_page(
			'',
			\__( 'Log entry', 'better-rest-api-logs' ),
			'',
			$cap,
			'better-rest-api-logs-detail',
			[ $this->detail, 'render_page' ]
		);

		if ( false !== $hook ) {
			$this->screen_id = (string) $hook;
			$this->assets->set_detail_screen_id( $this->screen_id );
		}
	}

	/**
	 * The dynamic screen ID assigned by WP to the hidden detail submenu.
	 *
	 * Available after register_menus() is called (admin_menu hook).
	 *
	 * @return string
	 */
	public function detail_screen_id(): string {
		return $this->screen_id;
	}
}
