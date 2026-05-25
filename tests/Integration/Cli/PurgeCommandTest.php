<?php
/**
 * Integration tests for `wp better-logs purge` — CLI purge command.
 *
 * RED-bar scaffold (Wave 0): Cli\Commands\PurgeCommand does not exist until
 * Wave 1. Tests are marked incomplete with a Wave 1 reason so the suite
 * discovers them without fatalling on the absent class.
 *
 * WP-CLI runtime stub is already loaded by tests/bootstrap.php (Lesson #1).
 * Uses \constant('WP_CLI') awareness per Lesson #8. No return after
 * \WP_CLI::error() per Lesson #10. PHP 8.4 nullable hints baked in per #11.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cli;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers the purge CLI verb: dry-run reports count without deleting,
 * live run deletes old rows, and subscriber cap-denial throws ExitException.
 * All tests are incomplete pending Wave 1.
 */
final class PurgeCommandTest extends WP_UnitTestCase {

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

	/** Insert N rows with a given created_at. */
	private function insert_rows( int $count, string $created_at ): void {
		$entries = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$req               = new RequestSnapshot();
			$req->route        = '/wp/v2/posts';
			$req->method       = 'GET';
			$req->content_type = 'application/json';

			$res               = new ResponseSnapshot();
			$res->status       = 200;
			$res->status_class = 2;
			$res->content_type = 'application/json';

			$entries[] = Entry::from_snapshots( $req, $res, [ 'created_at' => $created_at ] );
		}
		( new LogRepository() )->insert_batch( $entries );
	}

	/**
	 * --dry-run reports a count and deletes nothing.
	 *
	 * Filter token: PurgeCommand
	 */
	public function test_purge_dry_run_reports_count_without_deleting_PurgeCommand(): void {
		$this->markTestIncomplete( 'Cli\\Commands\\PurgeCommand not implemented yet — Wave 1' );

		global $wpdb;
		$old_date = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );
		$this->insert_rows( 4, $old_date );

		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );

		$command = new \BetterRestApiLogs\Cli\Commands\PurgeCommand();
		\ob_start();
		$command->run( [], [ 'dry-run' => true ] );
		$output = \ob_end_clean();

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( $before, $after, '--dry-run must not delete any rows.' );
		// The captured output (via ob or WP_CLI mock) would contain a count; that
		// assertion can be strengthened in Wave 1 when the command exists.
	}

	/**
	 * Live run (no --dry-run) deletes eligible rows.
	 *
	 * Filter token: PurgeCommand
	 */
	public function test_purge_live_run_deletes_old_rows_PurgeCommand(): void {
		$this->markTestIncomplete( 'Cli\\Commands\\PurgeCommand not implemented yet — Wave 1' );

		global $wpdb;
		$old_date = \gmdate( 'Y-m-d H:i:s', \strtotime( '-60 days' ) );
		$recent   = \gmdate( 'Y-m-d H:i:s', \strtotime( '-1 day' ) );
		$this->insert_rows( 3, $old_date );
		$this->insert_rows( 2, $recent );

		$command = new \BetterRestApiLogs\Cli\Commands\PurgeCommand();
		\ob_start();
		$command->run( [], [] );
		\ob_end_clean();

		$remaining = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 2, $remaining, 'Live purge must delete old rows but leave recent ones.' );
	}

	/**
	 * Subscriber cap-denial throws \WP_CLI\ExitException.
	 *
	 * Filter token: PurgeCommand
	 */
	public function test_purge_command_denied_for_subscriber_PurgeCommand(): void {
		$this->markTestIncomplete( 'Cli\\Commands\\PurgeCommand not implemented yet — Wave 1' );

		\wp_set_current_user( $this->subscriber_id );
		$command = new \BetterRestApiLogs\Cli\Commands\PurgeCommand();

		try {
			$command->run( [], [] );
			$this->fail( 'PurgeCommand must throw ExitException for subscriber.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString(
				'capabilities',
				\strtolower( $e->getMessage() ),
				'Error must mention insufficient capabilities.'
			);
		}
	}
}
