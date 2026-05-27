<?php
/**
 * Better REST API Logs — Uninstall.
 *
 * Runs in an isolated PHP process when the user deletes the plugin via wp-admin.
 * Plugin classes are NOT autoloaded here; only WordPress core API is available.
 *
 * Locked contract per CONTEXT.md D-11..D-15:
 *  - D-11: opt-in key is `brl_settings_delete_on_uninstall` (flat scalar).
 *  - D-12: default OFF (`''` — falsy). Logs are evidence; preservation is the safe default.
 *  - D-13: Activator seeds via `add_option` (not `update_option`) so existing preferences survive reactivation.
 *  - D-14: Read path is one line: `get_option( 'brl_settings_delete_on_uninstall' )`. NO plugin autoload.
 *  - D-15: `delete_option( 'brl_settings_delete_on_uninstall' )` runs LAST so the flag is cleaned up only after teardown.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Opt-in check — default OFF. User must have toggled Settings → Advanced.
// `brl_settings_delete_on_uninstall` is a FLAT scalar option and the sole
// source of truth for this opt-in. Phase 4's Advanced UI will bind it
// directly via its own register_setting() call — there is no mirror inside
// `brl_settings_advanced`. This is the only key uninstall.php reads before
// destroying data; keep it boot-critical (no plugin autoload required).
if ( ! get_option( 'brl_settings_delete_on_uninstall' ) ) {
	return;
}

// Wrapped in an IIFE so the teardown closure and loop variables stay local
// and never leak as file-scope globals (plugin-check flags any non-slug global,
// and it keys the expected prefix off the plugin slug, not our phpcs config).
( static function (): void {
	// Per-blog teardown. Drops the custom tables, removes every brl_* option,
	// sweeps leftover transients, and clears the scheduled purge for the CURRENT
	// blog. Called once per site on a network, once total on single-site.
	$teardown_blog = static function (): void {
		global $wpdb;

		// Drop our custom tables. They may not exist if Phase 2 never ran on this install,
		// or may have been dropped on a prior uninstall — `IF EXISTS` is mandatory.
		$tables = [
			$wpdb->prefix . 'brl_logs',
			$wpdb->prefix . 'brl_logs_bodies',
		];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Identifier composed from $wpdb->prefix + literal; safe.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		// Delete all brl_* options.
		$option_keys = [
			'brl_db_version',
			'brl_settings',          // Phase 1 legacy — one-cycle safety net; no-op if absent.
			'brl_internal',
			// Phase 2 per-tab options.
			'brl_settings_capture',
			'brl_settings_privacy',
			'brl_settings_retention',
			'brl_settings_network',
			'brl_settings_advanced',
		];
		foreach ( $option_keys as $key ) {
			delete_option( $key );
		}

		// Sweep any brl_* transients that may have been left over (circuit breaker state, etc.).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE-prefix sweep; delete_transient only works for known names.
		$wpdb->query(
			"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_brl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_brl\\_%'"
		);
		// Site transients (network-wide; harmless to re-run per blog).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE-prefix sweep.
		$wpdb->query(
			"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_site\\_transient\\_brl\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_brl\\_%'"
		);

		// Clear scheduled events — even though Deactivator already does this, run defensively
		// in case uninstall is invoked without prior deactivation (rare but possible).
		wp_clear_scheduled_hook( 'brl_purge_tick' );

		// LAST — clean up the opt-in flag itself per D-15.
		delete_option( 'brl_settings_delete_on_uninstall' );
	};

	// On a network, tear down every site so a network uninstall doesn't orphan
	// each sub-site's tables. Bound the loop so a very large network can't time
	// out the destructive process; sites past the cap keep their dormant tables,
	// which is the safe failure mode for a delete-everything operation.
	if ( is_multisite() ) {
		$site_ids = get_sites(
			[
				'number'   => 1000,
				'fields'   => 'ids',
				'spam'     => 0,
				'deleted'  => 0,
				'archived' => 0,
			]
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$teardown_blog();
			restore_current_blog();
		}
		return;
	}

	$teardown_blog();
} )();
