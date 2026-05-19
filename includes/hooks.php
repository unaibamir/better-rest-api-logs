<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry of every brl_* action and filter the plugin fires.
 *
 * Each hook below carries a full signature, description, and runnable example.
 * The docs generator in Phase 6 reads this file to build docs/HOOKS.md — keep
 * the docblock shape consistent: @since, @param lines, then @example.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_should_capture
 *
 * Last word at the pre-dispatch stage — return false to skip capture for this
 * request even when the route/method/CIDR settings would allow it. Return true
 * to force capture even when a prior filter denied it. Runs inside a try/catch
 * Throwable, so a crashing listener never surfaces to the REST client.
 *
 * @since 1.0.0
 *
 * @param bool             $capture Whether to capture. Defaults to true unless
 *                                  route/method/CIDR filters denied the request.
 * @param \WP_REST_Request $request The incoming REST request.
 *
 * @example
 *   add_filter(
 *       'brl_should_capture',
 *       static function ( $capture, $request ) {
 *           // Skip order endpoints — they carry too much customer data.
 *           if ( str_starts_with( $request->get_route(), '/wc/v3/orders' ) ) {
 *               return false;
 *           }
 *           return $capture;
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_pre_redact_headers
 *
 * Fires before the built-in header redactor runs, giving you a chance to strip
 * or replace values the plugin doesn't know about. Returning a non-array falls
 * back to an empty map — don't return null or a scalar.
 *
 * @since 1.0.0
 *
 * @param array<string,mixed> $headers Raw headers map (lower-cased keys).
 * @param array<string,mixed> $context { route: string, direction: 'request'|'response' }
 *
 * @example
 *   add_filter(
 *       'brl_pre_redact_headers',
 *       static function ( $headers, $context ) {
 *           // Wipe internal tracing headers before the log sees them.
 *           unset( $headers['x-internal-trace-id'] );
 *           return $headers;
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_post_redact_headers
 *
 * Fires after the built-in header redactor has run. The redacted map is the
 * first argument; the original pre-redaction map is the second, in case you need
 * to compare what changed. A non-array return falls back to an empty map.
 *
 * @since 1.0.0
 *
 * @param array<string,mixed> $redacted_headers  Headers after built-in redaction.
 * @param array<string,mixed> $original_headers  Headers before redaction.
 * @param array<string,mixed> $context           { route: string, direction: 'request'|'response' }
 *
 * @example
 *   add_filter(
 *       'brl_post_redact_headers',
 *       static function ( $redacted, $original, $context ) {
 *           // Keep the first two chars of Authorization so you can tell bearer from basic.
 *           if ( isset( $original['authorization'] ) ) {
 *               $redacted['authorization'] = substr( $original['authorization'], 0, 2 ) . '***';
 *           }
 *           return $redacted;
 *       },
 *       10,
 *       3
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_pre_redact_body
 *
 * Fires before the built-in body redactor runs. The full raw body string is
 * passed so you can remove anything sensitive before the pattern-based scrubber
 * takes a pass. Returning a non-string falls back to an empty string.
 *
 * @since 1.0.0
 *
 * @param string              $body    Raw body before redaction.
 * @param array<string,mixed> $context { route: string, direction: 'request'|'response' }
 *
 * @example
 *   add_filter(
 *       'brl_pre_redact_body',
 *       static function ( $body, $context ) {
 *           // Drop SSN-shaped values before anything else sees them.
 *           return preg_replace( '/\b\d{3}-\d{2}-\d{4}\b/', '[ssn]', $body );
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_post_redact_body
 *
 * Fires after the built-in body redactor has run. Both the redacted body and the
 * pre-redaction body are available, so you can audit what changed or apply a
 * second pass. Returning a non-string falls back to an empty string.
 *
 * @since 1.0.0
 *
 * @param string              $redacted_body  Body after built-in redaction.
 * @param string              $original_body  Body before redaction (post brl_pre_redact_body).
 * @param array<string,mixed> $context        { route: string, direction: 'request'|'response' }
 *
 * @example
 *   add_filter(
 *       'brl_post_redact_body',
 *       static function ( $redacted, $original, $context ) {
 *           // Truncate to 500 chars after redaction for a specific noisy route.
 *           if ( '/my-plugin/v1/bulkimport' === $context['route'] ) {
 *               return substr( $redacted, 0, 500 );
 *           }
 *           return $redacted;
 *       },
 *       10,
 *       3
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_pre_insert_entry
 *
 * Last chance to mutate or drop a log entry before it reaches the database.
 * The entry data array mirrors the columns in wp_brl_logs — you can add
 * extra fields, but the INSERT will ignore any column it doesn't know.
 * Return an empty array to drop the entry entirely; it won't be written.
 *
 * Fires AFTER redaction — listeners see scrubbed data, never raw credentials.
 *
 * @since 1.0.0
 *
 * @param array<string,mixed>                        $entry_data Assembled column values for the row.
 * @param \BetterRestApiLogs\Domain\RequestSnapshot  $req        Request snapshot (post-redaction).
 * @param \BetterRestApiLogs\Domain\ResponseSnapshot $res        Response snapshot (post-redaction).
 *
 * @example
 *   add_filter(
 *       'brl_pre_insert_entry',
 *       static function ( $entry, $req, $res ) {
 *           // Drop any 2xx response to /wp/v2/users from the log.
 *           if ( '/wp/v2/users' === $req->route && 2 === $res->status_class ) {
 *               return [];
 *           }
 *           return $entry;
 *       },
 *       10,
 *       3
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Action: brl_after_insert_entry
 *
 * Fires once per successfully inserted log row, right after the INSERT commits.
 * Use it to relay log data to an external queue, trigger a webhook, or update
 * a counter table. The $entry_data array is the same shape as brl_pre_insert_entry.
 *
 * @since 1.0.0
 *
 * @param int                 $log_id     Primary key of the new wp_brl_logs row.
 * @param array<string,mixed> $entry_data Column values that were written.
 *
 * @example
 *   add_action(
 *       'brl_after_insert_entry',
 *       static function ( $log_id, $entry_data ) {
 *           // Enqueue async relay to a webhook.
 *           wp_schedule_single_event( time(), 'my_relay_hook', [ $log_id ] );
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Action: brl_circuit_armed
 *
 * Fires when the circuit breaker trips open after too many consecutive INSERT
 * failures. Use it to send an admin alert or write to a fallback log file.
 * The breaker stays open until the first successful write after the cooldown.
 *
 * @since 1.0.0
 *
 * @param string $reason         Human-readable failure description.
 * @param int    $opens_until_ts Unix timestamp after which the breaker will attempt to close.
 *
 * @example
 *   add_action(
 *       'brl_circuit_armed',
 *       static function ( $reason, $opens_until_ts ) {
 *           wp_mail(
 *               get_option( 'admin_email' ),
 *               'REST log storage paused',
 *               "Circuit breaker opened: {$reason}. Retries after " . date( 'c', $opens_until_ts )
 *           );
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Action: brl_circuit_resumed
 *
 * Fires on the first successful INSERT after the circuit breaker was open. By
 * this point log rows are flowing again. Pair with brl_circuit_armed to send a
 * recovery notification.
 *
 * @since 1.0.0
 *
 * @param int $resumed_at_ts Unix timestamp of the recovery moment.
 *
 * @example
 *   add_action(
 *       'brl_circuit_resumed',
 *       static function ( $resumed_at_ts ) {
 *           wp_mail(
 *               get_option( 'admin_email' ),
 *               'REST log storage recovered',
 *               'Log writes resumed at ' . date( 'c', $resumed_at_ts )
 *           );
 *       }
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Action: brl_body_spill_failed
 *
 * Fires when a body-spill secondary INSERT fails after the parent wp_brl_logs
 * row already landed. The log row exists but its request/response bodies are
 * missing from wp_brl_log_bodies. Use this hook to alert or clean up.
 *
 * @since 1.0.0
 *
 * @param int $log_id Primary key of the wp_brl_logs row whose body insert failed.
 *
 * @example
 *   add_action(
 *       'brl_body_spill_failed',
 *       static function ( $log_id ) {
 *           error_log( "brl: body spill failed for log #{$log_id}" );
 *       }
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_use_fastcgi_finish_request
 *
 * Controls whether the shutdown flusher calls fastcgi_finish_request() to close
 * the response before draining the queue. The default is true unless Query
 * Monitor is loaded (class QM or constant QM_DISABLED is present), in which
 * case the plugin auto-disables it so QM's shutdown-time HTML injection still
 * reaches the browser. Return false to keep the connection open for any other
 * debug or profiling tool that writes during shutdown.
 *
 * @since 1.0.1
 *
 * @param bool $use Whether to call fastcgi_finish_request(). True by default,
 *                  flipped to false automatically when QM is loaded.
 *
 * @example
 *   // Keep the response open when New Relic's RUM injector is running.
 *   add_filter(
 *       'brl_use_fastcgi_finish_request',
 *       static function ( $use ) {
 *           return $use && ! function_exists( 'newrelic_get_browser_timing_header' );
 *       }
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_query_args
 *
 * Lets extensions mutate the validated QueryArgs object before it reaches
 * LogRepository::search. Fires from the Admin list screen, the REST list
 * endpoint, and the WP-CLI list command.
 *
 * @since 1.0.0
 *
 * @param \BetterRestApiLogs\DB\Query\QueryArgs $args    The validated filter set.
 * @param string                                $surface One of 'admin' | 'rest' | 'cli'.
 *
 * @example
 *   add_filter(
 *       'brl_query_args',
 *       static function ( $args, $surface ) {
 *           if ( 'rest' === $surface && current_user_can( 'edit_posts' ) ) {
 *               $args->user_id = get_current_user_id();
 *           }
 *           return $args;
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_rest_response
 *
 * Last-word filter on every REST response payload. Lets extensions add
 * fields without forking a controller.
 *
 * @since 1.0.0
 *
 * @param array<string,mixed> $payload The response body.
 * @param string              $route   The matched route slug (e.g. '/logs', '/stats').
 * @param \WP_REST_Request    $request The originating request.
 *
 * @example
 *   add_filter(
 *       'brl_rest_response',
 *       static function ( $payload, $route ) {
 *           if ( '/stats' === $route ) {
 *               $payload['my_extra_field'] = 'custom value';
 *           }
 *           return $payload;
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Action: brl_log_deleted
 *
 * Fires after a successful delete from any surface (admin UI, REST API, WP-CLI).
 *
 * @since 1.0.0
 *
 * @param int    $log_id  The deleted row's primary key.
 * @param string $surface One of 'admin' | 'rest' | 'cli'.
 *
 * @example
 *   add_action(
 *       'brl_log_deleted',
 *       static function ( $log_id, $surface ) {
 *           // Invalidate a related cache keyed on the log ID.
 *           wp_cache_delete( "brl_detail_{$log_id}" );
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_admin_required_capability
 *
 * Swaps the default `manage_options` cap for a custom role across every cap
 * check in the admin UI, REST controllers, and WP-CLI verbs. The $context arg
 * lets you distinguish surface areas when different roles need different access.
 *
 * @since 1.0.0
 * @param string $cap     Default 'manage_options'.
 * @param string $context One of 'admin' | 'rest' | 'cli'.
 *
 * @example
 *   add_filter(
 *       'brl_admin_required_capability',
 *       static function ( $cap, $ctx ) {
 *           // Let editors view logs via the REST API without giving admin access.
 *           return 'rest' === $ctx ? 'edit_posts' : $cap;
 *       },
 *       10,
 *       2
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Filter: brl_list_columns
 *
 * Lets extensions add, remove, or reorder columns on the admin list table.
 * The returned array is passed straight to WP_List_Table::get_columns(); keep
 * the 'cb' key if you want bulk-action checkboxes to remain.
 *
 * @since 1.0.0
 * @param array<string,string> $columns Column slug => label map (WP_List_Table format).
 *
 * @example
 *   add_filter(
 *       'brl_list_columns',
 *       static function ( $cols ) {
 *           $cols['my_plugin_col'] = 'My Plugin';
 *           return $cols;
 *       }
 *   );
 *
 * ──────────────────────────────────────────────────────────────────────────────
 */
final class Hooks {
	// Phase 6 implements register()/document() methods + docs/HOOKS.md generation.
}
