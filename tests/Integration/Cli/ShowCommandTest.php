<?php
/**
 * Integration tests for the `wp better-logs show <id>` CLI command.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-09
 * implements includes/cli/commands/show-command.php. Covers single-entry
 * output for a valid ID and error output with exit code 1 for a missing ID.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cli;

use BetterRestApiLogs\Cli\Commands\ShowCommand;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers the wp better-logs show command output and missing-id error path.
 */
final class ShowCommandTest extends WP_UnitTestCase {

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
		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	private function insert_entry(): int {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                = Entry::from_snapshots( $req, $res, [] );
		$packed               = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote = false !== $packed ? $packed : null;

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );
		return $ids[0];
	}

	public function test_show_returns_json_for_valid_id(): void {
		$log_id  = $this->insert_entry();
		$command = new ShowCommand();

		\ob_start();
		$command->run( [ $log_id ], [ 'format' => 'json' ] );
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'show command must produce valid JSON for existing entry.' );
		$this->assertSame( $log_id, $decoded['id'] ?? null );
	}

	public function test_show_emits_error_for_missing_id(): void {
		$command = new ShowCommand();

		// WP_CLI::error() throws WP_CLI\ExitException in test context.
		try {
			\ob_start();
			$command->run( [ 99999 ], [] );
			\ob_end_clean();
			$this->fail( 'ShowCommand must error on missing ID.' );
		} catch ( \Exception $e ) {
			// Expected — error path taken.
			$this->assertStringContainsString(
				'99999',
				$e->getMessage(),
				'Error message must mention the missing ID.'
			);
		}
	}
}
