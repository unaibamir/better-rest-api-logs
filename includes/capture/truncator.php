<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around Bytes::truncate_utf8 for the Capture pipeline.
 *
 * Adds the {body, bytes_original, truncated} tuple shape that Plan 03-08 needs
 * when assembling an Entry — original size is captured before truncation so the
 * reported byte count is always honest (CAP-08, T-03-18). Callers that need
 * D-15 ordering (redact before truncate) must redact first, then call truncate.
 *
 * @package BetterRestApiLogs\Capture
 */
final class Truncator {

	/**
	 * Truncate $body to at most $max_bytes bytes without splitting a UTF-8
	 * codepoint. Returns a tuple with the (possibly cut) body, the original
	 * byte count before any cutting, and a flag indicating whether cutting
	 * actually happened.
	 *
	 * $bytes_original is captured before the truncate call so it is always
	 * the true size of the input — it never changes after a cut (T-03-18).
	 *
	 * @param string $body      Raw string to truncate.
	 * @param int    $max_bytes Maximum byte length of the returned body.
	 * @return array{body: string, bytes_original: int, truncated: bool}
	 */
	public static function truncate( string $body, int $max_bytes ): array {
		$bytes_original = \strlen( $body );

		if ( $max_bytes < 0 ) {
			$max_bytes = 0;
		}

		$cut       = \BetterRestApiLogs\Support\Bytes::truncate_utf8( $body, $max_bytes );
		$truncated = \strlen( $cut ) !== $bytes_original;

		return [
			'body'           => $cut,
			'bytes_original' => $bytes_original,
			'truncated'      => $truncated,
		];
	}
}
