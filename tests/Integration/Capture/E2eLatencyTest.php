<?php
/**
 * End-to-end latency gate for the capture pipeline.
 *
 * RED-bar baseline: tests fail with Class not found until production code
 * lands. Covers REL-02 (p95 overhead <5ms at 1000 sequential requests)
 * per REQUIREMENTS.md.
 *
 * This test is informational: it measures the real overhead added by the
 * capture pipeline compared to a baseline run with capture disabled. CI
 * does NOT block on this assertion in isolation — the component microbench
 * in PerfTest.php is the primary gate. This test catches gross regressions
 * only (>5ms p95 delta).
 *
 * Tagged @group perf — excluded from the default test run by phpunit.xml.dist.
 * Run explicitly on the PHP 8.3 CI cell via:
 *   vendor/bin/phpunit --group perf tests/Integration/Capture/E2eLatencyTest.php
 *
 * @group perf
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Capture;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * @group perf
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
		Registry::set_setting( 'capture.enabled', false );

		$baseline_samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0  = \hrtime( true );
			$req = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
			\rest_do_request( $req );
			$baseline_samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}
		$baseline_p95 = $this->p95( $baseline_samples );

		// ---- Phase 2: with capture enabled ----
		Registry::set_setting( 'capture.enabled', true );
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
