<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Support\Clock.
 *
 * RED-bar baseline (Wave 0): asserts against Clock per CONTEXT D-27.
 * Plan 02-04 lands the production code; tests turn green then.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Support;

use BetterRestApiLogs\Support\Clock;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ClockTest extends TestCase {

	public function test_now_returns_mysql_datetime_format(): void {
		$clock = new Clock();
		$now   = $clock->now();

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$now,
			'Clock::now() returns MySQL DATETIME string in UTC.'
		);
	}

	public function test_now_micros_returns_increasing_int(): void {
		$clock = new Clock();
		$a     = $clock->now_micros();
		$b     = $clock->now_micros();

		$this->assertIsInt( $a );
		$this->assertIsInt( $b );
		$this->assertGreaterThanOrEqual( $a, $b, 'now_micros() is monotonically non-decreasing.' );
	}

	/**
	 * The value must track real wall-clock microseconds, not a wrapped or zeroed
	 * cast. On 64-bit PHP it should sit within a second of time() * 1e6; the old
	 * float-cast produced an out-of-range value on 32-bit builds.
	 */
	public function test_now_micros_is_in_realistic_epoch_range(): void {
		if ( \PHP_INT_SIZE < 8 ) {
			$this->markTestSkipped( 'Microseconds-since-epoch cannot fit in a 32-bit int.' );
		}

		$clock    = new Clock();
		$micros   = $clock->now_micros();
		$expected = \time() * 1000000;

		$this->assertGreaterThan( 0, $micros, 'now_micros() must be a real positive timestamp, not a wrapped 0.' );
		// Within ~5 seconds of wall clock — proves the seconds component is intact.
		$this->assertEqualsWithDelta( $expected, $micros, 5_000_000, 'now_micros() must track real epoch microseconds.' );
	}
}
