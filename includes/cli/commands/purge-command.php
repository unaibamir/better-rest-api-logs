<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Cron\Purge;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Support\Clock;

/**
 * Manually trigger the retention purge or inspect how many rows would be removed.
 *
 * In dry-run mode the command queries for eligible rows without deleting them.
 * In live mode it invokes the same bounded-batch logic as the cron tick so the
 * two surfaces stay in sync. Defense-in-depth capability check mirrors
 * DeleteCommand — operators MUST pair destructive commands with --user=<admin_id>
 * so current_user_can resolves against a real WP user.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Count eligible rows and report without deleting anything.
 *
 * [--yes]
 * : Skip the interactive confirmation prompt (live mode only).
 *
 * ## EXAMPLES
 *
 *     wp better-logs purge --dry-run
 *     wp better-logs purge --yes --user=1
 */
final class PurgeCommand extends \WP_CLI_Command {

	/**
	 * @param array<int, mixed>    $args       Positional command arguments from WP-CLI.
	 * @param array<string, mixed> $assoc_args Associative arguments from WP-CLI flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$required_cap = (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'cli' );
		if ( ! \current_user_can( $required_cap ) ) {
			\WP_CLI::error( \sprintf( 'Insufficient capabilities (%s required).', $required_cap ) );
		}

		$is_dry_run = isset( $assoc_args['dry-run'] );

		if ( $is_dry_run ) {
			$this->run_dry_run();
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'Purge eligible log rows? This action cannot be undone.', $assoc_args );
		}

		$this->run_live();
	}

	/**
	 * Count rows eligible for purge under the current retention setting and report
	 * without deleting anything.
	 */
	private function run_dry_run(): void {
		$registry       = Plugin::instance()->container()->get( SettingsRegistry::class );
		$retention_days = (int) $registry->get_setting( 'retention.retention_days' );

		if ( $retention_days <= 0 ) {
			\WP_CLI::log( 'Retention is set to keep-forever (retention_days = 0). No rows would be purged.' );
			return;
		}

		$clock  = new Clock();
		$cutoff = $clock->cutoff_datetime( $retention_days );

		global $wpdb;
		$logs = \BetterRestApiLogs\DB\Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs from Database accessor; cutoff flows through $wpdb->prepare placeholder.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $logs from Database::logs_table() (STOR-04).
				"SELECT COUNT(*) FROM {$logs} WHERE created_at < %s",
				$cutoff
			)
		);

		\WP_CLI::log(
			\sprintf(
				'Dry run: %d row(s) older than %d day(s) (cutoff: %s) would be purged.',
				$count,
				$retention_days,
				$cutoff
			)
		);
	}

	/**
	 * Run a live purge via the same Cron\Purge tick that the scheduler uses.
	 */
	private function run_live(): void {
		$purge = Plugin::instance()->container()->get( Purge::class );
		$purge->tick();
		\WP_CLI::success( 'Purge tick completed. Check the stats command for current row counts.' );
	}
}
