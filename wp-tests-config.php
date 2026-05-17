<?php
/**
 * wp-phpunit integration test config (DDEV).
 *
 * Loaded by vendor/wp-phpunit/wp-phpunit/wp-tests-config.php when the
 * env var WP_PHPUNIT__TESTS_CONFIG points here. NOT shipped to wp.org
 * (excluded by .distignore) and NOT loaded at runtime — test boot only.
 *
 * @package BetterRestApiLogs
 */

// Path to the WordPress source the test suite spins up. In DDEV the WordPress
// install lives at /var/www/html/wp; override with WP_TESTS_ABSPATH for CI.
define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ?: '/var/www/html/wp/' );

// DB connection — DDEV exposes the database at host "db" with root/root by default.
define( 'DB_NAME',     getenv( 'WP_TESTS_DB_NAME' )     ?: 'db' );
define( 'DB_USER',     getenv( 'WP_TESTS_DB_USER' )     ?: 'db' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'db' );
define( 'DB_HOST',     getenv( 'WP_TESTS_DB_HOST' )     ?: 'db' );
define( 'DB_CHARSET',  'utf8mb4' );
define( 'DB_COLLATE',  '' );

// Standard test salts (placeholder values; not used for crypto in tests).
define( 'AUTH_KEY',         'test-auth-key' );
define( 'SECURE_AUTH_KEY',  'test-secure-auth-key' );
define( 'LOGGED_IN_KEY',    'test-logged-in-key' );
define( 'NONCE_KEY',        'test-nonce-key' );
define( 'AUTH_SALT',        'test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
define( 'LOGGED_IN_SALT',   'test-logged-in-salt' );
define( 'NONCE_SALT',       'test-nonce-salt' );

// Test-suite metadata expected by wp-phpunit.
define( 'WP_TESTS_DOMAIN',     'example.org' );
define( 'WP_TESTS_EMAIL',      'admin@example.org' );
define( 'WP_TESTS_TITLE',      'Better REST API Logs Test Suite' );
define( 'WP_PHP_BINARY',       'php' );
define( 'WPLANG',              '' );
define( 'WP_DEBUG',            true );

// Per-suite table prefix — overridden by env if set.
$table_prefix = getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) ?: 'wptests_';
