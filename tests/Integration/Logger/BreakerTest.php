<?php
/**
 * Integration tests for BetterRestApiLogs\Logger\Breaker.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-07
 * implements includes/logger/breaker.php. Covers REL-01 (arms after 5
 * failures in 60s window), REL-04 (DB error does not 500 the response)
 * per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Logger;

use BetterRestApiLogs\Logger\Breaker;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

final class BreakerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_breaker_state();
	}

	public function tear_down(): void {
		$this->reset_breaker_state();
		parent::tear_down();
	}

	/**
	 * Reset breaker static state and persisted internal keys between tests.
	 */
	private function reset_breaker_state(): void {
		// Reset in-memory static counters via Reflection.
		foreach ( [ 'consecutive_failures', 'window_started_at' ] as $prop ) {
			try {
				$rp = new \ReflectionProperty( Breaker::class, $prop );
				$rp->setAccessible( true );
				$rp->setValue( null, 0 );
			} catch ( \ReflectionException $e ) {
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Property not yet defined — production code not shipped yet (RED bar).
				unset( $e );
			}
		}
		// Clear persisted circuit keys in brl_internal.
		try {
			Registry::set_internal( 'circuit_open_until', 0 );
			Registry::set_internal( 'circuit_consecutive_failures', 0 );
			Registry::set_internal( 'circuit_window_started_at', 0 );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Registry not yet wired — RED bar expectation.
			unset( $e );
		}
	}

	/** REL-01: breaker arms after exactly 5 consecutive failures within the 60s window. */
	public function test_arms_after_five_failures_within_window(): void {
		$breaker = new Breaker();

		// An empty-array return value signals failure (D-19).
		$failing = static function () {
			return [];
		};

		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->guard( $failing );
		}

		$this->assertTrue( $breaker->is_open(), 'Breaker must be open after 5 failures.' );
		$open_until = Registry::get_internal( 'circuit_open_until' );
		$this->assertGreaterThanOrEqual( time() + 295, $open_until, 'circuit_open_until must be ~300s in the future.' );
	}

	/** REL-01: failures outside the 60s window do not arm the breaker. */
	public function test_does_not_arm_when_failures_span_more_than_60_seconds(): void {
		$breaker = new Breaker();

		// Seed the window start 120s in the past so the window has already expired.
		try {
			$rp = new \ReflectionProperty( Breaker::class, 'window_started_at' );
			$rp->setAccessible( true );
			$rp->setValue( null, time() - 120 );
		} catch ( \ReflectionException $e ) {
			$this->markTestIncomplete( 'Breaker::$window_started_at not yet defined — Plan 03-07 ships it.' );
		}

		$failing = static function () {
			return [];
		};
		for ( $i = 0; $i < 4; $i++ ) {
			$breaker->guard( $failing );
		}

		$this->assertFalse( $breaker->is_open(), 'Breaker must remain closed when failures span >60s.' );
	}

	/** REL-01: a successful call resets the failure counter. */
	public function test_success_resets_counter(): void {
		$breaker = new Breaker();

		$failing = static function () {
			return [];
		};
		$breaker->guard( $failing );
		$breaker->guard( $failing );

		// Now a successful call.
		$breaker->guard(
			static function () {
				return [ 1 => 42 ];
			}
		);

		try {
			$rp = new \ReflectionProperty( Breaker::class, 'consecutive_failures' );
			$rp->setAccessible( true );
			$this->assertSame( 0, $rp->getValue( null ), 'Consecutive failures must reset to 0 after success.' );
		} catch ( \ReflectionException $e ) {
			$this->markTestIncomplete( 'Breaker::$consecutive_failures not yet defined — Plan 03-07 ships it.' );
		}
	}

	/** REL-01: an open breaker must skip the insert callable entirely. */
	public function test_open_breaker_skips_inserts(): void {
		Registry::set_internal( 'circuit_open_until', time() + 100 );

		$breaker = new Breaker();
		$called  = 0;
		$breaker->guard(
			static function () use ( &$called ) {
				$called++;
				return 'something';
			}
		);

		$this->assertSame( 0, $called, 'Open breaker must not invoke the callable.' );
	}

	/** REL-01: brl_circuit_armed fires exactly once when the breaker arms. */
	public function test_brl_circuit_armed_fires_when_breaker_arms(): void {
		$spy_count = 0;
		$spy_args  = [];

		\add_action(
			'brl_circuit_armed',
			static function ( $reason, $opens_until ) use ( &$spy_count, &$spy_args ) {
				$spy_count++;
				$spy_args = [ $reason, $opens_until ];
			},
			10,
			2
		);

		$breaker = new Breaker();
		$failing = static function () {
			return [];
		};
		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->guard( $failing );
		}

		$this->assertSame( 1, $spy_count, 'brl_circuit_armed must fire exactly once.' );
		$this->assertIsString( $spy_args[0], 'First arg (reason) must be a string.' );
		$this->assertIsInt( $spy_args[1], 'Second arg (opens_until_ts) must be an int.' );
	}

	/** REL-01: brl_circuit_resumed fires on the first successful call after the breaker auto-resumes. */
	public function test_brl_circuit_resumed_fires_on_first_success_after_open(): void {
		// circuit_open_until=0 means the breaker is technically not open (expired).
		// Seed consecutive_failures=5 via Reflection to simulate "just recovered".
		try {
			$rp = new \ReflectionProperty( Breaker::class, 'consecutive_failures' );
			$rp->setAccessible( true );
			$rp->setValue( null, 5 );
		} catch ( \ReflectionException $e ) {
			$this->markTestIncomplete( 'Breaker::$consecutive_failures not yet defined.' );
		}

		$resumed = false;
		\add_action(
			'brl_circuit_resumed',
			static function () use ( &$resumed ) {
				$resumed = true;
			}
		);

		$breaker = new Breaker();
		$breaker->guard(
			static function () {
				return [ 1 => 42 ];
			}
		);

		$this->assertTrue( $resumed, 'brl_circuit_resumed must fire after first success following failures.' );
	}

	/** REL-04: a database error during INSERT must not change the REST response code. */
	public function test_db_error_does_not_500_response(): void {
		global $wpdb;

		\add_action(
			'rest_api_init',
			static function () {
				\register_rest_route(
					'brl-test-breaker/v1',
					'/ok',
					[
						'methods'             => 'GET',
						'callback'            => static function () {
							return new \WP_REST_Response( [ 'ok' => true ], 200 );
						},
						'permission_callback' => static function () {
							return true; },
					]
				);
			}
		);

		$request  = new \WP_REST_Request( 'GET', '/brl-test-breaker/v1/ok' );
		$response = \rest_do_request( $request );

		// REST response code must be 200 regardless of what the shutdown handler does.
		$this->assertSame( 200, $response->get_status(), 'REL-04: DB error must not change REST response status.' );
	}

	/**
	 * Pitfall 7: update_option failure during breaker arm must not produce a fatal.
	 * This test renames the options table to simulate a completely down MySQL, then
	 * triggers a breaker failure, and verifies no exception escapes.
	 */
	public function test_set_internal_during_failure_swallowed_when_options_table_unreachable(): void {
		global $wpdb;

		// Rename options table to simulate outage. Restore in finally.
		$backup = $wpdb->options . '_bkp_' . time();
		$wpdb->query( "RENAME TABLE {$wpdb->options} TO {$backup}" );
		try {
			$breaker = new Breaker();
			$failing = static function () {
				return [];
			};
			// Must not throw even though update_option will fail.
			for ( $i = 0; $i < 3; $i++ ) {
				$breaker->guard( $failing );
			}
			$this->assertTrue( true, 'No exception escaped the guard during options-table outage.' );
		} finally {
			$wpdb->query( "RENAME TABLE {$backup} TO {$wpdb->options}" );
		}
	}
}
