<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

// CONTEXT.md D-41 — canonical shape of every filter set for the log list.

/**
 * Canonical shape of every filter set for the log list — Admin, REST, and CLI converge on this object.
 *
 * Public typed properties map 1:1 to the filter fields the list surfaces expose. Immutable
 * by convention: callers construct via {@see self::from_array()} and read typed properties
 * directly. No WordPress functions are referenced here — keeping this layer unit-testable
 * without bootstrapping WP.
 */
final class QueryArgs {

	// Whitelist constants — private so callers use from_array(), not reach inside the gate.
	private const ALLOWED_METHODS        = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD' ];
	private const ALLOWED_STATUS_CLASSES = [ '1xx', '2xx', '3xx', '4xx', '5xx' ];
	private const STATUS_MIN             = 100;
	private const STATUS_MAX             = 599;
	private const LIMIT_DEFAULT          = 20;
	private const LIMIT_MAX              = 200;

	public ?string $method        = null;   // 'GET'|'POST'|...
	public ?int $status           = null;   // 100-599
	public ?string $status_class  = null;   // '1xx'|'2xx'|'3xx'|'4xx'|'5xx'
	public ?string $route_prefix  = null;   // exact prefix match, e.g. '/wp/v2/'
	public ?int $user_id          = null;
	public ?string $ip            = null;   // IPv4/IPv6 literal — packed via inet_pton before binding
	public ?int $date_from_micros = null;
	public ?int $date_to_micros   = null;
	public ?string $free_text     = null;   // matched against route column
	public ?string $cursor        = null;   // base64url Paginator token
	public int $limit             = self::LIMIT_DEFAULT;   // capped server-side at 200
	public string $order_by       = 'created_at';          // 'created_at'|'duration_ms'
	public string $order_dir      = 'DESC';                // 'ASC'|'DESC'

	/**
	 * Build a QueryArgs from a raw associative array (URL args, REST params, CLI assoc_args).
	 *
	 * Each field is sanitised and validated at this boundary. Callers (REST controller,
	 * admin filter bar, CLI command) are responsible for wp_unslash() + sanitize_text_field()
	 * on $_GET / $_POST before feeding into this method — that keeps this class pure-PHP
	 * and unit-testable without WP boot.
	 *
	 * Integer fields use preg_match('/^-?\d+$/', trim(...)) — never is_numeric(), which
	 * accepts '3.14', '1e2', and whitespace-padded strings (CLAUDE.md hard rule).
	 *
	 * @param  array<string,mixed> $input Raw input array.
	 * @return self
	 * @throws \InvalidArgumentException On invalid field values.
	 */
	public static function from_array( array $input ): self {
		$a = new self();

		// method — whitelist after upper-case normalisation.
		if ( isset( $input['method'] ) ) {
			$m = strtoupper( trim( (string) $input['method'] ) );
			if ( ! in_array( $m, self::ALLOWED_METHODS, true ) ) {
				throw new \InvalidArgumentException( 'Unknown HTTP method.' );
			}
			$a->method = $m;
		}

		// status — integer-only via preg_match (rejects '3.14', '1e2', ' 5 '), then 100..599 range.
		if ( isset( $input['status'] ) ) {
			$s = (string) $input['status'];
			if ( 1 !== preg_match( '/^-?\d+$/', $s ) ) {
				throw new \InvalidArgumentException( 'Invalid status value.' );
			}
			$n = (int) $s;
			if ( $n < self::STATUS_MIN || $n > self::STATUS_MAX ) {
				throw new \InvalidArgumentException( 'Status out of range 100-599.' );
			}
			$a->status = $n;
		}

		// status_class — exact-match whitelist.
		if ( isset( $input['status_class'] ) ) {
			$c = (string) $input['status_class'];
			if ( ! in_array( $c, self::ALLOWED_STATUS_CLASSES, true ) ) {
				throw new \InvalidArgumentException( 'Unknown status class.' );
			}
			$a->status_class = $c;
		}

		// route_prefix — sanitised string, empty → null.
		$a->route_prefix = self::sanitise_string( $input['route_prefix'] ?? null );

		// user_id — non-negative integer-only via preg_match.
		if ( isset( $input['user_id'] ) ) {
			$u = (string) $input['user_id'];
			if ( 1 !== preg_match( '/^-?\d+$/', $u ) ) {
				throw new \InvalidArgumentException( 'Invalid user_id value.' );
			}
			$n = (int) $u;
			if ( $n < 0 ) {
				throw new \InvalidArgumentException( 'user_id must be non-negative.' );
			}
			$a->user_id = $n;
		}

		// ip — sanitised string; literal validation deferred to QueryBuilder via inet_pton.
		$a->ip = self::sanitise_string( $input['ip'] ?? null );

		// date_from_micros / date_to_micros — integer-only, no upper bound.
		foreach ( [ 'date_from_micros', 'date_to_micros' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$d = (string) $input[ $key ];
				if ( 1 !== preg_match( '/^-?\d+$/', $d ) ) {
					throw new \InvalidArgumentException( "Invalid {$key} value." );
				}
				$a->{$key} = (int) $d;
			}
		}

		// free_text + cursor — sanitised strings; cursor decode deferred to Paginator in Plan 04-05.
		$a->free_text = self::sanitise_string( $input['free_text'] ?? null );
		$a->cursor    = self::sanitise_string( $input['cursor'] ?? null );

		// limit — integer 1..200; values outside 1..LIMIT_MAX clamp to default rather than error,
		// because over-the-cap requests (e.g. limit=500 from a copied URL) should still get a
		// usable result. Scientific notation / non-integers DO throw — they indicate a malformed request.
		if ( isset( $input['limit'] ) ) {
			$l = (string) $input['limit'];
			if ( 1 !== preg_match( '/^-?\d+$/', $l ) ) {
				throw new \InvalidArgumentException( 'Invalid limit value.' );
			}
			$n = (int) $l;
			if ( $n >= 1 && $n <= self::LIMIT_MAX ) {
				$a->limit = $n;
			} elseif ( $n > self::LIMIT_MAX ) {
				// Cap silently — callers with bookmarked URLs get the max page size, not an error.
				$a->limit = self::LIMIT_MAX;
			}
			// limit < 1 falls back to the property default (LIMIT_DEFAULT).
		}

		// order_by + order_dir — delegated to Sort::validate (D-43). Invalid sort input propagates
		// as \InvalidArgumentException — bad bookmark URLs surface a clear error rather than
		// silently showing a default-sorted list (plan 04-03 pitfall #2 discusses the trade-off;
		// the tests assert the throw behaviour).
		$by  = isset( $input['order_by'] ) ? (string) $input['order_by'] : $a->order_by;
		$dir = isset( $input['order_dir'] ) ? (string) $input['order_dir'] : $a->order_dir;
		// Only call Sort::validate if the caller supplied order_by or order_dir explicitly.
		if ( isset( $input['order_by'] ) || isset( $input['order_dir'] ) ) {
			$sort         = Sort::validate( $by, $dir );
			$a->order_by  = $sort['column'];
			$a->order_dir = $sort['dir'];
		}

		return $a;
	}

	/**
	 * Serialise non-default + non-null fields to a query-var array.
	 *
	 * The three pagination defaults (limit, order_by, order_dir) are always included
	 * so cursor URLs are unambiguous — a future reader sees the exact search shape
	 * that produced the page, not just the filter overrides.
	 *
	 * @return array<string,mixed>
	 */
	public function to_query_var_array(): array {
		$out = [
			'limit'     => $this->limit,
			'order_by'  => $this->order_by,
			'order_dir' => $this->order_dir,
		];
		foreach ( [ 'method', 'status', 'status_class', 'route_prefix', 'user_id', 'ip', 'date_from_micros', 'date_to_micros', 'free_text', 'cursor' ] as $key ) {
			if ( null !== $this->{$key} ) {
				$out[ $key ] = $this->{$key};
			}
		}
		return $out;
	}

	/**
	 * Trim and return a string, or null if empty.
	 *
	 * @param  mixed $value
	 * @return string|null
	 */
	private static function sanitise_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$s = trim( (string) $value );
		return '' === $s ? null : $s;
	}
}
