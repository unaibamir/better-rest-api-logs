<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;

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

		// Treat 'brl_delete', 'delete', and empty action (when log_ids are given) as delete.
		$is_delete_action = \in_array( $action, [ 'brl_delete', 'delete', '' ], true );

		if ( ! $is_delete_action || [] === $ids ) {
			$this->redirect_back( \add_query_arg( 'error', 'nothing_selected', $base ) );
			return;
		}

		$deleted_count = $this->repo->delete_many( $ids );
		$failed_count  = \count( $ids ) - $deleted_count;

		// Fire brl_log_deleted per deleted ID (callers cannot know which succeeded
		// in a bulk op, so we fire for all IDs when the full set was removed).
		foreach ( $ids as $id ) {
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
