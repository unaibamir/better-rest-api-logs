<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Settings\Defaults;

/**
 * Runs once when the user activates the plugin.
 *
 * Locked contract per CONTEXT.md D-11..D-13, D-12 (Schema install dance), D-23
 * (admin notice scope):
 *  - D-11: opt-in key is `brl_settings_delete_on_uninstall` (flat scalar).
 *  - D-12: default OFF (`''` — falsy). Logs are evidence; preservation is the safe default.
 *  - D-13: `add_option` (not `update_option`) so existing user preferences survive reactivation.
 *  - D-12 (Schema): Activator calls Schema::install() once; plugins_loaded@0
 *    `Schema::maybe_install_or_upgrade` then runs the smoke check on the next
 *    request and bumps brl_db_version. The "double-run" is intentional and safe
 *    because dbDelta is idempotent.
 *  - D-23: schema-broken admin notice fires from `admin_notices` (wired in
 *    Plugin::boot, not here).
 *
 * Phase 2 (Plan 02-05) added `Defaults::seed_all_tabs()`; Plan 02-07 adds
 * `Schema::install()` so first activation creates tables AND seeds defaults
 * in a single transaction.
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

		// Phase 2 (Plan 02-07): install custom tables + seed per-tab defaults.
		// brl_db_version stays at '0' here on purpose — Schema::maybe_install_or_upgrade()
		// running from plugins_loaded@0 on the next request finishes the smoke check
		// and version bump, guaranteeing the smoke check exercises at least once after
		// every activation. dbDelta is idempotent so the double-run is safe.
		Schema::install();

		// (Phase 5 lands the wp_schedule_event call here.)
	}
}
