<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Rest;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\Export\Streamer;

/**
 * Handles the one-shot signed export URL surface.
 *
 * POST /export — mints a CSPRNG token, stores a short-lived transient holding
 * the query filter set + requesting user ID + format, and returns a download URL
 * pointing at the GET consume endpoint.
 *
 * GET /export/{token} — loads the transient, deletes it immediately (one-shot,
 * D-09), verifies the consumer matches the minter, then streams the export via
 * the rest_pre_serve_request filter so WP never tries to JSON-encode a binary
 * response. A reused or expired token returns 410; a user mismatch returns 403.
 *
 * Security surface (T-05-05-01..07):
 *  - Token is wp_generate_password(32, false) — CSPRNG, alphanumeric, 62^32 space.
 *  - delete_transient happens before any streaming so the token is single-use
 *    even when the download starts but the client disconnects.
 *  - Both routes declare permission_callback checking manage_options (via the
 *    brl_admin_required_capability filter) — never __return_true.
 *  - Error copy is generic — the token is never echoed back in 410/403 bodies.
 *
 * @package BetterRestApiLogs\Rest
 */
final class ExportController {

	private const NAMESPACE = 'better-rest-api-logs/v1';

	/** Transient TTL — 5 minutes. */
	private const TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

	private Streamer $streamer;

	/**
	 * @param Streamer|null $streamer Defaults to a new real instance.
	 */
	public function __construct( ?Streamer $streamer = null ) {
		$this->streamer = $streamer ?? new Streamer();
	}

	/**
	 * Register the POST /export and GET /export/{token} routes.
	 *
	 * Called by the Routes registrar on rest_api_init. Both routes share the
	 * same admin_only permission_callback so unauthenticated requests are
	 * rejected by WP before the callback runs.
	 */
	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/export',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_mint' ],
				'permission_callback' => self::admin_only(),
				'args'                => self::mint_args(),
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/export/(?P<token>[A-Za-z0-9]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_consume' ],
				'permission_callback' => self::admin_only(),
				'args'                => self::token_arg(),
			]
		);
	}

	/**
	 * Mint a one-shot signed download URL.
	 *
	 * Builds and validates a QueryArgs from the request params (so the stored
	 * filter set is already validated), mints a CSPRNG token, stores the token
	 * payload as a short-lived transient, and returns the consume URL.
	 *
	 * @param  \WP_REST_Request $request Incoming POST request.
	 * @return \WP_REST_Response
	 */
	public function handle_mint( \WP_REST_Request $request ): \WP_REST_Response {
		$format = $this->sanitize_format( (string) $request->get_param( 'format' ) );

		// Build from params — QueryArgs::from_array validates every field so
		// untrusted filter input cannot reach the query layer in raw form.
		$args = QueryArgs::from_array( (array) $request->get_params() );

		// CSPRNG alphanumeric token — 62^32 ≈ 2^190 search space (T-05-05-02).
		$token = \wp_generate_password( 32, false );

		$payload = [
			'query_args' => $args->to_query_var_array(),
			'user_id'    => \get_current_user_id(),
			'format'     => $format,
		];

		\set_transient( 'brl_export_' . $token, $payload, self::TOKEN_TTL );

		$download_url = \rest_url( self::NAMESPACE . '/export/' . $token );

		return new \WP_REST_Response( [ 'download_url' => $download_url ], 201 );
	}

	/**
	 * Consume a one-shot download token and stream the export.
	 *
	 * The transient is deleted before streaming — any failure after deletion
	 * results in a 410 on retry, never a second stream (D-09, T-05-05-01).
	 *
	 * Streaming is delegated through the rest_pre_serve_request filter which
	 * returns true to tell WP the response has been sent manually. This bypasses
	 * WP's JSON serialization so raw binary/NDJSON bytes reach the client.
	 *
	 * @param  \WP_REST_Request $request Incoming GET request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_consume( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$key   = 'brl_export_' . $token;

		$data = \get_transient( $key );

		if ( false === $data ) {
			// Token is expired or already used. Generic copy — never echo the token
			// back in the response body (T-05-05-07).
			return new \WP_Error(
				'brl_export_token_invalid',
				\__( 'Download link expired or already used.', 'better-rest-api-logs' ),
				[ 'status' => 410 ]
			);
		}

		// One-shot: delete before streaming so a dropped connection cannot replay
		// the download (D-09, T-05-05-01). Even if streaming fails afterwards the
		// token is burned.
		\delete_transient( $key );

		// Verify the consumer is the same user who minted the token (D-10,
		// T-05-05-03). An admin sharing the URL with another admin is intentional
		// use; sharing it beyond the current session window is not.
		if ( (int) $data['user_id'] !== \get_current_user_id() ) {
			return new \WP_Error(
				'brl_export_user_mismatch',
				\__( 'You are not authorized to use this download link.', 'better-rest-api-logs' ),
				[ 'status' => 403 ]
			);
		}

		$format   = $this->sanitize_format( (string) $data['format'] );
		$streamer = $this->streamer;
		$filename = \gmdate( 'Y-m-d' ) . '-rest-api-logs.' . ( 'csv' === $format ? 'csv' : 'ndjson' );

		// Hook into rest_pre_serve_request to stream raw bytes. Returning true
		// tells WP that the response has already been sent, suppressing its own
		// JSON encode + send cycle.
		\add_filter(
			'rest_pre_serve_request',
			static function ( $served ) use ( $streamer, $format, $filename, $data ) {
				if ( $served ) {
					return $served;
				}

				$streamer->send_download_headers( $format, $filename );

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is a streaming handle, not a filesystem path; WP_Filesystem has no equivalent.
				$sink = \fopen( 'php://output', 'wb' );
				if ( false !== $sink ) {
					$args = QueryArgs::from_array( (array) $data['query_args'] );
					$streamer->stream( $args, $format, $sink, true );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming handle opened above; must be closed after streaming completes.
					\fclose( $sink );
				}

				return true;
			},
			10,
			1
		);

		return new \WP_REST_Response( null, 200 );
	}

	/**
	 * Sanitise the format parameter to exactly 'csv' or 'ndjson'.
	 *
	 * Any value other than the exact string 'csv' falls back to 'ndjson' —
	 * the safer recommended default (CONTEXT.md D-Discretion, Pitfall 22).
	 *
	 * @param  string $value Raw format value from the request or transient.
	 * @return string 'csv' or 'ndjson'.
	 */
	private function sanitize_format( string $value ): string {
		return 'csv' === $value ? 'csv' : 'ndjson';
	}

	/**
	 * Returns the permission_callback closure used on every export route.
	 *
	 * Mirrors the admin_only() shape from Routes so the export surface has
	 * identical cap-gating to the list/single/stats routes. The
	 * brl_admin_required_capability filter lets site owners adjust the
	 * required capability without touching plugin code.
	 *
	 * Never __return_true — that would open an unauthenticated data-exfiltration
	 * surface (T-05-05-03 / check-banlist.sh gate).
	 */
	private static function admin_only(): callable {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST API contract requires the closure to accept the request even when the cap check ignores it.
		return static function ( \WP_REST_Request $request ): bool {
			return \current_user_can(
				\apply_filters( 'brl_admin_required_capability', 'manage_options', 'rest' )
			);
		};
	}

	/**
	 * Declared args for POST /export.
	 *
	 * Mirrors the list_args() shape from Routes — sanitize at the WP REST
	 * boundary before values reach QueryArgs::from_array(). The format param
	 * is handled by sanitize_format() after passing through sanitize_text_field.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function mint_args(): array {
		$sanitize = 'sanitize_text_field';
		return [
			'format'           => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'method'           => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'status'           => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'status_class'     => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'route_prefix'     => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'user_id'          => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'ip'               => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'date_from_micros' => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'date_to_micros'   => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'free_text'        => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'order_by'         => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
			'order_dir'        => [
				'type'              => 'string',
				'sanitize_callback' => $sanitize,
			],
		];
	}

	/**
	 * Validate callback for the {token} route parameter.
	 *
	 * Accepts only non-empty alphanumeric strings — matching the character set
	 * produced by wp_generate_password(32, false).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function token_arg(): array {
		return [
			'token' => [
				'validate_callback' => static function ( $v ): bool {
					return 1 === \preg_match( '/^[A-Za-z0-9]+$/', (string) $v ) && \strlen( (string) $v ) > 0;
				},
			],
		];
	}
}
