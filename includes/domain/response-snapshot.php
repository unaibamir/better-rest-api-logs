<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot captured at `rest_post_dispatch` — raw response state in memory.
 *
 * Mirror of {@see RequestSnapshot} for the response side of the dispatch.
 * `status_class` is stored (not derived) so the DTO is pure data; Phase 3's
 * Capture stage sets it alongside `status`, and {@see Entry::from_snapshots()}
 * computes its own from `status` for the persisted Entry.
 */
final class ResponseSnapshot {

	public int $status          = 0;
	public int $status_class    = 0;
	public string $content_type = '';

	/** @var array<string,mixed> */
	public array $headers = [];

	public ?string $body            = null;
	public int $body_bytes_original = 0;
	public int $finished_at_micros  = 0;

	/**
	 * Serialise to an associative array keyed by snapshot field name.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'status'              => $this->status,
			'status_class'        => $this->status_class,
			'content_type'        => $this->content_type,
			'headers'             => $this->headers,
			'body'                => $this->body,
			'body_bytes_original' => $this->body_bytes_original,
			'finished_at_micros'  => $this->finished_at_micros,
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
		$s->status              = (int) ( $row['status'] ?? 0 );
		$s->status_class        = (int) ( $row['status_class'] ?? 0 );
		$s->content_type        = (string) ( $row['content_type'] ?? '' );
		$s->headers             = isset( $row['headers'] ) && \is_array( $row['headers'] ) ? $row['headers'] : [];
		$s->body                = isset( $row['body'] ) ? (string) $row['body'] : null;
		$s->body_bytes_original = (int) ( $row['body_bytes_original'] ?? 0 );
		$s->finished_at_micros  = (int) ( $row['finished_at_micros'] ?? 0 );
		return $s;
	}
}
