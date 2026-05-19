<?php
/**
 * Integration tests for bulk action handling in the admin.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-07
 * implements includes/admin/bulk-action-handler.php. Covers nonce and
 * capability gating (denied for subscriber, allowed for admin) and cascade
 * deletion of spilled body rows.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Admin;

use BetterRestApiLogs\Admin\BulkActionHandler;
use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-07) — BulkActionHandler class does not exist yet.

final class BulkActionTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	/** @var int Subscriber user ID. */
	private int $subscriber_id = 0;

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

		$this->subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id      = $this->factory->user->create( [ 'role' => 'administrator' ] );
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

	private function insert_entry( bool $with_spill = false ): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                  = Entry::from_snapshots( $req, $res, [] );
		$packed                 = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote   = false !== $packed ? $packed : null;
		$entry->bodies_spilled  = $with_spill;
		$entry->request_body    = $with_spill ? null : '{"test":true}';

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );

		if ( $with_spill ) {
			$body_repo = new BodyRepository();
			$body_repo->insert_spilled( $ids[0], '{"spilled":"request"}', '{"spilled":"response"}' );
		}

		return $ids[0];
	}

	public function test_bulk_delete_denied_for_subscriber(): void {
		Plugin::instance()->boot();
		\wp_set_current_user( $this->subscriber_id );

		$log_id    = $this->insert_entry();
		$nonce     = \wp_create_nonce( 'brl_bulk' );
		$_POST     = [
			'action'   => 'delete',
			'log_ids'  => [ $log_id ],
			'_wpnonce' => $nonce,
		];

		$handler = new BulkActionHandler();

		// Subscriber must be denied — handler should call wp_die or redirect with error.
		$this->expectException( \WPDieException::class );
		$handler->handle();
	}

	public function test_bulk_delete_without_valid_nonce_is_rejected(): void {
		Plugin::instance()->boot();
		\wp_set_current_user( $this->admin_id );

		$log_id = $this->insert_entry();
		$_POST  = [
			'action'   => 'delete',
			'log_ids'  => [ $log_id ],
			'_wpnonce' => 'invalid-nonce-value',
		];

		$handler = new BulkActionHandler();

		$this->expectException( \WPDieException::class );
		$handler->handle();
	}

	public function test_bulk_delete_removes_rows_for_admin_with_valid_nonce(): void {
		global $wpdb;
		Plugin::instance()->boot();
		\wp_set_current_user( $this->admin_id );

		$id1 = $this->insert_entry();
		$id2 = $this->insert_entry();
		$id3 = $this->insert_entry();

		$nonce = \wp_create_nonce( 'brl_bulk' );
		$_POST = [
			'action'   => 'delete',
			'log_ids'  => [ $id1, $id2, $id3 ],
			'_wpnonce' => $nonce,
		];

		$handler = new BulkActionHandler();
		$handler->handle();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE id IN (%d, %d, %d)',
				$id1,
				$id2,
				$id3
			)
		);
		$this->assertSame( 0, $count, 'All three rows must be deleted.' );
	}

	public function test_bulk_delete_cascades_to_spilled_body_rows(): void {
		global $wpdb;
		Plugin::instance()->boot();
		\wp_set_current_user( $this->admin_id );

		$log_id = $this->insert_entry( true );

		// Verify the body row exists before deletion.
		$body_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d',
				$log_id
			)
		);
		$this->assertSame( 1, $body_before, 'Body spill row must exist before delete.' );

		$nonce = \wp_create_nonce( 'brl_bulk' );
		$_POST = [
			'action'   => 'delete',
			'log_ids'  => [ $log_id ],
			'_wpnonce' => $nonce,
		];

		$handler = new BulkActionHandler();
		$handler->handle();

		$body_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d',
				$log_id
			)
		);
		$this->assertSame( 0, $body_after, 'Body spill row must be cascaded on delete.' );
	}
}
