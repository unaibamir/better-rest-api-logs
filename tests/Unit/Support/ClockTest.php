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
}
