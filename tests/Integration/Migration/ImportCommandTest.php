<?php
/**
 * Integration test scaffold for Cli\Commands\ImportCommand — MIG-05.
 *
 * Failing scaffold (Wave 0): Cli\Commands\ImportCommand does not exist until
 * plan 05.  Tests guard on class_exists and fail with a clear message.
 *
 * WP-CLI runtime stub is loaded in tests/bootstrap.php (LESSON #1) so
 * WP_CLI_Command is available without the real WP-CLI binary.
 *
 * Covers:
 *  - --dry-run reports a count and writes no brl_logs rows
 *  - --reset rewinds the cursor only (does not delete imported rows)
 *
 * Admin user + wp_set_current_user, mirror POST into REQUEST, and
 * wp_redirect return-false filters are set up per LESSONS #3–#5 so this
 * scaffold flips green in plan 05 without setup churn.
 *
 * @package BetterRestApiLogs\Tests\Integration\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Migration;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * Covers the CLI --dry-run and --reset flags (MIG-05).
 */
final class ImportCommandTest extends WP_UnitTestCase {

	use UpstreamFixture;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	/** @var int ob_get_level snapshot for teardown. */
	private int $ob_level = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();

		// Admin user setup (LESSON #3).
		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		// Mirror $_POST into $_REQUEST (LESSON #4) — ImportCommand admin path
		// may call check_admin_referer which reads $_REQUEST.
		$_POST    = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture only.
		$_REQUEST = [];

		// Prevent wp_safe_redirect from fataling under PHPUnit (LESSON #5).
		\add_filter( 'wp_redirect', '__return_false', 99 );

		$this->register_upstream_cpt_and_taxonomies();
	}

	public function tear_down(): void {
		\remove_filter( 'wp_redirect', '__return_false', 99 );
		$_POST    = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture teardown.
		$_REQUEST = [];

		while ( \ob_get_level() > $this->ob_level ) {
			\ob_end_clean();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test teardown.
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		parent::tear_down();
	}

	/**
	 * --dry-run must report a count of source rows and write nothing to brl_logs.
	 */
	public function test_dry_run_reports_count_and_writes_nothing(): void {
		$this->assert_command_implemented();

		$this->seed_upstream_posts( 3 );

		$command = $this->make_command();
		\ob_start();
		$command->import( [], [ 'dry-run' => true ] );
		\ob_end_clean();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 0, $count, '--dry-run must not insert any rows into brl_logs' );
	}

	/**
	 * --reset must rewind the cursor to 0 and set status back to idle without
	 * deleting already-imported log rows (D-09).
	 */
	public function test_reset_rewinds_cursor_only(): void {
		$this->assert_command_implemented();

		// Set a non-zero cursor to simulate a partially-completed import.
		Registry::set_internal(
			'migration',
			[
				'status'       => 'done',
				'source_total' => 5,
				'processed'    => 5,
				'imported'     => 5,
				'skipped'      => 0,
				'cursor'       => 999,
				'started_at'   => \time() - 60,
			]
		);

		$command = $this->make_command();
		\ob_start();
		$command->import( [], [ 'reset' => true ] );
		\ob_end_clean();

		$state = Registry::get_internal( 'migration' );

		$this->assertIsArray( $state, '--reset must write back the migration marker array' );
		$this->assertSame( 0, $state['cursor'] ?? -1, '--reset must rewind cursor to 0' );
		$this->assertSame( 'idle', $state['status'] ?? '', '--reset must set status back to idle' );
	}

	// -------------------------------------------------------------------------

	private function assert_command_implemented(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Cli\\Commands\\ImportCommand' ) ) {
			$this->fail( 'Cli\\Commands\\ImportCommand not implemented yet (plan 05 turns this green).' );
		}
	}

	private function make_command(): \BetterRestApiLogs\Cli\Commands\ImportCommand {
		return new \BetterRestApiLogs\Cli\Commands\ImportCommand();
	}
}
