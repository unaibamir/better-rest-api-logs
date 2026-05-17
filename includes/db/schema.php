<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Custom-table DDL via dbDelta + version-mismatch upgrade trigger + smoke check + Site Health.
 *
 * Locked contract per CONTEXT.md D-12..D-23:
 *  - D-12: plugins_loaded@0, cached static, synchronous dbDelta on version mismatch.
 *  - D-13: SHOW TABLES smoke check; failure latches brl_internal['schema_broken'] = true.
 *  - D-14: BRL_DB_VERSION constant lives in better-rest-api-logs.php (current: '2.0').
 *  - D-15..D-17: Locked column inventory + index inventory copied verbatim from RESEARCH §"Pattern 1".
 *  - D-21..D-23: Site Health 'critical' + non-dismissible admin_notices on smoke failure.
 *
 * This class only defines the callables. Plan 02-07 wires the hook registrations
 * in better-rest-api-logs.php (plugins_loaded@0) and includes/plugin.php (admin_notices).
 */
final class Schema {

	/**
	 * Site Health direct-test ID per D-22. Stable; do not rename without
	 * updating downstream documentation that references the test slug.
	 */
	private const SITE_HEALTH_TEST_ID = 'brl_schema';

	/**
	 * Per-request short-circuit for maybe_install_or_upgrade(). A class
	 * static (not a function-local static) so SchemaUpgradeTest can reset
	 * it between cases via Reflection.
	 *
	 * @var bool
	 */
	private static $checked = false;

	/**
	 * Run dbDelta against both custom tables. Idempotent and upgrade-safe:
	 * dbDelta parses our canonical CREATE TABLE strings and emits CREATE on a
	 * fresh install or the minimum set of ALTERs to bring an existing schema
	 * to match.
	 */
	public static function install(): void {
		require_once \ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = Database::charset_collate();

		\dbDelta( self::logs_table_ddl( Database::logs_table(), $charset ) );
		\dbDelta( self::bodies_table_ddl( Database::bodies_table(), $charset ) );
	}

	/**
	 * Compare stored `brl_db_version` with the code constant and run install
	 * synchronously on mismatch. Hooked from plugins_loaded@0 in Plan 02-07.
	 *
	 * Cached-static early-return keeps the cost to a single `get_option` per
	 * request once the version is current (Pitfall 3).
	 */
	public static function maybe_install_or_upgrade(): void {
		if ( self::$checked ) {
			return;
		}
		self::$checked = true;

		$installed = (string) \get_option( 'brl_db_version', '0' );
		$current   = (string) \BRL_DB_VERSION;
		if ( \version_compare( $installed, $current, '>=' ) ) {
			return;
		}

		self::install();

		if ( self::smoke_check() ) {
			\update_option( 'brl_db_version', $current );
			self::clear_broken_flag();
		} else {
			self::set_broken_flag();
		}
	}

	/**
	 * SHOW TABLES post-install verification (D-13). Returns true only when
	 * both tables exist with the expected names. Failure trips the latch.
	 */
	public static function smoke_check(): bool {
		global $wpdb;

		$logs_table   = Database::logs_table();
		$bodies_table = Database::bodies_table();

		$logs_pattern   = $wpdb->esc_like( $logs_table );
		$bodies_pattern = $wpdb->esc_like( $bodies_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema smoke check; identifier from Database accessor.
		$logs_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_pattern ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema smoke check; identifier from Database accessor.
		$bodies_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bodies_pattern ) );

		return $logs_found === $logs_table && $bodies_found === $bodies_table;
	}

	/**
	 * Latch the schema-broken state inside `brl_internal` (D-13). Static so
	 * boot-time callers can use it without Registry being available.
	 */
	public static function set_broken_flag(): void {
		$internal = \get_option( 'brl_internal', array() );
		if ( ! \is_array( $internal ) ) {
			$internal = array();
		}
		$internal['schema_broken'] = true;
		\update_option( 'brl_internal', $internal );
	}

	/**
	 * Clear the schema-broken latch after a successful install + smoke check.
	 */
	public static function clear_broken_flag(): void {
		$internal = \get_option( 'brl_internal', array() );
		if ( ! \is_array( $internal ) ) {
			$internal = array();
		}
		if ( ! array_key_exists( 'schema_broken', $internal ) ) {
			return;
		}
		$internal['schema_broken'] = false;
		\update_option( 'brl_internal', $internal );
	}

	/**
	 * Register the `brl_schema` Site Health direct test (D-22). Returns the
	 * unmodified-shape tests array with our entry added.
	 *
	 * @param  array<string,mixed> $tests Existing test registry.
	 * @return array<string,mixed>
	 */
	public static function register_site_health_tests( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! \is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}
		$tests['direct'][ self::SITE_HEALTH_TEST_ID ] = array(
			'label'     => \__( 'Better REST API Logs database tables', 'better-rest-api-logs' ),
			'test'      => array( self::class, 'site_health_test_callback' ),
			'skip_cron' => false,
		);
		return $tests;
	}

	/**
	 * Site Health direct test result. Pass = green, fail = critical (D-21).
	 *
	 * @return array<string,mixed>
	 */
	public static function site_health_test_callback(): array {
		if ( self::smoke_check() ) {
			return array(
				'label'       => \__( 'Better REST API Logs database tables exist and are healthy.', 'better-rest-api-logs' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => \__( 'Plugins', 'better-rest-api-logs' ),
					'color' => 'green',
				),
				'description' => '<p>' . \esc_html__( 'Both brl_logs and brl_logs_bodies are present.', 'better-rest-api-logs' ) . '</p>',
				'actions'     => '',
				'test'        => self::SITE_HEALTH_TEST_ID,
			);
		}

		return array(
			'label'       => \__( 'Better REST API Logs cannot find its database tables.', 'better-rest-api-logs' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => \__( 'Plugins', 'better-rest-api-logs' ),
				'color' => 'red',
			),
			'description' => '<p>' . \esc_html__( 'Logging is paused until the tables are created. Try deactivating and reactivating the plugin to repair the schema.', 'better-rest-api-logs' ) . '</p>',
			'actions'     => '',
			'test'        => self::SITE_HEALTH_TEST_ID,
		);
	}

	/**
	 * Echo the non-dismissible schema-broken admin notice (D-23). Hooked to
	 * `admin_notices` in Plan 02-07. Bails when capability is wrong or the
	 * latch is not set.
	 */
	public static function maybe_render_broken_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$internal = \get_option( 'brl_internal', array() );
		if ( ! \is_array( $internal ) || empty( $internal['schema_broken'] ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo \esc_html__(
			'Better REST API Logs: log tables are missing or malformed. Logging is paused. Please deactivate and reactivate the plugin to repair the schema.',
			'better-rest-api-logs'
		);
		echo '</p></div>';
	}

	/**
	 * Canonical DDL for `{$wpdb->prefix}brl_logs`. Copied verbatim from
	 * RESEARCH §"Pattern 1" lines 307–341. Pitfall 7 silently no-ops on any
	 * deviation — do not reformat.
	 *
	 * @param string $table_name Composed via Database::logs_table().
	 * @param string $charset_collate Composed via Database::charset_collate().
	 */
	private static function logs_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  created_at_micros bigint(20) unsigned NOT NULL DEFAULT 0,
  method varchar(10) NOT NULL DEFAULT '',
  route varchar(255) NOT NULL DEFAULT '',
  route_prefix varchar(64) NOT NULL DEFAULT '',
  query_string text NULL,
  status smallint(5) unsigned NOT NULL DEFAULT 0,
  status_class tinyint(3) unsigned NOT NULL DEFAULT 0,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip_resolved varbinary(16) NULL,
  ip_raw_remote varbinary(16) NULL,
  request_content_type varchar(128) NOT NULL DEFAULT '',
  request_headers mediumtext NULL,
  request_body mediumblob NULL,
  request_body_bytes int(10) unsigned NOT NULL DEFAULT 0,
  request_body_truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
  response_content_type varchar(128) NOT NULL DEFAULT '',
  response_headers mediumtext NULL,
  response_body mediumblob NULL,
  response_body_bytes int(10) unsigned NOT NULL DEFAULT 0,
  response_body_truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
  bodies_spilled tinyint(1) unsigned NOT NULL DEFAULT 0,
  migration_source_id varchar(64) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY migration_source_id (migration_source_id),
  KEY created_at (created_at),
  KEY route_prefix (route_prefix),
  KEY status_class (status_class),
  KEY method_status (method,status_class),
  KEY user_id (user_id),
  KEY ip_resolved (ip_resolved)
) {$charset_collate};";
	}

	/**
	 * Canonical DDL for `{$wpdb->prefix}brl_logs_bodies`. Copied verbatim
	 * from RESEARCH §"Pattern 1" lines 344–351.
	 *
	 * @param string $table_name Composed via Database::bodies_table().
	 * @param string $charset_collate Composed via Database::charset_collate().
	 */
	private static function bodies_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
  log_id bigint(20) unsigned NOT NULL,
  request_body mediumblob NULL,
  response_body mediumblob NULL,
  PRIMARY KEY  (log_id)
) {$charset_collate};";
	}
}
