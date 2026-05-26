<?php
/**
 * Integration test scaffold for Migration\Importer — MIG-01, MIG-04, MIG-06.
 *
 * Failing scaffold (Wave 0): Migration\Importer does not exist until plan 04.
 * Tests guard on class_exists and fail with a clear message rather than fatal.
 *
 * Covers:
 *  - Full importer run inserts the expected number of brl_logs rows (MIG-01)
 *  - Second full run inserts zero duplicates via migration_source_id UNIQUE (MIG-04)
 *  - Imported bodies are re-redacted with the current Capture\Redactor config (MIG-06)
 *
 * @package BetterRestApiLogs\Tests\Integration\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Migration;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use WP_UnitTestCase;

/**
 * End-to-end importer coverage (MIG-01, MIG-04, MIG-06).
 */
final class ImporterTest extends WP_UnitTestCase {

	use UpstreamFixture;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );
		$this->register_upstream_cpt_and_taxonomies();
	}

	public function tear_down(): void {
		// Unwind any output buffers opened during the test to match the ob_level
		// snapshot — guards against wp_ob_end_flush_all closing PHPUnit's frame.
		while ( \ob_get_level() > $this->ob_level ) {
			\ob_end_clean();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test teardown only.
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		parent::tear_down();
	}

	/**
	 * Running the importer to completion must produce one brl_logs row for each
	 * upstream post seeded (MIG-01).
	 */
	public function test_importer_inserts_all_seeded_rows(): void {
		$this->assert_importer_implemented();

		$this->seed_upstream_posts( 3 );

		$importer = $this->make_importer();
		$importer->tick();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE migration_source_id IS NOT NULL' );

		$this->assertSame( 3, $count, 'Importer must insert one row per upstream post' );
	}

	/**
	 * Running the importer a second time with the same source data must not
	 * insert any duplicate rows — the migration_source_id UNIQUE constraint is
	 * the idempotency guarantee (MIG-04).
	 */
	public function test_importer_is_idempotent_on_second_run(): void {
		$this->assert_importer_implemented();

		$this->seed_upstream_posts( 2 );

		$importer = $this->make_importer();
		$importer->tick(); // first run.

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$after_first = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );

		$importer->tick(); // second run — must be a no-op.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$after_second = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );

		$this->assertSame(
			$after_first,
			$after_second,
			'Second importer run must not insert duplicate rows (migration_source_id UNIQUE)'
		);
	}

	/**
	 * Imported bodies must have been run through the current Capture\Redactor
	 * config — never trust upstream redaction (MIG-06).
	 * This verifies the column is not null; a full redaction coverage test would
	 * configure a mock redactor and assert a specific pattern was applied.
	 */
	public function test_importer_stores_re_redacted_bodies(): void {
		$this->assert_importer_implemented();

		$this->seed_upstream_posts( 1 );

		$importer = $this->make_importer();
		$importer->tick();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( 'SELECT response_body FROM ' . Database::logs_table() . ' WHERE migration_source_id IS NOT NULL LIMIT 1' );

		$this->assertNotNull( $row, 'Importer must insert at least one row' );
		// The body column should exist and not be null after re-redaction.
		$this->assertNotNull( $row->response_body, 'Imported response_body must not be null after re-redaction' );
	}

	// -------------------------------------------------------------------------

	/** @var int ob_get_level snapshot for teardown unwinding. */
	private int $ob_level = 0;

	private function assert_importer_implemented(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Migration\\Importer' ) ) {
			$this->fail( 'Migration\\Importer not implemented yet (plan 04 turns this green).' );
		}
	}

	/** Resolve the Importer from the plugin container. */
	private function make_importer(): \BetterRestApiLogs\Migration\Importer {
		return \BetterRestApiLogs\Plugin::instance()->container()->get(
			\BetterRestApiLogs\Migration\Importer::class
		);
	}
}
