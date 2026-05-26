<?php
/**
 * Plugin Name:       Better REST API Logs
 * Plugin URI:        https://github.com/unaibamir/better-rest-api-logs
 * Description:       Reliable, accurate, queryable REST API request/response logs for WordPress — without the wp_posts bloat.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Unaib Amir
 * Author URI:        https://unaibamir.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       better-rest-api-logs
 * Domain Path:       /languages
 *
 * @package BetterRestApiLogs
 *
 * Better REST API Logs is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Better REST API Logs is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Constants — single place where filesystem identity is decided.
define( 'BRL_VERSION', '1.0.0' );
// Schema version — bumped on schema-changing phases per CONTEXT D-14.
define( 'BRL_DB_VERSION', '2.0' );
define( 'BRL_FILE', __FILE__ );
define( 'BRL_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRL_URL', plugin_dir_url( __FILE__ ) );
define( 'BRL_BASENAME', plugin_basename( __FILE__ ) );

// Composer classmap autoload — required for every class call site below.
// Wrapped in an IIFE so neither the autoloader path nor the load result
// leak as globals (plugin-check flags any `$brl_*` global as a prefix violation).
if ( ! ( static function (): bool {
	if ( ! is_readable( BRL_DIR . 'vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__(
					'Better REST API Logs cannot start: the Composer autoloader is missing. If you installed from git, run `composer install`. If you installed from the WordPress.org zip, please report this as a bug.',
					'better-rest-api-logs'
				);
				echo '</p></div>';
			}
		);
		return false;
	}
	require_once BRL_DIR . 'vendor/autoload.php';
	return true;
} )() ) {
	return;
}

// Lifecycle hooks must register BEFORE plugins_loaded fires for the activator/deactivator to bind.
register_activation_hook( BRL_FILE, [ \BetterRestApiLogs\Activator::class, 'activate' ] );
register_deactivation_hook( BRL_FILE, [ \BetterRestApiLogs\Deactivator::class, 'deactivate' ] );

// Schema version-mismatch trigger — must run BEFORE Plugin::boot so tables exist
// when Phase 3+ hooks resolve. Priority 0 keeps it ahead of every other plugins_loaded
// listener including our own boot() at priority 10. Per CONTEXT D-12.
add_action(
	'plugins_loaded',
	[ \BetterRestApiLogs\DB\Schema::class, 'maybe_install_or_upgrade' ],
	0
);

// Boot — priority 10 (WP default) so Schema p0 finishes first. Phase 1 had this at
// priority 0 which contradicted CONTEXT.md "Integration Points"; Plan 02-07 corrects it.
add_action(
	'plugins_loaded',
	static function () {
		\BetterRestApiLogs\Plugin::instance()->boot();
	},
	10
);
