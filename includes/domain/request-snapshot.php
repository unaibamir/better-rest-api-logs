<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot captured at `rest_pre_dispatch` — raw request state in memory.
 *
 * Carries pre-redaction, pre-truncation data forward to the merge into Entry
 * via {@see Entry::from_snapshots()}. Pure-PHP value object with public typed
 * properties; immutable by convention. `body_bytes_original` records honest
 * pre-truncation length per CAP-08 so consumers can show "truncated from N
 * bytes" regardless of what the persisted blob ends up being.
 */
final class RequestSnapshot {

	public string $method        = '';
	public string $route         = '';
	public string $route_prefix  = '';
	public ?string $query_string = null;
	public string $content_type  = '';

	/** @var array<string,mixed> */
	public array $headers = [];

	public ?string $body            = null;
	public int $body_bytes_original = 0;
	public int $started_at_micros   = 0;

	/**
	 * 16-byte packed IP resolved by IpResolver at pre-dispatch.
	 * Null when REMOTE_ADDR was absent or invalid. Flusher::build_entry
	 * reads this for anonymization and storage (PRIV-05 / D-16).
	 *
	 * @var string|null
	 */
	public ?string $ip_resolved = null;

	/**
	 * Raw REMOTE_ADDR as a 16-byte packed IP — never anonymized (D-16).
	 *
	 * @var string|null
	 */
	public ?string $ip_raw_remote = null;

	/**
	 * Serialise to an associative array keyed by snapshot field name.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'method'              => $this->method,
			'route'               => $this->route,
			'route_prefix'        => $this->route_prefix,
			'query_string'        => $this->query_string,
			'content_type'        => $this->content_type,
			'headers'             => $this->headers,
			'body'                => $this->body,
			'body_bytes_original' => $this->body_bytes_original,
			'started_at_micros'   => $this->started_at_micros,
			'ip_resolved'         => $this->ip_resolved,
			'ip_raw_remote'       => $this->ip_raw_remote,
		];
	}

	/**
	 * Hydrate from an associative array (inverse of {@see self::to_array()}).
	 *
	 * @param  array<string,mixed> $row Snapshot fields keyed by name.
	 * @return self
	 */
	public static function from_array( array $row ): self {
		$s                      = new self();
		$s->method              = (string) ( $row['method'] ?? '' );
		$s->route               = (string) ( $row['route'] ?? '' );
		$s->route_prefix        = (string) ( $row['route_prefix'] ?? '' );
		$s->query_string        = isset( $row['query_string'] ) ? (string) $row['query_string'] : null;
		$s->content_type        = (string) ( $row['content_type'] ?? '' );
		$s->headers             = isset( $row['headers'] ) && \is_array( $row['headers'] ) ? $row['headers'] : [];
		$s->body                = isset( $row['body'] ) ? (string) $row['body'] : null;
		$s->body_bytes_original = (int) ( $row['body_bytes_original'] ?? 0 );
		$s->started_at_micros   = (int) ( $row['started_at_micros'] ?? 0 );
		$s->ip_resolved         = isset( $row['ip_resolved'] ) ? (string) $row['ip_resolved'] : null;
		$s->ip_raw_remote       = isset( $row['ip_raw_remote'] ) ? (string) $row['ip_raw_remote'] : null;
		return $s;
	}
}
