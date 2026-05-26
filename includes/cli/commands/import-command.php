<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Migration\Importer;
use BetterRestApiLogs\Migration\Reset;
use BetterRestApiLogs\Plugin;

/**
 * Trigger or inspect the migration from the old wp-rest-api-log plugin.
 *
 * In dry-run mode the command counts importable upstream entries without
 * writing anything. The --reset flag rewinds state markers only — it does
 * NOT delete any already-imported log rows (D-09). Live mode kicks the
 * background cron tick chain; it does not import inline.
 *
 * Operators must pair this command with --user=<admin_id> so
 * current_user_can resolves against a real WP user (same discipline as
 * DeleteCommand and PurgeCommand).
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Count upstream entries available to import and report without writing anything.
 *
 * [--reset]
 * : Rewind the progress cursor and status markers back to idle. Does NOT delete
 *   any log rows that were already imported — re-running after reset is safe because
 *   the migration_source_id UNIQUE index prevents duplicates (D-05, D-09).
 *
 * ## EXAMPLES
 *
 *     wp better-logs import --dry-run
 *     wp better-logs import --user=1
 *     wp better-logs import --reset --user=1
 */
final class ImportCommand extends \WP_CLI_Command {

	/**
	 * @param array<int, mixed>    $args       Positional command arguments from WP-CLI.
	 * @param array<string, mixed> $assoc_args Associative arguments from WP-CLI flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$required_cap = (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'cli' );
		if ( ! \current_user_can( $required_cap ) ) {
			\WP_CLI::error( \sprintf( 'Insufficient capabilities (%s required).', $required_cap ) );
		}

		if ( isset( $assoc_args['reset'] ) ) {
			$this->run_reset();
			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->run_dry_run();
			return;
		}

		$this->run_live();
	}

	/**
	 * Report the count of importable upstream entries without writing anything.
	 */
	private function run_dry_run(): void {
		$importer = Plugin::instance()->container()->get( Importer::class );
		$count    = $importer->count_source( 0 );

		if ( 0 === $count ) {
			\WP_CLI::log( 'Dry run: no wp-rest-api-log entries found to import.' );
			return;
		}

		\WP_CLI::log(
			\sprintf(
				'Dry run: %d upstream entr%s available to import (no rows written).',
				$count,
				1 === $count ? 'y' : 'ies'
			)
		);
	}

	/**
	 * Rewind migration state markers to idle. Imported log rows are kept (D-09).
	 */
	private function run_reset(): void {
		$reset = Plugin::instance()->container()->get( Reset::class );
		$reset->reset();
		\WP_CLI::success( 'Migration progress reset. Already-imported logs are kept; re-running is safe.' );
	}

	/**
	 * Schedule the first migration tick and let the cron drain chain do the rest.
	 */
	private function run_live(): void {
		$importer = Plugin::instance()->container()->get( Importer::class );
		$importer->tick();
		\WP_CLI::success( 'Migration tick started. Check the Import tab in Settings to monitor progress.' );
	}
}
