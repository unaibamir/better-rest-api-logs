<?php
/**
 * Integration test scaffold for BetterRestApiLogs\DB\Schema::install().
 *
 * RED-bar baseline (Wave 0): targets STOR-01 (both tables exist after install).
 * Plan 02-02 lands Database, Plan 02-07 lands Schema; this file turns green
 * after Plan 02-07.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use WP_UnitTestCase;

final class SchemaInstallTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		\delete_option( 'brl_db_version' );
	}

	public function test_install_creates_both_tables(): void {
		Schema::install();

		global $wpdb;
		$logs   = $wpdb->get_var( 'SHOW TABLES LIKE "' . Database::logs_table() . '"' );
		$bodies = $wpdb->get_var( 'SHOW TABLES LIKE "' . Database::bodies_table() . '"' );

		$this->assertSame( Database::logs_table(), $logs, 'STOR-01: brl_logs table created.' );
		$this->assertSame( Database::bodies_table(), $bodies, 'STOR-01: brl_logs_bodies table created.' );
	}

	public function test_install_table_has_an_engine(): void {
		Schema::install();

		global $wpdb;
		$create = $wpdb->get_var( 'SHOW CREATE TABLE ' . Database::logs_table(), 1 );

		$this->assertNotNull( $create );
		$this->assertStringContainsString(
			'ENGINE=',
			(string) $create,
			'Table must declare a storage engine (whatever site default chose).'
		);
	}
}
