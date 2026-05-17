<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Support\Json.
 *
 * RED-bar baseline (Wave 0): asserts against Json::encode/decode/FLAGS per
 * CONTEXT D-28 + RESEARCH Example D. Plan 02-04 turns these green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Support;

use BetterRestApiLogs\Support\Json;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class JsonTest extends TestCase {

	public function test_encode_uses_unescaped_unicode_and_slashes(): void {
		$encoded = Json::encode(
			[
				'url'     => 'https://example.com/path?a=1',
				'unicode' => 'café',
			]
		);

		$this->assertStringContainsString(
			'https://example.com/path',
			$encoded,
			'JSON_UNESCAPED_SLASHES — URL slashes must not be escaped.'
		);
		$this->assertStringContainsString(
			'café',
			$encoded,
			'JSON_UNESCAPED_UNICODE — non-ASCII must round-trip as UTF-8.'
		);
	}

	public function test_decode_returns_null_on_invalid_json(): void {
		$this->assertNull( Json::decode( '{not valid}' ) );
	}

	public function test_decode_returns_array_on_valid_object(): void {
		$this->assertSame( [ 'a' => 1 ], Json::decode( '{"a":1}' ) );
	}

	public function test_decode_returns_null_on_scalar_top(): void {
		$this->assertNull( Json::decode( '42' ), 'Top-level scalar JSON decodes to null — array-only contract.' );
	}

	public function test_flags_constant_includes_partial_output_on_error(): void {
		$this->assertNotSame(
			0,
			Json::FLAGS & JSON_PARTIAL_OUTPUT_ON_ERROR,
			'Json::FLAGS must include JSON_PARTIAL_OUTPUT_ON_ERROR so encode() never blocks an insert on invalid UTF-8.'
		);
	}
}
