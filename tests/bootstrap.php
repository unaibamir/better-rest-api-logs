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

	// Minimal WP-function shims for unit-tested source that calls a few
	// boundary sanitizers (Settings\Registry's per-tab sanitize_* methods).
	// The integration suite gets the real WP versions from wp-phpunit; these
	// shims are only active when the unit suite runs without a WP boot.
	// Best-effort approximation: sufficient for Phase 2 inputs (normal form
	// values), but does NOT mirror every pass WP core's sanitize_text_field
	// performs — notably it does not strip null bytes or URL-encoded octets
	// like '%00'. Tests that depend on those edge cases MUST live in the
	// integration suite where the real WP function is loaded.
	// PHPCS suppressions are scoped: these MUST be the WP function names
	// (we're duck-typing core), and strip_tags is fine here because
	// wp_strip_all_tags isn't loaded in the unit suite either.
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	// phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ): int {
			return abs( (int) $maybeint );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ): string {
			$str = (string) $str;
			$str = strip_tags( $str );
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
			return trim( (string) $str );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ): string {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
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

// 6. CLI command classes extend \WP_CLI_Command, which ships with the WP-CLI
// binary at runtime and is absent from WP core, wp-phpunit, and the WordPress
// stubs package. Pull in the same stub file PHPStan uses so PHPUnit can
// autoload the command subclasses without fataling on the parent lookup.
require_once dirname( __DIR__ ) . '/stubs/wp-cli.stub.php';

// 7. Boot the WP test suite.
require $brl_wp_tests_dir . '/includes/bootstrap.php';
