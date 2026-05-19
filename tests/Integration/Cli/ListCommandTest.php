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

/**
 * Covers the wp better-logs list command paging and output format.
 */
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

	/**
	 * Run the list command and expect WP_CLI::error() to throw with a message
	 * that contains "limit" — proving the integer-only validation rejected the
	 * input rather than silently coercing via (int) cast.
	 *
	 * @param string $bad_limit Raw limit value to feed to the command.
	 */
	private function assert_limit_rejected( string $bad_limit ): void {
		$command = new ListCommand();

		$threw   = false;
		$message = '';
		try {
			$command->run(
				[],
				[
					'format' => 'json',
					'limit'  => $bad_limit,
				]
			);
		} catch ( \Throwable $e ) {
			$threw   = true;
			$message = $e->getMessage();
		}

		$this->assertTrue( $threw, "--limit={$bad_limit} must be rejected, not silently coerced." );
		$this->assertStringContainsString(
			'limit',
			\strtolower( $message ),
			"Error for --limit={$bad_limit} must mention the offending parameter."
		);
	}

	public function test_list_command_rejects_non_integer_limit(): void {
		$this->insert_entries( 3 );
		$this->assert_limit_rejected( 'abc' );
	}

	public function test_list_command_rejects_decimal_limit(): void {
		$this->insert_entries( 3 );
		$this->assert_limit_rejected( '3.14' );
	}

	public function test_list_command_rejects_scientific_notation_limit(): void {
		$this->insert_entries( 3 );
		$this->assert_limit_rejected( '1e2' );
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
