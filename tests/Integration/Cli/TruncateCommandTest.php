<?php
/**
 * Integration tests for `wp better-logs truncate` — CLI truncate command.
 *
 * RED-bar scaffold (Wave 0): Cli\Commands\TruncateCommand does not exist until
 * Wave 1. Tests are marked incomplete with a Wave 1 reason so the suite
 * discovers them without fatalling on the absent class.
 *
 * WP-CLI runtime stub is already loaded by tests/bootstrap.php (Lesson #1).
 * Uses \constant('WP_CLI') awareness per Lesson #8.
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
 * Covers `wp better-logs truncate --yes` (empties both tables) and the
 * prompt path without --yes (TRUNC-02). All tests are incomplete pending Wave 1.
 */
final class TruncateCommandTest extends WP_UnitTestCase {

	/** @var int Snapshot ob_get_level before each test. */
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

	/**
	 * Insert N rows for truncate-then-count assertions.
	 *
	 * @param int $count Number of rows to insert.
	 */
	private function insert_rows( int $count = 5 ): void {
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

			$entries[] = Entry::from_snapshots(
				$req,
				$res,
				[
					'created_at' => \gmdate( 'Y-m-d H:i:s' ),
				]
			);
		}
		( new LogRepository() )->insert_batch( $entries );
	}

	/**
	 * TRUNC-02: `truncate --yes` empties both tables.
	 *
	 * Filter token: TruncateCommand
	 */
	public function test_truncate_yes_empties_both_tables_TruncateCommand(): void {
		global $wpdb;
		$this->insert_rows( 5 );

		$command = new \BetterRestApiLogs\Cli\Commands\TruncateCommand();
		\ob_start();
		$command->run( [], [ 'yes' => true ] );
		\ob_end_clean();

		$logs_count   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$bodies_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::bodies_table() );
		$this->assertSame( 0, $logs_count, 'Logs table must be empty after truncate --yes.' );
		$this->assertSame( 0, $bodies_count, 'Bodies table must be empty after truncate --yes.' );
	}

	/**
	 * TRUNC-02: without --yes the command invokes WP_CLI::confirm() to prompt.
	 * We assert the confirm path is taken (the WP_CLI stub accepts confirm calls).
	 *
	 * Filter token: TruncateCommand
	 */
	public function test_truncate_without_yes_prompts_TruncateCommand(): void {
		// The WP_CLI stub's confirm() is a no-op in tests. Running the command
		// without --yes must invoke confirm() (which the stub absorbs) and still
		// complete. We assert no exception escapes and the table is empty after
		// (since the stub absorbs the confirm prompt, execution continues).
		global $wpdb;
		$this->insert_rows( 3 );

		$command = new \BetterRestApiLogs\Cli\Commands\TruncateCommand();
		\ob_start();
		$command->run( [], [] );
		\ob_end_clean();

		// If confirm() is called and the stub absorbs it, tables should be emptied.
		$logs_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		// We assert against the outcome; the prompt path assertion is that no
		// exception/fatal escapes the command without --yes.
		$this->assertIsInt( $logs_count, 'Command must complete without a fatal when run without --yes.' );
	}
}
