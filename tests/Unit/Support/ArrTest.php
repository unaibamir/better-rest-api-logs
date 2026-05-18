<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Support\Arr.
 *
 * RED-bar baseline (Wave 0): asserts against Arr::get/has/set per CONTEXT D-26.
 * Plan 02-04 lands the production code; tests turn green then.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Support;

use BetterRestApiLogs\Support\Arr;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ArrTest extends TestCase {

	public function test_get_returns_default_on_missing_path(): void {
		$this->assertSame( 'fallback', Arr::get( [], 'a.b.c', 'fallback' ) );
	}

	public function test_get_returns_nested_value(): void {
		$this->assertSame( 'v', Arr::get( [ 'a' => [ 'b' => 'v' ] ], 'a.b' ) );
	}

	public function test_get_returns_default_when_traversal_hits_non_array(): void {
		$this->assertSame( 'd', Arr::get( [ 'a' => 'x' ], 'a.b', 'd' ) );
	}

	public function test_has_true_on_existing_path(): void {
		$this->assertTrue( Arr::has( [ 'a' => [ 'b' => 0 ] ], 'a.b' ) );
	}

	public function test_has_false_on_missing_path(): void {
		$this->assertFalse( Arr::has( [ 'a' => [ 'b' => 0 ] ], 'a.c' ) );
	}

	public function test_set_returns_new_array_without_mutating_input(): void {
		$input  = [ 'a' => [ 'b' => 1 ] ];
		$result = Arr::set( $input, 'a.b', 2 );

		$this->assertNotSame( $input, $result, 'set() returns a new array, never mutating the input.' );
		$this->assertSame( 1, $input['a']['b'], 'input is untouched.' );
		$this->assertSame( 2, $result['a']['b'], 'result has the new value at the target path.' );
	}
}
