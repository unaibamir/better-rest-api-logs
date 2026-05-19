<?php
/**
 * Integration tests for the `wp better-logs stats` CLI command.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-09
 * implements includes/cli/commands/stats-command.php. Covers the --format=json
 * round-trip and shape matching the stats endpoint contract.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cli;

use BetterRestApiLogs\Cli\Commands\StatsCommand;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers the wp better-logs stats command output and cache reuse.
 */
final class StatsCommandTest extends WP_UnitTestCase {

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

	private function insert_entry( string $method = 'GET', int $status = 200 ): void {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
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
		$repo->insert_batch( [ $entry ] );
	}

	public function test_stats_command_with_json_format_produces_valid_json(): void {
		$this->insert_entry( 'GET', 200 );

		$command = new StatsCommand();

		\ob_start();
		$command->run( [], [ 'format' => 'json' ] );
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertNotNull( $decoded, 'Stats command --format=json must produce valid JSON.' );
	}

	public function test_stats_json_output_has_total_estimate_key(): void {
		$this->insert_entry();

		$command = new StatsCommand();

		\ob_start();
		$command->run( [], [ 'format' => 'json' ] );
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertArrayHasKey( 'total_estimate', $decoded );
	}

	public function test_stats_cli_reports_table_size_bytes_above_zero_on_cache_miss(): void {
		// Delete any cached snapshot so the next call recomputes live.
		\delete_option( 'brl_internal' );

		// Insert a row so the table has a non-trivial footprint.
		$this->insert_entry();

		$command = new StatsCommand();

		\ob_start();
		$command->run( [], [ 'format' => 'json' ] );
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'table_size_bytes', $decoded );
		$this->assertGreaterThan(
			0,
			$decoded['table_size_bytes'],
			'CLI stats must report the real table size on a cache miss, not hard-code 0.'
		);
	}

	public function test_stats_json_output_has_by_status_class_with_five_keys(): void {
		$this->insert_entry( 'GET', 200 );

		$command = new StatsCommand();

		\ob_start();
		$command->run( [], [ 'format' => 'json' ] );
		$output = \ob_get_clean();

		$decoded = json_decode( $output, true );
		$this->assertArrayHasKey( 'by_status_class', $decoded );
		$this->assertArrayHasKey( '1xx', $decoded['by_status_class'] );
		$this->assertArrayHasKey( '2xx', $decoded['by_status_class'] );
		$this->assertArrayHasKey( '3xx', $decoded['by_status_class'] );
		$this->assertArrayHasKey( '4xx', $decoded['by_status_class'] );
		$this->assertArrayHasKey( '5xx', $decoded['by_status_class'] );
	}
}
