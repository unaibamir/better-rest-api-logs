<?php
/**
 * Plugin Name:       Better REST API Logs
 * Plugin URI:        https://github.com/unaibamir/better-rest-api-logs
 * Description:       Reliable, accurate, queryable REST API request/response logs for WordPress — without the wp_posts bloat.
 * Version:           1.0.0
 * Requires at least: 6.6
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
// Wrapped in an IIFE so the autoloader path does not leak as a global.
$brl_autoload_loaded = ( static function (): bool {
	$autoload = BRL_DIR . 'vendor/autoload.php';
	if ( ! is_readable( $autoload ) ) {
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
	require_once $autoload;
	return true;
} )();
if ( ! $brl_autoload_loaded ) {
	return;
}
unset( $brl_autoload_loaded );

// Lifecycle hooks must register BEFORE plugins_loaded fires for the activator/deactivator to bind.
register_activation_hook( BRL_FILE, [ \BetterRestApiLogs\Activator::class, 'activate' ] );
register_deactivation_hook( BRL_FILE, [ \BetterRestApiLogs\Deactivator::class, 'deactivate' ] );

// Boot.
add_action(
	'plugins_loaded',
	static function () {
		\BetterRestApiLogs\Plugin::instance()->boot();
	},
	0
);
