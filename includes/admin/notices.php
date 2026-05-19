<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders admin notices from flash query args set by BulkActionHandler redirects.
 *
 * The three-branch contract (full success / partial failure / complete failure)
 * mirrors the pattern in ~/.claude/rules/wordpress-safety.md. Never touches the
 * DB — reads only from sanitised GET args.
 *
 * @package BetterRestApiLogs\Admin
 */
final class Notices {

	/**
	 * Emit any pending flash notice and return.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash args; no state-changing action performed here.
		$deleted = isset( $_GET['deleted'] ) ? \absint( $_GET['deleted'] ) : 0;
		$failed  = isset( $_GET['failed'] ) ? \absint( $_GET['failed'] ) : 0;
		$error   = isset( $_GET['error'] ) ? \sanitize_key( \wp_unslash( (string) $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $deleted > 0 && 0 === $failed ) {
			\printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				\esc_html(
					\sprintf(
						\_n( 'Deleted %d log entry.', 'Deleted %d log entries.', $deleted, 'better-rest-api-logs' ),
						$deleted
					)
				)
			);
		} elseif ( $deleted > 0 && $failed > 0 ) {
			\printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				\esc_html(
					\sprintf(
						/* translators: 1: number of deleted entries, 2: total number of selected entries */
						\__( 'Deleted %1$d of %2$d selected entries. The rest could not be removed.', 'better-rest-api-logs' ),
						$deleted,
						$deleted + $failed
					)
				)
			);
		} elseif ( '' !== $error ) {
			\printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				\esc_html( $this->error_copy( $error ) )
			);
		}
	}

	/**
	 * Resolve an error slug to human copy.
	 *
	 * @param  string $slug Error slug from BulkActionHandler redirect.
	 * @return string       Translated error message.
	 */
	private function error_copy( string $slug ): string {
		switch ( $slug ) {
			case 'delete_failed':
				return \__( 'Could not delete any of the selected entries. Check the server error log for details.', 'better-rest-api-logs' );
			case 'nothing_selected':
				return \__( 'Pick at least one log entry before applying a bulk action.', 'better-rest-api-logs' );
			case 'nonce_expired':
				return \__( 'Your session expired. Reload the page and try again.', 'better-rest-api-logs' );
			default:
				return \__( 'An unknown error occurred.', 'better-rest-api-logs' );
		}
	}
}
