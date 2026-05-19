<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Plugin;

/**
 * Delete a single log entry and its associated spilled body row.
 *
 * Defense-in-depth capability check: even though WP-CLI can be called without
 * a `--user=<id>` arg (which bypasses WP authentication and leaves
 * `wp_get_current_user()->ID === 0`), production operators MUST pair
 * destructive commands with `--user=<admin_id>` so that `current_user_can`
 * resolves against a real WP user. Integration tests exercise the check
 * explicitly via `wp_set_current_user()`.
 *
 * Note: Phase 5 export commands will add `--out=<path>` with a path-traversal
 * rejection guard. That guard is out of scope for this phase (CLI-04).
 *
 * ## OPTIONS
 *
 * <id>
 * : Numeric log entry id to delete.
 *
 * [--yes]
 * : Skip the interactive confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     wp better-logs delete 12345 --yes
 *     wp better-logs delete 12345 --user=1
 */
final class DeleteCommand extends \WP_CLI_Command {

	/**
	 * @param array<int, mixed>    $args       Positional command arguments from WP-CLI.
	 * @param array<string, mixed> $assoc_args Associative arguments from WP-CLI flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Defense-in-depth capability check (CLI-03 / T-04-46).
		// `brl_admin_required_capability` lets operators harden to a custom cap;
		// the denial message therefore quotes the filtered cap rather than
		// hard-coding manage_options.
		$required_cap = (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'cli' );
		if ( ! \current_user_can( $required_cap ) ) {
			\WP_CLI::error( \sprintf( 'Insufficient capabilities (%s required).', $required_cap ) );
		}

		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'Pass a log id, for example: wp better-logs delete 12345 --yes' );
		}

		$raw = (string) $args[0];
		if ( 1 !== \preg_match( '/^\d+$/', $raw ) ) {
			\WP_CLI::error( 'Log id must be a positive integer.' );
		}

		$id = (int) $raw;
		if ( $id <= 0 ) {
			\WP_CLI::error( 'Log id must be a positive integer.' );
		}

		// Interactive guard — skipped when --yes is present (useful in scripts / CI).
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				\sprintf( 'Delete log #%d? This action cannot be undone.', $id ),
				$assoc_args
			);
		}

		$repo = Plugin::instance()->container()->get( LogRepository::class );
		$ok   = $repo->delete( $id );

		if ( ! $ok ) {
			\WP_CLI::error( \sprintf( 'Log entry %d does not exist.', $id ) );
		}

		// Fire a hook so audit-log or cache-invalidation plugins can react.
		\do_action( 'brl_log_deleted', $id, 'cli' );

		\WP_CLI::success( \sprintf( 'Deleted log entry %d.', $id ) );
	}
}
