<?php
/**
 * Integration tests for BetterRestApiLogs\Rest\SingleController.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-06
 * implements includes/rest/single-controller.php. Covers single-entry fetch
 * with body-spill resolution, 404 on missing ID, and DELETE cascade.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Rest;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers the REST single-entry controller read and delete paths.
 */
final class SingleControllerTest extends WP_UnitTestCase {

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

	private function insert_entry_with_spill(): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'POST';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 201;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                 = Entry::from_snapshots( $req, $res, [] );
		$packed                = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote  = false !== $packed ? $packed : null;
		$entry->bodies_spilled = true;
		$entry->request_body   = null;

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );

		$body_repo = new BodyRepository();
		$body_repo->insert_spilled( $ids[0], '{"spilled_request":"data"}', '{"spilled_response":"data"}' );

		return $ids[0];
	}

	public function test_get_single_returns_200_for_existing_entry(): void {
		$log_id  = $this->insert_entry_with_spill();
		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs/' . $log_id );

		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_get_single_resolves_spilled_body_bytes(): void {
		$log_id  = $this->insert_entry_with_spill();
		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs/' . $log_id );

		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		// The spilled body must be resolved from brl_logs_bodies.
		$this->assertArrayHasKey( 'request_body', $data );
		$this->assertStringContainsString( 'spilled_request', $data['request_body'] );
	}

	public function test_get_single_returns_404_for_missing_id(): void {
		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/logs/99999' );

		$response = \rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_delete_removes_log_row(): void {
		global $wpdb;
		$log_id  = $this->insert_entry_with_spill();
		$request = new \WP_REST_Request( 'DELETE', '/better-rest-api-logs/v1/logs/' . $log_id );

		$response = \rest_do_request( $request );

		$this->assertSame( 204, $response->get_status() );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE id = %d', $log_id )
		);
		$this->assertSame( 0, $count, 'Log row must be deleted.' );
	}

	public function test_delete_cascades_to_spilled_body(): void {
		global $wpdb;
		$log_id  = $this->insert_entry_with_spill();
		$request = new \WP_REST_Request( 'DELETE', '/better-rest-api-logs/v1/logs/' . $log_id );

		\rest_do_request( $request );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d', $log_id )
		);
		$this->assertSame( 0, $count, 'Spilled body row must be cascade-deleted.' );
	}
}
