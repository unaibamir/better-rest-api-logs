<?php
/**
 * Integration tests for the `wp better-logs delete <id>` CLI command.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-09
 * implements includes/cli/commands/delete-command.php. Covers the
 * defense-in-depth capability check, --yes bypass, and cascade delete of
 * spilled body rows.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cli;

use BetterRestApiLogs\Cli\Commands\DeleteCommand;
use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-09) — DeleteCommand class does not exist yet.

final class DeleteCommandTest extends WP_UnitTestCase {

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

		Plugin::instance()->boot();
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

	private function insert_entry( bool $with_spill = false ): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                 = Entry::from_snapshots( $req, $res, [] );
		$packed                = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote  = false !== $packed ? $packed : null;
		$entry->bodies_spilled = $with_spill;
		$entry->request_body   = $with_spill ? null : '{"test":true}';

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );

		if ( $with_spill ) {
			$body_repo = new BodyRepository();
			$body_repo->insert_spilled( $ids[0], '{"spilled":"data"}', '{"response":"data"}' );
		}

		return $ids[0];
	}

	public function test_delete_command_denied_for_subscriber(): void {
		\wp_set_current_user( $this->subscriber_id );

		$log_id  = $this->insert_entry();
		$command = new DeleteCommand();

		try {
			$command->run( [ $log_id ], [ 'yes' => true ] );
			$this->fail( 'DeleteCommand must error for subscriber.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString(
				'capabilities',
				strtolower( $e->getMessage() ),
				'Error must mention insufficient capabilities.'
			);
		}
	}

	public function test_delete_command_removes_row_for_admin(): void {
		global $wpdb;
		\wp_set_current_user( $this->admin_id );

		$log_id  = $this->insert_entry();
		$command = new DeleteCommand();

		\ob_start();
		$command->run( [ $log_id ], [ 'yes' => true ] );
		\ob_end_clean();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE id = %d', $log_id )
		);
		$this->assertSame( 0, $count, 'Row must be deleted for admin with --yes.' );
	}

	public function test_delete_command_cascades_to_spilled_body(): void {
		global $wpdb;
		\wp_set_current_user( $this->admin_id );

		$log_id  = $this->insert_entry( true );
		$command = new DeleteCommand();

		\ob_start();
		$command->run( [ $log_id ], [ 'yes' => true ] );
		\ob_end_clean();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d', $log_id )
		);
		$this->assertSame( 0, $count, 'Spilled body must be cascade-deleted.' );
	}
}
