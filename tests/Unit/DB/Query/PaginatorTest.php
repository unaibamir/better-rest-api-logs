<?php
/**
 * Unit tests for BetterRestApiLogs\DB\Query\Paginator.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-02
 * implements includes/db/query/paginator.php. Covers the cursor token
 * contract: base64url encoding, round-trip fidelity, malformed-input
 * rejection, and collision avoidance when rows share a created_at_micros
 * value.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\DB\Query;

use BetterRestApiLogs\DB\Query\Paginator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the Paginator cursor token round-trip and decode-error paths.
 */
final class PaginatorTest extends TestCase {

	public function test_encode_decode_round_trips(): void {
		$micros = 1716109800000000;
		$id     = 12345;

		$token  = Paginator::encode( $micros, $id );
		$result = Paginator::decode( $token );

		$this->assertSame( $micros, $result['micros'] );
		$this->assertSame( $id, $result['id'] );
	}

	public function test_encoded_token_contains_only_url_safe_characters(): void {
		$token = Paginator::encode( 1716109800000000, 12345 );

		// base64url alphabet: A-Z a-z 0-9 _ - (no +, /, or =).
		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9_-]+$/',
			$token,
			'Cursor token must use base64url alphabet with no +, /, or = characters.'
		);
	}

	public function test_decoded_values_are_exact_integers_no_float_precision_loss(): void {
		// Large microsecond timestamp near the 64-bit boundary that would
		// lose precision if stored as a float.
		$micros = 9007199254740993; // 2^53 + 1 — first integer float can't represent exactly.
		$id     = PHP_INT_MAX - 1;

		$result = Paginator::decode( Paginator::encode( $micros, $id ) );

		$this->assertSame( $micros, $result['micros'] );
		$this->assertSame( $id, $result['id'] );
	}

	public function test_malformed_token_throws_invalid_argument_exception(): void {
		$this->expectException( \InvalidArgumentException::class );
		Paginator::decode( 'not-a-valid-base64url-cursor-token!!!!' );
	}

	public function test_empty_string_token_throws_invalid_argument_exception(): void {
		$this->expectException( \InvalidArgumentException::class );
		Paginator::decode( '' );
	}

	public function test_valid_base64url_with_wrong_json_structure_throws(): void {
		// Valid base64url but the decoded JSON has no 'c'/'i' keys.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encodes a deliberately malformed cursor payload to exercise the decoder's structural-validation branch.
		$bad_token = rtrim( strtr( base64_encode( '{"x":1,"y":2}' ), '+/', '-_' ), '=' );

		$this->expectException( \InvalidArgumentException::class );
		Paginator::decode( $bad_token );
	}

	public function test_two_tokens_with_same_micros_but_different_ids_are_distinct(): void {
		$micros = 1716109800000000;

		$token_a = Paginator::encode( $micros, 1 );
		$token_b = Paginator::encode( $micros, 2 );

		$this->assertNotSame(
			$token_a,
			$token_b,
			'Tokens differing only in id must produce different strings.'
		);
	}

	public function test_decode_returns_array_with_micros_and_id_keys(): void {
		$result = Paginator::decode( Paginator::encode( 1716109800000000, 42 ) );

		$this->assertArrayHasKey( 'micros', $result );
		$this->assertArrayHasKey( 'id', $result );
	}

	public function test_zero_values_round_trip_correctly(): void {
		$result = Paginator::decode( Paginator::encode( 0, 0 ) );

		$this->assertSame( 0, $result['micros'] );
		$this->assertSame( 0, $result['id'] );
	}
}
