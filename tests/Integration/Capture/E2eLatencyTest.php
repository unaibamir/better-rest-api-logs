<?php
/**
 * End-to-end latency gate for the capture pipeline.
 *
 * RED-bar baseline: tests fail with Class not found until production code
 * lands. Covers REL-02 (p95 overhead <5ms at 1000 sequential requests)
 * per REQUIREMENTS.md.
 *
 * This test is informational: it measures the real overhead added by the
 * capture pipeline compared to a baseline run with capture disabled. The
 * 5ms delta is an absolute wall-clock budget, which is too noisy to hard-gate
 * on shared CI runners, so it lives in its own perf-e2e group and runs only
 * in the informational CI step. The component microbench in PerfTest.php and
 * the search benchmark in LogRepositorySearchPerfTest are the hard gates.
 *
 * Tagged @group perf-e2e — excluded from the default test run by
 * phpunit.xml.dist (which excludes both perf and perf-e2e). Run explicitly:
 *   vendor/bin/phpunit --group perf-e2e --testsuite=integration
 *
 * @group perf-e2e
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Capture;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * @group perf-e2e
 */
final class E2eLatencyTest extends WP_UnitTestCase {

	private const ITERATIONS = 1000;

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->boot();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		\delete_option( 'brl_settings_capture' );
		parent::tear_down();
	}

	/**
	 * Compute p95 from a sorted array of microsecond duration samples.
	 *
	 * @param float[] $samples Array of microsecond durations.
	 */
	private function p95( array $samples ): float {
		sort( $samples );
		$idx = (int) ( 0.95 * count( $samples ) );
		return $samples[ $idx ];
	}

	/**
	 * REL-02: p95 overhead of the capture pipeline must not exceed 5ms
	 * when measured against 1000 sequential GET /wp/v2/posts calls.
	 */
	public function test_p95_overhead_under_5ms(): void {
		global $wpdb;

		// ---- Phase 1: baseline (capture disabled) ----
		\update_option( 'brl_settings_capture', [ 'enabled' => false ] );

		$baseline_samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0  = \hrtime( true );
			$req = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
			\rest_do_request( $req );
			$baseline_samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}
		$baseline_p95 = $this->p95( $baseline_samples );

		// ---- Phase 2: with capture enabled ----
		\update_option( 'brl_settings_capture', [ 'enabled' => true ] );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );

		$measured_samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0  = \hrtime( true );
			$req = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
			\rest_do_request( $req );
			$measured_samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}
		$measured_p95 = $this->p95( $measured_samples );

		$delta_us = $measured_p95 - $baseline_p95;

		// Write to STDERR for CI artifact capture.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite(
			STDERR,
			sprintf(
				"\nE2E latency — baseline p95: %.1fµs | capture p95: %.1fµs | delta p95: %.1fµs\n",
				$baseline_p95,
				$measured_p95,
				$delta_us
			)
		);

		// REL-02: delta must be under 5ms = 5000µs.
		$this->assertLessThan(
			5000,
			$delta_us,
			"E2E p95 delta {$delta_us}µs exceeds 5ms budget (REL-02)."
		);
	}
}
