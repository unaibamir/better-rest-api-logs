<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cron;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Support\Clock;

/**
 * Registers and manages the brl_purge_tick WP-Cron event.
 *
 * The four public methods (schedule, unschedule, enqueue_followup, is_scheduled)
 * are the PURGE-08 seam — a v1.1 Action Scheduler adapter slides behind this
 * interface once a second concrete implementation exists (per the no-interface-
 * until-2-impls rule). No BackendInterface is declared in v1.0.
 *
 * @package BetterRestApiLogs\Cron
 */
final class Scheduler {

	/**
	 * WP-Cron hook name. Must match the literal in Deactivator::deactivate().
	 */
	const HOOK = 'brl_purge_tick';

	private Clock $clock;

	/**
	 * @param Clock|null $clock Monotonic clock; null = real time. Injected for
	 *                          deterministic seq arguments in tests.
	 */
	public function __construct( ?Clock $clock = null ) {
		$this->clock = $clock ?? new Clock();
	}

	/**
	 * Schedule the purge event as a twicedaily recurrence.
	 *
	 * Idempotent — if the event is already scheduled the call is a no-op and
	 * true is returned. When wp_schedule_event returns false (WP or hosting
	 * environment rejected the request) the failure is recorded in
	 * purge_state.schedule_error and false is returned so callers can surface
	 * the error.
	 *
	 * 'twicedaily' is a WP built-in interval; no cron_schedules filter needed.
	 *
	 * @return bool True when the event is (or was already) scheduled; false on failure.
	 */
	public function schedule(): bool {
		if ( \wp_next_scheduled( self::HOOK ) ) {
			return true;
		}

		$result = \wp_schedule_event( \time(), 'twicedaily', self::HOOK );

		if ( false === $result ) {
			$this->record_schedule_error( true );
			return false;
		}

		// Clear any prior error marker now that scheduling succeeded.
		$this->record_schedule_error( false );
		return true;
	}

	/**
	 * Remove all scheduled instances of the purge hook.
	 */
	public function unschedule(): void {
		\wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Return true when the purge event is currently scheduled.
	 */
	public function is_scheduled(): bool {
		return (bool) \wp_next_scheduled( self::HOOK );
	}

	/**
	 * Schedule an immediate one-off tick with a unique seq arg.
	 *
	 * Passing a unique microsecond seed arg defeats the 10-minute dedup window
	 * that wp_schedule_single_event applies to events with identical (hook, args),
	 * so back-to-back full batches are not swallowed (PURGE-04, Pitfall 1).
	 */
	public function enqueue_followup(): void {
		// wp_schedule_single_event requires a list (integer-indexed) for $args.
		// Wrapping in another array makes it a single-element list whose sole
		// item is the associative seq map — the shape the cron dispatcher passes
		// to the action callback as its first argument.
		\wp_schedule_single_event( \time(), self::HOOK, [ [ 'seq' => $this->clock->now_micros() ] ] );
	}

	/**
	 * Write the schedule_error flag into purge_state.
	 *
	 * Wrapped in try/catch Throwable because MySQL may be the very thing that
	 * is broken when scheduling fails — we must not throw out of this method
	 * (mirrors Breaker on_failure discipline, D-22).
	 *
	 * @param bool $failed True to mark an error; false to clear a prior marker.
	 */
	public function record_schedule_error( bool $failed ): void {
		try {
			$current = SettingsRegistry::get_internal( 'purge_state' );
			if ( ! \is_array( $current ) ) {
				$current = [];
			}
			$current['schedule_error'] = $failed;
			SettingsRegistry::set_internal( 'purge_state', $current );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Persistence is best-effort; same model as Logger\Breaker (D-22).
			unset( $e );
		}
	}
}
