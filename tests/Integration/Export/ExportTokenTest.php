<?php
/**
 * Integration tests for the one-shot export token REST surface — REST-06/EXP-05.
 *
 * RED-bar scaffold (Wave 0): Rest\ExportController does not exist until Wave 1.
 * Tests are marked incomplete with a Wave 1 reason so the suite discovers them
 * without fatalling on the absent class.
 *
 * Covers: mint (POST /export → 201 + download_url), consume-once (GET → 200 +
 * transient deleted), 410 on reuse, and subscriber cap-denial.
 *
 * Harness traps baked in per LESSONS-LEARNED:
 *  - ob_get_level() snapshot + restore (CLAUDE.md)
 *  - do_action('rest_api_init') after boot (mirrors SingleControllerTest)
 *  - admin user + wp_set_current_user in set_up (#3)
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Export;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers the one-shot signed export URL: mint → consume-once → 410 on reuse,
 * and subscriber cap-denial (REST-06/EXP-05).
 * All tests are incomplete pending Wave 1.
 */
final class ExportTokenTest extends WP_UnitTestCase {

	private const NAMESPACE = 'better-rest-api-logs/v1';

	/** @var int Snapshot ob_get_level before each test. */
	private int $ob_level_before = 0;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	/** @var int Subscriber user ID (cap-denial). */
	private int $subscriber_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id      = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		Plugin::instance()->boot();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- test fixture simulates WP firing core 'rest_api_init' hook to register routes under test.
		\do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wpdb, $wp_rest_server;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\wp_set_current_user( 0 );
		// Reset the global REST server so the next test's rest_api_init fires
		// fresh. Without this, tests that add routes inside add_action('rest_api_init')
		// in their body would get 404 because the server was already populated here.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $wp_rest_server is a WP core global; we're resetting it, not defining a new plugin-owned global.
		$wp_rest_server = null;
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * Extract the one-shot token from a download URL.
	 *
	 * Handles both pretty-permalink URLs (.../export/TOKEN) and the
	 * ?rest_route=.../export/TOKEN form produced by the WP test suite's
	 * default empty permalink structure.
	 *
	 * @param  string $url The download_url value from the mint response.
	 * @return string      The alphanumeric token.
	 */
	private function token_from_url( string $url ): string {
		// Pretty-permalink path: last segment is the token.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() delegates to parse_url() for PHP 7.4+; both are consistent on the specific PHP_URL_PATH/QUERY components we use here.
		$path = (string) \parse_url( $url, PHP_URL_PATH );
		if ( '' !== $path && 'index.php' !== \basename( $path ) ) {
			return \basename( $path );
		}

		// Non-pretty: ?rest_route=/namespace/export/TOKEN.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- same rationale as above.
		$query = (string) \parse_url( $url, PHP_URL_QUERY );
		\parse_str( $query, $params );
		$route = (string) ( $params['rest_route'] ?? '' );
		return \basename( $route );
	}

	/** Insert a log entry so filter-based exports have rows to stream. */
	private function seed_entry(): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry = Entry::from_snapshots(
			$req,
			$res,
			[
				'created_at' => \gmdate( 'Y-m-d H:i:s' ),
			]
		);

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );
		return $ids[0];
	}

	/**
	 * EXP-05/REST-06: POST /export mints a token and returns 201 + download_url.
	 *
	 * Filter token: ExportToken
	 */
	public function test_mint_returns_201_with_download_url_ExportToken(): void {
		$this->seed_entry();
		$request = new \WP_REST_Request( 'POST', '/' . self::NAMESPACE . '/export' );
		$request->set_param( 'format', 'ndjson' );

		$response = \rest_do_request( $request );

		$this->assertSame( 201, $response->get_status(), 'Mint endpoint must return 201.' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'download_url', $data, 'Response must carry a download_url.' );
		$this->assertStringContainsString( '/export/', $data['download_url'], 'download_url must point at the consume route.' );
	}

	/**
	 * EXP-05/REST-06: GET /export/{token} streams once (200) and deletes the transient.
	 *
	 * Filter token: ExportToken
	 */
	public function test_consume_once_deletes_transient_ExportToken(): void {
		$this->seed_entry();

		// Mint a token first.
		$mint_req = new \WP_REST_Request( 'POST', '/' . self::NAMESPACE . '/export' );
		$mint_req->set_param( 'format', 'ndjson' );
		$mint_res = \rest_do_request( $mint_req );

		$this->assertSame( 201, $mint_res->get_status() );
		$data  = $mint_res->get_data();
		$token = $this->token_from_url( (string) $data['download_url'] );

		// Verify the transient was created.
		$this->assertNotFalse(
			\get_transient( 'brl_export_' . $token ),
			'Transient must exist after minting.'
		);

		// Consume it.
		$consume_req = new \WP_REST_Request( 'GET', '/' . self::NAMESPACE . '/export/' . $token );
		$consume_res = \rest_do_request( $consume_req );

		$this->assertSame( 200, $consume_res->get_status(), 'First consume must return 200.' );

		// Transient must be gone after consumption (one-shot).
		$this->assertFalse(
			\get_transient( 'brl_export_' . $token ),
			'Transient must be deleted on consume (one-shot guarantee).'
		);
	}

	/**
	 * EXP-05/REST-06: a second GET on the same token must return 410 Gone.
	 *
	 * Filter token: ExportToken
	 */
	public function test_reused_token_returns_410_ExportToken(): void {
		$this->seed_entry();

		// Mint.
		$mint_req = new \WP_REST_Request( 'POST', '/' . self::NAMESPACE . '/export' );
		$mint_req->set_param( 'format', 'csv' );
		$mint_res = \rest_do_request( $mint_req );
		$data     = $mint_res->get_data();
		$token    = $this->token_from_url( (string) $data['download_url'] );

		// Consume once (burns the transient).
		\rest_do_request( new \WP_REST_Request( 'GET', '/' . self::NAMESPACE . '/export/' . $token ) );

		// Second consume must be 410.
		$second_res = \rest_do_request( new \WP_REST_Request( 'GET', '/' . self::NAMESPACE . '/export/' . $token ) );
		$this->assertSame( 410, $second_res->get_status(), 'Reused token must return 410 Gone.' );
	}

	/**
	 * EXP-05/REST-06: subscriber must receive 401 or 403 from the mint endpoint.
	 *
	 * Filter token: ExportToken
	 */
	public function test_mint_denied_for_subscriber_ExportToken(): void {
		\wp_set_current_user( $this->subscriber_id );
		$request = new \WP_REST_Request( 'POST', '/' . self::NAMESPACE . '/export' );
		$request->set_param( 'format', 'ndjson' );

		$response = \rest_do_request( $request );
		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Subscriber must receive 401 or 403 from the mint endpoint.'
		);
	}
}
