<?php
/**
 * Integration tests for BetterRestApiLogs\Logger\Flusher.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-07
 * implements includes/logger/flusher.php. Covers CAP-01, CAP-02, CAP-05
 * per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Logger;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Logger\Flusher;
use BetterRestApiLogs\Logger\Queue;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

final class FlusherTest extends WP_UnitTestCase {

	/**
	 * Output-buffer depth at the start of the test. do_action('shutdown') runs
	 * wp_ob_end_flush_all which closes every active ob_* buffer — including the
	 * one PHPUnit opens around each test — and PHPUnit marks the test as risky
	 * when the depth at tearDown does not match. Snapshot here, restore in
	 * tear_down so we don't double-close PHPUnit's frame.
	 *
	 * @var int
	 */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		Queue::reset();
		// Remove the WP_UnitTestCase temporary-table rewrite for our custom tables.
		// Other test classes (SchemaInstallTest) drop the real tables in their tear_down;
		// ensure they exist before every Flusher test runs.
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

	/**
	 * Boot the plugin and register /wp/v2/posts if not already registered.
	 */
	private function boot_plugin(): void {
		Plugin::instance()->boot();
		// Ensure the REST server is primed.
		\rest_get_server();
	}

	/** CAP-01 + CAP-02: dispatching a request must produce one row after shutdown drains. */
	public function test_curl_equivalent_creates_one_row(): void {
		global $wpdb;
		$this->boot_plugin();

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\rest_do_request( $request );

		// Verify deferral — no row before shutdown.
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 0, $before, 'CAP-02: no insert before shutdown.' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 1, $after, 'CAP-01: exactly one row after shutdown.' );

		$row = $wpdb->get_row( 'SELECT method, route, status FROM ' . Database::logs_table(), ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertSame( 'GET', $row['method'] );
		$this->assertSame( '/wp/v2/posts', $row['route'] );
		$this->assertGreaterThanOrEqual( 200, (int) $row['status'] );
	}

	/** CAP-02: no rows exist before shutdown is fired. */
	public function test_insert_runs_in_shutdown_handler_not_in_pre_dispatch(): void {
		global $wpdb;
		$this->boot_plugin();

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\rest_do_request( $request );

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 0, $count, 'No row must be inserted before shutdown.' );
	}

	/** CAP-05: the plugin self-namespace must never produce a log row. */
	public function test_better_rest_api_logs_route_not_logged(): void {
		global $wpdb;
		$this->boot_plugin();

		// Register a probe route under the plugin namespace.
		\add_action(
			'rest_api_init',
			static function () {
				\register_rest_route(
					'better-rest-api-logs/v1',
					'/probe',
					[
						'methods'             => 'GET',
						'callback'            => static function () {
							return new \WP_REST_Response( [ 'ok' => true ] );
						},
						'permission_callback' => static function () {
							return true; },
					]
				);
			}
		);

		$request = new \WP_REST_Request( 'GET', '/better-rest-api-logs/v1/probe' );
		\rest_do_request( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		$count = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . Database::logs_table() . " WHERE route LIKE '/better-rest-api-logs/v1%'"
		);
		$this->assertSame( 0, $count, 'CAP-05: self-namespace route must never be logged.' );
	}

	/** CAP-03: post_dispatch filter must return the same response object it receives. */
	public function test_post_dispatch_returns_result_unchanged(): void {
		$this->boot_plugin();

		$original_response = null;
		$seen_response     = null;

		// Hook at priority 10000 (after our 9999) to observe the response.
		\add_filter(
			'rest_post_dispatch',
			static function ( $result ) use ( &$seen_response ) {
				$seen_response = $result;
				return $result;
			},
			10000,
			1
		);

		// Hook at priority 1 (before ours) to capture what we start with.
		\add_filter(
			'rest_post_dispatch',
			static function ( $result ) use ( &$original_response ) {
				$original_response = $result;
				return $result;
			},
			1,
			1
		);

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\rest_do_request( $request );

		// Both hooks see the same object — Flusher must not swap it out.
		$this->assertSame( $original_response, $seen_response, 'CAP-03: passthrough discipline.' );
	}

	/** Flusher must not fatal when called under PHPUnit CLI (no fastcgi_finish_request). */
	public function test_fastcgi_finish_request_guard_does_not_fatal_on_cli(): void {
		$this->boot_plugin();

		// Capture any PHP warnings that might escape the guard.
		$warning_triggered = false;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		\set_error_handler(
			static function () use ( &$warning_triggered ) {
				$warning_triggered = true;
				return true;
			},
			E_WARNING
		);

		$flusher = new Flusher();
		$flusher->on_shutdown();

		\restore_error_handler();

		$this->assertFalse( $warning_triggered, 'fastcgi_finish_request guard must not trigger E_WARNING on CLI.' );
	}

	/** A brl_pre_insert_entry mutation must be written, not silently discarded. */
	public function test_pre_insert_entry_mutation_is_persisted(): void {
		global $wpdb;
		$this->boot_plugin();

		$filter = static function ( array $entry ) {
			$entry['route'] = '/mutated/by/filter';
			return $entry;
		};
		\add_filter( 'brl_pre_insert_entry', $filter, 10, 3 );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\rest_do_request( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		\remove_filter( 'brl_pre_insert_entry', $filter, 10 );

		$route = $wpdb->get_var( 'SELECT route FROM ' . Database::logs_table() . ' LIMIT 1' );
		$this->assertSame( '/mutated/by/filter', $route, 'The filtered route must be the value written to the row.' );
	}

	/**
	 * A spilled body must be stored only in the secondary table — the inline
	 * brl_logs columns must be NULL so the payload is not written twice.
	 */
	public function test_spilled_body_is_not_duplicated_inline(): void {
		global $wpdb;
		$this->boot_plugin();

		// Enable spill with a tiny threshold so a normal body spills.
		\update_option(
			'brl_settings_capture',
			[
				'enabled'                    => true,
				'body_spill_enabled'         => true,
				'body_spill_threshold_bytes' => 16,
				'body_size_cap_bytes'        => 1048576,
			]
		);

		\add_action(
			'rest_api_init',
			static function () {
				\register_rest_route(
					'brl-test/v1',
					'/spill',
					[
						'methods'             => 'POST',
						'callback'            => static function () {
							return new \WP_REST_Response( [ 'ok' => true ], 200 );
						},
						'permission_callback' => static function () {
							return true;
						},
					]
				);
			}
		);
		// Re-fire the init action so the route registers on the already-booted server.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'rest_api_init', \rest_get_server() );

		$request = new \WP_REST_Request( 'POST', '/brl-test/v1/spill' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"payload":"' . \str_repeat( 'x', 500 ) . '"}' );

		\rest_do_request( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		\delete_option( 'brl_settings_capture' );

		$row = $wpdb->get_row(
			'SELECT id, bodies_spilled, request_body FROM ' . Database::logs_table()
			. " WHERE route = '/brl-test/v1/spill'",
			ARRAY_A
		);
		$this->assertNotNull( $row, 'The spilled request must still be captured.' );
		$this->assertSame( '1', (string) $row['bodies_spilled'], 'The row must be flagged as spilled.' );
		$this->assertNull( $row['request_body'], 'The inline request_body must be NULL on spill — no double write.' );

		$bodies_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d', (int) $row['id'] )
		);
		$this->assertSame( 1, $bodies_count, 'The body must be stored in the secondary table.' );
	}

	/** Returning an empty array from brl_pre_insert_entry still drops the entry. */
	public function test_pre_insert_entry_empty_array_drops_entry(): void {
		global $wpdb;
		$this->boot_plugin();

		$filter = static function () {
			return [];
		};
		\add_filter( 'brl_pre_insert_entry', $filter, 10, 3 );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\rest_do_request( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );

		\remove_filter( 'brl_pre_insert_entry', $filter, 10 );

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 0, $count, 'An empty-array return must drop the entry.' );
	}
}
