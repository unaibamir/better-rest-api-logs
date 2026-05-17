<?php
/**
 * Test bootstrap — loads Composer autoload, then wp-phpunit core test framework.
 *
 * Two-suite handshake:
 *  - Unit suite: returns early after Composer autoload — no WordPress boot.
 *    Pure-PHP code under tests/Unit/ uses Yoast\PHPUnitPolyfills\TestCases\TestCase.
 *  - Integration suite: full wp-phpunit boot with the plugin loaded via the
 *    `muplugins_loaded` hook, so tests can extend WP_UnitTestCase.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

// 1. Composer autoload (production + dev classmap, brings PHPUnit + polyfills).
require dirname( __DIR__ ) . '/vendor/autoload.php';

// 2. If this is a unit-only run (no WP boot needed), exit early.
// wp-phpunit boot is heavy; unit tests should not pay for it.
// Detect both forms: `--testsuite=unit` (single arg, equals form) and
// `--testsuite unit` (two args, space form). PHPUnit accepts both.
$brl_argv  = isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : [];
$brl_suite = '';
foreach ( $brl_argv as $brl_i => $brl_arg ) {
	if ( 0 === strpos( $brl_arg, '--testsuite=' ) ) {
		$brl_suite = substr( $brl_arg, strlen( '--testsuite=' ) );
		break;
	}
	if ( '--testsuite' === $brl_arg && isset( $brl_argv[ $brl_i + 1 ] ) ) {
		$brl_suite = $brl_argv[ $brl_i + 1 ];
		break;
	}
}
if ( 'unit' === $brl_suite ) {
	// Plugin source files guard with `defined('ABSPATH') || exit;`. Unit tests do
	// not boot WordPress, so define a sentinel ABSPATH here to satisfy the guard
	// without pulling in WP. The value is unused — only the constant's existence
	// matters. Integration suite (below) gets the real ABSPATH from wp-phpunit.
	defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	return;
}

// 3. Polyfills path handshake — wp-phpunit reads this constant.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// 4. wp-phpunit dir resolution — defaults to vendor path, override with WP_TESTS_DIR env.
$brl_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $brl_wp_tests_dir || '' === $brl_wp_tests_dir ) {
	$brl_wp_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $brl_wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find wp-phpunit at {$brl_wp_tests_dir}/includes/functions.php\n" );
	exit( 1 );
}

// 5. Load our plugin into the test WP install via the muplugins_loaded hook.
require_once $brl_wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/better-rest-api-logs.php';
	}
);

// 6. Boot the WP test suite.
require $brl_wp_tests_dir . '/includes/bootstrap.php';
