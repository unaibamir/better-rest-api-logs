<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Migration;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Settings\Internal;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;

/**
 * Rewinds migration state markers without deleting imported data (D-09).
 *
 * Reset rewrites the brl_internal.migration marker to its idle defaults
 * (status='idle', cursor=0, all counts=0) and clears any in-flight
 * brl_migration_tick scheduled events. It does NOT delete rows from
 * brl_logs that were already imported — re-running after reset is safe
 * via the migration_source_id UNIQUE idempotency guarantee (D-05).
 */
final class Reset {

	/**
	 * Clear migration state markers and stop the drain chain.
	 *
	 * State write is wrapped in try/catch Throwable because MySQL may be the
	 * broken thing — same discipline as Cron\Purge and Cron\Scheduler (D-22).
	 * Imported log rows are NEVER deleted (D-09 — load-bearing contract).
	 */
	public function reset(): void {
		try {
			SettingsRegistry::set_internal( 'migration', Internal::defaults()['migration'] );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Persistence is best-effort; stop the chain regardless.
			unset( $e );
		}

		// Stop any queued tick chain — mirrors Deactivator::deactivate() shape.
		\wp_clear_scheduled_hook( 'brl_migration_tick' );
	}
}
