<?php
/**
 * Integration tests for BetterRestApiLogs\Logger\Queue.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-06
 * implements includes/logger/queue.php. Covers CAP-04 (recursion dedupe
 * via outermost-request flag) per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Logger;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Logger\Queue;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

final class QueueTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Queue::reset();
	}

	public function tear_down(): void {
		Queue::reset();
		parent::tear_down();
	}

	/**
	 * Build a minimal RequestSnapshot for fixture use.
	 *
	 * @param string $route REST route path.
	 */
	private function make_request( string $route = '/wp/v2/posts' ): RequestSnapshot {
		$req        = new RequestSnapshot();
		$req->route = $route;
		return $req;
	}

	/**
	 * Build a minimal ResponseSnapshot for fixture use.
	 *
	 * @param int $status HTTP status code.
	 */
	private function make_response( int $status = 200 ): ResponseSnapshot {
		$res         = new ResponseSnapshot();
		$res->status = $status;
		return $res;
	}

	public function test_push_then_all_returns_pushed_entries(): void {
		$req_a = $this->make_request( '/wp/v2/posts' );
		$req_b = $this->make_request( '/wp/v2/users' );

		Queue::push( 'uuid-a', $req_a );
		Queue::push( 'uuid-b', $req_b );

		$all = Queue::all();
		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'uuid-a', $all );
		$this->assertArrayHasKey( 'uuid-b', $all );
		$this->assertSame( $req_a, $all['uuid-a']['request'] );
		$this->assertNull( $all['uuid-a']['response'] );
	}

	public function test_backfill_fills_response_for_matching_uuid(): void {
		$req = $this->make_request();
		$res = $this->make_response();

		Queue::push( 'uuid-x', $req );
		Queue::backfill( 'uuid-x', $res );

		$all = Queue::all();
		$this->assertSame( $res, $all['uuid-x']['response'] );
	}

	public function test_backfill_for_unknown_uuid_is_noop(): void {
		Queue::push( 'uuid-a', $this->make_request() );
		// This must not throw.
		Queue::backfill( 'uuid-nonexistent', $this->make_response() );

		$this->assertCount( 1, Queue::all() );
	}

	public function test_outermost_request_id_initial_null(): void {
		$this->assertNull( Queue::outermost_request_id() );
	}

	public function test_set_outermost_request_id_persists(): void {
		Queue::set_outermost_request_id( 'uuid-outer' );
		$this->assertSame( 'uuid-outer', Queue::outermost_request_id() );
	}

	public function test_reset_clears_queue_and_outermost(): void {
		Queue::push( 'uuid-a', $this->make_request() );
		Queue::set_outermost_request_id( 'uuid-a' );

		Queue::reset();

		$this->assertSame( [], Queue::all() );
		$this->assertNull( Queue::outermost_request_id() );
	}

	/**
	 * CAP-04: nested rest_do_request inside a controller must NOT produce a second log row.
	 * The outermost-request flag prevents re-entrant capture.
	 */
	public function test_nested_rest_do_request_dedupes_at_capture_layer(): void {
		global $wpdb;

		// Ensure real tables exist — SchemaInstallTest drops them in its own tear_down.
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );

		// Boot the plugin so capture hooks are wired.
		Plugin::instance()->boot();

		// Register a pair of test routes. The outer controller calls rest_do_request
		// internally, which would trigger a second pre_dispatch cycle without the guard.
		\add_action(
			'rest_api_init',
			static function () {
				\register_rest_route(
					'brl-test/v1',
					'/inner',
					[
						'methods'             => 'GET',
						'callback'            => static function () {
							return new \WP_REST_Response( [ 'ok' => true ] );
						},
						'permission_callback' => static function () {
							return true; },
					]
				);
				\register_rest_route(
					'brl-test/v1',
					'/outer',
					[
						'methods'             => 'GET',
						'callback'            => static function () {
							$inner_req = new \WP_REST_Request( 'GET', '/brl-test/v1/inner' );
							\rest_do_request( $inner_req );
							return new \WP_REST_Response( [ 'outer' => true ] );
						},
						'permission_callback' => static function () {
							return true; },
					]
				);
			}
		);

		// Reset the REST server so rest_api_init re-fires and registers our test
		// routes. Without this, a prior REST dispatch (e.g. BreakerTest) can leave
		// the server initialised, causing subsequent add_action('rest_api_init') calls
		// to be silently ignored and our routes to produce 404s.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test helper resetting WP core global.
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();

		// Dispatch the outer request.
		$request = new \WP_REST_Request( 'GET', '/brl-test/v1/outer' );
		\rest_do_request( $request );

		// Drain the queue by firing shutdown (wp-phpunit does not call PHP shutdown).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		// Only one row must exist — the inner route must be deduped.
		$outer_count = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Database::logs_table() . " WHERE route = '/brl-test/v1/outer'"
		);
		$inner_count = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Database::logs_table() . " WHERE route = '/brl-test/v1/inner'"
		);

		$this->assertSame( 1, $outer_count, 'Outer route must produce exactly one log row.' );
		$this->assertSame( 0, $inner_count, 'Inner (nested) route must not be logged (CAP-04).' );
	}
}
