<?php
/**
 * Stand-alone clock stub shared by cron unit tests.
 *
 * Clock is final so we cannot subclass it. This helper mirrors the arithmetic
 * that Clock::cutoff_datetime() must implement so tests can assert expected
 * values without depending on the real class.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Cron;

/**
 * Pinned-timestamp helper for cutoff arithmetic assertions.
 *
 * @internal Only for unit tests.
 */
final class FixedClockHelper {

	/** @var int Pinned Unix timestamp. */
	public int $pinned;

	/**
	 * @param int $pinned Unix timestamp to pin the clock at.
	 */
	public function __construct( int $pinned ) {
		$this->pinned = $pinned;
	}

	/**
	 * Compute the expected cutoff string using the same formula Clock must use.
	 * This lets CutoffMathTest assert the expected value without touching Clock.
	 *
	 * @param int $days Number of days to subtract from the pinned timestamp.
	 */
	public function expected_cutoff( int $days ): string {
		return \gmdate( 'Y-m-d H:i:s', $this->pinned - $days * DAY_IN_SECONDS );
	}
}
