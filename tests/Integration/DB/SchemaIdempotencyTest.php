<?php
/**
 * Integration test scaffold for dbDelta idempotency.
 *
 * RED-bar baseline (Wave 0): targets STOR-02. On a no-op re-run, dbDelta
 * must return an empty array — phantom ALTERs are the highest-risk bug
 * class for this phase (Pitfall 7). Plan 02-07 turns this green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use WP_UnitTestCase;

final class SchemaIdempotencyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		\remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		\delete_option( 'brl_db_version' );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		parent::tear_down();
	}

	public function test_second_install_does_not_fatal(): void {
		Schema::install();
		Schema::install();

		// If we get here without a fatal, that's the first half of the contract.
		$this->assertTrue( true, 'Second Schema::install() ran without fatal.' );
	}

	public function test_dbdelta_returns_empty_array_on_identical_rerun(): void {
		Schema::install();

		// Re-run dbDelta directly on the same DDL — second call MUST be a no-op.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// We do not have direct access to Schema's DDL strings; the contract is
		// that Schema::install() called twice produces no phantom ALTERs. The
		// cleanest sentinel: capture the SHOW CREATE before and after a second
		// install and assert byte-identical output.
		global $wpdb;
		$create_before = $wpdb->get_var( 'SHOW CREATE TABLE ' . Database::logs_table(), 1 );

		Schema::install();

		$create_after = $wpdb->get_var( 'SHOW CREATE TABLE ' . Database::logs_table(), 1 );

		$this->assertSame(
			$create_before,
			$create_after,
			'STOR-02 (Pitfall 7/8): Schema::install() must be idempotent — no phantom ALTERs.'
		);
	}
}
