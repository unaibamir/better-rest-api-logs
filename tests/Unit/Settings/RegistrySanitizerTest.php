<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Settings\Registry per-tab sanitizers.
 *
 * RED-bar baseline (Wave 0): per-tab sanitizers are private methods on Registry
 * (D-08); we exercise them via Reflection so the test does not depend on the
 * Settings API form-post pipeline. Plan 02-06 lands the sanitizer bodies; this
 * file turns green then.
 *
 * Pitfall 2 contract: sanitizers must return an array (never null) and must
 * drop unknown keys.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Settings;

use BetterRestApiLogs\Settings\Registry;
use BetterRestApiLogs\Tests\Unit\Settings\Fixtures\InMemoryRepository;
use ReflectionMethod;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RegistrySanitizerTest extends TestCase {

	/**
	 * Invoke a private per-tab sanitizer via Reflection.
	 *
	 * @param Registry $registry Registry instance under test.
	 * @param string   $method   Private method name (e.g., `sanitize_capture`).
	 * @param mixed    $input    Raw input to pass into the sanitizer.
	 * @return mixed
	 */
	private function invoke_sanitizer( Registry $registry, string $method, $input ) {
		$ref = new ReflectionMethod( Registry::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( $registry, $input );
	}

	private function make_registry(): Registry {
		return new Registry( new InMemoryRepository() );
	}

	public function test_sanitize_capture_coerces_types_and_drops_unknown_keys(): void {
		$registry = $this->make_registry();
		$result   = $this->invoke_sanitizer(
			$registry,
			'sanitize_capture',
			array(
				'enabled'             => 1,         // truthy: coerce to bool true.
				'body_size_cap_bytes' => '99999',   // numeric string: coerce to int.
				'garbage'             => 'ignored', // unknown key: drop.
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['enabled'], 'enabled coerced to bool.' );
		$this->assertSame( 99999, $result['body_size_cap_bytes'], 'numeric string coerced to int.' );
		$this->assertArrayNotHasKey( 'garbage', $result, 'unknown keys dropped.' );
	}

	public function test_sanitize_privacy_coerces_anonymize_ip(): void {
		$registry = $this->make_registry();
		$result   = $this->invoke_sanitizer(
			$registry,
			'sanitize_privacy',
			array( 'anonymize_ip' => 'yes' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['anonymize_ip'] );
	}

	public function test_sanitize_retention_absints_days(): void {
		$registry = $this->make_registry();
		$result   = $this->invoke_sanitizer(
			$registry,
			'sanitize_retention',
			array( 'retention_days' => '-7' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['retention_days'], 'absint coerces negative integer to positive.' );
	}

	public function test_sanitize_network_filters_non_string_cidr_entries(): void {
		$registry = $this->make_registry();
		$result   = $this->invoke_sanitizer(
			$registry,
			'sanitize_network',
			array( 'cidr_allowlist' => array( '10.0.0.0/8', 42, null, '192.168.0.0/16' ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( '10.0.0.0/8', '192.168.0.0/16' ),
			array_values( $result['cidr_allowlist'] ),
			'Non-string entries dropped from CIDR list.'
		);
	}

	public function test_sanitize_advanced_returns_array(): void {
		$registry = $this->make_registry();
		$result   = $this->invoke_sanitizer(
			$registry,
			'sanitize_advanced',
			array( 'truncate_confirm_copy' => '  trim me  ' )
		);

		$this->assertIsArray( $result );
	}

	public function test_sanitizer_returns_array_not_null_on_invalid_input(): void {
		$registry = $this->make_registry();
		$result   = $this->invoke_sanitizer( $registry, 'sanitize_capture', 'not-an-array' );

		$this->assertIsArray(
			$result,
			'Pitfall 2: sanitizer must return array even on garbage input — never null.'
		);
	}
}
