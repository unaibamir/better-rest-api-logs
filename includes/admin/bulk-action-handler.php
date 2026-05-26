<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\Export\CsvWriter;
use BetterRestApiLogs\Export\NdjsonWriter;
use BetterRestApiLogs\Export\Streamer;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Repository as SettingsRepository;
use BetterRestApiLogs\Support\Bytes;

/**
 * Admin-post handlers for bulk-delete and single-row-delete.
 *
 * Note: the `Handler` suffix is grandfathered by CONTEXT.md D-47 — this class
 * is the one place where WP's admin-post.php dispatch needs an unambiguous naming
 * hook for the routed action. The CLAUDE.md no-`*Handler` rule does not apply
 * here; see PATTERNS.md §"Deviation Notes" #1.
 *
 * Wired at:
 *   add_action( 'admin_post_brl_delete_log', [ BulkActionHandler::class, 'handle_single_delete' ] )
 *   add_action( 'admin_post_brl_export',     [ BulkActionHandler::class, 'handle_export' ] )
 *   add_action( 'load-{list_hook}',          [ BulkActionHandler::class, 'handle_early_export' ] )
 *
 * handle() runs from the list-screen render callback for the bulk-delete GET
 * form; it never streams a download (exports are owned by handle_early_export,
 * which fires before any output).
 */
final class BulkActionHandler {

	private LogRepository $repo;

	/**
	 * No-arg constructor resolves a fresh LogRepository.
	 *
	 * When wired via the container, Plugin::boot() can inject a shared instance
	 * using the constructor directly. The test scaffold calls new BulkActionHandler()
	 * without arguments so the zero-arg form is required.
	 *
	 * @param LogRepository|null $repo Injected for testability; defaults to a new instance.
	 */
	public function __construct( ?LogRepository $repo = null ) {
		$this->repo = $repo ?? new LogRepository();
	}

	/**
	 * Handle the admin_post_brl_bulk_action request.
	 *
	 * Security order: nonce first (check_admin_referer calls wp_die on failure),
	 * capability second (defense-in-depth). POST data is sanitized after both
	 * checks pass.
	 *
	 * 3-branch result messaging per ~/.claude/rules/wordpress-safety.md:
	 *   full success  → ?deleted=N
	 *   partial       → ?deleted=N&failed=M
	 *   total failure → ?error=delete_failed
	 *   nothing given → ?error=nothing_selected
	 */
	public function handle(): void {
		// The bulk dropdown lives in the WP_List_Table form, so the submitted
		// _wpnonce is the one WP_List_Table emits: bulk-{plural}, i.e. bulk-logs.
		\check_admin_referer( 'bulk-logs' );

		if ( ! \current_user_can( \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die(
				\esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ),
				'',
				[ 'response' => 403 ]
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer.
		$action = isset( $_POST['bulk_action'] )
			? \sanitize_key( \wp_unslash( $_POST['bulk_action'] ) )
			: ( isset( $_POST['action'] ) ? \sanitize_key( \wp_unslash( $_POST['action'] ) ) : '' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is run through absint() in the array_map below before any use.
		$raw = isset( $_POST['log_ids'] ) ? (array) \wp_unslash( $_POST['log_ids'] ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ids = \array_values(
			\array_filter(
				\array_map( 'absint', $raw ),
				static function ( int $i ): bool {
					return $i > 0;
				}
			)
		);

		$base = $this->referer_url();

		// Export bulk actions are handled earlier on load-{hook} by
		// handle_early_export, which streams before any output. This render-path
		// handler only deletes — never streams a download — so a file can't be
		// emitted after admin-header.php has already started the page.
		if ( \in_array( $action, [ 'brl_export_csv', 'brl_export_ndjson' ], true ) ) {
			return;
		}

		// Treat 'brl_delete', 'delete', and empty action (when log_ids are given) as delete.
		$is_delete_action = \in_array( $action, [ 'brl_delete', 'delete', '' ], true );

		if ( ! $is_delete_action || [] === $ids ) {
			$this->redirect_back( \add_query_arg( 'error', 'nothing_selected', $base ) );
			return;
		}

		$deleted_ids   = $this->repo->delete_many_returning_ids( $ids );
		$deleted_count = \count( $deleted_ids );
		$failed_count  = \count( $ids ) - $deleted_count;

		// Fire brl_log_deleted only for IDs that the repository confirms were
		// actually removed — extensions must not be notified about a row that
		// still exists (e.g. a stale checkbox selection in a partial-failure
		// bulk operation).
		foreach ( $deleted_ids as $id ) {
			\do_action( 'brl_log_deleted', $id, 'admin' );
		}

		if ( $deleted_count === \count( $ids ) ) {
			$this->redirect_back( \add_query_arg( 'deleted', $deleted_count, $base ) );
		} elseif ( $deleted_count > 0 ) {
			$this->redirect_back(
				\add_query_arg(
					[
						'deleted' => $deleted_count,
						'failed'  => $failed_count,
					],
					$base
				)
			);
		} else {
			$this->redirect_back( \add_query_arg( 'error', 'delete_failed', $base ) );
		}
	}

	/**
	 * Handle the admin_post_brl_delete_log request.
	 *
	 * Per-row nonce action `brl_delete_{id}` prevents replay across rows.
	 * Nonce first, capability second, then delete.
	 */
	public function handle_single_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below via check_admin_referer.
		$log_id = isset( $_GET['log_id'] ) ? \absint( \wp_unslash( $_GET['log_id'] ) ) : 0;
		$base   = $this->referer_url();

		if ( $log_id <= 0 ) {
			$this->redirect_back( \add_query_arg( 'error', 'delete_failed', $base ) );
			return;
		}

		\check_admin_referer( 'brl_delete_' . $log_id, '_wpnonce' );

		if ( ! \current_user_can( \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die(
				\esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$ok = $this->repo->delete( $log_id );

		if ( $ok ) {
			\do_action( 'brl_log_deleted', $log_id, 'admin' );
			$this->redirect_back( \add_query_arg( 'deleted', 1, $base ) );
		} else {
			$this->redirect_back( \add_query_arg( 'error', 'delete_failed', $base ) );
		}
	}

	/**
	 * Early export intercept — called on load-{list_hook}, before admin-header.php
	 * outputs any HTML or sets Content-Type: text/html.
	 *
	 * Detects a GET bulk-action export (action or action2 = brl_export_csv /
	 * brl_export_ndjson) and streams the file then exits. Non-export actions
	 * are ignored so the normal page render continues.
	 *
	 * Security order: nonce first (check_admin_referer), capability second.
	 * GET values are mirrored into $_REQUEST for check_admin_referer() and
	 * into $_POST for stream_export_for_ids().
	 *
	 * @return void
	 */
	public static function handle_early_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified below via check_admin_referer once we confirm this is an export action.
		$top    = isset( $_GET['action'] ) ? \sanitize_key( \wp_unslash( (string) $_GET['action'] ) ) : '';
		$bottom = isset( $_GET['action2'] ) ? \sanitize_key( \wp_unslash( (string) $_GET['action2'] ) ) : '';
		$action = '' !== $top && '-1' !== $top ? $top : $bottom;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! \in_array( $action, [ 'brl_export_csv', 'brl_export_ndjson' ], true ) ) {
			return; // Not an export action — let the page render proceed.
		}

		// Mirror the nonce from GET into $_REQUEST so check_admin_referer() finds it.
		if ( isset( $_GET['_wpnonce'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- value is only moved; actual verification happens in check_admin_referer() below.
			$nonce                = \sanitize_text_field( \wp_unslash( (string) $_GET['_wpnonce'] ) );
			$_REQUEST['_wpnonce'] = $nonce;
			$_POST['_wpnonce']    = $nonce;
		}

		// The bulk dropdown lives in the WP_List_Table form, so the submitted
		// _wpnonce is the one WP_List_Table emits: bulk-{plural}, i.e. bulk-logs.
		\check_admin_referer( 'bulk-logs' );

		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die(
				\esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ),
				'',
				[ 'response' => 403 ]
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; each element is absint'd below.
		$ids_raw = isset( $_GET['log_ids'] ) && \is_array( $_GET['log_ids'] )
			? \wp_unslash( $_GET['log_ids'] )
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$ids = \array_values(
			\array_filter(
				\array_map( 'absint', (array) $ids_raw ),
				static function ( int $i ): bool {
					return $i > 0;
				}
			)
		);

		$format = ( 'brl_export_csv' === $action ) ? 'csv' : 'ndjson';

		self::stream_export_for_ids( $ids, $format );
		// stream_export_for_ids exits; the line below is only reached in test seams.
	}

	/**
	 * Handle admin_post_brl_export — streams a download to the browser.
	 *
	 * Security order mirrors handle(): nonce first, capability second (T-05-06-05).
	 * Resolves QueryArgs from POST: either the serialised active filter set ("Export
	 * current view" path) or a list of selected log_ids ("Export selected" path).
	 *
	 * Calls exit after streaming so the Flusher::on_shutdown does not truncate the
	 * response body (T-05-06-05). In integration tests the wp_redirect filter and
	 * the exit gate are short-circuited by the test harness.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		// SECURITY ORDER: nonce first — check_admin_referer calls wp_die on failure.
		\check_admin_referer( 'brl_export' );

		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die(
				\esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ),
				'',
				[ 'response' => 403 ]
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer.
		$raw_format = isset( $_POST['format'] )
			? \sanitize_key( \wp_unslash( (string) $_POST['format'] ) )
			: 'ndjson';
		$format     = ( 'csv' === $raw_format ) ? 'csv' : 'ndjson';

		// Distinguish "Export selected" (log_ids[] present) from "Export current view".
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element absint'd below.
		$raw_ids = isset( $_POST['log_ids'] ) ? (array) \wp_unslash( $_POST['log_ids'] ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ids = \array_values(
			\array_filter(
				\array_map( 'absint', $raw_ids ),
				static function ( int $i ): bool {
					return $i > 0;
				}
			)
		);

		if ( [] !== $ids ) {
			self::stream_export_for_ids( $ids, $format );
			return; // Reached only in test seams where exit() is intercepted.
		}

		// "Export current view" — rebuild QueryArgs from the posted filter vars.
		// wp_unslash + sanitize_text_field at the from_array boundary (QueryArgs
		// already validates each field; sanitize_text_field strips tags + trims).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified at top.
		$raw_args = (array) $_POST;
		$clean    = [];
		foreach ( $raw_args as $k => $v ) {
			$clean[ \sanitize_key( $k ) ] = \is_array( $v )
				? $v
				: \sanitize_text_field( \wp_unslash( (string) $v ) );
		}

		try {
			$args = QueryArgs::from_array( $clean );
		} catch ( \InvalidArgumentException $_unused ) {
			$args = new QueryArgs();
		}

		$timestamp = \gmdate( 'Y-m-d' );
		$filename  = "rest-api-logs-{$timestamp}.{$format}";

		$streamer = Plugin::instance()->container()->get( Streamer::class );
		$streamer->send_download_headers( $format, $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is not a filesystem path; WP_Filesystem has no equivalent.
		$sink = \fopen( 'php://output', 'wb' );
		if ( false === $sink ) {
			\wp_die( \esc_html__( 'Could not open output stream.', 'better-rest-api-logs' ), '', [ 'response' => 500 ] );
		}

		$streamer->stream( $args, $format, $sink, true, 'admin' );

		// Exit before shutdown so Flusher::on_shutdown does not truncate the stream
		// (T-05-06-05). Integration tests intercept exit via the wp_redirect filter
		// and the test harness's exit-override mechanism.
		exit;
	}

	/**
	 * Stream an export for a specific set of log ids (the "Export selected" path).
	 *
	 * Fetches each selected row via LogRepository::find() and writes it through
	 * the appropriate format writer. Bounded by the number of checkboxes the
	 * admin can select on a single page (max 500 per Screen Options clamp).
	 * Sends download headers, streams, and exits.
	 *
	 * @param int[]  $ids    Validated positive integers.
	 * @param string $format 'csv' or 'ndjson'.
	 * @return void
	 */
	private static function stream_export_for_ids( array $ids, string $format ): void {
		// Defense in depth: never stream a download once output has begun. The
		// real entry point (handle_early_export on load-{hook}) runs before any
		// HTML, so this only trips if a future caller reaches here from a render
		// path — better a clean error than a corrupt download with page chrome.
		if ( \headers_sent() ) {
			\wp_die(
				\esc_html__( 'Cannot start a download after the page has begun rendering.', 'better-rest-api-logs' ),
				'',
				[ 'response' => 500 ]
			);
		}

		$timestamp = \gmdate( 'Y-m-d' );
		$filename  = "rest-api-logs-selected-{$timestamp}.{$format}";

		$streamer = Plugin::instance()->container()->get( Streamer::class );
		$streamer->send_download_headers( $format, $filename );

		$repo   = Plugin::instance()->container()->get( LogRepository::class );
		$bodies = new BodyRepository();

		$anonymize_ip = (bool) ( new SettingsRegistry( new SettingsRepository() ) )->get_setting( 'privacy.anonymize_ip', false );
		$writer       = 'csv' === $format ? new CsvWriter() : new NdjsonWriter( $anonymize_ip );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is not a filesystem path.
		$sink = \fopen( 'php://output', 'wb' );
		if ( false === $sink ) {
			\wp_die( \esc_html__( 'Could not open output stream.', 'better-rest-api-logs' ), '', [ 'response' => 500 ] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to php://output.
		\fwrite( $sink, $writer->preamble() );

		// Fetch rows by ID. Bounded by the page's row count (max 500).
		$rows = [];
		foreach ( $ids as $id ) {
			$entry = $repo->find( $id );
			if ( null !== $entry ) {
				$rows[] = $entry;
			}
		}

		// Resolve spilled bodies for NDJSON (D-13) in a single batch query.
		if ( 'ndjson' === $format && [] !== $rows ) {
			$spilled_ids = [];
			foreach ( $rows as $entry ) {
				if ( $entry->bodies_spilled ) {
					$spilled_ids[] = $entry->id;
				}
			}
			if ( [] !== $spilled_ids ) {
				$body_map = $bodies->find_by_log_ids( $spilled_ids );
				foreach ( $rows as $entry ) {
					if ( $entry->bodies_spilled && isset( $body_map[ $entry->id ] ) ) {
						// Decompress if gzip_bodies was enabled when the row was stored.
						// Bytes::gunzip() passes non-gzip input through via a magic-byte
						// check, so calling it unconditionally is safe.
						$raw_req              = $body_map[ $entry->id ]['request_body'];
						$raw_res              = $body_map[ $entry->id ]['response_body'];
						$entry->request_body  = null !== $raw_req ? Bytes::gunzip( $raw_req ) : null;
						$entry->response_body = null !== $raw_res ? Bytes::gunzip( $raw_res ) : null;
					}
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to php://output.
		\fwrite( $sink, $writer->rows( $rows ) );
		\flush();
		exit;
	}

	/**
	 * Determine the redirect destination — list page as canonical fallback.
	 *
	 * @return string Absolute URL pointing at the log list screen.
	 */
	private function referer_url(): string {
		return \admin_url( 'tools.php?page=better-rest-api-logs' );
	}

	/**
	 * Send a safe redirect.
	 *
	 * The wp_safe_redirect call refuses off-site URLs (T-04-41). The caller is
	 * responsible for exiting after this when running under a real HTTP request —
	 * the class itself does not call exit so that integration tests can assert
	 * database state after the handler completes (exit would terminate the test
	 * process).
	 *
	 * @param string $url Destination URL.
	 */
	private function redirect_back( string $url ): void {
		\wp_safe_redirect( $url );
	}
}
