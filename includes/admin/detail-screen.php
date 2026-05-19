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
			\esc_html__( '\u{2190} Back to logs', 'better-rest-api-logs' )
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

		// Headers table.
		$headers_json = $is_request
			? (string) ( $entry->request_headers ?? '{}' )
			: (string) ( $entry->response_headers ?? '{}' );
		$headers      = (array) ( \json_decode( $headers_json, true ) ?: [] );

		\printf( '<h3>%s</h3>', \esc_html__( 'Headers', 'better-rest-api-logs' ) );
		echo '<table class="brl-headers-table widefat striped"><tbody>';
		foreach ( $headers as $name => $value ) {
			\printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				\esc_html( (string) $name ),
				\esc_html( \is_scalar( $value ) ? (string) $value : (string) \wp_json_encode( $value ) )
			);
		}
		echo '</tbody></table>';

		// Body code block — esc_html BEFORE injection (T-04-30).
		// highlight.js reads textContent, so the browser-decoded string is what
		// hljs tokenises. We never set innerHTML from raw bytes.
		$body    = $is_request
			? (string) ( $entry->request_body ?? '' )
			: (string) ( $entry->response_body ?? '' );
		$id_attr = $is_request ? 'brl-req-body' : 'brl-resp-body';
		$lang    = self::guess_language( $headers, $is_request ? $entry->request_content_type : $entry->response_content_type );

		\printf(
			'<h3>%s <button type="button" class="button button-small" data-clipboard-target="#%s">%s</button></h3>',
			\esc_html__( 'Body', 'better-rest-api-logs' ),
			\esc_attr( $id_attr ),
			\esc_html__( 'Copy', 'better-rest-api-logs' )
		);
		\printf(
			'<pre class="brl-code" id="%s"><code class="language-%s">%s</code></pre>',
			\esc_attr( $id_attr ),
			\esc_attr( $lang ),
			\esc_html( $body )
		);

		echo '</section>';
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
