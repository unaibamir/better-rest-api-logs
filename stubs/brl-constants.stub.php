<?php
/**
 * PHPStan-only stubs for plugin constants defined at runtime in better-rest-api-logs.php.
 * Never loaded at runtime.
 */

namespace {
	if ( ! defined( 'BRL_VERSION' ) ) {
		define( 'BRL_VERSION', '1.0.0' );
	}
	if ( ! defined( 'BRL_DB_VERSION' ) ) {
		define( 'BRL_DB_VERSION', '2.0' );
	}
	if ( ! defined( 'BRL_FILE' ) ) {
		define( 'BRL_FILE', '' );
	}
	if ( ! defined( 'BRL_DIR' ) ) {
		define( 'BRL_DIR', '' );
	}
	if ( ! defined( 'BRL_URL' ) ) {
		define( 'BRL_URL', '' );
	}
	if ( ! defined( 'BRL_BASENAME' ) ) {
		define( 'BRL_BASENAME', '' );
	}
	// WordPress core defines DB_NAME in wp-config.php at runtime. The phpstan-wordpress
	// extension does not ship this constant, so the StatsController query that reads
	// it through information_schema needs a stub to keep level 6 clean.
	if ( ! defined( 'DB_NAME' ) ) {
		define( 'DB_NAME', '' );
	}
}
