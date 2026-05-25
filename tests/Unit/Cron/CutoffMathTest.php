<?php
/**
 * Unit tests for Support\Clock::cutoff_datetime() — PURGE-01 cutoff arithmetic.
 *
 * Calls the real Clock::cutoff_datetime() with a pinned $now_unix argument so
 * the test is deterministic without a subclass or a duplicate helper.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Cron;

use BetterRestApiLogs\Support\Clock;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the cutoff_datetime() arithmetic with a pinned clock (PURGE-01).
 */
final class CutoffMathTest extends TestCase {

	/** Known timestamp: 2026-02-01 00:00:00 UTC. */
	private const PINNED_TS = 1738368000;

	public function test_cutoff_30_days_arithmetic(): void {
		$result   = ( new Clock() )->cutoff_datetime( 30, self::PINNED_TS );
		$expected = \gmdate( 'Y-m-d H:i:s', self::PINNED_TS - 30 * DAY_IN_SECONDS );
		$this->assertSame( $expected, $result, 'cutoff_datetime(30) must subtract 30 * DAY_IN_SECONDS from pinned time.' );
	}

	public function test_cutoff_7_days_arithmetic(): void {
		$result   = ( new Clock() )->cutoff_datetime( 7, self::PINNED_TS );
		$expected = \gmdate( 'Y-m-d H:i:s', self::PINNED_TS - 7 * DAY_IN_SECONDS );
		$this->assertSame( $expected, $result, 'cutoff_datetime(7) must subtract 7 * DAY_IN_SECONDS.' );
	}

	public function test_cutoff_returns_datetime_shape_not_micros(): void {
		$result = ( new Clock() )->cutoff_datetime( 30, self::PINNED_TS );

		// Must match YYYY-MM-DD HH:MM:SS — the SQL DATETIME shape.
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$result,
			'cutoff_datetime() must return a SQL DATETIME string, NOT a microseconds integer.'
		);
		$this->assertIsString( $result );
		$this->assertGreaterThan( 10, \strlen( $result ), 'DATETIME string must be at least 10 chars.' );
	}

	public function test_cutoff_1_day_boundary(): void {
		$result   = ( new Clock() )->cutoff_datetime( 1, self::PINNED_TS );
		$expected = \gmdate( 'Y-m-d H:i:s', self::PINNED_TS - 1 * DAY_IN_SECONDS );
		$this->assertSame( $expected, $result, '1-day cutoff must subtract exactly one DAY_IN_SECONDS.' );
	}

	public function test_cutoff_returns_utc_not_local_time(): void {
		$result = ( new Clock() )->cutoff_datetime( 30, self::PINNED_TS );

		// gmdate and date may diverge when the server timezone is not UTC.
		// The cutoff must always match gmdate (UTC), never date().
		$gmdate_result = \gmdate( 'Y-m-d H:i:s', self::PINNED_TS - 30 * DAY_IN_SECONDS );
		$this->assertSame( $gmdate_result, $result, 'cutoff_datetime() must use gmdate (UTC), not localtime.' );
	}
}
