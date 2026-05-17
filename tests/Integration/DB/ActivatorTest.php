<?php
/**
 * Integration test scaffold for the Phase 2 Activator extensions.
 *
 * RED-bar baseline (Wave 0): targets STOR-06 (activation installs schema +
 * seeds defaults). Plan 02-07 turns this green when Activator::activate()
 * gains the Schema::install() + Defaults::seed_all_tabs() calls.
 *
 * Phase 1's ActivationTest already covers the brl_db_version + opt-in
 * uninstall flag; this file adds Phase 2 expectations only.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\Activator;
use BetterRestApiLogs\DB\Database;
use WP_UnitTestCase;

final class ActivatorTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::bodies_table() );
		\delete_option( 'brl_db_version' );
		\delete_option( 'brl_internal' );
		foreach ( array( 'capture', 'privacy', 'retention', 'network', 'advanced' ) as $tab ) {
			\delete_option( "brl_settings_{$tab}" );
		}
	}

	public function test_activate_installs_schema(): void {
		Activator::activate();

		global $wpdb;
		$this->assertSame(
			Database::logs_table(),
			$wpdb->get_var( 'SHOW TABLES LIKE "' . Database::logs_table() . '"' )
		);
		$this->assertSame(
			Database::bodies_table(),
			$wpdb->get_var( 'SHOW TABLES LIKE "' . Database::bodies_table() . '"' )
		);
	}

	public function test_activate_seeds_per_tab_defaults(): void {
		Activator::activate();

		$capture = \get_option( 'brl_settings_capture' );
		$this->assertIsArray( $capture );
		$this->assertArrayHasKey( 'enabled', $capture );
	}

	public function test_activate_is_idempotent_on_existing_options(): void {
		\update_option( 'brl_settings_capture', array( 'enabled' => false ) );

		Activator::activate();

		$this->assertSame(
			array( 'enabled' => false ),
			\get_option( 'brl_settings_capture' ),
			'add_option (not update_option) keeps existing user preference on reactivation.'
		);
	}
}
