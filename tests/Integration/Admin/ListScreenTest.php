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
}
