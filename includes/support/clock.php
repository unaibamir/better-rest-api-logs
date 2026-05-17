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
	 */
	public function now_micros(): int {
		return (int) ( \microtime( true ) * 1000000 );
	}
}
