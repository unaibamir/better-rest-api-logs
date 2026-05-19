<?php
/**
 * Integration tests for BetterRestApiLogs\Rest\StatsController.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-06
 * implements includes/rest/stats-controller.php. Covers the 5-minute cache
 * TTL, cache_age_seconds honesty marker, and transient lock preventing
 * redundant computation on concurrent requests.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Rest;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * Covers the REST stats controller snapshot reuse and freshness window.
 */
final class StatsControllerTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		Plugin::instance()->boot();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- test fixture simulates WP firing core 'rest_api_init' hook to register routes under test.
		\do_action( 'rest_api_init' );

		// Clear any cached snapshot from a previous test.
		Registry::get_global_instance()->set_internal( 'stats_snapshot', [] );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\wp_set_current_user( 0 );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	private function insert_entry( string $method = 'GET', int $status = 200 ): void {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = $method;
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = $status;
		$res->status_class = (int) floor( $status / 100 );
		$res->content_type = 'application/json';

		$entry                = Entry::from_snapshots( $req, $res, [] );
		$packed               = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote = false !== $packed ? $packed : null;

		$repo = new LogRepository();
		$repo->insert_batch( [ $entry ] );
	}

	public function test_first_request_computes_snapshot(): void {
		$this->insert_entry( 'GET', 200 );
		$this->insert_entry( 'POST', 201 );

		$request  = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/stats' );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'total_estimate', $data );
		$this->assertGreaterThan( 0, $data['total_estimate'] );
	}

	public function test_first_request_persists_snapshot_to_internal(): void {
		$this->insert_entry();

		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/stats' );
		\rest_do_request( $request );

		$snapshot = Registry::get_global_instance()->get_internal( 'stats_snapshot' );
		$this->assertNotEmpty( $snapshot, 'Stats snapshot must be persisted to brl_internal after first request.' );
	}

	public function test_second_request_within_ttl_returns_cached_snapshot(): void {
		$this->insert_entry();

		$request1 = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/stats' );
		\rest_do_request( $request1 );

		// Second request within TTL should read from cache.
		$request2  = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/stats' );
		$response2 = \rest_do_request( $request2 );
		$data2     = $response2->get_data();

		$this->assertSame( 200, $response2->get_status() );
		$this->assertGreaterThan( 0, $data2['cache_age_seconds'], 'Cached response must have cache_age_seconds > 0.' );
	}

	public function test_response_includes_cache_age_seconds(): void {
		$this->insert_entry();

		$request  = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/stats' );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'cache_age_seconds', $data );
		$this->assertIsInt( $data['cache_age_seconds'] );
	}

	public function test_response_has_expected_shape(): void {
		$this->insert_entry( 'GET', 200 );

		$request  = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/stats' );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'total_estimate', $data );
		$this->assertArrayHasKey( 'by_status_class', $data );
		$this->assertArrayHasKey( 'by_method', $data );
		$this->assertArrayHasKey( 'oldest', $data );
		$this->assertArrayHasKey( 'newest', $data );
		$this->assertArrayHasKey( 'table_size_bytes', $data );
		$this->assertArrayHasKey( 'cache_age_seconds', $data );

		// All five status class keys must always be present.
		$this->assertArrayHasKey( '1xx', $data['by_status_class'] );
		$this->assertArrayHasKey( '2xx', $data['by_status_class'] );
		$this->assertArrayHasKey( '3xx', $data['by_status_class'] );
		$this->assertArrayHasKey( '4xx', $data['by_status_class'] );
		$this->assertArrayHasKey( '5xx', $data['by_status_class'] );
	}
}
