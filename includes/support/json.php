<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical JSON codec for log payloads.
 *
 * Wraps json_encode/json_decode with locked flags so every call site stores
 * the same byte-faithful shape. JSON_PARTIAL_OUTPUT_ON_ERROR guarantees the
 * encoder returns a string even on malformed UTF-8 — Phase 3 inserts never
 * block because of an upstream byte the redactor missed.
 *
 * Locked contract per CONTEXT.md D-28:
 *  - encode() never returns false; encode failure surfaces as an empty string.
 *  - decode() returns null on parse failure OR when the top-level value is
 *    not an array (scalars like "42", "true" decode to non-arrays).
 */
final class Json {

	/**
	 * Locked encode flags. JSON_UNESCAPED_UNICODE keeps café as café.
	 * JSON_UNESCAPED_SLASHES keeps URL slashes readable. JSON_PARTIAL_OUTPUT_ON_ERROR
	 * lets the encoder degrade gracefully on invalid UTF-8 rather than fail outright.
	 */
	public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

	/**
	 * Encode a value with the locked flags. Empty string on failure.
	 *
	 * @param  mixed $value Value to encode.
	 * @return string       JSON string, or '' when encoding fails outright.
	 */
	public static function encode( $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- D-28 mandates raw json_encode with the locked FLAGS (JSON_PARTIAL_OUTPUT_ON_ERROR); wp_json_encode strips that flag.
		$out = \json_encode( $value, self::FLAGS );
		return false === $out ? '' : $out;
	}

	/**
	 * Decode a JSON string into an associative array. null on parse failure or
	 * when the top-level value is not an array.
	 *
	 * @param  string $json JSON input.
	 * @return array<int|string,mixed>|null
	 */
	public static function decode( string $json ): ?array {
		$out = \json_decode( $json, true );
		return \is_array( $out ) ? $out : null;
	}
}
