<?php
/**
 * Integration test scaffold for Schema version-mismatch upgrade trigger.
 *
 * RED-bar baseline (Wave 0): targets STOR-03/STOR-08. Plan 02-07 turns
 * this green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use ReflectionClass;
use WP_UnitTestCase;

final class SchemaUpgradeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		\delete_option( 'brl_db_version' );
		\delete_option( 'brl_internal' );
		$this->reset_schema_static_cache();
	}

	public function tear_down(): void {
		$this->reset_schema_static_cache();
		parent::tear_down();
	}

	/**
	 * The maybe_install_or_upgrade() method holds a function-local static
	 * `$checked` to prevent re-entry within a single request. To exercise
	 * the upgrade path across tests we need to reset it. PHP exposes
	 * function-local statics via Reflection on the function itself; the
	 * production class is expected to either (a) accept a `Schema::$checked`
	 * static property we can reset, or (b) expose a documented reset hook.
	 * Either way, this helper centralises the reset call.
	 */
	private function reset_schema_static_cache(): void {
		if ( ! class_exists( Schema::class ) ) {
			return;
		}
		$ref = new ReflectionClass( Schema::class );
		if ( $ref->hasProperty( 'checked' ) ) {
			$prop = $ref->getProperty( 'checked' );
			$prop->setAccessible( true );
			$prop->setValue( null, false );
		}
	}

	public function test_version_compare_triggers_dbdelta_on_mismatch(): void {
		// No tables, stale version.
		\update_option( 'brl_db_version', '0.0' );

		Schema::maybe_install_or_upgrade();

		global $wpdb;
		$logs = $wpdb->get_var( 'SHOW TABLES LIKE "' . Database::logs_table() . '"' );
		$this->assertSame( Database::logs_table(), $logs, 'STOR-03: mismatch triggers install.' );

		$installed = \get_option( 'brl_db_version' );
		$this->assertSame(
			(string) BRL_DB_VERSION,
			(string) $installed,
			'STOR-03: brl_db_version bumped to current code constant after successful install + smoke check.'
		);
	}

	public function test_cached_static_prevents_second_run(): void {
		Schema::maybe_install_or_upgrade();
		$version_after_first = \get_option( 'brl_db_version' );

		// A direct mutation simulating drift; the cached static should prevent
		// a re-evaluation within the same request.
		\update_option( 'brl_db_version', '0.0' );

		Schema::maybe_install_or_upgrade();

		$this->assertSame(
			'0.0',
			(string) \get_option( 'brl_db_version' ),
			'Static cache must short-circuit the second call so the synthetic 0.0 sticks.'
		);
		$this->assertSame(
			(string) BRL_DB_VERSION,
			(string) $version_after_first,
			'First call did bump the version.'
		);
	}
}
