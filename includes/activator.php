<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the user activates the plugin.
 *
 * Locked contract per CONTEXT.md D-11..D-13:
 *  - D-11: opt-in key is `brl_settings_delete_on_uninstall` (flat scalar).
 *  - D-12: default OFF (`''` — falsy). Logs are evidence; preservation is the safe default.
 *  - D-13: `add_option` (not `update_option`) so existing user preferences survive reactivation.
 */
final class Activator {

	public static function activate(): void {
		// Seed the opt-in flag with the safe default (OFF). add_option (not update_option)
		// so an existing user preference survives reactivation. Per D-13.
		add_option( 'brl_settings_delete_on_uninstall', '' );

		// Seed the schema-version marker. Phase 2 will read this and run dbDelta.
		// Using '0' so Phase 2's "if installed version < current" check fires on first run.
		add_option( 'brl_db_version', '0' );

		// (Phase 2 lands the actual schema creation here.)
		// (Phase 5 lands the wp_schedule_event call here.)
	}
}
