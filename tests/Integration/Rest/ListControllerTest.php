<?php
/**
 * Integration tests for BetterRestApiLogs\Rest\ListController.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-06
 * implements includes/rest/list-controller.php. Covers cursor pagination
 * round-trips, has_more flipping at page boundaries, and filter narrowing.
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
use WP_UnitTestCase;

/**
 * Covers the REST list controller routing, validation, and shaped output.
 */
final class ListControllerTest extends WP_UnitTestCase {

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

	private function insert_entries( int $count, string $method = 'GET', int $status = 200 ): void {
		$entries = [];
		for ( $i = 0; $i < $count; $i++ ) {
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
			$entries[]            = $entry;
		}

		$repo = new LogRepository();
		$repo->insert_batch( $entries );
	}

	public function test_get_logs_returns_items_and_has_more_false_when_page_fits(): void {
		$this->insert_entries( 5 );

		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs' );
		$request->set_param( 'per_page', 10 );

		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'has_more', $data );
		$this->assertFalse( $data['has_more'], 'has_more must be false when all rows fit on one page.' );
		$this->assertCount( 5, $data['items'] );
	}

	public function test_has_more_true_when_rows_exceed_page_size(): void {
		$this->insert_entries( 25 );

		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs' );
		$request->set_param( 'per_page', 10 );

		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['has_more'], 'has_more must be true when rows exceed page size.' );
		$this->assertNotEmpty( $data['next_cursor'], 'next_cursor must be set when has_more is true.' );
	}

	public function test_cursor_pagination_second_page_returns_different_rows(): void {
		$this->insert_entries( 25 );

		// First page.
		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs' );
		$request->set_param( 'per_page', 10 );
		$first_response = \rest_do_request( $request );
		$first_data     = $first_response->get_data();
		$cursor         = $first_data['next_cursor'];

		// Second page using cursor.
		$request2 = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs' );
		$request2->set_param( 'per_page', 10 );
		$request2->set_param( 'cursor', $cursor );
		$second_response = \rest_do_request( $request2 );
		$second_data     = $second_response->get_data();

		$this->assertSame( 200, $second_response->get_status() );
		$this->assertCount( 10, $second_data['items'] );

		$first_ids  = array_column( $first_data['items'], 'id' );
		$second_ids = array_column( $second_data['items'], 'id' );

		$overlap = array_intersect( $first_ids, $second_ids );
		$this->assertEmpty( $overlap, 'Second page must not contain any rows from the first page.' );
	}

	public function test_filter_by_method_narrows_results(): void {
		$this->insert_entries( 5, 'GET' );
		$this->insert_entries( 3, 'POST' );

		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs' );
		$request->set_param( 'method', 'POST' );

		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data['items'] );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'POST', $item['method'] );
		}
	}
}
