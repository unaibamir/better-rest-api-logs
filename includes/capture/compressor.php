<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in gzip wrapper for log body storage.
 *
 * Compression only fires when the caller opts in AND the body is at least
 * COMPRESSION_BREAKEVEN_BYTES bytes — below that threshold gzip headers and
 * entropy overhead produce output larger than the input (CAP-10, RESEARCH
 * recommendation #2, user pre-accepted). Default is opt-out so sites that
 * don't need it pay nothing.
 *
 * @package BetterRestApiLogs\Capture
 */
final class Compressor {

	/**
	 * Minimum body size in bytes before gzip compression is attempted.
	 *
	 * Below this threshold gzip framing overhead exceeds the size reduction
	 * for typical JSON payloads. Constant so callers can reference it without
	 * coupling to any settings layer. Value locked per RESEARCH recommendation #2.
	 */
	public const COMPRESSION_BREAKEVEN_BYTES = 1024;

	/**
	 * Compress $body when the caller has opted in and the size justifies it.
	 *
	 * Returns the input unchanged when:
	 *  - $opt_in is false (CAP-10 default OFF), OR
	 *  - strlen($body) < COMPRESSION_BREAKEVEN_BYTES (overhead > saving).
	 *
	 * When Bytes::gzip fails (encoder error) it returns the input unchanged;
	 * if the compressed output is somehow not smaller than the input, this
	 * method falls back to the original too — cheap insurance against edge
	 * cases on adversarial or already-compressed payloads.
	 *
	 * @param string $body   Body string to (maybe) compress.
	 * @param bool   $opt_in True when gzip storage is enabled in settings.
	 */
	public static function maybe_compress( string $body, bool $opt_in ): string {
		if ( ! $opt_in ) {
			return $body;
		}

		if ( \strlen( $body ) < self::COMPRESSION_BREAKEVEN_BYTES ) {
			return $body;
		}

		$compressed = \BetterRestApiLogs\Support\Bytes::gzip( $body );

		// Bytes::gzip returns input on encoder failure. Guard the uncommon case
		// where a pre-compressed or adversarial payload expands under gzip.
		if ( \strlen( $compressed ) >= \strlen( $body ) ) {
			return $body;
		}

		return $compressed;
	}
}
