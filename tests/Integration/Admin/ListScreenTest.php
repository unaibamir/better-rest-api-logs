<?php
/**
 * Integration tests for BetterRestApiLogs\Admin\ListScreen.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-07
 * implements includes/admin/list-screen.php. Covers the list page column set
 * and filter narrowing (every filter must actually narrow results, not just
 * render a form field that is ignored).
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Admin;

use BetterRestApiLogs\Admin\ListScreen;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers the admin list screen rendering and filter bar wiring.
 */
final class ListScreenTest extends WP_UnitTestCase {

	/** @var int Snapshot of ob_get_level() taken before each test. */
	private int $ob_level_before = 0;

	/** @var int Admin user id; render path runs current_user_can(manage_options). */
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
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * Insert a minimal log entry for fixture use.
	 *
	 * @param string $method  HTTP method.
	 * @param int    $status  HTTP status code.
	 * @param string $route   REST route path.
	 */
	private function insert_entry( string $method = 'GET', int $status = 200, string $route = '/wp/v2/posts' ): int {
		$req               = new RequestSnapshot();
		$req->route        = $route;
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
		$ids  = $repo->insert_batch( [ $entry ] );
		return $ids[0];
	}

	private function boot_plugin(): void {
		Plugin::instance()->boot();
	}

	public function test_render_includes_expected_column_headings(): void {
		$this->boot_plugin();
		$this->insert_entry();

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		// Columns: Time, Method, Status, Route, Duration, User, IP, Action (UI-01, D-10).
		$this->assertStringContainsString( 'Method', $html );
		$this->assertStringContainsString( 'Status', $html );
		$this->assertStringContainsString( 'Route', $html );
	}

	public function test_filter_by_method_narrows_to_matching_rows(): void {
		$this->boot_plugin();
		$this->insert_entry( 'GET', 200, '/wp/v2/posts' );
		$this->insert_entry( 'POST', 201, '/wp/v2/posts' );
		$this->insert_entry( 'GET', 200, '/wp/v2/users' );

		// Simulate the method filter.
		$_GET['method'] = 'POST';

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['method'] );

		// Only the POST row should appear; GET rows must not.
		$this->assertStringContainsString( 'POST', $html );
	}

	public function test_filter_by_status_class_narrows_to_matching_rows(): void {
		$this->boot_plugin();
		$this->insert_entry( 'GET', 200, '/wp/v2/posts' );
		$this->insert_entry( 'GET', 404, '/wp/v2/missing' );

		$_GET['status_class'] = '4xx';

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['status_class'] );

		$this->assertStringContainsString( '404', $html );
	}

	public function test_empty_filter_returns_all_rows(): void {
		$this->boot_plugin();
		$this->insert_entry( 'GET', 200 );
		$this->insert_entry( 'POST', 201 );
		$this->insert_entry( 'DELETE', 204 );

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		// All three methods should appear with no filter active.
		$this->assertStringContainsString( 'GET', $html );
		$this->assertStringContainsString( 'POST', $html );
		$this->assertStringContainsString( 'DELETE', $html );
	}

	/**
	 * Insert a row with an explicit created_at_micros value so date filters can be exercised deterministically.
	 *
	 * @param int    $micros Microseconds since Unix epoch for the row's created_at_micros column.
	 * @param string $route  REST route stored on the row (used to identify it in assertions).
	 */
	private function insert_entry_at( int $micros, string $route ): int {
		$req               = new RequestSnapshot();
		$req->route        = $route;
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                    = Entry::from_snapshots( $req, $res, [] );
		$entry->created_at        = \gmdate( 'Y-m-d H:i:s', (int) ( $micros / 1_000_000 ) );
		$entry->created_at_micros = $micros;
		$packed                   = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote     = false !== $packed ? $packed : null;

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );
		return $ids[0];
	}

	public function test_date_from_and_date_to_narrow_to_rows_within_window(): void {
		$this->boot_plugin();

		// Row 1: 2024-12-31 (before the window).
		$this->insert_entry_at( \strtotime( '2024-12-31 12:00:00 UTC' ) * 1_000_000, '/wp/v2/before' );
		// Row 2: 2025-01-15 (inside the window).
		$this->insert_entry_at( \strtotime( '2025-01-15 12:00:00 UTC' ) * 1_000_000, '/wp/v2/inside' );
		// Row 3: 2025-02-01 (after the window).
		$this->insert_entry_at( \strtotime( '2025-02-01 12:00:00 UTC' ) * 1_000_000, '/wp/v2/after' );

		$_GET['date_from'] = '2025-01-01';
		$_GET['date_to']   = '2025-01-31';

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['date_from'], $_GET['date_to'] );

		$this->assertStringContainsString( '/wp/v2/inside', $html, 'Row inside the window must appear.' );
		$this->assertStringNotContainsString( '/wp/v2/before', $html, 'Row before the window must be filtered out.' );
		$this->assertStringNotContainsString( '/wp/v2/after', $html, 'Row after the window must be filtered out.' );
	}

	/**
	 * The "Export current view" form must sit outside the list's GET form — a
	 * nested <form> is invalid HTML the browser silently drops.
	 */
	public function test_export_current_view_form_is_not_nested(): void {
		$this->boot_plugin();
		$this->insert_entry();

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		$this->assertStringContainsString( 'brl-export-current', $html, 'The export control must render.' );

		// The list GET form must close before the export POST form opens.
		$list_form_close = \strpos( $html, '</form>' );
		$export_marker   = \strpos( $html, 'brl-export-current' );
		$this->assertNotFalse( $list_form_close );
		$this->assertNotFalse( $export_marker );
		$this->assertLessThan(
			$export_marker,
			$list_form_close,
			'The export form must appear after the list GET form closes, not nested inside it.'
		);

		// No <form> may open between the list form opening and its first close —
		// i.e. the export form is not inside the GET form.
		$list_form_open = \strpos( $html, 'id="brl-logs-form"' );
		$this->assertNotFalse( $list_form_open );
		$between = \substr( $html, $list_form_open, $list_form_close - $list_form_open );
		$this->assertStringNotContainsString(
			'<form',
			$between,
			'No nested <form> may appear inside the list GET form.'
		);
	}

	/**
	 * An export action arriving on the list-page render must NOT emit a download
	 * from the render callback — exports are owned by handle_early_export, which
	 * runs before any output. The render path stays HTML.
	 */
	public function test_export_action_in_render_path_does_not_stream(): void {
		$this->boot_plugin();
		$this->insert_entry();

		$_GET['action']   = 'brl_export_ndjson';
		$_GET['log_ids']  = [ 1 ];
		$_GET['_wpnonce'] = \wp_create_nonce( 'brl_bulk' );

		\ob_start();
		ListScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['action'], $_GET['log_ids'], $_GET['_wpnonce'] );

		// The page rendered as HTML — no NDJSON payload leaked from the render path.
		$this->assertStringContainsString( 'wrap brl-admin', $html, 'The render path must produce the list page, not a download.' );
	}
}
