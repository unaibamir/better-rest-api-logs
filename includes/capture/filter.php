<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Request-level capture gate.
 *
 * Implements the D-09 pre-dispatch ordering and D-10 post-dispatch status-class
 * filter. Stateless and pure-PHP — no WP function calls, no $wpdb, no options.
 * The Capture orchestrator (Plan 03-08) calls these gates from on_pre_dispatch
 * and on_shutdown after resolving the IP via IpResolver.
 *
 * D-09 step ordering enforced by should_capture_at_pre_dispatch:
 *   1. capture.enabled master toggle (FILT-01) — early-return for zero overhead.
 *   2. Self-namespace skip — /better-rest-api-logs/v1 routes never logged (D-09 step 2).
 *   3. Method filter — empty list captures all methods (FILT-05).
 *   4. Route allow/deny — deny wins on conflict (FILT-02, FILT-03).
 *   5. CIDR allow/deny via resolved packed IP — deny wins on conflict (FILT-03).
 *
 * D-10 enforced by should_capture_at_shutdown (status-class filter, FILT-05).
 * D-17 enforced by is_auth_endpoint (PRIV-06 auth-endpoint body suppression).
 *
 * @package BetterRestApiLogs\Capture
 */
final class Filter {

	/**
	 * Decide whether to capture a request at the pre-dispatch stage.
	 *
	 * The $request parameter is intentionally untyped so unit tests can pass
	 * a plain stdClass stub without bootstrapping WordPress. Production callers
	 * always pass a real WP_REST_Request. The internal helpers use method_exists
	 * so both shapes work transparently.
	 *
	 * @param mixed               $request           WP_REST_Request-shaped object.
	 * @param array<string,mixed> $settings           Tab-keyed settings array.
	 * @param string|null         $resolved_packed_ip 16-byte packed IP from IpResolver; null skips CIDR check.
	 */
	public static function should_capture_at_pre_dispatch( $request, array $settings, ?string $resolved_packed_ip = null ): bool {
		// Step 1 (FILT-01): master toggle — early-return before any allocations.
		if ( empty( $settings['capture']['enabled'] ) ) {
			return false;
		}

		$route = self::extract_route( $request );

		// Step 2 (D-09 step 2 — CAP-05): skip own REST namespace unconditionally.
		if ( 0 === \strpos( $route, '/better-rest-api-logs/v1' ) ) {
			return false;
		}

		// Step 3 (D-09 step 4 — FILT-05 method filter): empty list = capture all.
		$methods = (array) ( $settings['capture']['methods'] ?? [] );
		if ( $methods !== [] ) {
			$method = \strtoupper( self::extract_method( $request ) );
			if ( ! \in_array( $method, \array_map( 'strtoupper', $methods ), true ) ) {
				return false;
			}
		}

		// Step 4 (D-09 step 5 — FILT-02 route filter): deny wins on conflict.
		$route_allowlist = (array) ( $settings['capture']['route_allowlist'] ?? [] );
		$route_denylist  = (array) ( $settings['capture']['route_denylist'] ?? [] );

		// Deny check runs first so deny wins when both lists match the same route.
		foreach ( $route_denylist as $glob ) {
			if ( 1 === \preg_match( self::glob_to_regex( (string) $glob ), $route ) ) {
				return false;
			}
		}

		// Non-empty allowlist: route must match at least one pattern.
		if ( $route_allowlist !== [] ) {
			$allowed = false;
			foreach ( $route_allowlist as $glob ) {
				if ( 1 === \preg_match( self::glob_to_regex( (string) $glob ), $route ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return false;
			}
		}

		// Step 5 (D-09 step 6 — FILT-03 CIDR filter): deny wins on conflict.
		if ( null !== $resolved_packed_ip ) {
			$cidr_denylist  = (array) ( $settings['capture']['cidr_denylist'] ?? [] );
			$cidr_allowlist = (array) ( $settings['capture']['cidr_allowlist'] ?? [] );

			if ( $cidr_denylist !== [] || $cidr_allowlist !== [] ) {
				foreach ( $cidr_denylist as $cidr ) {
					if ( IpResolver::ip_in_cidr( $resolved_packed_ip, (string) $cidr ) ) {
						return false;
					}
				}

				if ( $cidr_allowlist !== [] ) {
					$cidr_allowed = false;
					foreach ( $cidr_allowlist as $cidr ) {
						if ( IpResolver::ip_in_cidr( $resolved_packed_ip, (string) $cidr ) ) {
							$cidr_allowed = true;
							break;
						}
					}
					if ( ! $cidr_allowed ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Decide whether to store a response at the shutdown stage.
	 *
	 * Implements D-10: empty status_class_filter captures every response code.
	 * Populated list captures only responses whose status class (1xx..5xx) is
	 * in the list. Status class = floor($status / 100).
	 *
	 * @param int                 $status   HTTP response status code.
	 * @param array<string,mixed> $settings Tab-keyed settings array.
	 */
	public static function should_capture_at_shutdown( int $status, array $settings ): bool {
		$classes = (array) ( $settings['capture']['status_class_filter'] ?? [] );
		if ( $classes === [] ) {
			return true;
		}
		$class = (int) \floor( $status / 100 );
		return \in_array( $class, \array_map( 'intval', $classes ), true );
	}

	/**
	 * Return true when $route matches any path in the auth-endpoint suppression list.
	 *
	 * Reuses the same glob_to_regex helper as route_allowlist/route_denylist —
	 * one implementation, two call sites per RESEARCH recommendation #3. The
	 * Capture orchestrator (Plan 03-08) calls this to suppress body capture for
	 * auth routes (PRIV-06, D-17).
	 *
	 * @param string        $route REST route path.
	 * @param array<string> $paths Auth-endpoint suppression list (glob patterns).
	 */
	public static function is_auth_endpoint( string $route, array $paths ): bool {
		foreach ( $paths as $path ) {
			if ( 1 === \preg_match( self::glob_to_regex( (string) $path ), $route ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert a glob pattern to a PCRE regex string.
	 *
	 * Metacharacters in the glob are quoted via preg_quote so a literal dot in
	 * the pattern matches only a dot (not any character). The single wildcard
	 * character * is then substituted with .* so it matches any sub-path
	 * segment. No nested quantifiers or backreferences are introduced —
	 * worst-case time is linear (T-03-16 mitigation).
	 *
	 * @param string $glob Glob pattern, e.g. '/wp/v2/*'.
	 * @return string PCRE regex string anchored at both ends, delimiter '#'.
	 */
	public static function glob_to_regex( string $glob ): string {
		$quoted = \preg_quote( $glob, '#' );
		$regex  = \str_replace( '\\*', '.*', $quoted );
		return '#^' . $regex . '$#';
	}

	/**
	 * Extract the route string from a WP_REST_Request-shaped object.
	 *
	 * Prefers the get_route() method (production WP_REST_Request). Falls back
	 * to the _route property used by unit-test stubs to avoid coupling tests
	 * to WordPress.
	 *
	 * @param mixed $request WP_REST_Request or test stub.
	 */
	private static function extract_route( $request ): string {
		if ( \is_object( $request ) && \method_exists( $request, 'get_route' ) ) {
			return (string) $request->get_route();
		}
		if ( \is_object( $request ) && isset( $request->_route ) ) {
			return (string) $request->_route;
		}
		return '';
	}

	/**
	 * Extract the HTTP method from a WP_REST_Request-shaped object.
	 *
	 * Prefers the get_method() method (production WP_REST_Request). Falls back
	 * to the _method property used by unit-test stubs.
	 *
	 * @param mixed $request WP_REST_Request or test stub.
	 */
	private static function extract_method( $request ): string {
		if ( \is_object( $request ) && \method_exists( $request, 'get_method' ) ) {
			return (string) $request->get_method();
		}
		if ( \is_object( $request ) && isset( $request->_method ) ) {
			return (string) $request->_method;
		}
		return '';
	}
}
