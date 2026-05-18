<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Settings\Registry.
 *
 * RED-bar baseline (Wave 0): uses an in-memory Repository test double
 * (extends the production Repository via the D-30 test seam) so Registry
 * can be exercised without bootstrapping WordPress. Plan 02-05 lands
 * Registry; Plan 02-06 lands sanitizers + cache invalidation; this file
 * goes green across both.
 *
 * Targets SET-01 (dot-path getter), D-09 (single read API), D-10 (internal
 * surface), D-11 (cache), D-03 (flat brl_db_version special case).
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Settings;

use BetterRestApiLogs\Settings\Defaults;
use BetterRestApiLogs\Settings\Registry;
use BetterRestApiLogs\Tests\Unit\Settings\Fixtures\InMemoryRepository;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SettingsRegistryTest extends TestCase {

	public function test_get_setting_returns_default_when_option_absent(): void {
		$repo     = new InMemoryRepository();
		$registry = new Registry( $repo );

		// Per D-09 overlay semantics: an unset stored option means the registered
		// default surfaces, NOT the caller-supplied fallback.
		$expected_default = Defaults::for_tab( 'capture' )['enabled'];
		$this->assertSame(
			$expected_default,
			$registry->get_setting( 'capture.enabled', 'fallback' )
		);
	}

	public function test_get_setting_returns_user_value_when_option_present(): void {
		$repo = new InMemoryRepository();
		$repo->update_option( 'brl_settings_capture', array( 'enabled' => false ) );
		$registry = new Registry( $repo );

		$this->assertFalse( $registry->get_setting( 'capture.enabled' ) );
	}

	public function test_get_setting_falls_back_for_unknown_nested_key(): void {
		$repo     = new InMemoryRepository();
		$registry = new Registry( $repo );

		$this->assertSame( 99, $registry->get_setting( 'capture.nonexistent', 99 ) );
	}

	public function test_get_setting_returns_default_when_path_missing_dot(): void {
		$repo     = new InMemoryRepository();
		$registry = new Registry( $repo );

		$this->assertSame(
			'fallback',
			$registry->get_setting( 'capture', 'fallback' ),
			'A dot-less path is invalid — Registry must return the caller default.'
		);
	}

	public function test_dot_path_resolves_nested_arrays(): void {
		$repo = new InMemoryRepository();
		$repo->update_option(
			'brl_settings_network',
			array( 'cidr_allowlist' => array( '10.0.0.0/8' ) )
		);
		$registry = new Registry( $repo );

		$this->assertSame(
			array( '10.0.0.0/8' ),
			$registry->get_setting( 'network.cidr_allowlist' )
		);
	}

	public function test_cache_hit_on_second_read(): void {
		$repo = new InMemoryRepository();
		$repo->update_option( 'brl_settings_capture', array( 'enabled' => true ) );
		$registry = new Registry( $repo );

		$first = $registry->get_setting( 'capture.enabled' );

		// Mutate the underlying store WITHOUT going through the cache invalidator.
		$repo->update_option( 'brl_settings_capture', array( 'enabled' => false ) );

		$second = $registry->get_setting( 'capture.enabled' );

		$this->assertSame( $first, $second, 'Second read must hit Registry in-memory cache (D-11).' );
		$this->assertTrue( $second, 'Cached value is the first-read value, not the mutated value.' );
	}

	public function test_invalidate_cache_on_option_change_flushes_per_tab(): void {
		$repo = new InMemoryRepository();
		$repo->update_option( 'brl_settings_capture', array( 'enabled' => true ) );
		$registry = new Registry( $repo );

		$registry->get_setting( 'capture.enabled' ); // Populate cache.

		// Simulate the updated_option/added_option hook firing.
		$repo->update_option( 'brl_settings_capture', array( 'enabled' => false ) );
		$registry->invalidate_cache_on_option_change( 'brl_settings_capture' );

		$this->assertFalse(
			$registry->get_setting( 'capture.enabled' ),
			'After cache invalidation, the next read reflects the new stored value.'
		);
	}

	public function test_get_internal_db_version_reads_flat_option(): void {
		$repo = new InMemoryRepository();
		$repo->update_option( 'brl_db_version', '2.0' );
		$registry = new Registry( $repo );

		$this->assertSame(
			'2.0',
			$registry->get_internal( 'db_version' ),
			'D-03: brl_db_version is a flat scalar option, hidden behind get_internal().'
		);
	}

	public function test_get_internal_circuit_open_until_reads_from_brl_internal_array(): void {
		$repo = new InMemoryRepository();
		$repo->update_option( 'brl_internal', array( 'circuit_open_until' => 1234567 ) );
		$registry = new Registry( $repo );

		$this->assertSame( 1234567, $registry->get_internal( 'circuit_open_until' ) );
	}

	public function test_set_internal_writes_to_brl_internal_array(): void {
		$repo     = new InMemoryRepository();
		$registry = new Registry( $repo );

		$registry->set_internal( 'schema_broken', true );

		$stored = $repo->get_option( 'brl_internal' );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'schema_broken', $stored );
		$this->assertTrue( $stored['schema_broken'] );
	}

	public function test_set_internal_rejects_unknown_key(): void {
		$repo     = new InMemoryRepository();
		$registry = new Registry( $repo );

		$this->expectException( \InvalidArgumentException::class );

		// Typo guard: 'cirtcuit_open_until' (transposed letters) must be rejected.
		$registry->set_internal( 'cirtcuit_open_until', 1 );
	}

	public function test_set_internal_unknown_key_does_not_persist(): void {
		$repo     = new InMemoryRepository();
		$registry = new Registry( $repo );

		try {
			$registry->set_internal( 'totally_made_up', 'value' );
			$this->fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			// Expected — assert the bad key never landed in the stored array.
			$stored = $repo->get_option( 'brl_internal', null );
			$this->assertNull( $stored, 'brl_internal must not be written when the key is invalid.' );
		}
	}
}
