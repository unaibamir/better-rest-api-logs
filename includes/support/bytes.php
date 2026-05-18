<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-level helpers for log body storage.
 *
 * Truncate_utf8() caps by bytes (not chars) without splitting a UTF-8
 * codepoint. human_readable() formats sizes for the admin UI. gzip/gunzip
 * round-trip payloads through PHP's zlib filter at the default level
 * (`gzencode()` with no level argument; currently maps to 6 in zlib).
 *
 * Locked contract per CONTEXT.md D-29:
 *  - truncate_utf8 walks back to the last whole UTF-8 sequence boundary.
 *    No ellipsis is appended — caller signals truncation via a flag.
 *  - gzip/gunzip never block on failure — they return the input unchanged.
 */
final class Bytes {

	/**
	 * Truncate `$s` to at most `$max_bytes` bytes without splitting a UTF-8
	 * codepoint. Returns `$s` unchanged when it's already within the cap.
	 *
	 * @param string $s         Source string.
	 * @param int    $max_bytes Maximum byte length of the returned string.
	 */
	public static function truncate_utf8( string $s, int $max_bytes ): string {
		if ( $max_bytes <= 0 ) {
			return '';
		}
		if ( \strlen( $s ) <= $max_bytes ) {
			return $s;
		}

		$cut = \substr( $s, 0, $max_bytes );

		// Walk back over any trailing continuation bytes (10xxxxxx, mask 0xC0 == 0x80).
		$i = \strlen( $cut ) - 1;
		while ( $i >= 0 && ( \ord( $cut[ $i ] ) & 0xC0 ) === 0x80 ) {
			--$i;
		}

		// If the byte we landed on is a multi-byte lead (11xxxxxx, mask 0xC0 == 0xC0),
		// the sequence is incomplete — drop the lead byte too.
		if ( $i >= 0 && ( \ord( $cut[ $i ] ) & 0xC0 ) === 0xC0 ) {
			--$i;
		}

		return $i < 0 ? '' : \substr( $cut, 0, $i + 1 );
	}

	/**
	 * Format `$bytes` as a human-readable string (e.g. "1.0 KB", "500 B").
	 *
	 * @param int $bytes Size in bytes.
	 */
	public static function human_readable( int $bytes ): string {
		$units    = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$max_unit = \count( $units ) - 1;
		$size     = (float) $bytes;
		$unit     = 0;

		while ( $size >= 1024 && $unit < $max_unit ) {
			$size /= 1024;
			++$unit;
		}

		if ( 0 === $unit ) {
			return $bytes . ' ' . $units[0];
		}

		return \sprintf( '%.1f %s', $size, $units[ $unit ] );
	}

	/**
	 * Gzip-compress `$s` at PHP's default compression level (omitting the
	 * level argument selects zlib's internal default — currently 6).
	 * Returns the input unchanged on encoder failure — Phase 3's insert
	 * path must never block on compression.
	 *
	 * @param string $s Raw payload.
	 */
	public static function gzip( string $s ): string {
		$out = \gzencode( $s );
		return false === $out ? $s : $out;
	}

	/**
	 * Decompress a gzip payload. Non-gzip input is detected by the leading
	 * gzip magic bytes (0x1f 0x8b) and passed through unchanged — no `@`
	 * suppression, so genuine decode failure on a gzip-marked-but-corrupt
	 * payload still surfaces as a PHP warning instead of being silently
	 * masked. The "return input on failure" semantics are preserved for
	 * back-compat with callers that expect a string round-trip.
	 *
	 * @param string $s Gzip-compressed payload (or arbitrary string).
	 */
	public static function gunzip( string $s ): string {
		// Passthrough when the leading two bytes are not the gzip magic.
		// Avoids feeding non-gzip input to gzdecode (which would warn) and
		// keeps the "arbitrary string round-trip" contract intact.
		if ( \strlen( $s ) < 2 || "\x1f\x8b" !== \substr( $s, 0, 2 ) ) {
			return $s;
		}

		$out = \gzdecode( $s );
		return false === $out ? $s : $out;
	}
}
