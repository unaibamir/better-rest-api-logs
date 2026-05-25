<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cron;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\PurgeRepository;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Support\Clock;

/**
 * Bounded, lock-guarded retention drain (PURGE-01..08).
 *
 * The tick() method runs under a transient lock, deletes rows in time-and-row-bounded
 * batches via PurgeRepository, cascades body-spill rows in each batch, then
 * re-enqueues an immediate follow-up tick when the last batch came back full.
 *
 * The maybe_render_schedule_notice() and register_site_health_test() methods
 * mirror Logger\Breaker's admin-notice + Site Health pattern (D-07) for the
 * wp_schedule_event failure surface.
 *
 * @package BetterRestApiLogs\Cron
 */
final class Purge {

	/**
	 * Transient key for the running-lock (D-04).
	 */
	const LOCK_KEY = 'brl_purge_running';

	/**
	 * Site Health test key registered when schedule_error is set.
	 */
	const HEALTH_TEST_KEY = 'brl_purge_schedule_error';

	private SettingsRegistry $registry;
	private Clock $clock;
	private PurgeRepository $repo;
	private Scheduler $scheduler;

	/**
	 * @param SettingsRegistry|null $registry Plugin settings source.
	 * @param Clock|null            $clock    UTC clock for cutoff computation.
	 * @param PurgeRepository|null  $repo     Bounded batch deleter.
	 * @param Scheduler|null        $scheduler Cron event registrar.
	 */
	public function __construct(
		?SettingsRegistry $registry = null,
		?Clock $clock = null,
		?PurgeRepository $repo = null,
		?Scheduler $scheduler = null
	) {
		$this->registry  = $registry ?? new SettingsRegistry( new \BetterRestApiLogs\Settings\Repository() );
		$this->clock     = $clock ?? new Clock();
		$this->repo      = $repo ?? new PurgeRepository();
		$this->scheduler = $scheduler ?? new Scheduler( $clock ?? new Clock() );
	}

	/**
	 * Run one bounded purge tick.
	 *
	 * Accepts an optional $args parameter because wp_schedule_single_event
	 * passes its third argument (the unique seq array) to the action callback;
	 * we ignore the value — it is only there to defeat WP's 10-min dedup
	 * window (PURGE-04, Pitfall 1).
	 *
	 * Flow:
	 *  1. Bail immediately when the transient lock is held (parallel invocation).
	 *  2. Set the lock (5-min TTL) + ignore_user_abort(true).
	 *  3. Return early when retention_days <= 0 (keep-forever, D-02).
	 *  4. Drain: delete_older_than in a bounded loop until the time or row
	 *     budget is exhausted; the $now_unix seam on Clock::cutoff_datetime()
	 *     keeps the cutoff deterministic in tests without subclassing.
	 *  5. Write purge_state (last_run_at, last_deleted, schedule_error:false).
	 *  6. Fire brl_purge_completed.
	 *  7. Enqueue follow-up tick when the last batch was full.
	 *  8. Clear the lock in finally (always, even on exception).
	 *
	 * @param mixed $args Ignored; present to absorb the seq arg from the
	 *                    wp_schedule_single_event follow-up call.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $args absorbs the seq array from wp_schedule_single_event follow-up; it is intentionally unused.
	public function tick( $args = null ): void {
		// Bail when a parallel tick is already running (D-04).
		if ( \get_transient( self::LOCK_KEY ) ) {
			return;
		}

		\set_transient( self::LOCK_KEY, 1, 5 * \MINUTE_IN_SECONDS );
		\ignore_user_abort( true );

		$deleted_total = 0;
		$more_pending  = false;

		try {
			$retention_days = (int) $this->registry->get_setting( 'retention.retention_days' );

			// D-02: retention_days = 0 means keep-forever — skip deletes entirely.
			if ( $retention_days <= 0 ) {
				return;
			}

			$batch_size = (int) $this->registry->get_setting( 'retention.purge_batch_size' );
			$max_secs   = (int) $this->registry->get_setting( 'retention.purge_max_tick_seconds' );

			// Safety floor so a misconfigured 0 doesn't spin forever.
			if ( $batch_size <= 0 ) {
				$batch_size = 1000;
			}
			// Ceiling: an oversized batch would build an IN() list that strains
			// MySQL's max_allowed_packet and the query planner (PURGE-06).
			if ( $batch_size > 5000 ) {
				$batch_size = 5000;
			}
			if ( $max_secs <= 0 ) {
				$max_secs = 10;
			}

			$cutoff = $this->clock->cutoff_datetime( $retention_days );
			$start  = \microtime( true );

			// D-03/D-05: drain in a bounded loop — keep deleting full batches
			// until either (a) a batch comes back less than full (no more rows)
			// or (b) the tick's wall-clock budget runs out. This makes
			// purge_max_tick_seconds load-bearing: without the loop, a single
			// batch per tick means the drain rate is capped at
			// batch_size × cron_frequency rather than batch_size × ticks_per_tick.
			// When the loop exits on the time budget, more_pending triggers an
			// immediate follow-up tick to continue draining (D-05).
			$last_deleted = 0;
			do {
				$last_deleted   = $this->repo->delete_older_than( $cutoff, $batch_size );
				$deleted_total += $last_deleted;
			} while ( $last_deleted === $batch_size && ( \microtime( true ) - $start ) < $max_secs );

			$more_pending = ( $last_deleted === $batch_size );
		} finally {
			// Always clear the lock — even on exception or early return (D-04).
			\delete_transient( self::LOCK_KEY );
		}

		// Write purge_state after the lock is released so a crash inside
		// set_internal doesn't leave the lock held. try/catch Throwable
		// mirrors Breaker discipline (D-22): MySQL may be the broken thing.
		try {
			$current = SettingsRegistry::get_internal( 'purge_state' );
			if ( ! \is_array( $current ) ) {
				$current = [];
			}
			$current['last_run_at']    = \time();
			$current['last_deleted']   = $deleted_total;
			$current['schedule_error'] = false;
			SettingsRegistry::set_internal( 'purge_state', $current );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Persistence is best-effort; the drain already happened (D-22).
			unset( $e );
		}

		/**
		 * Fires after a purge tick completes.
		 *
		 * @since 1.0.0
		 *
		 * @param int  $deleted      Total rows removed in this tick.
		 * @param bool $more_pending True when the last batch was full and a
		 *                           follow-up tick has been scheduled.
		 */
		\do_action( 'brl_purge_completed', $deleted_total, $more_pending );

		// D-05: when the last batch was full, immediately enqueue a follow-up
		// tick with a unique arg so WP's 10-min dedup window doesn't swallow it.
		if ( $more_pending ) {
			$this->scheduler->enqueue_followup();
		}
	}

	/**
	 * Admin-notices callback. Renders a non-dismissible error banner when
	 * wp_schedule_event previously failed for the purge hook (D-07).
	 *
	 * Mirrors Logger\Breaker::maybe_render_armed_notice() — same guard pattern,
	 * same HTML structure, different copy.
	 */
	public function maybe_render_schedule_notice(): void {
		if ( ! \is_admin() ) {
			return;
		}
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$purge_state = SettingsRegistry::get_internal( 'purge_state' );
		if ( ! \is_array( $purge_state ) || empty( $purge_state['schedule_error'] ) ) {
			return;
		}

		\printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			\esc_html__(
				'Better REST API Logs (brl): the retention purge event could not be scheduled. Log retention will not run until the event is registered. Deactivating and reactivating the plugin may resolve this.',
				'better-rest-api-logs'
			)
		);
	}

	/**
	 * Filter callback for `site_status_tests`. Adds a `direct` Site Health
	 * entry for the purge schedule error when the marker is set (D-07).
	 *
	 * Mirrors Logger\Breaker::register_site_health_test().
	 *
	 * @param  array<string,array<string,mixed>> $tests Existing tests.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_site_health_test( array $tests ): array {
		$purge_state = SettingsRegistry::get_internal( 'purge_state' );
		if ( ! \is_array( $purge_state ) || empty( $purge_state['schedule_error'] ) ) {
			return $tests;
		}

		$tests['direct'][ self::HEALTH_TEST_KEY ] = [
			'label' => \__( 'Log retention purge schedule status', 'better-rest-api-logs' ),
			'test'  => [ $this, 'site_health_test_callback' ],
		];
		return $tests;
	}

	/**
	 * Site Health test callback for the purge schedule surface.
	 *
	 * Returns `recommended` (not `critical`) — this is operational degradation,
	 * not user-facing downtime. Mirrors Breaker::site_health_test_callback().
	 *
	 * @return array<string,mixed>
	 */
	public function site_health_test_callback(): array {
		return [
			'label'       => \__( 'Log retention purge is not scheduled', 'better-rest-api-logs' ),
			'status'      => 'recommended',
			'badge'       => [
				'label' => \__( 'Performance', 'better-rest-api-logs' ),
				'color' => 'orange',
			],
			'description' => '<p>' . \esc_html__(
				'The scheduled purge event for log retention could not be registered. Old log rows will not be automatically removed. Deactivating and reactivating the plugin may resolve this.',
				'better-rest-api-logs'
			) . '</p>',
			'test'        => self::HEALTH_TEST_KEY,
		];
	}
}
