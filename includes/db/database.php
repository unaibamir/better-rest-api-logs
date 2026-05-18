<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised table-name accessor. The ONLY call site in the plugin that
 * composes `{$wpdb->prefix}brl_*`. Every repository, every CLI command,
 * every REST controller takes table names from here.
 *
 * Locked contract per CONTEXT.md D-20 + STOR-04..05:
 *  - Static methods only; no instance state, no constructor.
 *  - D-20 / PURGE-07 invariant: when a parent brl_logs row is deleted, the
 *    matching brl_logs_bodies.log_id row MUST be deleted in the same statement
 *    batch. Schema does not enforce this — the Phase 5 purger and the Phase 4
 *    delete handler are responsible.
 *  - STOR-05: identifier composition uses `$wpdb->prefix` + hard-coded literal
 *    only. Never interpolate user-controlled input into a table name.
 */
final class Database {

	public static function logs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'brl_logs';
	}

	public static function bodies_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'brl_logs_bodies';
	}

	public static function charset_collate(): string {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}
}
