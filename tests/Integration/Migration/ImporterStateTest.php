<?php
/**
 * Integration test scaffold for migration state persistence — MIG-07.
 *
 * Failing scaffold (Wave 0): the importer state writes do not exist until
 * plan 04.  Tests guard on class_exists and fail with a clear message.
 *
 * Covers:
 *  - The 7 brl_migration_* marker keys persist across simulated requests via
 *    Registry::set_internal('migration', ...) (MIG-07)
 *  - The cursor is recoverable after a simulated mid-batch restart
 *
 * @package BetterRestApiLogs\Tests\Integration\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Migration;

use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Settings\Internal;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * Verifies the 7-key marker shape persists and the cursor is recoverable (MIG-07).
 */
final class ImporterStateTest extends WP_UnitTestCase {

	use UpstreamFixture;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );
		$this->register_upstream_cpt_and_taxonomies();

		// Reset migration state to defaults before each test.
		Registry::set_internal( 'migration', Internal::defaults()['migration'] );
	}

	/**
	 * All 7 marker keys must survive a write+read cycle via Registry, simulating
	 * cross-request persistence through brl_internal (MIG-07, CLAUDE.md — static
	 * PHP-FPM state does not survive across requests; must use brl_internal).
	 */
	public function test_seven_marker_keys_persist_via_registry(): void {
		$this->assert_importer_state_implemented();

		$state = [
			'status'       => 'running',
			'source_total' => 100,
			'processed'    => 25,
			'imported'     => 20,
			'skipped'      => 5,
			'cursor'       => 125,
			'started_at'   => 1_748_000_000,
		];

		Registry::set_internal( 'migration', $state );

		// Simulate a new request by flushing any in-memory cache.
		// wp_cache_flush would be too destructive; re-read from the option.
		\wp_cache_delete( 'brl_internal', 'options' );

		$recovered = Registry::get_internal( 'migration' );

		$this->assertIsArray( $recovered, 'Migration state must be readable as an array' );
		$this->assertSame( 'running', $recovered['status'] );
		$this->assertSame( 100, $recovered['source_total'] );
		$this->assertSame( 25, $recovered['processed'] );
		$this->assertSame( 20, $recovered['imported'] );
		$this->assertSame( 5, $recovered['skipped'] );
		$this->assertSame( 125, $recovered['cursor'] );
		$this->assertSame( 1_748_000_000, $recovered['started_at'] );
	}

	/**
	 * After a simulated mid-batch crash (cursor left at a non-zero value), the
	 * importer must resume from the persisted cursor rather than starting over.
	 * This test verifies the cursor is recoverable — not that the importer itself
	 * resumes (that is an ImporterTest concern).
	 */
	public function test_cursor_is_recoverable_after_restart(): void {
		$this->assert_importer_state_implemented();

		// Write a partial-progress state with a non-zero cursor.
		Registry::set_internal(
			'migration',
			[
				'status'       => 'running',
				'source_total' => 50,
				'processed'    => 10,
				'imported'     => 10,
				'skipped'      => 0,
				'cursor'       => 42,
				'started_at'   => \time() - 30,
			]
		);

		// Flush any in-memory cache to simulate a fresh request reading from DB.
		\wp_cache_delete( 'brl_internal', 'options' );

		$recovered_cursor = Registry::get_internal( 'migration' )['cursor'] ?? null;

		$this->assertSame( 42, $recovered_cursor, 'Cursor must survive across simulated requests' );
	}

	/**
	 * Internal::defaults()['migration'] must carry exactly the 7 expected keys
	 * so is_known_key() accepts writes to all of them (MIG-07 contract).
	 */
	public function test_defaults_carry_the_seven_key_shape(): void {
		// This test does NOT guard on class_exists — it validates Internal::defaults()
		// which is already implemented as part of Task 1 (plan 01).
		$defaults = Internal::defaults();

		$this->assertArrayHasKey( 'migration', $defaults );
		$migration = $defaults['migration'];

		$this->assertIsArray( $migration );
		$this->assertSame( 7, \count( $migration ), 'migration key must carry exactly 7 sub-keys' );

		foreach ( [ 'status', 'source_total', 'processed', 'imported', 'skipped', 'cursor', 'started_at' ] as $key ) {
			$this->assertArrayHasKey( $key, $migration, "migration['{$key}'] must be present in defaults" );
		}

		$this->assertSame( 'idle', $migration['status'], "Default status must be 'idle'" );
		$this->assertSame( 0, $migration['cursor'], 'Default cursor must be 0' );
	}


	private function assert_importer_state_implemented(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Migration\\Importer' ) ) {
			$this->fail( 'Migration\\Importer not implemented yet (plan 04 turns this green).' );
		}
	}
}
