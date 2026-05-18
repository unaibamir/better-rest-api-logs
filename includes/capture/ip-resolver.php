<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the real client IP from a server variable array.
 *
 * Implements the D-11 algorithm: REMOTE_ADDR is the authoritative source when
 * no trusted proxies are configured. When the caller sets trusted_proxy_cidrs,
 * the resolver walks X-Forwarded-For (right to left) then RFC 7239 Forwarded,
 * skipping hops inside the trusted ranges. CF-Connecting-IP is honoured only
 * when the direct connection originates from a Cloudflare range (D-12).
 *
 * Consumers MUST set network.trusted_proxies before any X-F-F or Forwarded
 * header is trusted — the default empty list means REMOTE_ADDR only (IP-01).
 * The Cloudflare CIDR snapshot was taken on 2026-05-18; bump on each release
 * per D-12 (no runtime fetch — PROJECT.md PRIV-04 forbids external calls).
 *
 * Every result is a 16-byte v4-mapped binary string ready for VARBINARY(16)
 * storage so v4 and v6 share a single column with consistent byte ordering.
 *
 * @package BetterRestApiLogs\Capture
 */
final class IpResolver {

	/**
	 * Cloudflare published IPv4 ranges. Snapshot 2026-05-18.
	 *
	 * Source: https://www.cloudflare.com/ips-v4
	 * Refresh policy: bump on each release. No runtime fetch (PRIV-04).
	 */
	public const CLOUDFLARE_CIDRS_V4 = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	];

	/**
	 * Cloudflare published IPv6 ranges. Snapshot 2026-05-18.
	 *
	 * Source: https://www.cloudflare.com/ips-v6
	 */
	public const CLOUDFLARE_CIDRS_V6 = [
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * RFC 1918 / RFC 4193 private and link-local ranges (IP-05).
	 *
	 * Header-supplied IPs in any of these ranges are rejected unless the
	 * matching range is explicitly listed in trusted_proxy_cidrs.
	 */
	public const PRIVATE_CIDRS = [
		'127.0.0.0/8',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'169.254.0.0/16',
		'::1/128',
		'fc00::/7',
		'fe80::/10',
	];

	/**
	 * Resolve the real client IP for a request.
	 *
	 * The $server array is normally $_SERVER, injected by the Capture
	 * orchestrator so unit tests can pass any superglobal-shaped array.
	 * The $settings array must contain a 'network' key with sub-keys
	 * 'trusted_proxies' (array of CIDR strings) and 'cloudflare_cidrs'
	 * (array or empty to use the class constants — D-12).
	 *
	 * Returns ['resolved' => ?string, 'raw' => ?string] where both values
	 * are 16-byte v4-mapped packed strings, or null when REMOTE_ADDR is
	 * absent or not a valid IP (IP-01, IP-06).
	 *
	 * @param array<string,mixed> $server   Server variable array (usually $_SERVER).
	 * @param array<string,mixed> $settings Plugin settings array.
	 * @return array{resolved: ?string, raw: ?string}
	 */
	public static function resolve( array $server, array $settings ): array {
		$remote = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		$raw    = self::pack_ip( $remote );

		if ( null === $raw ) {
			return [
				'resolved' => null,
				'raw'      => null,
			];
		}

		// Default: both point to REMOTE_ADDR (IP-01).
		$result = [
			'resolved' => $raw,
			'raw'      => $raw,
		];

		$network       = isset( $settings['network'] ) && \is_array( $settings['network'] ) ? $settings['network'] : [];
		$trusted_cidrs = isset( $network['trusted_proxies'] ) && \is_array( $network['trusted_proxies'] )
			? $network['trusted_proxies']
			: [];
		$cf_cidrs_cfg  = isset( $network['cloudflare_cidrs'] ) && \is_array( $network['cloudflare_cidrs'] )
			? $network['cloudflare_cidrs']
			: [];

		// Fall back to built-in snapshot when caller supplies no overrides (D-12).
		$cloudflare_cidrs = $cf_cidrs_cfg !== []
			? $cf_cidrs_cfg
			: \array_merge( self::CLOUDFLARE_CIDRS_V4, self::CLOUDFLARE_CIDRS_V6 );

		// CF-Connecting-IP path: trusted only when the connection itself is from Cloudflare (IP-03).
		if ( self::match_any( $raw, $cloudflare_cidrs ) && isset( $server['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = self::pack_ip( (string) $server['HTTP_CF_CONNECTING_IP'] );
			if ( null !== $cf && ! self::is_private( $cf, $trusted_cidrs ) ) {
				return [
					'resolved' => $cf,
					'raw'      => $raw,
				];
			}
		}

		// X-Forwarded-For / Forwarded path: only when REMOTE_ADDR is a trusted proxy (IP-02, IP-04).
		if ( ! self::match_any( $raw, $trusted_cidrs ) ) {
			return $result;
		}

		// RFC 7239 Forwarded takes precedence over X-Forwarded-For when both are present.
		if ( isset( $server['HTTP_FORWARDED'] ) && '' !== (string) $server['HTTP_FORWARDED'] ) {
			$hops     = self::parse_forwarded( (string) $server['HTTP_FORWARDED'] );
			$resolved = self::walk_right_to_left( $hops, $trusted_cidrs );
			if ( null !== $resolved ) {
				return [
					'resolved' => $resolved,
					'raw'      => $raw,
				];
			}
		} elseif ( isset( $server['HTTP_X_FORWARDED_FOR'] ) && '' !== (string) $server['HTTP_X_FORWARDED_FOR'] ) {
			$parts    = \explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] );
			$hops     = \array_map( 'trim', $parts );
			$resolved = self::walk_right_to_left( $hops, $trusted_cidrs );
			if ( null !== $resolved ) {
				return [
					'resolved' => $resolved,
					'raw'      => $raw,
				];
			}
		}

		return $result;
	}

	/**
	 * Pack an IP address string into a 16-byte v4-mapped binary string.
	 *
	 * IPv4 addresses are padded to the 16-byte IPv4-mapped IPv6 form
	 * (::ffff:a.b.c.d) so every value occupies exactly VARBINARY(16) (IP-06).
	 * Returns null for any input that is not a valid IP literal.
	 *
	 * @param string $ip Dotted-decimal IPv4 or colon-hex IPv6 address.
	 * @return string|null 16-byte binary string, or null on invalid input.
	 */
	public static function pack_ip( string $ip ): ?string {
		$ip = \trim( $ip );

		if ( false === \filter_var( $ip, \FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = \inet_pton( $ip );
		if ( false === $packed ) {
			// Defensive: filter_var passed but inet_pton failed (shouldn't happen).
			return null;
		}

		if ( 4 === \strlen( $packed ) ) {
			$packed = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $packed;
		}

		return $packed;
	}

	/**
	 * Test whether a packed IP address falls within a CIDR block.
	 *
	 * Both v4 and v6 addresses are supported. IPv4 inputs and nets are
	 * normalised to the 16-byte v4-mapped form before the bitwise comparison
	 * so a single code path handles both families (IP-05 guard reuses this).
	 *
	 * @param string $packed_ip 16-byte packed IP (from pack_ip()).
	 * @param string $cidr      CIDR notation string, e.g. '10.0.0.0/8'.
	 * @return bool True when $packed_ip falls within $cidr.
	 */
	public static function ip_in_cidr( string $packed_ip, string $cidr ): bool {
		if ( \strlen( $packed_ip ) !== 16 ) {
			return false;
		}

		$parts = \explode( '/', $cidr, 2 );
		$net   = $parts[0];
		$bits  = isset( $parts[1] ) ? (int) $parts[1] : null;

		// Detect whether the net is IPv4 (no colon) or IPv6.
		$is_v4_net  = false === \strpos( $net, ':' );
		$packed_net = \inet_pton( $net );
		if ( false === $packed_net ) {
			return false;
		}

		if ( 4 === \strlen( $packed_net ) ) {
			// Pad to 16-byte v4-mapped form and translate prefix length.
			$packed_net = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $packed_net;
			$bits       = ( null === $bits ? 32 : $bits ) + 96;
		} else {
			$bits = null === $bits ? 128 : $bits;
		}

		unset( $is_v4_net );

		if ( $bits < 0 || $bits > 128 ) {
			return false;
		}

		$whole_bytes = (int) ( $bits / 8 );
		$remainder   = $bits % 8;

		if (
			$whole_bytes > 0
			&& \substr( $packed_ip, 0, $whole_bytes ) !== \substr( $packed_net, 0, $whole_bytes )
		) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask = \chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );

		return ( $packed_ip[ $whole_bytes ] & $mask ) === ( $packed_net[ $whole_bytes ] & $mask );
	}

	/**
	 * Parse an RFC 7239 Forwarded header into an ordered list of IP strings.
	 *
	 * Returns the hops in left-to-right order (same direction as the header).
	 * Callers that need right-to-left traversal must reverse the array. Brackets,
	 * optional port suffixes, and surrounding quotes are stripped from each value
	 * so the returned strings are bare IP literals ready for pack_ip() (IP-04).
	 *
	 * @param string $header Raw Forwarded header value.
	 * @return list<string> IP strings in left-to-right order.
	 */
	public static function parse_forwarded( string $header ): array {
		$hops = \explode( ',', $header );
		$out  = [];

		foreach ( $hops as $hop ) {
			$kvs = \explode( ';', \trim( $hop ) );
			foreach ( $kvs as $kv ) {
				$kv = \trim( $kv );
				// Only process 'for=' directives; stripos offset 0 means it starts with 'for='.
				if ( 0 !== \stripos( $kv, 'for=' ) ) {
					continue;
				}
				$val = \substr( $kv, 4 );

				// Strip surrounding quotes first.
				$val = \trim( $val, '"' );

				if ( '' !== $val && '[' === $val[0] ) {
					// IPv6 in brackets: "[2001:db8::1]:port" → "2001:db8::1".
					$end = \strrpos( $val, ']' );
					$val = false === $end
						? \ltrim( $val, '[' )
						: \substr( $val, 1, $end - 1 );
				} elseif ( 1 === \substr_count( $val, ':' ) ) {
					// IPv4 with optional port: "1.2.3.4:5678" → "1.2.3.4".
					// Only strip when there is exactly one colon (port suffix on v4).
					$colon = \strpos( $val, ':' );
					if ( false !== $colon ) {
						$val = \substr( $val, 0, $colon );
					}
				}

				$out[] = $val;
				break; // Only one 'for=' per hop entry.
			}
		}

		return $out;
	}

	/**
	 * Walk a hop list and return the first non-trusted, non-private IP.
	 *
	 * Hops are given in left-to-right order (leftmost = original client).
	 * The walk starts from the left because XFF and Forwarded semantics agree:
	 * the real client IP is the leftmost entry that is neither a trusted proxy
	 * nor a private-range address (when the private range isn't explicitly trusted).
	 *
	 * Returns a 16-byte packed string on success, null when every hop is
	 * trusted or private (caller falls back to REMOTE_ADDR in that case).
	 *
	 * @param array<string> $hops          IP strings in left-to-right order.
	 * @param array<string> $trusted_cidrs Trusted proxy CIDR list.
	 * @return string|null Packed IP or null.
	 */
	private static function walk_right_to_left( array $hops, array $trusted_cidrs ): ?string {
		foreach ( $hops as $hop ) {
			$packed = self::pack_ip( \trim( (string) $hop ) );
			if ( null === $packed ) {
				continue;
			}
			// Skip hops that are themselves trusted proxies.
			if ( self::match_any( $packed, $trusted_cidrs ) ) {
				continue;
			}
			// Reject private-range IPs unless the range is explicitly trusted.
			if ( self::is_private( $packed, $trusted_cidrs ) ) {
				continue;
			}
			return $packed;
		}
		return null;
	}

	/**
	 * Return true when $packed_ip falls inside any of $cidrs.
	 *
	 * @param string        $packed_ip 16-byte packed IP.
	 * @param array<string> $cidrs     CIDR list.
	 */
	private static function match_any( string $packed_ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::ip_in_cidr( $packed_ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return true when $packed_ip is in a private/link-local range AND that
	 * range is not explicitly listed in $trusted_cidrs (IP-05).
	 *
	 * @param string        $packed_ip     16-byte packed IP.
	 * @param array<string> $trusted_cidrs Trusted proxy CIDR list.
	 */
	private static function is_private( string $packed_ip, array $trusted_cidrs ): bool {
		foreach ( self::PRIVATE_CIDRS as $private_cidr ) {
			if ( ! self::ip_in_cidr( $packed_ip, $private_cidr ) ) {
				continue;
			}
			// The IP is private — accepted only if the exact private range is trusted.
			if ( ! self::match_any( $packed_ip, $trusted_cidrs ) ) {
				return true;
			}
		}
		return false;
	}
}
