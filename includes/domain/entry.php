<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Captured REST request/response — one row of `brl_logs` as a pure-PHP value object.
 *
 * Public typed properties map 1:1 to columns defined in CONTEXT.md D-15. Immutable
 * by convention per D-24/D-25: do not mutate after construction; build a fresh
 * Entry via {@see self::from_snapshots()} or {@see self::from_array()} when state
 * needs to change. No WordPress functions are referenced in this file — keeping
 * the DTO layer unit-testable without bootstrapping WP.
 *
 * Property groups (logical, not enforced):
 *  - Identity + timestamps: id, created_at, created_at_micros
 *  - Request meta: method, route, route_prefix, query_string
 *  - Response status: status, status_class, duration_ms
 *  - Identity / network: user_id, ip_resolved, ip_raw_remote (16-byte packed via inet_pton)
 *  - Request body group: request_content_type, request_headers (JSON), request_body, request_body_bytes, request_body_truncated
 *  - Response body group: same shape, response_* prefix
 *  - Flags + migration: bodies_spilled, migration_source_id
 */
final class Entry {

	public int $id                       = 0;
	public string $created_at            = '';
	public int $created_at_micros        = 0;
	public string $method                = '';
	public string $route                 = '';
	public string $route_prefix          = '';
	public ?string $query_string         = null;
	public int $status                   = 0;
	public int $status_class             = 0;
	public int $duration_ms              = 0;
	public int $user_id                  = 0;
	public ?string $ip_resolved          = null;
	public ?string $ip_raw_remote        = null;
	public string $request_content_type  = '';
	public ?string $request_headers      = null;
	public ?string $request_body         = null;
	public int $request_body_bytes       = 0;
	public bool $request_body_truncated  = false;
	public string $response_content_type = '';
	public ?string $response_headers     = null;
	public ?string $response_body        = null;
	public int $response_body_bytes      = 0;
	public bool $response_body_truncated = false;
	public bool $bodies_spilled          = false;
	public ?string $migration_source_id  = null;

	/**
	 * Merge a request + response snapshot pair into a new Entry.
	 *
	 * Phase 3's Redactor / Truncator / Compressor fill headers and bodies on the
	 * returned Entry after construction — this factory only sets the shape.
	 *
	 * @param RequestSnapshot     $req     Captured at rest_pre_dispatch.
	 * @param ResponseSnapshot    $res     Captured at rest_post_dispatch.
	 * @param array<string,mixed> $context Extras from the Logger (created_at, user_id, ip_*, bodies_spilled, migration_source_id).
	 * @return self
	 */
	public static function from_snapshots( RequestSnapshot $req, ResponseSnapshot $res, array $context ): self {
		$e                        = new self();
		$e->created_at            = (string) ( $context['created_at'] ?? '' );
		$e->created_at_micros     = (int) $req->started_at_micros;
		$e->method                = $req->method;
		$e->route                 = $req->route;
		$e->route_prefix          = $req->route_prefix;
		$e->query_string          = $req->query_string;
		$e->status                = $res->status;
		$e->status_class          = (int) \floor( $res->status / 100 );
		$e->duration_ms           = (int) \round( ( $res->finished_at_micros - $req->started_at_micros ) / 1000 );
		$e->user_id               = (int) ( $context['user_id'] ?? 0 );
		$e->ip_resolved           = isset( $context['ip_resolved'] ) ? (string) $context['ip_resolved'] : null;
		$e->ip_raw_remote         = isset( $context['ip_raw_remote'] ) ? (string) $context['ip_raw_remote'] : null;
		$e->request_content_type  = $req->content_type;
		$e->response_content_type = $res->content_type;
		$e->bodies_spilled        = (bool) ( $context['bodies_spilled'] ?? false );
		$e->migration_source_id   = isset( $context['migration_source_id'] ) ? (string) $context['migration_source_id'] : null;
		return $e;
	}

	/**
	 * Serialise to an associative array keyed by `brl_logs` column name.
	 *
	 * Booleans are cast to 1/0 so callers can hand the array straight to the DB layer.
	 * `id` is included; INSERT callers should unset it or let the DB ignore it.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'id'                      => $this->id,
			'created_at'              => $this->created_at,
			'created_at_micros'       => $this->created_at_micros,
			'method'                  => $this->method,
			'route'                   => $this->route,
			'route_prefix'            => $this->route_prefix,
			'query_string'            => $this->query_string,
			'status'                  => $this->status,
			'status_class'            => $this->status_class,
			'duration_ms'             => $this->duration_ms,
			'user_id'                 => $this->user_id,
			'ip_resolved'             => $this->ip_resolved,
			'ip_raw_remote'           => $this->ip_raw_remote,
			'request_content_type'    => $this->request_content_type,
			'request_headers'         => $this->request_headers,
			'request_body'            => $this->request_body,
			'request_body_bytes'      => $this->request_body_bytes,
			'request_body_truncated'  => $this->request_body_truncated ? 1 : 0,
			'response_content_type'   => $this->response_content_type,
			'response_headers'        => $this->response_headers,
			'response_body'           => $this->response_body,
			'response_body_bytes'     => $this->response_body_bytes,
			'response_body_truncated' => $this->response_body_truncated ? 1 : 0,
			'bodies_spilled'          => $this->bodies_spilled ? 1 : 0,
			'migration_source_id'     => $this->migration_source_id,
		];
	}

	/**
	 * Hydrate from a `brl_logs` row (or any equivalent associative array).
	 *
	 * @param  array<string,mixed> $row Row keyed by column name.
	 * @return self
	 */
	public static function from_array( array $row ): self {
		$e                          = new self();
		$e->id                      = (int) ( $row['id'] ?? 0 );
		$e->created_at              = (string) ( $row['created_at'] ?? '' );
		$e->created_at_micros       = (int) ( $row['created_at_micros'] ?? 0 );
		$e->method                  = (string) ( $row['method'] ?? '' );
		$e->route                   = (string) ( $row['route'] ?? '' );
		$e->route_prefix            = (string) ( $row['route_prefix'] ?? '' );
		$e->query_string            = isset( $row['query_string'] ) ? (string) $row['query_string'] : null;
		$e->status                  = (int) ( $row['status'] ?? 0 );
		$e->status_class            = (int) ( $row['status_class'] ?? 0 );
		$e->duration_ms             = (int) ( $row['duration_ms'] ?? 0 );
		$e->user_id                 = (int) ( $row['user_id'] ?? 0 );
		$e->ip_resolved             = isset( $row['ip_resolved'] ) ? (string) $row['ip_resolved'] : null;
		$e->ip_raw_remote           = isset( $row['ip_raw_remote'] ) ? (string) $row['ip_raw_remote'] : null;
		$e->request_content_type    = (string) ( $row['request_content_type'] ?? '' );
		$e->request_headers         = isset( $row['request_headers'] ) ? (string) $row['request_headers'] : null;
		$e->request_body            = isset( $row['request_body'] ) ? (string) $row['request_body'] : null;
		$e->request_body_bytes      = (int) ( $row['request_body_bytes'] ?? 0 );
		$e->request_body_truncated  = (bool) ( $row['request_body_truncated'] ?? 0 );
		$e->response_content_type   = (string) ( $row['response_content_type'] ?? '' );
		$e->response_headers        = isset( $row['response_headers'] ) ? (string) $row['response_headers'] : null;
		$e->response_body           = isset( $row['response_body'] ) ? (string) $row['response_body'] : null;
		$e->response_body_bytes     = (int) ( $row['response_body_bytes'] ?? 0 );
		$e->response_body_truncated = (bool) ( $row['response_body_truncated'] ?? 0 );
		$e->bodies_spilled          = (bool) ( $row['bodies_spilled'] ?? 0 );
		$e->migration_source_id     = isset( $row['migration_source_id'] ) ? (string) $row['migration_source_id'] : null;
		return $e;
	}
}
