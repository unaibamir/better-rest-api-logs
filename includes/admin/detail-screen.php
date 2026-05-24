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
 * Layout: a fixed-width overview sidebar on the left holds the entry's meta;
 * a wider main column on the right stacks Request and Response sections.
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
	 */
	public function render_page(): void {
		self::render_with( $this->repo );
	}

	/**
	 * Static convenience render — used by integration tests after Plugin::boot().
	 */
	public static function render(): void {
		self::render_with( new LogRepository() );
	}

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

		self::render_title_bar( $entry );

		echo '<div class="brl-detail-layout">';
		self::render_sidebar( $entry );
		echo '<div class="brl-detail-main">';
		self::render_request_section( $entry );
		self::render_response_section( $entry );
		echo '</div>';
		echo '</div>';

		echo '</div>';
	}

	private static function render_title_bar( Entry $entry ): void {
		$back_url = \admin_url( 'tools.php?page=better-rest-api-logs' );

		$delete_url = \wp_nonce_url(
			\add_query_arg(
				[
					'action' => 'brl_delete_log',
					'log_id' => (int) $entry->id,
				],
				\admin_url( 'admin-post.php' )
			),
			'brl_delete_' . (int) $entry->id,
			'_wpnonce'
		);

		\printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			\esc_html(
				\sprintf(
					/* translators: %d: log entry ID */
					\__( 'REST API Log #%d', 'better-rest-api-logs' ),
					(int) $entry->id
				)
			)
		);

		\printf(
			'<a class="page-title-action" href="%s">%s</a>',
			\esc_url( $back_url ),
			\esc_html__( "\u{2190} Back to logs", 'better-rest-api-logs' )
		);

		\printf(
			'<a class="page-title-action brl-detail-delete" href="%s" onclick="return confirm(\'%s\');">%s</a>',
			\esc_url( $delete_url ),
			\esc_js(
				\sprintf(
					/* translators: %d: log entry id */
					\__( 'Delete log #%d? This cannot be undone.', 'better-rest-api-logs' ),
					(int) $entry->id
				)
			),
			\esc_html__( 'Delete this entry', 'better-rest-api-logs' )
		);

		echo '<hr class="wp-header-end" />';
	}

	private static function render_sidebar( Entry $entry ): void {
		echo '<aside class="brl-detail-sidebar"><div class="brl-detail-sidebar__box">';
		\printf( '<h2 class="brl-detail-sidebar__title">%s</h2>', \esc_html__( 'Overview', 'better-rest-api-logs' ) );

		echo '<dl class="brl-detail-sidebar__meta">';

		self::meta_row( __( 'Route', 'better-rest-api-logs' ), (string) $entry->route );
		self::meta_row( __( 'Method', 'better-rest-api-logs' ), (string) $entry->method );
		self::meta_row( __( 'Status', 'better-rest-api-logs' ), (string) (int) $entry->status );
		self::meta_row( __( 'Time', 'better-rest-api-logs' ), self::format_time( $entry ) );
		self::meta_row(
			__( 'Duration', 'better-rest-api-logs' ),
			\sprintf(
				/* translators: %d: duration in milliseconds */
				\__( '%d ms', 'better-rest-api-logs' ),
				(int) $entry->duration_ms
			)
		);
		self::meta_row( __( 'Request size', 'better-rest-api-logs' ), self::format_bytes( (int) $entry->request_body_bytes ) );
		self::meta_row( __( 'Response size', 'better-rest-api-logs' ), self::format_bytes( (int) $entry->response_body_bytes ) );
		self::meta_row( __( 'User', 'better-rest-api-logs' ), self::format_user( (int) $entry->user_id ) );
		self::meta_row( __( 'IP', 'better-rest-api-logs' ), self::format_ip( $entry ) );

		echo '</dl></div></aside>';
	}

	private static function meta_row( string $label, string $value ): void {
		\printf(
			'<dt>%s</dt><dd>%s</dd>',
			\esc_html( $label ),
			'' === $value ? '&mdash;' : \esc_html( $value )
		);
	}

	private static function render_request_section( Entry $entry ): void {
		echo '<section class="brl-detail-section brl-detail-section--request">';
		\printf( '<h2>%s</h2>', \esc_html__( 'Request', 'better-rest-api-logs' ) );

		$headers = self::decode_headers( (string) ( $entry->request_headers ?? '{}' ) );
		self::render_headers_tabs( $headers, 'request' );

		$query_string = (string) ( $entry->query_string ?? '' );
		if ( '' !== $query_string ) {
			self::render_query_tabs( $query_string );
		}

		$lang = self::guess_language( $headers, $entry->request_content_type );
		self::render_body_block( (string) ( $entry->request_body ?? '' ), $lang, 'brl-req-body' );

		echo '</section>';
	}

	private static function render_response_section( Entry $entry ): void {
		echo '<section class="brl-detail-section brl-detail-section--response">';
		\printf( '<h2>%s</h2>', \esc_html__( 'Response', 'better-rest-api-logs' ) );

		$headers = self::decode_headers( (string) ( $entry->response_headers ?? '{}' ) );
		self::render_headers_tabs( $headers, 'response' );

		$lang = self::guess_language( $headers, $entry->response_content_type );
		self::render_body_block( (string) ( $entry->response_body ?? '' ), $lang, 'brl-resp-body' );

		echo '</section>';
	}

	/**
	 * @param  string $json Stored headers JSON (defaults to "{}" upstream).
	 * @return array<int|string,mixed>
	 */
	private static function decode_headers( string $json ): array {
		$decoded = \json_decode( $json, true );
		return \is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<int|string,mixed> $headers Decoded headers map.
	 * @param string                  $kind    'request' or 'response'.
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

		\printf( '<div class="brl-tabs" data-brl-tabs="%s-headers">', \esc_attr( $kind ) );

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

		echo '<div class="brl-tabs__panel" role="tabpanel" data-brl-tab-panel="json">';
		\printf(
			'<pre class="brl-code" id="%s"><code class="language-json">%s</code></pre>',
			\esc_attr( $copy_id ),
			\esc_html( $json )
		);
		echo '</div>';

		echo '<div class="brl-tabs__panel" role="tabpanel" data-brl-tab-panel="kv" hidden>';
		self::render_kv_table( $headers );
		echo '</div>';

		echo '</div>';
	}

	private static function render_query_tabs( string $query_string ): void {
		$parsed = [];
		\parse_str( $query_string, $parsed );
		if ( [] === $parsed ) {
			return;
		}

		$json    = (string) \wp_json_encode( $parsed, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT );
		$copy_id = 'brl-query-json';

		\printf(
			'<h3>%s <button type="button" class="button button-small" data-clipboard-target="#%s">%s</button></h3>',
			\esc_html__( 'Query parameters', 'better-rest-api-logs' ),
			\esc_attr( $copy_id ),
			\esc_html__( 'Copy', 'better-rest-api-logs' )
		);

		echo '<div class="brl-tabs" data-brl-tabs="query">';

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

		echo '<div class="brl-tabs__panel" role="tabpanel" data-brl-tab-panel="json">';
		\printf(
			'<pre class="brl-code" id="%s"><code class="language-json">%s</code></pre>',
			\esc_attr( $copy_id ),
			\esc_html( $json )
		);
		echo '</div>';

		echo '<div class="brl-tabs__panel" role="tabpanel" data-brl-tab-panel="kv" hidden>';
		self::render_kv_table( $parsed );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param array<int|string,mixed> $pairs Header or query-string pairs to render.
	 */
	private static function render_kv_table( array $pairs ): void {
		echo '<table class="brl-headers-table widefat striped"><tbody>';
		foreach ( $pairs as $name => $value ) {
			\printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				\esc_html( (string) $name ),
				\esc_html( self::flatten_value( $value ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_body_block( string $body, string $lang, string $id_attr ): void {
		\printf(
			'<h3>%s <button type="button" class="button button-small" data-clipboard-target="#%s">%s</button></h3>',
			\esc_html__( 'Body', 'better-rest-api-logs' ),
			\esc_attr( $id_attr ),
			\esc_html__( 'Copy', 'better-rest-api-logs' )
		);

		if ( '' === $body ) {
			\printf(
				'<p class="brl-detail-body-empty">%s</p>',
				\esc_html__( 'Body was stored as empty.', 'better-rest-api-logs' )
			);
			\printf(
				'<pre class="brl-code" id="%s" hidden><code class="language-%s"></code></pre>',
				\esc_attr( $id_attr ),
				\esc_attr( $lang )
			);
			return;
		}

		$display_body = self::maybe_pretty_print_body( $body, $lang );

		\printf(
			'<pre class="brl-code" id="%s"><code class="language-%s">%s</code></pre>',
			\esc_attr( $id_attr ),
			\esc_attr( $lang ),
			\esc_html( $display_body )
		);
	}

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
	 * @param  mixed $value Header / query param value.
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
	 * @param array<mixed,mixed> $headers      Decoded headers map.
	 * @param string             $content_type Fallback Content-Type column value.
	 */
	private static function guess_language( array $headers, string $content_type ): string {
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

	private static function format_time( Entry $entry ): string {
		$micros = (int) $entry->created_at_micros;
		if ( $micros > 0 ) {
			return \gmdate( 'Y-m-d H:i:s', (int) ( $micros / 1_000_000 ) );
		}
		return (string) $entry->created_at;
	}

	private static function format_bytes( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '0 B';
		}
		if ( $bytes < 1024 ) {
			return \sprintf( '%d B', $bytes );
		}
		$kb = $bytes / 1024;
		if ( $kb < 1024 ) {
			return \sprintf( '%.1f KB', $kb );
		}
		$mb = $kb / 1024;
		if ( $mb < 1024 ) {
			return \sprintf( '%.1f MB', $mb );
		}
		return \sprintf( '%.1f GB', $mb / 1024 );
	}

	private static function format_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return \__( '(guest)', 'better-rest-api-logs' );
		}
		$user = \get_userdata( $user_id );
		if ( false === $user ) {
			return \sprintf(
				/* translators: %d: user ID */
				\__( 'User #%d', 'better-rest-api-logs' ),
				$user_id
			);
		}
		return (string) $user->user_login;
	}

	private static function format_ip( Entry $entry ): string {
		$packed = ( null !== $entry->ip_resolved && '' !== $entry->ip_resolved )
			? $entry->ip_resolved
			: $entry->ip_raw_remote;
		if ( null === $packed || '' === $packed ) {
			return '';
		}
		$printable = \inet_ntop( $packed );
		if ( false === $printable || '' === $printable ) {
			return '';
		}
		if ( 0 === \strncasecmp( $printable, '::ffff:', 7 ) ) {
			$printable = \substr( $printable, 7 );
		}
		return $printable;
	}
}
