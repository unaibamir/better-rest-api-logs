<?php
/**
 * Unit tests for BetterRestApiLogs\Capture\Compressor.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-05
 * implements includes/capture/compressor.php. Covers CAP-10 (gzip opt-in
 * and 1024-byte breakeven) per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Capture;

use BetterRestApiLogs\Capture\Compressor;
use BetterRestApiLogs\Support\Bytes;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class CompressorTest extends TestCase {

	public function test_returns_input_when_opt_in_false(): void {
		$input  = 'hello world';
		$result = Compressor::maybe_compress( $input, false );

		$this->assertSame( $input, $result );
	}

	public function test_returns_input_when_below_breakeven_even_with_opt_in_true(): void {
		// 500 bytes < 1024 breakeven — no compression.
		$input  = \str_repeat( 'a', 500 );
		$result = Compressor::maybe_compress( $input, true );

		$this->assertSame( $input, $result );
	}

	public function test_compresses_when_above_breakeven_and_opt_in_true(): void {
		// str_repeat gives a highly compressible payload well above the 1024-byte threshold.
		$input  = \str_repeat( 'hello world ', 200 );
		$result = Compressor::maybe_compress( $input, true );

		$this->assertLessThan( \strlen( $input ), \strlen( $result ) );
		// gzip magic bytes 0x1f 0x8b.
		$this->assertSame( "\x1f\x8b", \substr( $result, 0, 2 ) );
	}

	public function test_round_trip_via_bytes_gunzip(): void {
		$input      = \str_repeat( 'round-trip test payload ', 100 );
		$compressed = Compressor::maybe_compress( $input, true );

		// gunzip must recover the original string exactly.
		$this->assertSame( $input, Bytes::gunzip( $compressed ) );
	}

	public function test_breakeven_constant_value(): void {
		$this->assertSame( 1024, Compressor::COMPRESSION_BREAKEVEN_BYTES );
	}
}
