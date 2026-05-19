<?php
/**
 * Integration tests for BetterRestApiLogs\Admin\DetailScreen.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-07
 * implements includes/admin/detail-screen.php. Covers XSS-safe output
 * (body bytes must be esc_html'd before injection), language class markup for
 * highlight.js, and Copy button data attribute.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Admin;

use BetterRestApiLogs\Admin\DetailScreen;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-07) — DetailScreen class does not exist yet.

final class DetailScreenTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
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

	private function insert_entry_with_body( string $body ): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'POST';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 201;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                = Entry::from_snapshots( $req, $res, [] );
		$packed               = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote = false !== $packed ? $packed : null;
		$entry->request_body  = $body;

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );
		return $ids[0];
	}

	public function test_detail_screen_escapes_script_tags_in_body(): void {
		Plugin::instance()->boot();
		$xss_payload = '<script>alert(1)</script>';
		$log_id      = $this->insert_entry_with_body( $xss_payload );

		$_GET['log_id'] = $log_id;

		\ob_start();
		DetailScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['log_id'] );

		// The raw script tag must NOT appear in output.
		$this->assertStringNotContainsString(
			'<script>alert(1)</script>',
			$html,
			'Raw <script> tag must never appear in the detail view HTML.'
		);

		// The escaped version must be present.
		$this->assertStringContainsString(
			'&lt;script&gt;alert(1)&lt;/script&gt;',
			$html,
			'Escaped <script> form must appear in the detail view HTML.'
		);
	}

	public function test_detail_screen_includes_language_json_class(): void {
		Plugin::instance()->boot();
		$log_id = $this->insert_entry_with_body( '{"key":"value"}' );

		$_GET['log_id'] = $log_id;

		\ob_start();
		DetailScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['log_id'] );

		// highlight.js uses class="language-json" on <code> blocks.
		$this->assertStringContainsString( 'language-json', $html );
	}

	public function test_detail_screen_includes_copy_button_with_clipboard_target(): void {
		Plugin::instance()->boot();
		$log_id = $this->insert_entry_with_body( '{"key":"value"}' );

		$_GET['log_id'] = $log_id;

		\ob_start();
		DetailScreen::render();
		$html = \ob_get_clean();

		unset( $_GET['log_id'] );

		// clipboard.js requires data-clipboard-target attribute on the copy button.
		$this->assertStringContainsString( 'data-clipboard-target', $html );
	}
}
