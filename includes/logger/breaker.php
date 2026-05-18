<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Logger;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Settings\Registry as SettingsRegistry;

/**
 * Circuit breaker for the REST API capture INSERT path (D-18..D-22).
 *
 * Five consecutive insert failures within a sixty-second window arm the
 * breaker. While open, guard() short-circuits to an empty return and skips
 * the storage step entirely — so an operator's database problem can't cascade
 * into REST 500s (REL-04). After five minutes the breaker auto-resumes: the
 * next successful insert clears all counters and fires `brl_circuit_resumed`.
 *
 * Eventual-consistency model — per-PHP-FPM-worker in-memory state is
 * authoritative within a request; the `brl_internal` option mirror is
 * opportunistic across workers. Concurrent failures across workers may
 * double-fire `brl_circuit_armed` (intentional: both workers had five genuine
 * failures; arming twice writes the same final state, so it's idempotent).
 *
 * D-22: all Registry::set_internal calls are wrapped in try/catch Throwable.
 * If MySQL is down, recording the breaker state would fail the same way the
 * insert did — we don't try to write to the thing that's broken.
 *
 * @package BetterRestApiLogs
 */
final class Breaker {

	/**
	 * In-memory failure count for this PHP-FPM worker (D-18).
	 * Resets to zero on the first successful insert within a request.
	 *
	 * @var int
	 */
	private static int $consecutive_failures = 0;

	/**
	 * Unix timestamp of the first failure in the current 60s window (D-18).
	 * Null when no failures have been recorded in this worker's lifetime.
	 *
	 * @var int|null
	 */
	private static ?int $window_started_at = null;

	/**
	 * No dependencies needed — get_internal / set_internal on Registry are
	 * static and call directly into the WP options layer.
	 */
	public function __construct() {}

	/**
	 * Return true when the breaker is armed and the open window has not yet expired.
	 */
	public function is_open(): bool {
		$open_until = (int) SettingsRegistry::get_internal( 'circuit_open_until' );
		return $open_until > \time();
	}

	/**
	 * Wrap an INSERT callable. Skip it entirely when the breaker is open.
	 *
	 * Failure detection (D-19): the callable returned [] OR $wpdb->last_error
	 * is non-empty after the call. Either condition counts as a failure.
	 *
	 * @template T
	 * @param  callable():T $insert Callable that performs the INSERT; must return
	 *                              [] on failure or a non-empty value on success.
	 * @return T|array{} Returns the callable's result, or [] when the breaker is open.
	 */
	public function guard( callable $insert ) {
		if ( $this->is_open() ) {
			return [];
		}

		global $wpdb;

		$result = $insert();

		// D-19: failure = callable returned [] OR $wpdb->last_error is non-empty.
		// We read last_error directly from $wpdb (not a pre-cleared snapshot) so the
		// check reflects whatever the callable left behind.
		$failed = ( [] === $result ) || ( '' !== (string) $wpdb->last_error );

		if ( $failed ) {
			$this->on_failure();
		} else {
			$this->on_success();
		}

		return $result;
	}

	/**
	 * Admin-notices callback. Renders a non-dismissible error banner when the
	 * breaker is armed. Guards prevent rendering for non-admins (T-03-26).
	 */
	public function maybe_render_armed_notice(): void {
		if ( ! \is_admin() ) {
			return;
		}
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->is_open() ) {
			return;
		}

		// All copy is a static literal — no user data interpolated (T-03-27).
		\printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			\esc_html__(
				'Better REST API Logs: request capture is paused — recent insert failures armed the circuit breaker. Logging will resume automatically.',
				'better-rest-api-logs'
			)
		);
	}

	/**
	 * Filter callback for `site_status_tests`. Adds a `direct` Site Health entry
	 * showing the current capture state (D-20).
	 *
	 * @param  array<string,array<string,mixed>> $tests Existing tests array from WP.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_site_health_test( array $tests ): array {
		$tests['direct']['brl_capture_paused'] = [
			'label' => \__( 'REST API capture status', 'better-rest-api-logs' ),
			'test'  => [ $this, 'site_health_test_callback' ],
		];
		return $tests;
	}

	/**
	 * Runs as the Site Health test callback. Returns `recommended` (not
	 * `critical`) when the breaker is armed — captures-paused is operational
	 * degradation, not user-facing downtime (RESEARCH §"Specifics").
	 *
	 * @return array<string,mixed>
	 */
	public function site_health_test_callback(): array {
		if ( ! $this->is_open() ) {
			return [
				'label'       => \__( 'REST API request capture is active', 'better-rest-api-logs' ),
				'status'      => 'good',
				'badge'       => [
					'label' => \__( 'Performance', 'better-rest-api-logs' ),
					'color' => 'blue',
				],
				'description' => '<p>' . \esc_html__(
					'REST API requests are being captured and stored normally.',
					'better-rest-api-logs'
				) . '</p>',
				'test'        => 'brl_capture_paused',
			];
		}

		return [
			'label'       => \__( 'REST API request capture is paused', 'better-rest-api-logs' ),
			'status'      => 'recommended',
			'badge'       => [
				'label' => \__( 'Performance', 'better-rest-api-logs' ),
				'color' => 'orange',
			],
			'description' => '<p>' . \esc_html__(
				'Recent insert failures armed the circuit breaker. Capture will auto-resume when storage recovers.',
				'better-rest-api-logs'
			) . '</p>',
			'test'        => 'brl_capture_paused',
		];
	}

	/**
	 * Reset static in-memory state between tests.
	 *
	 * @internal Test seam only. Does NOT clear brl_internal persistent state —
	 *           the test's setUp must do that separately via Registry::set_internal
	 *           if persistence isolation is also required.
	 */
	public static function reset_state_for_tests(): void {
		self::$consecutive_failures = 0;
		self::$window_started_at    = null;
	}

	/**
	 * Record a failure against the in-memory counter and arm the breaker when
	 * the 5-failures-in-60s threshold is crossed (D-20).
	 */
	private function on_failure(): void {
		$now = \time();

		if ( null === self::$window_started_at || ( $now - self::$window_started_at ) > 60 ) {
			// Start a fresh window — stale counter from a prior window is discarded
			// (T-03-25: old workers can't arm spuriously after a 60s gap).
			self::$window_started_at    = $now;
			self::$consecutive_failures = 1;
		} else {
			++self::$consecutive_failures;
		}

		// Persist counters; if MySQL is down this write fails too — swallow it (D-22).
		try {
			SettingsRegistry::set_internal( 'circuit_consecutive_failures', self::$consecutive_failures );
			SettingsRegistry::set_internal( 'circuit_window_started_at', (int) self::$window_started_at );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// In-memory state is authoritative; persistence is best-effort (D-22).
			unset( $e );
		}

		if ( self::$consecutive_failures >= 5 ) {
			$opens_until = $now + 300;
			try {
				SettingsRegistry::set_internal( 'circuit_open_until', $opens_until );
			} catch ( \Throwable $e ) {
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Same model — arm in-memory even when persistence fails (D-22).
				unset( $e );
			}
			\do_action( 'brl_circuit_armed', 'consecutive_insert_failures', $opens_until );
		}
	}

	/**
	 * Record a success: reset the failure counter and fire `brl_circuit_resumed`
	 * when recovering from an open state (D-21).
	 */
	private function on_success(): void {
		// Fast path when nothing needs resetting.
		if ( 0 === self::$consecutive_failures ) {
			$open_until = (int) SettingsRegistry::get_internal( 'circuit_open_until' );
			if ( 0 === $open_until ) {
				return;
			}
		}

		$was_open = ( (int) SettingsRegistry::get_internal( 'circuit_open_until' ) ) > 0;

		self::$consecutive_failures = 0;
		self::$window_started_at    = null;

		try {
			SettingsRegistry::set_internal( 'circuit_consecutive_failures', 0 );
			SettingsRegistry::set_internal( 'circuit_window_started_at', 0 );
			SettingsRegistry::set_internal( 'circuit_open_until', 0 );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Persistence is best-effort; in-memory counters are already reset (D-22).
			unset( $e );
		}

		if ( $was_open ) {
			\do_action( 'brl_circuit_resumed', \time() );
		}
	}
}
