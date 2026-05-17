<?php
/**
 * Integration test scaffold for the index inventory on brl_logs.
 *
 * RED-bar baseline (Wave 0): targets STOR-06 (index coverage). Plan 02-07
 * turns this green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use WP_UnitTestCase;

final class SchemaIndexesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		\delete_option( 'brl_db_version' );
	}

	/**
	 * @return array<string,array<int,string>> Map of key_name → list of column names.
	 */
	private function collect_indexes(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Database::logs_table(), ARRAY_A );

		$by_key = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row['Key_name'];
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = array();
			}
			$by_key[ $key ][] = (string) $row['Column_name'];
		}
		return $by_key;
	}

	public function test_primary_key_on_id(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'PRIMARY', $indexes );
		$this->assertSame( array( 'id' ), $indexes['PRIMARY'] );
	}

	public function test_unique_migration_source_id(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'migration_source_id', $indexes );
		$this->assertSame( array( 'migration_source_id' ), $indexes['migration_source_id'] );
	}

	public function test_created_at_index(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'created_at', $indexes );
	}

	public function test_route_prefix_index(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'route_prefix', $indexes );
	}

	public function test_status_class_index(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'status_class', $indexes );
	}

	public function test_composite_method_status_index(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'method_status', $indexes );
		$this->assertSame(
			array( 'method', 'status_class' ),
			$indexes['method_status'],
			'STOR-06 locks method + status_class composite for the common "GET 4xx" query.'
		);
	}

	public function test_user_id_index(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'user_id', $indexes );
	}

	public function test_ip_resolved_index(): void {
		Schema::install();
		$indexes = $this->collect_indexes();
		$this->assertArrayHasKey( 'ip_resolved', $indexes );
	}
}
