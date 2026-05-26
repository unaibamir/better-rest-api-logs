<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Capture;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Support\Json;

/**
 * Privacy primitives for the capture pipeline.
 *
 * Redacts sensitive headers before they reach the DB, walks JSON bodies
 * recursively for credential-shaped keys, falls back to a targeted regex when
 * the body isn't valid JSON, and anonymizes packed IPv4/IPv6 addresses for the
 * privacy opt-in. Locked contract per CONTEXT.md D-13..D-17.
 *
 * Pure PHP — no WordPress functions, no $wpdb, no apply_filters. Keeps this
 * class unit-testable without bootstrapping WP. WP-coupled hooks that wrap these
 * methods live in the Capture orchestrator (Plan 03-08).
 */
final class Redactor {

	/**
	 * Headers that are always redacted regardless of user configuration.
	 * Defaults merge — never opt out — because PRIV-03 treats these five as
	 * universally sensitive across every REST API integration.
	 *
	 * Stored as an indexed array of lowercase names so assertContains() tests
	 * and foreach iteration are both natural. redact_headers() flips this to a
	 * hash internally for O(1) isset() lookups.
	 */
	public const DEFAULT_HEADER_REDACT_LIST = array(
		'authorization',
		'cookie',
		'set-cookie',
		'x-api-key',
		'proxy-authorization',
	);

	/**
	 * Regex for credential-shaped JSON keys (PRIV-02, D-14).
	 * The alternation is short and non-nested, so no catastrophic backtracking.
	 */
	public const KEY_REGEX_DEFAULT = '/(password|secret|token|key|credit|card|cvv|ssn)/i';

	/** Single source of truth for the redaction placeholder string. */
	public const REDACTED_SENTINEL = '[redacted]';

	/**
	 * Content-type prefixes that are safe to capture body payloads for.
	 * Binary, streaming, and media types are excluded by default (CAP-07).
	 * An empty $allowlist arg to should_capture_body() falls back to this list.
	 */
	public const DEFAULT_BODY_CONTENT_TYPES = array(
		'application/json',
		'application/x-www-form-urlencoded',
		'multipart/form-data',
		'text/plain',
		'application/xml',
		'text/xml',
	);

	/**
	 * Redact sensitive request/response headers.
	 *
	 * Defaults from DEFAULT_HEADER_REDACT_LIST are always applied; $user_extras
	 * extend the lookup table. Input array shape mirrors WP_REST_Request::get_headers:
	 * keys are the original header names, values are arrays of strings.
	 *
	 * @param  array<string,array<int,string>> $headers     Headers keyed by name.
	 * @param  array<int,string>               $user_extras Additional header names to redact (lowercase).
	 * @return array<string,array<int,string>>              New array; input is not mutated.
	 *
	 * Locked: D-13, PRIV-01, PRIV-03.
	 */
	public function redact_headers( array $headers, array $user_extras ): array {
		// Flip to hash for O(1) isset() lookups; merge user extras on top.
		// Canonicalise every key so the hyphenated default list matches the
		// underscored form WP_REST_Request::get_headers() actually delivers.
		$lookup = array();
		foreach ( self::DEFAULT_HEADER_REDACT_LIST as $name ) {
			$lookup[ self::canonical_header_key( $name ) ] = true;
		}
		foreach ( $user_extras as $name ) {
			if ( is_string( $name ) ) {
				$lookup[ self::canonical_header_key( $name ) ] = true;
			}
		}

		$out = array();
		foreach ( $headers as $key => $values ) {
			if ( isset( $lookup[ self::canonical_header_key( (string) $key ) ] ) ) {
				$out[ $key ] = array( self::REDACTED_SENTINEL );
			} else {
				$out[ $key ] = $values;
			}
		}
		return $out;
	}

	/**
	 * Canonicalise a header name for case- and separator-insensitive matching.
	 *
	 * WP_REST_Request::get_headers() lowercases names AND replaces `-` with `_`
	 * (so `X-Api-Key` arrives as `x_api_key`), while the default redact list and
	 * raw HTTP names use hyphens. Folding both sides to a single lowercase,
	 * underscored form is what bridges the two so the lookup never misses.
	 *
	 * @param  string $name Raw header name in any case/separator form.
	 * @return string       Lowercase, `-`-to-`_` canonical form.
	 */
	public static function canonical_header_key( string $name ): string {
		return str_replace( '-', '_', strtolower( $name ) );
	}

	/**
	 * Redact credential-shaped keys from a JSON body string.
	 *
	 * Decodes the body and walks the resulting array recursively. On parse
	 * failure, falls back to a key-targeted regex so at least string-value
	 * occurrences are scrubbed even in malformed payloads. Re-encodes via
	 * Support\Json (locked flags, JSON_PARTIAL_OUTPUT_ON_ERROR) per D-28.
	 *
	 * $extra_pattern is NOT preg_quoted here — caller responsibility (the Phase 4
	 * settings sanitizer preg_quotes user input before passing it down).
	 *
	 * @param  string $body          Raw JSON body.
	 * @param  string $extra_pattern Additional key alternation to append (no delimiters).
	 * @return string                Redacted JSON, or regex-scrubbed original on parse failure.
	 *
	 * Locked: D-14, PRIV-02.
	 */
	public function redact_json_body( string $body, string $extra_pattern = '' ): string {
		$pattern = $this->build_key_pattern( $extra_pattern );
		$decoded = Json::decode( $body );

		if ( null === $decoded ) {
			return $this->redact_via_regex( $body, $extra_pattern );
		}

		$this->walk_redact( $decoded, $pattern );
		return Json::encode( $decoded );
	}

	/**
	 * Redact credential-shaped keys from a form-urlencoded body string.
	 *
	 * Parses via parse_str, walks the same key regex as the JSON path, then
	 * round-trips back through http_build_query. Non-parseable bodies are
	 * returned unchanged.
	 *
	 * @param  string $body          Raw form body (e.g. "password=x&name=y").
	 * @param  string $extra_pattern Additional key alternation (no delimiters).
	 * @return string                Redacted form-urlencoded string.
	 *
	 * Locked: D-14, PRIV-02.
	 */
	public function redact_form_body( string $body, string $extra_pattern = '' ): string {
		// parse_str always populates $parsed as an array — never false/null.
		parse_str( $body, $parsed );

		$pattern = $this->build_key_pattern( $extra_pattern );
		$this->walk_redact( $parsed, $pattern );
		return http_build_query( $parsed );
	}

	/**
	 * Anonymize a packed (inet_pton) IPv4 or IPv6 address.
	 *
	 * IPv4-mapped form (bytes 0..11 = 00..00 ff ff): zeros the last octet only.
	 * Native IPv6: zeros the last 80 bits (bytes 6..15) per GDPR/RIPE NCC guidance.
	 * Returns null unchanged, and passes through any non-16-byte value defensively.
	 *
	 * ip_raw_remote is NEVER anonymized — it is always the raw audit value (D-16).
	 *
	 * @param  string|null $packed_ip 16-byte packed IP from inet_pton, or null.
	 * @return string|null            Anonymized packed IP, or null.
	 *
	 * Locked: D-16, PRIV-05.
	 */
	public function anonymize_ip( ?string $packed_ip ): ?string {
		if ( null === $packed_ip || 16 !== strlen( $packed_ip ) ) {
			return $packed_ip;
		}

		// v4-mapped prefix: ::ffff:x.x.x.x.
		$v4_prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
		if ( substr( $packed_ip, 0, 12 ) === $v4_prefix ) {
			// Keep the first 3 octets; zero the last (byte 15).
			return substr( $packed_ip, 0, 15 ) . "\x00";
		}

		// Native IPv6: zero the last 80 bits (bytes 6..15).
		return substr( $packed_ip, 0, 6 ) . str_repeat( "\x00", 10 );
	}

	/**
	 * Whether the given Content-Type is safe to capture body bytes for.
	 *
	 * Strips the charset/boundary parameter, lowercases, then checks against
	 * $allowlist. When $allowlist is empty, falls back to DEFAULT_BODY_CONTENT_TYPES
	 * (CAP-07). Binary and streaming media types do not appear in the default list.
	 *
	 * @param  string        $content_type Raw Content-Type header value.
	 * @param  array<string> $allowlist    Allowed MIME prefixes; empty = use defaults.
	 * @return bool
	 *
	 * Locked: D-17, CAP-07.
	 */
	public function should_capture_body( string $content_type, array $allowlist ): bool {
		$stripped = strstr( $content_type, ';', true );
		$base     = trim( strtolower( false === $stripped ? $content_type : $stripped ) );

		$list = empty( $allowlist )
			? array_map( 'strtolower', self::DEFAULT_BODY_CONTENT_TYPES )
			: array_map( 'strtolower', $allowlist );

		return in_array( $base, $list, true );
	}

	/**
	 * Recursively replace values at credential-shaped keys with the sentinel.
	 * Any leaf type is replaced — the key name signals sensitivity, not the value type.
	 *
	 * @param array<mixed> $arr     Decoded array, passed by reference.
	 * @param string       $pattern PCRE pattern for credential key names.
	 */
	private function walk_redact( array &$arr, string $pattern ): void {
		foreach ( $arr as $k => &$v ) {
			if ( is_string( $k ) && 1 === preg_match( $pattern, $k ) ) {
				$v = self::REDACTED_SENTINEL;
				continue;
			}
			if ( is_array( $v ) ) {
				$this->walk_redact( $v, $pattern );
			}
		}
		unset( $v );
	}

	/**
	 * Regex fallback for malformed JSON. Targets "key":"value" pairs only.
	 * No nested quantifiers on identical chars, so no catastrophic backtracking (T-03-06).
	 *
	 * @param string $body          Raw body that failed JSON decode.
	 * @param string $extra_pattern Additional key alternation (no delimiters).
	 * @return string
	 */
	private function redact_via_regex( string $body, string $extra_pattern ): string {
		$keys = 'password|secret|token|key|credit|card|cvv|ssn';
		if ( '' !== $extra_pattern ) {
			$keys .= '|' . $extra_pattern;
		}

		$regex = '/"(?P<key>' . $keys . ')"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/i';
		$sub   = '"$1":"' . self::REDACTED_SENTINEL . '"';
		return (string) preg_replace( $regex, $sub, $body );
	}

	/**
	 * Build the PCRE pattern for credential key names, extended when $extra_pattern is set.
	 *
	 * @param string $extra_pattern Additional alternation without delimiters. Empty = defaults only.
	 * @return string
	 */
	private function build_key_pattern( string $extra_pattern ): string {
		if ( '' === $extra_pattern ) {
			return self::KEY_REGEX_DEFAULT;
		}
		return '/(password|secret|token|key|credit|card|cvv|ssn|' . $extra_pattern . ')/i';
	}
}
