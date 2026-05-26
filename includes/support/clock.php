<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Support;

defined( 'ABSPATH' ) || exit;

/**
 * UTC-clock wrapper around microtime/gmdate.
 *
 * Instance class — callers receive a Clock via constructor injection and
 * unit tests substitute a FixedClock subclass for deterministic time.
 *
 * Locked contract per CONTEXT.md D-27:
 *  - now() returns MySQL DATETIME shape (Y-m-d H:i:s) in UTC.
 *  - now_micros() returns microseconds since the Unix epoch as an int.
 */
final class Clock {

	/**
	 * Current UTC datetime in MySQL DATETIME shape (Y-m-d H:i:s).
	 */
	public function now(): string {
		return \gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Microseconds since the Unix epoch.
	 *
	 * Built from gettimeofday()'s integer second/microsecond components rather
	 * than `(int) ( microtime( true ) * 1e6 )`. That float intermediate is ~1.75e15
	 * today, far beyond PHP_INT_MAX (~2.1e9) on a 32-bit build, where casting an
	 * out-of-range float to int is undefined and silently poisons the cursor key
	 * and duration_ms. Integer arithmetic on the components is exact on 64-bit PHP.
	 */
	public function now_micros(): int {
		$tod = \gettimeofday();
		return ( (int) $tod['sec'] ) * 1000000 + (int) $tod['usec'];
	}

	/**
	 * UTC datetime $days before the given (or current) time, in MySQL DATETIME
	 * shape (Y-m-d H:i:s). Used by Cron\Purge to compute the retention cutoff.
	 *
	 * The optional $now_unix parameter pins the reference point for unit tests.
	 * In production the default null causes \time() to be used, keeping the
	 * class final while still being deterministically testable.
	 *
	 * @param int      $days     Number of days to subtract from now.
	 * @param int|null $now_unix Unix timestamp to treat as "now"; null = real time.
	 * @return string            MySQL DATETIME string in UTC.
	 */
	public function cutoff_datetime( int $days, ?int $now_unix = null ): string {
		$base = null !== $now_unix ? $now_unix : \time();
		return \gmdate( 'Y-m-d H:i:s', $base - $days * \DAY_IN_SECONDS );
	}
}
