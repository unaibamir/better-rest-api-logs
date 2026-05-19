<?php
/**
 * Unit tests for BetterRestApiLogs\Rest\Shapers\StatsShaper.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-06
 * implements includes/rest/shapers/stats-shaper.php. Covers the stats
 * endpoint JSON shape contract: all five status class keys always present,
 * missing snapshot keys default to zero/empty string, and the
 * cache_age_seconds pass-through is verbatim.
 *
 * This is a pure-PHP formatter — no WP boot required, no $wpdb.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Rest\Shapers;

use BetterRestApiLogs\Rest\Shapers\StatsShaper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-06) — StatsShaper class does not exist yet.

final class StatsShaperTest extends TestCase {

	/**
	 * A representative snapshot returned by the DB aggregate queries.
	 *
	 * @return array<string,mixed>
	 */
	private function full_snapshot(): array {
		return [
			'total'            => 12345,
			'by_status_class'  => [ '2xx' => 9876 ],
			'by_method'        => [ 'GET' => 8000, 'POST' => 4345 ],
			'oldest'           => '2026-05-12T00:01:23Z',
			'newest'           => '2026-05-19T11:55:00Z',
			'table_size_bytes' => 4567890,
		];
	}

	public function test_shape_returns_total_estimate(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 42 );

		$this->assertSame( 12345, $result['total_estimate'] );
	}

	public function test_shape_returns_by_status_class_with_all_five_keys(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 0 );

		$this->assertArrayHasKey( 'by_status_class', $result );
		$classes = $result['by_status_class'];

		$this->assertArrayHasKey( '1xx', $classes );
		$this->assertArrayHasKey( '2xx', $classes );
		$this->assertArrayHasKey( '3xx', $classes );
		$this->assertArrayHasKey( '4xx', $classes );
		$this->assertArrayHasKey( '5xx', $classes );
	}

	public function test_shape_fills_missing_status_class_keys_with_zero(): void {
		$result  = StatsShaper::shape( $this->full_snapshot(), 0 );
		$classes = $result['by_status_class'];

		// Only 2xx was in the snapshot; the others should default to 0.
		$this->assertSame( 0, $classes['1xx'] );
		$this->assertSame( 9876, $classes['2xx'] );
		$this->assertSame( 0, $classes['3xx'] );
		$this->assertSame( 0, $classes['4xx'] );
		$this->assertSame( 0, $classes['5xx'] );
	}

	public function test_shape_returns_by_method(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 0 );

		$this->assertArrayHasKey( 'by_method', $result );
		$this->assertSame( 8000, $result['by_method']['GET'] );
		$this->assertSame( 4345, $result['by_method']['POST'] );
	}

	public function test_shape_returns_oldest_and_newest(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 0 );

		$this->assertSame( '2026-05-12T00:01:23Z', $result['oldest'] );
		$this->assertSame( '2026-05-19T11:55:00Z', $result['newest'] );
	}

	public function test_shape_returns_table_size_bytes(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 0 );

		$this->assertSame( 4567890, $result['table_size_bytes'] );
	}

	public function test_shape_passes_through_cache_age_seconds(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 42 );

		$this->assertSame( 42, $result['cache_age_seconds'] );
	}

	public function test_shape_with_zero_cache_age_returns_zero(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 0 );

		$this->assertSame( 0, $result['cache_age_seconds'] );
	}

	public function test_shape_with_empty_snapshot_defaults_all_fields(): void {
		$result = StatsShaper::shape( [], 0 );

		$this->assertSame( 0, $result['total_estimate'] );
		$this->assertSame( 0, $result['by_status_class']['1xx'] );
		$this->assertSame( 0, $result['by_status_class']['2xx'] );
		$this->assertSame( 0, $result['by_status_class']['3xx'] );
		$this->assertSame( 0, $result['by_status_class']['4xx'] );
		$this->assertSame( 0, $result['by_status_class']['5xx'] );
		$this->assertSame( [], $result['by_method'] );
		$this->assertSame( '', $result['oldest'] );
		$this->assertSame( '', $result['newest'] );
		$this->assertSame( 0, $result['table_size_bytes'] );
	}

	public function test_shape_result_contains_all_expected_top_level_keys(): void {
		$result = StatsShaper::shape( $this->full_snapshot(), 0 );

		$this->assertArrayHasKey( 'total_estimate', $result );
		$this->assertArrayHasKey( 'by_status_class', $result );
		$this->assertArrayHasKey( 'by_method', $result );
		$this->assertArrayHasKey( 'oldest', $result );
		$this->assertArrayHasKey( 'newest', $result );
		$this->assertArrayHasKey( 'table_size_bytes', $result );
		$this->assertArrayHasKey( 'cache_age_seconds', $result );
	}
}
