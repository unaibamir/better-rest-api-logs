<?php
/**
 * Integration tests for the `wp better-logs list` CLI command.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-09
 * implements includes/cli/commands/list-command.php. Covers --format=json
 * round-trip and --limit enforcing the per-page size.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cli;

use BetterRestApiLogs\Cli\Commands\ListCommand;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-09) — ListCommand class does not exist yet.

final class ListCommandTest extends WP_UnitTestCase {

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

	private function insert_entries( int $count, string $method = 'GET' ): void {
		$entries = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$req               = new RequestSnapshot();
			$req->route        = '/wp/v2/posts';
			$req->method       = $method;
			$req->content_type = 'application/json';

			$res               = new ResponseSnapshot();
			$res->status       = 200;
			$res->status_class = 2;
			$res->content_type = 'application/json';

			$entry                = Entry::from_snapshots( $req, $res, [] );
			$packed               = \inet_pton( '::ffff:127.0.0.1' );
			$entry->ip_raw_remote = false !== $packed ? $packed : null;
			$entries[]            = $entry;
		}

		$repo = new LogRepository();
		$repo->insert_batch( $entries );
	}

	public function test_list_command_with_json_format_produces_valid_json(): void {
		$this->insert_entries( 5 );

		$command = new ListCommand();

		\ob_start();
		$command->run(
			[],
			[
				'format' => 'json',
				'limit'  => 5,
			]
		);
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'list command --format=json must produce valid JSON.' );
		$this->assertIsArray( $decoded );
	}

	public function test_list_command_limit_controls_result_count(): void {
		$this->insert_entries( 25 );

		$command = new ListCommand();

		\ob_start();
		$command->run(
			[],
			[
				'format' => 'json',
				'limit'  => 15,
			]
		);
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertCount( 15, $decoded, '--limit=15 must return exactly 15 entries.' );
	}

	public function test_list_command_items_have_expected_fields(): void {
		$this->insert_entries( 1 );

		$command = new ListCommand();

		\ob_start();
		$command->run(
			[],
			[
				'format' => 'json',
				'limit'  => 1,
			]
		);
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertIsArray( $decoded );
		$item = $decoded[0] ?? [];

		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'method', $item );
		$this->assertArrayHasKey( 'status', $item );
		$this->assertArrayHasKey( 'route', $item );
	}
}
