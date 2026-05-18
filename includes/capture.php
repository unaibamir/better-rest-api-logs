<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Capture\Filter;
use BetterRestApiLogs\Capture\IpResolver; // Static methods only — no instance stored.
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Logger\Queue;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Support\Clock;
use BetterRestApiLogs\Support\Json;

/**
 * WP-coupled capture orchestrator (D-01, D-02, D-03, D-04, D-30).
 *
 * Single WP-coupled file under includes/ that connects pure-PHP collaborators
 * to the REST dispatch lifecycle. Hooks onto rest_pre_dispatch (priority 9999)
 * and rest_post_dispatch (priority 9999) to observe every REST request without
 * ever influencing the response.
 *
 * Passthrough discipline (D-01, D-02): every public callback returns its first
 * argument — the response value — untouched regardless of any internal branch.
 * A try/catch \Throwable wraps each observation body so even an exception in
 * the capture path cannot corrupt the response reaching the client (REL-04).
 *
 * Recursion guard (D-03): when rest_do_request is called inside a controller,
 * pre_dispatch fires again. Queue::outermost_request_id() is non-null after the
 * first call, so observe_pre returns immediately — exactly one queue entry is
 * created per PHP request lifecycle.
 *
 * Self-namespace skip (D-04, CAP-05): routes under /better-rest-api-logs/v1
 * are rejected before the outermost flag is set, so plugin-own REST calls are
 * invisible to the logger by design.
 *
 * Redaction, truncation, compression, and body spill happen in Flusher::on_shutdown
 * where DB insert occurs. This class only builds the raw snapshots and queues them.
 *
 * @package BetterRestApiLogs
 */
final class Capture {

	private Filter $filter;
	private SettingsRegistry $registry;
	private Clock $clock;

	/**
	 * @param Filter|null           $filter   Route/method/CIDR pre-dispatch gate.
	 * @param SettingsRegistry|null $registry Settings source for filter config.
	 * @param Clock|null            $clock    Monotonic microsecond clock.
	 */
	public function __construct(
		?Filter $filter = null,
		?SettingsRegistry $registry = null,
		?Clock $clock = null
	) {
		$this->filter   = $filter ?? new Filter();
		$this->registry = $registry ?? new SettingsRegistry( new \BetterRestApiLogs\Settings\Repository() );
		$this->clock    = $clock ?? new Clock();
	}

	/**
	 * REST pre-dispatch callback (priority 9999) — PASSTHROUGH (D-01).
	 *
	 * Null means no short-circuit response has been set; WP_REST_Response means
	 * another plugin intercepted first. Either way we must return the value
	 * unchanged so we don't accidentally override a cache-hit or auth-denial.
	 *
	 * @param mixed $response Null or WP_REST_Response from a prior filter.
	 * @param mixed $server   REST server instance.
	 * @param mixed $request  Incoming REST request.
	 * @return mixed $response, always unchanged.
	 */
	public function on_pre_dispatch( $response, $server, $request ) {
		try {
			$this->observe_pre( $request );
		} catch ( \Throwable $e ) {
			// Logging must never raise. Swallow everything; client is unaffected.
			unset( $e );
		}
		return $response; // PASSTHROUGH (D-01).
	}

	/**
	 * REST post-dispatch callback (priority 9999) — PASSTHROUGH (D-02).
	 *
	 * Fires from serve_request() (full HTTP path), embed context, and batch
	 * requests. Does NOT fire when rest_do_request() is called directly, which
	 * only invokes dispatch() → respond_to_request(). For that path, backfill
	 * happens via on_after_callbacks() below.
	 *
	 * @param mixed $result  The response object after dispatch.
	 * @param mixed $server  REST server instance.
	 * @param mixed $request The REST request.
	 * @return mixed $result, always unchanged.
	 */
	public function on_post_dispatch( $result, $server, $request ) {
		try {
			$this->observe_post( $result, $request );
		} catch ( \Throwable $e ) {
			// Logging must never raise. Swallow everything; client is unaffected.
			unset( $e );
		}
		return $result; // PASSTHROUGH (D-02).
	}

	/**
	 * REST after-callbacks hook (priority 9999) — PASSTHROUGH (D-02).
	 *
	 * Fires from respond_to_request() on ALL dispatch paths — including the
	 * rest_do_request() → dispatch() route that does NOT fire rest_post_dispatch.
	 * Signature differs: ($response, $handler, $request) vs ($result, $server, $request).
	 * Queue::backfill is idempotent so double-fire on the full HTTP path is safe.
	 *
	 * @param mixed $response Response value before rest_ensure_response wrapping.
	 * @param mixed $handler  Route handler array.
	 * @param mixed $request  The REST request.
	 * @return mixed $response, always unchanged.
	 */
	public function on_after_callbacks( $response, $handler, $request ) {
		try {
			$this->observe_post( $response, $request );
		} catch ( \Throwable $e ) {
			// Logging must never raise. Swallow everything; client is unaffected.
			unset( $e );
		}
		return $response; // PASSTHROUGH (D-02).
	}

	/**
	 * Observe the pre-dispatch stage and push a RequestSnapshot to the queue.
	 *
	 * @param mixed $request WP_REST_Request or stub.
	 */
	private function observe_pre( $request ): void {
		$route = \method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

		// D-04 / CAP-05: skip own REST namespace before touching any state.
		if ( 0 === \strpos( $route, '/better-rest-api-logs/v1' ) ) {
			return;
		}

		// D-03: recursion guard — only the outermost request fires.
		if ( null !== Queue::outermost_request_id() ) {
			return;
		}

		// Load the settings snapshot used throughout the remaining steps.
		$settings = $this->load_settings();

		// Resolve client IP before the filter so CIDR deny/allow checks work.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- raw $_SERVER passed to IpResolver which validates each key internally.
		$ip = IpResolver::resolve(
			$_SERVER,
			[
				'network' => [
					'trusted_proxies'  => $this->registry->get_setting( 'network.trusted_proxy_cidrs', [] ),
					'cloudflare_cidrs' => $this->registry->get_setting( 'network.cloudflare_cidrs', [] ),
				],
			]
		);

		// D-09 pre-dispatch filter chain (steps 1–6 handled inside Filter).
		if ( ! $this->filter->should_capture_at_pre_dispatch( $request, $settings, $ip['resolved'] ) ) {
			return;
		}

		// D-09 step 7: brl_should_capture escape hatch for third-party code.
		$capture = \apply_filters( 'brl_should_capture', true, $request );
		if ( false === $capture ) {
			return;
		}

		$uuid     = \wp_generate_uuid4();
		$snapshot = $this->build_request_snapshot( $request, $ip );

		Queue::push( $uuid, $snapshot );
		Queue::set_outermost_request_id( $uuid );
	}

	/**
	 * Observe the post-dispatch stage and backfill the queue entry with the response.
	 *
	 * @param mixed $result  The response value from dispatch.
	 * @param mixed $request WP_REST_Request or stub.
	 */
	private function observe_post( $result, $request ): void {
		$uuid = Queue::outermost_request_id();
		if ( null === $uuid ) {
			return;
		}

		// Defensive recheck: skip if somehow the self-namespace fired post.
		$route = \method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( 0 === \strpos( $route, '/better-rest-api-logs/v1' ) ) {
			return;
		}

		$snapshot = $this->build_response_snapshot( $result );
		Queue::backfill( $uuid, $snapshot );
	}

	/**
	 * Build a RequestSnapshot DTO from the current request and resolved IP.
	 *
	 * @param mixed                                  $request WP_REST_Request or stub.
	 * @param array{resolved: ?string, raw: ?string} $ip      Resolved IP pair.
	 * @return RequestSnapshot
	 */
	private function build_request_snapshot( $request, array $ip ): RequestSnapshot {
		$s = new RequestSnapshot();

		$s->method  = \method_exists( $request, 'get_method' ) ? (string) $request->get_method() : '';
		$s->route   = \method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$s->headers = \method_exists( $request, 'get_headers' ) ? (array) $request->get_headers() : [];

		// Route prefix: the first two path segments (e.g. /wp/v2 from /wp/v2/posts).
		$parts           = \explode( '/', \ltrim( $s->route, '/' ), 3 );
		$s->route_prefix = isset( $parts[0], $parts[1] ) ? '/' . $parts[0] . '/' . $parts[1] : '';

		$query_params    = \method_exists( $request, 'get_query_params' ) ? $request->get_query_params() : [];
		$s->query_string = ! empty( $query_params ) ? \http_build_query( $query_params ) : null;

		$s->content_type = '';
		if ( ! empty( $s->headers['content-type'][0] ) ) {
			$s->content_type = (string) $s->headers['content-type'][0];
		}

		$raw_body = \method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		$s->body  = '' !== $raw_body ? $raw_body : null;

		$s->body_bytes_original = null !== $s->body ? \strlen( $s->body ) : 0;
		$s->started_at_micros   = $this->clock->now_micros();

		// Attach resolved IP for Flusher::build_entry (PRIV-05 / D-16).
		$s->ip_resolved   = $ip['resolved'];
		$s->ip_raw_remote = $ip['raw'];

		return $s;
	}

	/**
	 * Build a ResponseSnapshot DTO from the dispatch result.
	 *
	 * @param mixed $result WP_HTTP_Response or similar; null-safe.
	 * @return ResponseSnapshot
	 */
	private function build_response_snapshot( $result ): ResponseSnapshot {
		$s = new ResponseSnapshot();

		if ( $result instanceof \WP_HTTP_Response ) {
			$s->status   = (int) $result->get_status();
			$raw_headers = $result->get_headers();
			$s->headers  = \is_array( $raw_headers ) ? $raw_headers : [];
			$s->body     = Json::encode( $result->get_data() );
		} else {
			$s->status  = 200;
			$s->headers = [];
			$s->body    = null;
		}

		$s->status_class = (int) \floor( $s->status / 100 );

		$content_type = '';
		foreach ( $s->headers as $name => $value ) {
			if ( 'content-type' === \strtolower( (string) $name ) ) {
				$content_type = \is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value;
				break;
			}
		}
		$s->content_type = $content_type;

		$s->body_bytes_original = null !== $s->body ? \strlen( $s->body ) : 0;
		$s->finished_at_micros  = $this->clock->now_micros();

		return $s;
	}

	/**
	 * Load a tab-keyed settings snapshot for the pre-dispatch filter chain.
	 *
	 * Only capture-tab keys needed at the gate are pulled here; network keys
	 * are fetched inline in observe_pre before IpResolver runs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function load_settings(): array {
		return [
			'capture' => [
				'enabled'             => $this->registry->get_setting( 'capture.enabled', true ),
				'route_allowlist'     => (array) $this->registry->get_setting( 'capture.route_allowlist', [] ),
				'route_denylist'      => (array) $this->registry->get_setting( 'capture.route_denylist', [ '/better-rest-api-logs/v1/*' ] ),
				'methods'             => (array) $this->registry->get_setting( 'capture.method_filter', [] ),
				'status_class_filter' => (array) $this->registry->get_setting( 'capture.status_class_filter', [] ),
				'cidr_allowlist'      => (array) $this->registry->get_setting( 'capture.cidr_allowlist', [] ),
				'cidr_denylist'       => (array) $this->registry->get_setting( 'capture.cidr_denylist', [] ),
			],
		];
	}
}
