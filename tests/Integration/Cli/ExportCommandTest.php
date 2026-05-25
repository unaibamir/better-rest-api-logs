<?php
/**
 * Integration tests for `wp better-logs export` — CLI export command.
 *
 * RED-bar scaffold (Wave 0): Cli\Commands\ExportCommand does not exist until
 * Wave 1. Tests are marked incomplete with a Wave 1 reason so the suite
 * discovers them without fatalling on the absent class.
 *
 * Covers: BOM in CSV output, --out=../x throws ExitException before any write,
 * STDOUT stream when --out is omitted (CLI-04 + EXP-05 file path).
 *
 * WP-CLI runtime stub is already loaded by tests/bootstrap.php (Lesson #1).
 * Uses \constant('WP_CLI') awareness per Lesson #8. No return after
 * \WP_CLI::error() per Lesson #10. PHP 8.4 nullable hints per Lesson #11.
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
 * Covers --out file write (BOM prefix), path-traversal guard (ExitException),
 * and STDOUT fallback when --out is omitted (CLI-04 + EXP-05).
 * All tests are incomplete pending Wave 1.
 */
final class ExportCommandTest extends WP_UnitTestCase {

	private const TMP_CSV = '/tmp/brl-export-test.csv';

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

		// Remove any stale temp file from a previous run.
		if ( \file_exists( self::TMP_CSV ) ) {
			\unlink( self::TMP_CSV );
		}
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\wp_set_current_user( 0 );
		if ( \file_exists( self::TMP_CSV ) ) {
			\unlink( self::TMP_CSV );
		}
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/** Insert one log entry so the export has something to write. */
	private function seed_entry(): void {
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry = Entry::from_snapshots( $req, $res, [
			'created_at' => \gmdate( 'Y-m-d H:i:s' ),
		] );
		( new LogRepository() )->insert_batch( [ $entry ] );
	}

	/**
	 * --out with a safe path writes a CSV file whose first bytes are the UTF-8 BOM.
	 *
	 * Filter token: ExportCommand
	 */
	public function test_export_csv_file_starts_with_bom_ExportCommand(): void {
		$this->markTestIncomplete( 'Cli\\Commands\\ExportCommand not implemented yet — Wave 1' );

		$this->seed_entry();
		$command = new \BetterRestApiLogs\Cli\Commands\ExportCommand();
		\ob_start();
		$command->run( [], [ 'out' => self::TMP_CSV, 'format' => 'csv' ] );
		\ob_end_clean();

		$this->assertFileExists( self::TMP_CSV, 'Export command must create the output file.' );
		$head = \file_get_contents( self::TMP_CSV, false, null, 0, 3 );
		$this->assertSame( "\xEF\xBB\xBF", $head, 'CSV file must start with the UTF-8 BOM.' );
	}

	/**
	 * --out=../escape.csv must throw ExitException BEFORE writing any file (CLI-04).
	 *
	 * Filter token: ExportCommand / OutPathGuard
	 */
	public function test_traversal_out_path_throws_exit_exception_before_write_ExportCommand(): void {
		$this->markTestIncomplete( 'Cli\\Commands\\ExportCommand not implemented yet — Wave 1' );

		$this->seed_entry();
		$command    = new \BetterRestApiLogs\Cli\Commands\ExportCommand();
		$traversal  = '../escape.csv';
		$absolute   = \dirname( self::TMP_CSV ) . '/' . $traversal;

		try {
			\ob_start();
			$command->run( [], [ 'out' => $traversal, 'format' => 'csv' ] );
			\ob_end_clean();
			$this->fail( 'ExportCommand must throw for a traversal --out path.' );
		} catch ( \Exception $e ) {
			// ExitException or any exception signals the guard fired.
			$this->assertStringNotContainsString(
				$traversal,
				\realpath( \getcwd() ) . '/' . $traversal,
				'Path guard must reject traversal before writing.'
			);
		}

		// No output file must have been created.
		$this->assertFileDoesNotExist( $absolute, 'No file must be written when the path guard fires.' );
	}

	/**
	 * Without --out the command streams to STDOUT (captured via ob_start).
	 *
	 * Filter token: ExportCommand
	 */
	public function test_export_without_out_streams_to_stdout_ExportCommand(): void {
		$this->markTestIncomplete( 'Cli\\Commands\\ExportCommand not implemented yet — Wave 1' );

		$this->seed_entry();
		$command = new \BetterRestApiLogs\Cli\Commands\ExportCommand();

		\ob_start();
		$command->run( [], [ 'format' => 'ndjson' ] );
		$output = \ob_get_clean();

		// Without --out the command must write to STDOUT; ob captures it.
		$this->assertNotEmpty( $output, 'Export without --out must emit output to STDOUT.' );
		// Each line must be valid JSON.
		foreach ( \array_filter( \explode( "\n", \trim( (string) $output ) ) ) as $line ) {
			$decoded = \json_decode( $line, true );
			$this->assertIsArray( $decoded, 'Each STDOUT line must be a valid JSON object.' );
		}
	}
}
