<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Logger;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Capture\Compressor;
use BetterRestApiLogs\Capture\Filter;
use BetterRestApiLogs\Capture\Redactor;
use BetterRestApiLogs\Capture\Truncator;
use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Support\Clock;
use BetterRestApiLogs\Support\Json;

/**
 * Shutdown handler that drains the request queue and writes to the database (D-06).
 *
 * Registered on `shutdown` at priority 1 so it runs before most other handlers.
 * The full pipeline is:
 *   1. fastcgi_finish_request when available — flushes the response to the
 *      browser before we do any work (T-03-35 mitigation via function_exists guard).
 *   2. ignore_user_abort(true) — keep running even if the TCP connection closes
 *      mid-flight. This leaks to subsequent shutdown handlers (Pitfall 8, T-03-36);
 *      accepted because restoring would require stashing the prior value and the
 *      benefit does not justify the added complexity.
 *   3. Drain Queue::all() into a local snapshot then reset the queue so any
 *      re-entrant call (long-running CLI, custom shutdown) starts clean.
 *   4. Short-circuit when the breaker is open (D-21) — drop the queue rather
 *      than attempt storage that will fail the same way.
 *   5. Build entry DTOs for each queue item: auth-endpoint suppression, header
 *      redaction, body redaction, truncation, optional gzip, body-spill decision.
 *   6. INSERT via Breaker::guard wrapping LogRepository::insert_batch (D-07).
 *   7. Body-spill secondary inserts for entries flagged bodies_spilled; fires
 *      brl_body_spill_failed when the secondary insert fails (D-08, D-28).
 *   8. Fire brl_after_insert_entry for each successfully inserted row.
 *   9. Queue::reset ensures a long-running CLI process doesn't accumulate state.
 *
 * Hook listeners must not assume is_admin() or wp_get_current_user state —
 * shutdown runs outside the normal WP request lifecycle (Pitfall 12).
 *
 * All collaborators (Filter, Redactor, Truncator, Compressor, LogRepository,
 * BodyRepository, Breaker, Registry, Clock) are constructor-injectable so
 * integration tests can provide test doubles. Defaults create real instances
 * suitable for production use.
 *
 * @package BetterRestApiLogs\Logger
 */
final class Flusher {

	private Filter $filter;
	private Redactor $redactor;
	private Truncator $truncator;
	private Compressor $compressor;
	private LogRepository $log_repo;
	private BodyRepository $body_repo;
	private Breaker $breaker;
	private SettingsRegistry $registry;
	private Clock $clock;

	/**
	 * @param Filter|null           $filter     Route/method/status-class gate.
	 * @param Redactor|null         $redactor   Header + body privacy scrubber.
	 * @param Truncator|null        $truncator  Body size cap enforcer.
	 * @param Compressor|null       $compressor Optional gzip wrapper.
	 * @param LogRepository|null    $log_repo   Primary log table writer.
	 * @param BodyRepository|null   $body_repo  Overflow body table writer.
	 * @param Breaker|null          $breaker    Circuit breaker for INSERT path.
	 * @param SettingsRegistry|null $registry  Plugin settings source.
	 * @param Clock|null            $clock      Monotonic clock for created_at.
	 */
	public function __construct(
		?Filter $filter = null,
		?Redactor $redactor = null,
		?Truncator $truncator = null,
		?Compressor $compressor = null,
		?LogRepository $log_repo = null,
		?BodyRepository $body_repo = null,
		?Breaker $breaker = null,
		?SettingsRegistry $registry = null,
		?Clock $clock = null
	) {
		$this->filter     = $filter ?? new Filter();
		$this->redactor   = $redactor ?? new Redactor();
		$this->truncator  = $truncator ?? new Truncator();
		$this->compressor = $compressor ?? new Compressor();
		$this->log_repo   = $log_repo ?? new LogRepository();
		$this->body_repo  = $body_repo ?? new BodyRepository();
		$this->breaker    = $breaker ?? new Breaker();
		$this->registry   = $registry ?? new SettingsRegistry( new \BetterRestApiLogs\Settings\Repository() );
		$this->clock      = $clock ?? new Clock();
	}

	/**
	 * Registered on `shutdown` at priority 1. Drains the queue and writes rows.
	 *
	 * Wraps the entire body in try/catch Throwable — the response is already
	 * gone, so any exception here is observation-only and must not surface.
	 */
	public function on_shutdown(): void {
		try {
			$this->run_pipeline();
		} catch ( \Throwable $e ) {
			// Shutdown errors are observation-only. Never let them bubble.
			unset( $e );
		}
	}

	/**
	 * Inner pipeline: drain queue, build entries, insert, fire hooks.
	 */
	private function run_pipeline(): void {
		// Step 1: early flush so the browser receives the response immediately.
		// Debug tools like Query Monitor inject panel HTML during shutdown and
		// need the connection open until their own handler runs — closing the
		// FastCGI response at priority 1 cuts them off mid-write. Auto-detect QM
		// and let any other tool opt out through the `brl_use_fastcgi_finish_request`
		// filter (default true; passed false from our QM-detection callback).
		$use_fcgi_finish = true;
		if ( \class_exists( 'QM' ) || \defined( 'QM_DISABLED' ) ) {
			$use_fcgi_finish = false;
		}
		/**
		 * Skip the early FastCGI flush when a debug tool needs the response open
		 * past our shutdown handler. Return false to keep the connection open;
		 * the rest of the pipeline still runs after PHP's standard shutdown chain.
		 *
		 * @param bool $use_fcgi_finish Whether to call fastcgi_finish_request().
		 */
		$use_fcgi_finish = (bool) \apply_filters( 'brl_use_fastcgi_finish_request', $use_fcgi_finish );

		if ( $use_fcgi_finish && \function_exists( 'fastcgi_finish_request' ) ) {
			\fastcgi_finish_request();
		}
		// Ignore TCP disconnect — we need to finish the write regardless.
		\ignore_user_abort( true );

		// Step 2: drain the queue into a local snapshot, then reset.
		$entries = Queue::all();
		if ( [] === $entries ) {
			return;
		}
		Queue::reset();

		// Step 3: short-circuit when the breaker is open (D-21).
		if ( $this->breaker->is_open() ) {
			return;
		}

		// Honour the schema-broken latch (D-13). Once the tables are known to be
		// missing or out of sync we stop attempting inserts so $wpdb does not
		// spam debug.log with "table doesn't exist" on every captured request.
		if ( true === SettingsRegistry::get_internal( 'schema_broken' ) ) {
			return;
		}

		// Step 4: build Entry DTOs for each complete queue item.
		$assembled = [];
		// Parallel map (same keys as $assembled) holding the spilled body payload
		// for each spilling entry. The inline columns on those entries are NULLed
		// before the INSERT so an oversize body is stored once — in the secondary
		// table — not duplicated inline and in brl_logs_bodies.
		$spill_bodies = [];
		foreach ( $entries as $pair ) {
			$req = $pair['request'];
			$res = $pair['response'];

			// Incomplete pair: pre-dispatch fired but post-dispatch did not.
			if ( null === $res ) {
				continue;
			}

			// D-10 status-class filter at shutdown.
			$settings = $this->load_settings();
			if ( ! $this->filter->should_capture_at_shutdown( $res->status, $settings ) ) {
				continue;
			}

			$entry = $this->build_entry( $req, $res, $settings );
			if ( null === $entry ) {
				continue;
			}

			$index = \count( $assembled );
			if ( $entry->bodies_spilled ) {
				$spill_bodies[ $index ] = [
					'request_body'  => $entry->request_body,
					'response_body' => $entry->response_body,
				];
				// Keep the inline columns empty on spill — the body lives in the
				// secondary table only.
				$entry->request_body  = null;
				$entry->response_body = null;
			}

			$assembled[] = $entry;
		}

		if ( [] === $assembled ) {
			return;
		}

		// Step 5: insert through the circuit breaker. Suppress $wpdb error
		// printing for the duration of the insert — the response has already
		// gone to the browser, and the failure modes that print here (missing
		// tables, connection drops) are observed through the breaker and the
		// schema-broken latch below rather than the WP debug log.
		global $wpdb;
		$prev_suppress = $wpdb->suppress_errors( true );

		$log_ids = $this->breaker->guard(
			function () use ( $assembled ): array {
				return $this->log_repo->insert_batch( $assembled );
			}
		);

		// Self-heal: if the insert failed because our tables are missing, latch
		// schema_broken so subsequent requests skip the insert path entirely.
		// Schema::install() can clear the latch once the tables come back.
		$last_error = (string) $wpdb->last_error;
		if ( [] === $log_ids
			&& '' !== $last_error
			&& false !== \stripos( $last_error, "doesn't exist" )
		) {
			SettingsRegistry::set_internal( 'schema_broken', true );
		}

		$wpdb->suppress_errors( $prev_suppress );

		if ( [] === $log_ids ) {
			return;
		}

		// Step 6: body-spill secondary inserts for oversize entries (D-08). The
		// payload comes from the parallel map captured before the inline columns
		// were NULLed, keyed by the same index as $assembled.
		foreach ( $assembled as $i => $entry ) {
			if ( ! $entry->bodies_spilled || ! isset( $spill_bodies[ $i ], $log_ids[ $i ] ) ) {
				continue;
			}
			$ok = $this->body_repo->insert_spilled(
				$log_ids[ $i ],
				$spill_bodies[ $i ]['request_body'],
				$spill_bodies[ $i ]['response_body']
			);
			if ( ! $ok ) {
				\do_action( 'brl_body_spill_failed', $log_ids[ $i ] );
			}
		}

		// Step 7: notify listeners of each successfully inserted row.
		foreach ( $assembled as $i => $entry ) {
			if ( ! isset( $log_ids[ $i ] ) ) {
				continue;
			}
			\do_action( 'brl_after_insert_entry', $log_ids[ $i ], $entry->to_array() );
		}
	}

	/**
	 * Build an Entry DTO from a request/response snapshot pair.
	 *
	 * Runs the full privacy pipeline: auth-endpoint suppression, header
	 * redaction with brl_pre/post_redact_headers hooks, body redaction with
	 * brl_pre/post_redact_body hooks, truncation, optional gzip, body-spill
	 * decision, IP anonymization. Fires brl_pre_insert_entry — returning an
	 * empty array from that filter drops the entry entirely.
	 *
	 * @param RequestSnapshot     $req      Captured at rest_pre_dispatch.
	 * @param ResponseSnapshot    $res      Captured at rest_post_dispatch.
	 * @param array<string,mixed> $settings Loaded tab-keyed settings snapshot.
	 * @return Entry|null Entry ready for insertion, or null to skip.
	 */
	private function build_entry( RequestSnapshot $req, ResponseSnapshot $res, array $settings ): ?Entry {
		// Pull the individual settings keys used below.
		$auth_paths      = (array) ( $settings['privacy']['auth_endpoint_allowlist'] ?? [] );
		$extra_headers   = (array) ( $settings['privacy']['redact_extra_headers'] ?? [] );
		$extra_patterns  = (array) ( $settings['privacy']['redact_json_key_patterns'] ?? [] );
		$anonymize       = (bool) ( $settings['privacy']['anonymize_ip'] ?? false );
		$body_allowlist  = (array) ( $settings['capture']['body_content_type_allowlist'] ?? [] );
		$body_cap        = (int) ( $settings['capture']['body_size_cap_bytes'] ?? 65536 );
		$spill_enabled   = (bool) ( $settings['capture']['body_spill_enabled'] ?? false );
		$spill_threshold = (int) ( $settings['capture']['body_spill_threshold_bytes'] ?? 65536 );
		$gzip_opt_in     = (bool) ( $settings['capture']['gzip_bodies'] ?? false );

		// D-17: auth-endpoint suppression — empty bodies for auth routes.
		$suppress_body = Filter::is_auth_endpoint( $req->route, $auth_paths );

		// Header redaction — both request and response sides.
		$req_headers = $this->redact_headers( $req->headers, $extra_headers, $req->route, 'request' );
		$res_headers = $this->redact_headers( $res->headers, $extra_headers, $req->route, 'response' );

		// Body redaction pipeline.
		$req_body = $this->redact_body( $req->body, $req->content_type, $extra_patterns, $suppress_body, $body_allowlist, $req->route, 'request' );
		$res_body = $this->redact_body( $res->body, $res->content_type, $extra_patterns, false, $body_allowlist, $req->route, 'response' );

		// Truncate (D-15 — redact before truncate).
		$req_tr       = null !== $req_body ? $this->truncator->truncate( $req_body, $body_cap ) : null;
		$req_body_out = null !== $req_tr ? $req_tr['body'] : null;
		$req_bytes    = null !== $req_tr ? $req_tr['bytes_original'] : 0;
		$req_trunc    = null !== $req_tr && $req_tr['truncated'];

		$res_tr       = null !== $res_body ? $this->truncator->truncate( $res_body, $body_cap ) : null;
		$res_body_out = null !== $res_tr ? $res_tr['body'] : null;
		$res_bytes    = null !== $res_tr ? $res_tr['bytes_original'] : 0;
		$res_trunc    = null !== $res_tr && $res_tr['truncated'];

		// Compress (CAP-10).
		$req_body_out = null !== $req_body_out ? $this->compressor->maybe_compress( $req_body_out, $gzip_opt_in ) : null;
		$res_body_out = null !== $res_body_out ? $this->compressor->maybe_compress( $res_body_out, $gzip_opt_in ) : null;

		// Body-spill decision (D-08): spill when either body exceeds the threshold.
		$req_size = null !== $req_body_out ? \strlen( $req_body_out ) : 0;
		$res_size = null !== $res_body_out ? \strlen( $res_body_out ) : 0;
		$spill    = $spill_enabled && ( $req_size >= $spill_threshold || $res_size >= $spill_threshold );

		// IP anonymization (D-16). ip_raw_remote is never anonymized.
		$ip_resolved = $anonymize
			? $this->redactor->anonymize_ip( $req->ip_resolved )
			: $req->ip_resolved;

		// Assemble Entry from snapshots.
		$entry = Entry::from_snapshots(
			$req,
			$res,
			[
				'created_at'          => $this->clock->now(),
				'user_id'             => \get_current_user_id(),
				'ip_resolved'         => $ip_resolved,
				'ip_raw_remote'       => $req->ip_raw_remote,
				'bodies_spilled'      => $spill,
				'migration_source_id' => null,
			]
		);

		$entry->request_headers         = Json::encode( $req_headers );
		$entry->response_headers        = Json::encode( $res_headers );
		$entry->request_body_bytes      = $req_bytes;
		$entry->request_body_truncated  = $req_trunc;
		$entry->response_body_bytes     = $res_bytes;
		$entry->response_body_truncated = $res_trunc;

		// Set the bodies on the entry. run_pipeline moves the payload of a
		// spilling entry into the secondary table and NULLs these inline columns,
		// so the body is never stored twice.
		$entry->request_body  = $req_body_out;
		$entry->response_body = $res_body_out;

		// brl_pre_insert_entry: third-party code may mutate or drop the entry.
		// An empty array return signals "drop this entry".
		$entry_data = \apply_filters( 'brl_pre_insert_entry', $entry->to_array(), $req, $res );
		if ( ! \is_array( $entry_data ) || [] === $entry_data ) {
			return null;
		}

		// Rehydrate from the (possibly mutated) array so documented mutations are
		// honoured rather than silently discarded. The row written — and what the
		// spill insert in run_pipeline reads — reflects the filtered values.
		return Entry::from_array( $entry_data );
	}

	/**
	 * Redact headers with brl_pre_redact_headers / brl_post_redact_headers hooks.
	 *
	 * @param  array<string,mixed> $headers      Raw headers map.
	 * @param  array<string>       $extra_headers Additional header names to redact.
	 * @param  string              $route         REST route for hook context.
	 * @param  string              $direction     'request' or 'response'.
	 * @return array<string,mixed> Redacted headers map.
	 */
	private function redact_headers( array $headers, array $extra_headers, string $route, string $direction ): array {
		$context = [
			'route'     => $route,
			'direction' => $direction,
		];

		/** @var array<string,mixed> $headers */
		$headers = \apply_filters( 'brl_pre_redact_headers', $headers, $context );
		if ( ! \is_array( $headers ) ) {
			$headers = [];
		}

		$redacted = $this->redactor->redact_headers( $headers, $extra_headers );

		/** @var array<string,mixed> $redacted */
		$redacted = \apply_filters( 'brl_post_redact_headers', $redacted, $headers, $context );
		if ( ! \is_array( $redacted ) ) {
			$redacted = [];
		}

		return $redacted;
	}

	/**
	 * Redact body with brl_pre_redact_body / brl_post_redact_body hooks.
	 *
	 * Returns null when body capture is suppressed or the content-type is not
	 * in the allowlist. Auth-endpoint suppression also produces null.
	 *
	 * @param  string|null   $body          Raw body or null.
	 * @param  string        $content_type  Content-Type header value.
	 * @param  array<string> $extra_patterns Extra JSON key patterns to redact.
	 * @param  bool          $suppress      True when auth-endpoint suppression applies.
	 * @param  array<string> $body_allowlist Content-type allowlist.
	 * @param  string        $route         REST route for hook context.
	 * @param  string        $direction     'request' or 'response'.
	 * @return string|null Redacted body or null.
	 */
	private function redact_body(
		?string $body,
		string $content_type,
		array $extra_patterns,
		bool $suppress,
		array $body_allowlist,
		string $route,
		string $direction
	): ?string {
		if ( $suppress || null === $body || '' === $body ) {
			return null;
		}

		if ( ! $this->redactor->should_capture_body( $content_type, $body_allowlist ) ) {
			return null;
		}

		$context = [
			'route'     => $route,
			'direction' => $direction,
		];

		// brl_pre_redact_body fires before redaction — listeners see the raw body.
		$body     = (string) \apply_filters( 'brl_pre_redact_body', $body, $context );
		$original = $body;

		// Apply credential-pattern redaction based on content-type. The patterns
		// are spliced into a /.../i regex downstream, so each one must be quoted
		// against that exact delimiter — a bare preg_quote() leaves `/` intact
		// and a stored value like "api/v2" would break the delimiter.
		$pattern = '' !== \implode( '', $extra_patterns )
			? \implode(
				'|',
				\array_map(
					static function ( $p ): string {
						return \preg_quote( (string) $p, '/' );
					},
					$extra_patterns
				)
			)
			: '';

		if ( false !== \stripos( $content_type, 'json' ) ) {
			$body = $this->redactor->redact_json_body( $body, $pattern );
		} elseif ( false !== \stripos( $content_type, 'x-www-form-urlencoded' ) ) {
			$body = $this->redactor->redact_form_body( $body, $pattern );
		}

		// brl_post_redact_body fires after redaction — listeners see redacted body and original.
		$body = (string) \apply_filters( 'brl_post_redact_body', $body, $original, $context );

		return $body;
	}

	/**
	 * Load a tab-keyed settings snapshot for the pipeline.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function load_settings(): array {
		return [
			'capture' => [
				'body_size_cap_bytes'         => $this->registry->get_setting( 'capture.body_size_cap_bytes', 65536 ),
				'body_content_type_allowlist' => $this->registry->get_setting( 'capture.body_content_type_allowlist', [] ),
				'body_spill_enabled'          => $this->registry->get_setting( 'capture.body_spill_enabled', false ),
				'body_spill_threshold_bytes'  => $this->registry->get_setting( 'capture.body_spill_threshold_bytes', 65536 ),
				'gzip_bodies'                 => $this->registry->get_setting( 'capture.gzip_bodies', false ),
				'status_class_filter'         => $this->registry->get_setting( 'capture.status_class_filter', [] ),
			],
			'privacy' => [
				'auth_endpoint_allowlist'  => $this->registry->get_setting( 'privacy.auth_endpoint_allowlist', [] ),
				'redact_extra_headers'     => $this->registry->get_setting( 'privacy.redact_extra_headers', [] ),
				'redact_json_key_patterns' => $this->registry->get_setting( 'privacy.redact_json_key_patterns', [] ),
				'anonymize_ip'             => $this->registry->get_setting( 'privacy.anonymize_ip', false ),
			],
		];
	}
}
