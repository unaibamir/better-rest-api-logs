<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the user deactivates the plugin via wp-admin.
 *
 * Leaves data alone — destruction is opt-in via uninstall.php only.
 */
final class Deactivator {

	public static function deactivate(): void {
		// Clear all scheduled events we may have registered. Safe to call even if
		// no event is currently scheduled.
		\wp_clear_scheduled_hook( 'brl_purge_tick' );
	}
}
