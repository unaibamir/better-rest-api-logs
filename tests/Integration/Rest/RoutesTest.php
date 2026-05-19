<?php
/**
 * Integration tests for REST route registration and permission callbacks.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-06
 * implements includes/rest/routes.php. Asserts that every registered route
 * under better-rest-api-logs/v1 has a permission_callback that returns false
 * for anonymous users — catching __return_true and static fn () => true
 * escapes that the banlist grep alone cannot detect.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Rest;

use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-06) — REST routes class does not exist yet.

final class RoutesTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		Plugin::instance()->boot();
		// Force route registration.
		\do_action( 'rest_api_init' );
		\rest_get_server();
	}

	public function tear_down(): void {
		\wp_set_current_user( 0 );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	public function test_plugin_rest_routes_are_registered(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$brl_routes = array_filter(
			array_keys( $routes ),
			static function ( string $route ): bool {
				return 0 === strpos( $route, '/better-rest-api-logs/v1' );
			}
		);

		$this->assertNotEmpty(
			$brl_routes,
			'At least one route must be registered under /better-rest-api-logs/v1.'
		);
	}

	public function test_all_routes_deny_anonymous_user_via_permission_callback(): void {
		\wp_set_current_user( 0 );

		$server = \rest_get_server();
		$routes = $server->get_routes();

		foreach ( $routes as $route => $handlers ) {
			if ( 0 !== strpos( $route, '/better-rest-api-logs/v1' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['permission_callback'] ) ) {
					continue;
				}

				$callback = $handler['permission_callback'];
				$request  = new \WP_REST_Request( 'GET', $route );
				$result   = \call_user_func( $callback, $request );

				$this->assertFalse(
					$result,
					"permission_callback for route '{$route}' must return false for anonymous user. " .
					'Returned: ' . var_export( $result, true )
				);
			}
		}
	}

	public function test_get_logs_route_exists(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/better-rest-api-logs/v1/logs',
			$routes,
			'GET /logs route must be registered.'
		);
	}

	public function test_get_single_log_route_exists(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		// The single-log route uses a regex parameter.
		$found = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/better-rest-api-logs/v1/logs/' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'GET /logs/{id} route must be registered.' );
	}

	public function test_get_stats_route_exists(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayHasKey(
			'/better-rest-api-logs/v1/stats',
			$routes,
			'GET /stats route must be registered.'
		);
	}
}
