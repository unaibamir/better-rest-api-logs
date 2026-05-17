<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Settings\Defaults;

/**
 * Runs once when the user activates the plugin.
 *
 * Locked contract per CONTEXT.md D-11..D-13:
 *  - D-11: opt-in key is `brl_settings_delete_on_uninstall` (flat scalar).
 *  - D-12: default OFF (`''` — falsy). Logs are evidence; preservation is the safe default.
 *  - D-13: `add_option` (not `update_option`) so existing user preferences survive reactivation.
 *
 * Phase 2 (Plan 02-05) extends this to seed the per-tab settings and the
 * brl_internal marker array via `Defaults::seed_all_tabs()`. All seeds use
 * `add_option` and are therefore idempotent on reactivation.
 */
final class Activator {

	public static function activate(): void {
		// Seed the opt-in flag with the safe default (OFF). add_option (not update_option)
		// so an existing user preference survives reactivation. Per D-13.
		\add_option( 'brl_settings_delete_on_uninstall', '' );

		// Seed the schema-version marker. Phase 2 will read this and run dbDelta.
		// Using '0' so Phase 2's "if installed version < current" check fires on first run.
		\add_option( 'brl_db_version', '0' );

		// Phase 2 (Plan 02-05): seed per-tab user settings + brl_internal markers.
		// add_option is idempotent — existing customisations survive reactivation.
		// brl_internal gets autoload=no per Pitfall 4 (handled inside seed_all_tabs).
		Defaults::seed_all_tabs();

		// (Plan 02-07 lands Schema::install() here.)
		// (Phase 5 lands the wp_schedule_event call here.)
	}
}
