<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Cursor pagination — opaque created_at_micros+id token.
 *
 * Encodes a (created_at_micros, id) pair as a base64url-safe opaque token
 * so the REST API can page through logs without OFFSET or COUNT(*). Callers
 * treat the token as an opaque string; the internal layout (JSON keys "c"/"i")
 * is an implementation detail that can change between major versions.
 */
final class Paginator {

	/**
	 * Encode a cursor position into an opaque URL-safe token.
	 *
	 * @param int $micros created_at_micros value of the last row on the current page.
	 * @param int $id     Primary-key id of that row (tie-breaker when two rows share a micros value).
	 * @return string Base64url string with no padding characters.
	 */
	public static function encode( int $micros, int $id ): string {
		// JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES keep the payload compact;
		// integers stay as integers in the encoded string so json_decode restores
		// them exactly on 64-bit PHP without any BIGINT flag.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the cursor token must be an opaque, deterministic byte string. wp_json_encode is a wrapper that can apply filters and returns false on encoding failure with no diagnostic — both unacceptable here.
		$json = \json_encode(
			array(
				'c' => $micros,
				'i' => $id,
			),
			\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
		);

		// json_encode on integer arrays cannot return false, but we guard anyway.
		if ( false === $json ) {
			$json = '{"c":' . $micros . ',"i":' . $id . '}';
		}

		// base64url: replace standard alphabet chars that are unsafe in URLs,
		// then strip the trailing padding ('=').
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is the standard cursor-token format; the value is non-secret pagination state, not obfuscated code.
		return \rtrim( \strtr( \base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode a cursor token back into its component integers.
	 *
	 * @param  string $token Opaque token produced by encode().
	 * @return array{micros:int,id:int}
	 * @throws \InvalidArgumentException When the token is empty, not valid base64url,
	 *                                   or does not contain the expected JSON structure.
	 */
	public static function decode( string $token ): array {
		if ( '' === $token ) {
			throw new \InvalidArgumentException( 'Cursor token must not be empty.' );
		}

		// Restore standard base64 padding before decoding.
		$padded = $token . \str_repeat( '=', ( 4 - \strlen( $token ) % 4 ) % 4 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the base64url cursor token produced by encode(); the strict flag rejects malformed input.
		$raw = \base64_decode( \strtr( $padded, '-_', '+/' ), true );

		if ( false === $raw ) {
			throw new \InvalidArgumentException( 'Cursor token is not valid base64url.' );
		}

		$data = \json_decode( $raw, true );

		if (
			! \is_array( $data )
			|| ! \array_key_exists( 'c', $data )
			|| ! \array_key_exists( 'i', $data )
			|| ! \is_int( $data['c'] )
			|| ! \is_int( $data['i'] )
		) {
			throw new \InvalidArgumentException( 'Cursor token does not contain the expected structure.' );
		}

		return array(
			'micros' => $data['c'],
			'id'     => $data['i'],
		);
	}
}
