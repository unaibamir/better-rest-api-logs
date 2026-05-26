<?php
/**
 * Integration tests for request-body + content-type capture through a real
 * WP_REST_Request. WP_REST_Request::get_headers() lowercases names and swaps
 * `-` for `_`, so the Content-Type arrives as `content_type`. The earlier code
 * read `headers['content-type']` (hyphen) and always saw an empty content-type,
 * which dropped every request body. These tests drive the real request object
 * the way WordPress delivers it.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Capture;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Logger\Queue;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

final class RequestBodyCaptureTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		Queue::reset();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		Queue::reset();
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	private function boot_plugin(): void {
		Plugin::instance()->boot();
		\add_action(
			'rest_api_init',
			static function () {
				\register_rest_route(
					'brl-test/v1',
					'/echo',
					[
						'methods'             => 'POST',
						'callback'            => static function ( \WP_REST_Request $request ) {
							return new \WP_REST_Response( [ 'received' => $request->get_json_params() ], 200 );
						},
						'permission_callback' => '__return_true',
					]
				);
			}
		);
		// Re-fire init so the route registers on the already-booted REST server
		// without tripping WP's "register on rest_api_init" doing-it-wrong notice.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'rest_api_init', \rest_get_server() );
	}

	/**
	 * A JSON POST sent the way WordPress delivers it (underscored content_type
	 * header) must store the request content-type and body, not blank them.
	 */
	public function test_json_post_body_and_content_type_captured(): void {
		global $wpdb;
		$this->boot_plugin();

		$request = new \WP_REST_Request( 'POST', '/brl-test/v1/echo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$body = '{"title":"hello","count":3}';
		$request->set_body( $body );

		\rest_do_request( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		$row = $wpdb->get_row(
			'SELECT request_content_type, request_body, request_body_bytes FROM ' . Database::logs_table()
			. " WHERE route = '/brl-test/v1/echo'",
			ARRAY_A
		);

		$this->assertNotNull( $row, 'A row must be captured for the POST.' );
		$this->assertSame( 'application/json', $row['request_content_type'], 'request_content_type must be populated.' );
		$this->assertSame( $body, (string) $row['request_body'], 'request_body must be the JSON sent.' );
		$this->assertSame( \strlen( $body ), (int) $row['request_body_bytes'], 'request_body_bytes must match.' );
	}

	/** Confirm get_headers() really delivers the underscored key shape we fixed for. */
	public function test_wp_rest_request_delivers_underscored_content_type_key(): void {
		$request = new \WP_REST_Request( 'POST', '/brl-test/v1/echo' );
		$request->set_header( 'Content-Type', 'application/json' );

		$headers = $request->get_headers();

		$this->assertArrayHasKey( 'content_type', $headers, 'WP delivers the underscored key.' );
		$this->assertArrayNotHasKey( 'content-type', $headers, 'WP does not deliver the hyphenated key.' );
	}
}
