<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Migration;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Domain\Entry;

/**
 * Converts one SourceEntry into a Domain\Entry plus a meta array.
 *
 * Pure PHP — no $wpdb, no apply_filters, no get_option. No redaction is
 * performed here; the Importer re-redacts imported bodies via Capture\Redactor
 * before insert (D-06), keeping this class framework-free and unit-testable.
 *
 * Two accepted design resolutions:
 *   OQ1 — user_id: integer-only strings become int user_id; anything else → 0.
 *         Uses preg_match (not is_numeric) per CLAUDE.md: is_numeric accepts
 *         '3.14', '1e2', whitespace-padded strings which are not valid WP user IDs.
 *   OQ2 — request body: prefer the raw _request_body field over parsed
 *         request_body_params rows — raw bytes are more faithful for re-redaction.
 *
 * Imported rows carry second-resolution timestamps (created_at_micros = 0) because
 * the upstream wp-rest-api-log plugin stored only post_date (MySQL DATETIME, no
 * sub-second component).
 */
final class Mapper {

	/**
	 * Map a SourceEntry to an Entry DTO plus a meta array.
	 *
	 * @param  SourceEntry $s Raw upstream fields (already unslashed by the Reader).
	 * @return array{ entry: Entry, meta: array<string,mixed> }
	 */
	public static function map( SourceEntry $s ): array {
		$e = new Entry();

		// Timestamps — second resolution only; no sub-second data in upstream.
		$e->created_at        = $s->created_at;
		$e->created_at_micros = 0;

		// Request identity.
		$e->method       = $s->method;
		$e->route        = $s->route;
		$e->route_prefix = self::derive_route_prefix( $s->route );
		$e->query_string = null;

		// Response status; derive status_class so filter-by-class works on imported rows.
		$status          = (int) $s->status;
		$e->status       = $status;
		$e->status_class = $status >= 100 ? \intdiv( $status, 100 ) : 0;

		// Timing.
		$e->duration_ms = (int) $s->duration_ms;

		// User identity — OQ1: integer-only string → int user_id; anything else → 0.
		$user_str = \trim( $s->user );
		if ( $user_str !== '' && \preg_match( '/^\d+$/', $user_str ) ) {
			$e->user_id = (int) $user_str;
		} else {
			$e->user_id = 0;
		}

		// Network — raw IP string from _ip-address meta; packed storage happens in Importer.
		$e->ip_resolved   = $s->ip_address !== '' ? $s->ip_address : null;
		$e->ip_raw_remote = null;

		// Bodies — OQ2: prefer raw _request_body over parsed request_body_params.
		$e->request_body  = $s->request_body !== '' ? $s->request_body : null;
		$e->response_body = $s->response_body !== '' ? $s->response_body : null;

		// Header arrays → JSON strings for the columns.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure-PHP tier; no WP functions permitted here.
		$e->request_headers = $s->request_headers !== [] ? \json_encode( $s->request_headers ) : null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure-PHP tier; no WP functions permitted here.
		$e->response_headers = $s->response_headers !== [] ? \json_encode( $s->response_headers ) : null;

		// Content types are not stored separately upstream; default to empty.
		$e->request_content_type  = '';
		$e->response_content_type = '';

		// Byte counts will be computed by the Importer after re-redaction; default 0.
		$e->request_body_bytes      = $s->request_body !== '' ? \strlen( $s->request_body ) : 0;
		$e->response_body_bytes     = $s->response_body !== '' ? \strlen( $s->response_body ) : 0;
		$e->request_body_truncated  = false;
		$e->response_body_truncated = false;
		$e->bodies_spilled          = false;

		// Migration provenance — raw int post ID so the UNIQUE constraint deduplicates re-runs.
		$e->migration_source_id = $s->source_id;

		return [
			'entry' => $e,
			'meta'  => [],
		];
	}

	/**
	 * Derive a route prefix from the first two segments of a REST route.
	 *
	 * /wp/v2/posts → wp/v2  |  /better-rest-api-logs/v1/logs → better-rest-api-logs/v1
	 *
	 * @param  string $route Full REST route (e.g. '/wp/v2/posts').
	 * @return string        Namespace/version prefix, or empty string if not derivable.
	 */
	private static function derive_route_prefix( string $route ): string {
		$parts = \explode( '/', \ltrim( $route, '/' ), 3 );
		if ( isset( $parts[0], $parts[1] ) && $parts[0] !== '' ) {
			return $parts[0] . '/' . $parts[1];
		}
		return '';
	}
}
