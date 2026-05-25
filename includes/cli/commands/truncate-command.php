<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\Settings\Registry;

/**
 * Truncate all log entries — empties both brl_logs and brl_logs_bodies.
 *
 * TRUNCATE resets the auto-increment counters (unlike DELETE FROM) so new
 * rows start from id=1 after the operation. The stats snapshot is cleared so
 * the REST /stats endpoint and WP-CLI stats command reflect the empty tables
 * immediately rather than reporting stale counts from the cache.
 *
 * Defense-in-depth capability check mirrors DeleteCommand — operators MUST
 * pair destructive commands with --user=<admin_id> so current_user_can
 * resolves against a real WP user.
 *
 * ## OPTIONS
 *
 * [--yes]
 * : Skip the interactive confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     wp better-logs truncate --yes --user=1
 *     wp better-logs truncate --user=1
 */
final class TruncateCommand extends \WP_CLI_Command {

	/**
	 * @param array<int, mixed>    $args       Positional command arguments from WP-CLI.
	 * @param array<string, mixed> $assoc_args Associative arguments from WP-CLI flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$required_cap = (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'cli' );
		if ( ! \current_user_can( $required_cap ) ) {
			\WP_CLI::error( \sprintf( 'Insufficient capabilities (%s required).', $required_cap ) );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				'This will permanently delete ALL log entries and reset the auto-increment counters. Continue?',
				$assoc_args
			);
		}

		global $wpdb;
		$logs_table   = Database::logs_table();
		$bodies_table = Database::bodies_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor; count before truncate so the success message reports the actual rows removed.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor; TRUNCATE resets autoincrement, no user data in SQL.
		$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies_table from Database accessor; TRUNCATE resets autoincrement, no user data in SQL.
		$wpdb->query( "TRUNCATE TABLE {$bodies_table}" );

		// A wiped table with a stale stats cache would lie to the REST /stats endpoint
		// and the stats CLI command. Clear unconditionally — truncating an already-empty
		// table still invalidates any count the cache may be holding.
		Registry::set_internal( 'stats_snapshot', [] );

		\do_action( 'brl_logs_truncated', $count );

		\WP_CLI::success(
			\sprintf(
				'Truncated both log tables. %d row(s) removed.',
				$count
			)
		);
	}
}
