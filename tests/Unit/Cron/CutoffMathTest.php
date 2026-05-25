<?php
/**
 * Unit tests for Support\Clock::cutoff_datetime() — PURGE-01 cutoff arithmetic.
 *
 * RED-bar scaffold (Wave 0): cutoff_datetime() does not exist on Clock until Wave 1.
 * Uses class_exists() + method_exists() guard — skips cleanly rather than
 * fatalling the unit suite. Skip reason names the implementing wave.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Cron;

use BetterRestApiLogs\Support\Clock;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the cutoff_datetime() arithmetic against a pinned clock (PURGE-01).
 * All assertions are RED until Support\Clock::cutoff_datetime() is added in Wave 1.
 */
final class CutoffMathTest extends TestCase {

	/** Known timestamp: 2026-02-01 00:00:00 UTC. */
	private const PINNED_TS = 1738368000;

	private function skip_until_wave1(): void {
		if ( ! \method_exists( Clock::class, 'cutoff_datetime' ) ) {
			$this->markTestSkipped( 'Support\\Clock::cutoff_datetime() not implemented yet — Wave 1' );
		}
	}

	public function test_cutoff_30_days_arithmetic(): void {
		$this->skip_until_wave1();
		$helper = new FixedClockHelper( self::PINNED_TS );
		// Wave 1 contract: Clock::cutoff_datetime(int $days, ?int $now = null)
		// Passing $now pins the clock for deterministic test output.
		$result   = ( new Clock() )->cutoff_datetime( 30, self::PINNED_TS );
		$expected = $helper->expected_cutoff( 30 );
		$this->assertSame( $expected, $result, 'cutoff_datetime(30) must subtract 30 * DAY_IN_SECONDS from pinned time.' );
	}

	public function test_cutoff_7_days_arithmetic(): void {
		$this->skip_until_wave1();
		$helper   = new FixedClockHelper( self::PINNED_TS );
		$result   = ( new Clock() )->cutoff_datetime( 7, self::PINNED_TS );
		$expected = $helper->expected_cutoff( 7 );
		$this->assertSame( $expected, $result, 'cutoff_datetime(7) must subtract 7 * DAY_IN_SECONDS.' );
	}

	public function test_cutoff_returns_datetime_shape_not_micros(): void {
		$this->skip_until_wave1();
		$result = ( new Clock() )->cutoff_datetime( 30, self::PINNED_TS );

		// Must match YYYY-MM-DD HH:MM:SS — the SQL DATETIME shape.
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$result,
			'cutoff_datetime() must return a SQL DATETIME string, NOT a microseconds integer.'
		);
		// Sanity: the value is a string, not an integer (guards against returning now_micros).
		$this->assertIsString( $result );
		$this->assertGreaterThan( 10, \strlen( $result ), 'DATETIME string must be at least 10 chars.' );
	}

	public function test_cutoff_1_day_boundary(): void {
		$this->skip_until_wave1();
		$helper   = new FixedClockHelper( self::PINNED_TS );
		$result   = ( new Clock() )->cutoff_datetime( 1, self::PINNED_TS );
		$expected = $helper->expected_cutoff( 1 );
		$this->assertSame( $expected, $result, '1-day cutoff must subtract exactly one DAY_IN_SECONDS.' );
	}

	public function test_cutoff_returns_utc_not_local_time(): void {
		$this->skip_until_wave1();
		$result = ( new Clock() )->cutoff_datetime( 30, self::PINNED_TS );

		// gmdate and date may diverge if the server timezone is not UTC.
		// The cutoff must always match gmdate (UTC), never date().
		$gmdate_result = \gmdate( 'Y-m-d H:i:s', self::PINNED_TS - 30 * DAY_IN_SECONDS );
		$this->assertSame( $gmdate_result, $result, 'cutoff_datetime() must use gmdate (UTC), not localtime.' );
	}
}
