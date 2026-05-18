<?php
/**
 * Unit tests for BetterRestApiLogs\Capture\Truncator.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-05
 * implements includes/capture/truncator.php. Covers CAP-08 (UTF-8 boundary
 * truncation and honest original-byte reporting) per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Capture;

use BetterRestApiLogs\Capture\Truncator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TruncatorTest extends TestCase {

	public function test_truncate_under_cap_passes_through_with_truncated_false(): void {
		$result = Truncator::truncate( 'hello', 100 );

		$this->assertSame( 'hello', $result['body'] );
		$this->assertSame( 5, $result['bytes_original'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_truncate_over_cap_returns_truncated_true_and_original_bytes(): void {
		$input  = \str_repeat( 'a', 1000 );
		$result = Truncator::truncate( $input, 100 );

		$this->assertSame( 100, \strlen( $result['body'] ) );
		$this->assertSame( 1000, $result['bytes_original'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_truncate_walks_back_to_utf8_boundary(): void {
		// 'h' (1 byte) + 50 × 'é' (2 bytes each = 100 bytes) = 101 bytes total.
		$input = 'h' . \str_repeat( 'é', 50 );
		// Cap at 2 bytes would land mid-é (after 0xC3) — must walk back to 'h'.
		$result = Truncator::truncate( $input, 2 );

		$this->assertSame( 'h', $result['body'] );
		$this->assertSame( 1, \strlen( $result['body'] ) );
		$this->assertSame( 101, $result['bytes_original'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_truncate_with_zero_max_returns_empty_string_with_honest_original_size(): void {
		$result = Truncator::truncate( 'some content', 0 );

		$this->assertSame( '', $result['body'] );
		$this->assertSame( 12, $result['bytes_original'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function test_truncate_exact_boundary_is_not_truncated(): void {
		// Input is exactly max_bytes — should pass through with truncated=false.
		$input  = 'abcd';
		$result = Truncator::truncate( $input, 4 );

		$this->assertSame( 'abcd', $result['body'] );
		$this->assertSame( 4, $result['bytes_original'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_truncate_empty_string_returns_empty_not_truncated(): void {
		$result = Truncator::truncate( '', 100 );

		$this->assertSame( '', $result['body'] );
		$this->assertSame( 0, $result['bytes_original'] );
		$this->assertFalse( $result['truncated'] );
	}
}
