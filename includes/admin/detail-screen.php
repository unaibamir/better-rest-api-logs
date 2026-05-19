<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Domain\Entry;

/**
 * Render callback for the hidden detail submenu page (D-02).
 *
 * SECURITY (T-04-30): Every body byte is escaped via esc_html() BEFORE injection
 * into the <code class="language-*"> block. highlight.js reads el.textContent
 * (already HTML-decoded by the browser) and rewrites innerHTML with its own
 * internally-escaped output — the documented safe path (D-17, RESEARCH §4).
 * NEVER set innerHTML from raw body bytes.
 *
 * Provides both instance render_page() (WP callback) and static render()
 * (test convenience).
 *
 * @package BetterRestApiLogs\Admin
 */
final class DetailScreen {

	/** @var LogRepository */
	private $repo;

	/**
	 * @param LogRepository $repo Data access layer.
	 */
	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * WP menu callback — renders via the injected repo instance.
	 *
	 * @return void
	 */
	public function render_page(): void {
		self::render_with( $this->repo );
	}

	/**
	 * Static convenience render — used by integration tests after Plugin::boot().
	 *
	 * @return void
	 */
	public static function render(): void {
		self::render_with( new LogRepository() );
	}

	/**
	 * Core render — shared by instance and static entry points.
	 *
	 * @param  LogRepository $repo Data access layer.
	 * @return void
	 */
	private static function render_with( LogRepository $repo ): void {
		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ), '', [ 'response' => 403 ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detail view; deletion routes through admin-post.php nonce.
		$id    = isset( $_GET['log_id'] ) ? \absint( $_GET['log_id'] ) : 0;
		$entry = $id > 0 ? $repo->find( $id ) : null;

		echo '<div class="wrap brl-admin">';

		if ( null === $entry ) {
			\printf( '<h1>%s</h1>', \esc_html__( 'Log entry not found', 'better-rest-api-logs' ) );
			\printf( '<p>%s</p>', \esc_html__( 'It may have been deleted or purged.', 'better-rest-api-logs' ) );
			echo '</div>';
			return;
		}

		$back_url = \admin_url( 'tools.php?page=better-rest-api-logs' );
		\printf(
			'<h1>%s <a class="page-title-action" href="%s">%s</a></h1>',
			\esc_html(
				\sprintf(
					/* translators: %d: log entry ID */
					\__( 'REST API Log #%d', 'better-rest-api-logs' ),
					(int) $entry->id
				)
			),
			\esc_url( $back_url ),
			\esc_html__( '← Back to logs', 'better-rest-api-logs' )
		);

		self::render_panel( 'request', $entry );
		self::render_panel( 'response', $entry );

		echo '</div>';
	}

	/**
	 * Render one request or response panel.
	 *
	 * @param  string $kind  'request' or 'response'.
	 * @param  Entry  $entry Hydrated log entry.
	 * @return void
	 */
	private static function render_panel( string $kind, Entry $entry ): void {
		$is_request = 'request' === $kind;

		\printf( '<section class="brl-detail-panel brl-detail-panel--%s">', \esc_attr( $kind ) );
		\printf(
			'<h2>%s</h2>',
			$is_request
				? \esc_html__( 'Request', 'better-rest-api-logs' )
				: \esc_html__( 'Response', 'better-rest-api-logs' )
		);

		// Meta definition list.
		echo '<dl class="brl-detail-panel__meta">';
		if ( $is_request ) {
			\printf(
				'<dt>%s</dt><dd>%s</dd>',
				\esc_html__( 'Method', 'better-rest-api-logs' ),
				\esc_html( (string) $entry->method )
			);
			\printf(
				'<dt>%s</dt><dd>%s</dd>',
				\esc_html__( 'Route', 'better-rest-api-logs' ),
				\esc_html( (string) $entry->route )
			);
			\printf(
				'<dt>%s</dt><dd>%s</dd>',
				\esc_html__( 'Prefix', 'better-rest-api-logs' ),
				\esc_html( (string) $entry->route_prefix )
			);
		} else {
			\printf(
				'<dt>%s</dt><dd>%d</dd>',
				\esc_html__( 'Status', 'better-rest-api-logs' ),
				(int) $entry->status
			);
			\printf(
				'<dt>%s</dt><dd>%s ms</dd>',
				\esc_html__( 'Duration', 'better-rest-api-logs' ),
				\esc_html( (string) $entry->duration_ms )
			);
		}
		echo '</dl>';

		// Query parameters — request side only, shown when present.
		if ( $is_request ) {
			$query_string = (string) ( $entry->query_string ?? '' );
			if ( '' !== $query_string ) {
				self::render_query_params( $query_string );
			}
		}

		// Headers — tabbed (JSON default, Key/Value fallback).
		$headers_json = $is_request
			? (string) ( $entry->request_headers ?? '{}' )
			: (string) ( $entry->response_headers ?? '{}' );
		$decoded      = \json_decode( $headers_json, true );
		$headers      = \is_array( $decoded ) ? $decoded : [];

		self::render_headers_tabs( $headers, $kind );

		// Body code block.
		$body    = $is_request
			? (string) ( $entry->request_body ?? '' )
			: (string) ( $entry->response_body ?? '' );
		$id_attr = $is_request ? 'brl-req-body' : 'brl-resp-body';
		$lang    = self::guess_language( $headers, $is_request ? $entry->request_content_type : $entry->response_content_type );

		self::render_body_block( $body, $lang, $id_attr );

		echo '</section>';
	}

	/**
	 * Render the query-string as a key/value table.
	 *
	 * Values are decoded once via parse_str (handles percent-encoding) and
	 * escaped at output. Multi-value params (?a=1&a=2) collapse to the array
	 * form parse_str emits.
	 *
	 * @param  string $query_string Raw http_build_query output (no leading "?").
	 * @return void
	 */
	private static function render_query_params( string $query_string ): void {
		$parsed = [];
		\parse_str( $query_string, $parsed );
		if ( [] === $parsed ) {
			return;
		}

		\printf( '<h3>%s</h3>', \esc_html__( 'Query parameters', 'better-rest-api-logs' ) );
		echo '<table class="brl-headers-table widefat striped"><tbody>';
		foreach ( $parsed as $name => $value ) {
			\printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				\esc_html( (string) $name ),
				\esc_html( self::flatten_value( $value ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the headers as two tabs — JSON (default) and Key/Value.
	 *
	 * The JSON tab uses JSON_UNESCAPED_SLASHES so wildcard-style header values
	 * render as the literal asterisk-slash-asterisk rather than escaped slashes
	 * (T-04-30 — esc_html still runs). Key/Value joins array-typed header
	 * values with ", " instead of round-tripping them through wp_json_encode.
	 *
	 * @param  array<int|string,mixed> $headers Decoded headers map.
	 * @param  string                  $kind    'request' or 'response'.
	 * @return void
	 */
	private static function render_headers_tabs( array $headers, string $kind ): void {
		$json    = (string) \wp_json_encode( $headers, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT );
		$copy_id = 'brl-' . $kind . '-headers-json';

		\printf(
			'<h3>%s <button type="button" class="button button-small" data-clipboard-target="#%s">%s</button></h3>',
			\esc_html__( 'Headers', 'better-rest-api-logs' ),
			\esc_attr( $copy_id ),
			\esc_html__( 'Copy', 'better-rest-api-logs' )
		);

		// Tabs wrapper.
		\printf( '<div class="brl-tabs" data-brl-tabs="%s">', \esc_attr( $kind ) );

		echo '<div class="brl-tabs__nav" role="tablist">';
		\printf(
			'<button type="button" class="brl-tabs__tab is-active" role="tab" aria-selected="true" data-brl-tab-target="json">%s</button>',
			\esc_html__( 'JSON', 'better-rest-api-logs' )
		);
		\printf(
			'<button type="button" class="brl-tabs__tab" role="tab" aria-selected="false" data-brl-tab-target="kv">%s</button>',
			\esc_html__( 'Key / Value', 'better-rest-api-logs' )
		);
		echo '</div>';

		// JSON panel (default visible).
		echo '<div class="brl-tabs__panel" role="tabpanel" data-brl-tab-panel="json">';
		\printf(
			'<pre class="brl-code" id="%s"><code class="language-json">%s</code></pre>',
			\esc_attr( $copy_id ),
			\esc_html( $json )
		);
		echo '</div>';

		// Key/Value panel (hidden by default; toggled by admin-detail.js).
		echo '<div class="brl-tabs__panel" role="tabpanel" data-brl-tab-panel="kv" hidden>';
		echo '<table class="brl-headers-table widefat striped"><tbody>';
		foreach ( $headers as $name => $value ) {
			\printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				\esc_html( (string) $name ),
				\esc_html( self::flatten_value( $value ) )
			);
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render a body code block with a Copy button.
	 *
	 * Highlight.js reads textContent (browser-decoded) and rewrites innerHTML
	 * with its own escape-safe output (D-17, T-04-30). We never inject raw
	 * body bytes — esc_html() runs before output.
	 *
	 * @param  string $body    Raw body bytes.
	 * @param  string $lang    highlight.js language slug.
	 * @param  string $id_attr DOM id used as clipboard target.
	 * @return void
	 */
	private static function render_body_block( string $body, string $lang, string $id_attr ): void {
		\printf(
			'<h3>%s <button type="button" class="button button-small" data-clipboard-target="#%s">%s</button></h3>',
			\esc_html__( 'Body', 'better-rest-api-logs' ),
			\esc_attr( $id_attr ),
			\esc_html__( 'Copy', 'better-rest-api-logs' )
		);

		// JSON-prettify when the body parses as JSON; otherwise show raw bytes
		// so non-JSON payloads (form-encoded, XML, plain text) survive
		// untouched. esc_html runs at the printf call regardless.
		$display_body = self::maybe_pretty_print_body( $body, $lang );

		\printf(
			'<pre class="brl-code" id="%s"><code class="language-%s">%s</code></pre>',
			\esc_attr( $id_attr ),
			\esc_attr( $lang ),
			\esc_html( $display_body )
		);
	}

	/**
	 * Pretty-print body bytes when they're valid JSON; otherwise return as-is.
	 *
	 * @param  string $body Raw body.
	 * @param  string $lang Detected highlight.js language slug.
	 * @return string       Pretty JSON or untouched body.
	 */
	private static function maybe_pretty_print_body( string $body, string $lang ): string {
		if ( '' === $body || 'json' !== $lang ) {
			return $body;
		}
		$decoded = \json_decode( $body, true );
		if ( \JSON_ERROR_NONE !== \json_last_error() ) {
			return $body;
		}
		$pretty = \wp_json_encode( $decoded, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT );
		return false === $pretty ? $body : $pretty;
	}

	/**
	 * Flatten a header/query value into a display string.
	 *
	 * WP_REST_Request::get_headers() returns array<string,array<string>> —
	 * we join the inner array with `, ` instead of json-encoding so the
	 * display reads naturally. Scalars cast through (string).
	 *
	 * @param  mixed $value Header / query param value.
	 * @return string       Display string.
	 */
	private static function flatten_value( $value ): string {
		if ( \is_array( $value ) ) {
			$parts = [];
			foreach ( $value as $v ) {
				$parts[] = \is_scalar( $v ) ? (string) $v : (string) \wp_json_encode( $v, \JSON_UNESCAPED_SLASHES );
			}
			return \implode( ', ', $parts );
		}
		return \is_scalar( $value ) ? (string) $value : (string) \wp_json_encode( $value, \JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Guess a highlight.js language class from headers or content-type.
	 *
	 * @param  array<mixed,mixed> $headers     Decoded headers map.
	 * @param  string             $content_type Content-Type header from the entry.
	 * @return string                           Language slug for class="language-*".
	 */
	private static function guess_language( array $headers, string $content_type ): string {
		// Prefer the content-type from the headers map, fall back to the column.
		$ct = '';
		foreach ( $headers as $k => $v ) {
			if ( 0 === \strcasecmp( (string) $k, 'content-type' ) ) {
				$ct = \is_scalar( $v ) ? (string) $v : '';
				break;
			}
		}
		if ( '' === $ct ) {
			$ct = $content_type;
		}

		if ( false !== \stripos( $ct, 'json' ) ) {
			return 'json';
		}
		if ( false !== \stripos( $ct, 'xml' ) ) {
			return 'xml';
		}
		return 'plaintext';
	}
}
