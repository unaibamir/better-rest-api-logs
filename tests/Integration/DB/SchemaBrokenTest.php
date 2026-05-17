<?php
/**
 * Integration test scaffold for the schema-broken latch + admin notice +
 * Site Health registration.
 *
 * RED-bar baseline (Wave 0): targets STOR-08 (D-13, D-21..D-23). Plan 02-07
 * turns this green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use WP_UnitTestCase;

final class SchemaBrokenTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		\delete_option( 'brl_db_version' );
		\delete_option( 'brl_internal' );
	}

	public function test_smoke_check_failure_sets_schema_broken_flag(): void {
		// Force the broken state by dropping tables after install.
		Schema::install();

		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );

		Schema::set_broken_flag();

		$internal = \get_option( 'brl_internal' );
		$this->assertIsArray( $internal );
		$this->assertArrayHasKey( 'schema_broken', $internal );
		$this->assertTrue( $internal['schema_broken'], 'D-13: smoke-check failure latches schema_broken=true.' );
	}

	public function test_render_broken_notice_outputs_error_class(): void {
		Schema::set_broken_flag();

		\set_current_screen( 'dashboard' );
		\wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Schema::maybe_render_broken_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $html, 'D-23: non-dismissible error notice.' );
		$this->assertStringContainsString(
			'log tables are missing or malformed',
			$html,
			'D-23: locked copy is rendered.'
		);
	}

	public function test_site_health_registration_adds_brl_schema_direct_test(): void {
		$tests = Schema::register_site_health_tests( array() );

		$this->assertIsArray( $tests );
		$this->assertArrayHasKey( 'direct', $tests );
		$this->assertArrayHasKey(
			'brl_schema',
			$tests['direct'],
			'D-22: Site Health direct test id is "brl_schema".'
		);
	}
}
