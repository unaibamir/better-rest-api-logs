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

/**
 * Admin-post handlers for bulk-delete and single-row-delete.
 *
 * Note: the `Handler` suffix is grandfathered by CONTEXT.md D-47 — this class
 * is the one place where WP's admin-post.php dispatch needs an unambiguous naming
 * hook for the routed action. The CLAUDE.md no-`*Handler` rule does not apply
 * here; see PATTERNS.md §"Deviation Notes" #1.
 *
 * Wired at:
 *   add_action( 'admin_post_brl_bulk_action', [ BulkActionHandler::class, 'handle' ] )
 *   add_action( 'admin_post_brl_delete_log',  [ BulkActionHandler::class, 'handle_single_delete' ] )
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
		\check_admin_referer( 'brl_bulk', '_wpnonce' );

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

		// Route export bulk actions to the streaming export path before the delete path.
		// Format is encoded in the action slug (brl_export_csv / brl_export_ndjson).
		if ( \in_array( $action, [ 'brl_export_csv', 'brl_export_ndjson' ], true ) ) {
			$format = ( 'brl_export_csv' === $action ) ? 'csv' : 'ndjson';
			self::stream_export_for_ids( $ids, $format );
			return; // stream_export_for_ids exits; return is the test-seam path.
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

		$streamer->stream( $args, $format, $sink, true );

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
		$timestamp = \gmdate( 'Y-m-d' );
		$filename  = "rest-api-logs-selected-{$timestamp}.{$format}";

		$streamer = Plugin::instance()->container()->get( Streamer::class );
		$streamer->send_download_headers( $format, $filename );

		$repo   = Plugin::instance()->container()->get( LogRepository::class );
		$bodies = new BodyRepository();
		$writer = 'csv' === $format ? new CsvWriter() : new NdjsonWriter();

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
						$entry->request_body  = $body_map[ $entry->id ]['request_body'];
						$entry->response_body = $body_map[ $entry->id ]['response_body'];
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
