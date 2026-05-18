<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Support\Bytes.
 *
 * RED-bar baseline (Wave 0): asserts against Bytes::truncate_utf8/human_readable/
 * gzip/gunzip per CONTEXT D-29 + RESEARCH Example E. Plan 02-04 turns these green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Support;

use BetterRestApiLogs\Support\Bytes;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class BytesTest extends TestCase {

	public function test_truncate_utf8_passthrough_when_under_cap(): void {
		$this->assertSame( 'hi', Bytes::truncate_utf8( 'hi', 10 ) );
	}

	public function test_truncate_utf8_walks_back_to_sequence_boundary(): void {
		// "héllo" — h (1 byte), é (0xC3 0xA9, 2 bytes), llo (3 bytes).
		// Cap at 2 bytes would land mid-é (after 0xC3). truncate_utf8 must walk back to 'h'.
		$out = Bytes::truncate_utf8( 'héllo', 2 );
		$this->assertSame( 1, \strlen( $out ), 'Mid-sequence cap must walk back to whole UTF-8 boundary.' );
		$this->assertSame( 'h', $out );
	}

	public function test_truncate_utf8_handles_pure_ascii(): void {
		$this->assertSame( 'abc', Bytes::truncate_utf8( 'abcdef', 3 ) );
	}

	public function test_human_readable_kb(): void {
		$this->assertSame( '1.0 KB', Bytes::human_readable( 1024 ) );
	}

	public function test_human_readable_bytes(): void {
		$this->assertSame( '500 B', Bytes::human_readable( 500 ) );
	}

	public function test_gzip_gunzip_round_trip(): void {
		$this->assertSame( 'payload', Bytes::gunzip( Bytes::gzip( 'payload' ) ) );
	}
}
