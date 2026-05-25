<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\Settings\Defaults;
use BetterRestApiLogs\Settings\Registry;

/**
 * Settings → REST API Logs page renderer and Settings API field registrar.
 *
 * Render is intentionally static so it can be used as a bare WP callback
 * without the container being wired first (test-seam friendly, mirrors the
 * WP_List_Table subclass pattern where methods are called by core callbacks).
 */
final class SettingsScreen {

	/**
	 * Tab slugs in display order.
	 *
	 * Labels are resolved through tab_label() so make-pot sees literal __() calls.
	 * No variable may appear inside __() — I18N-03.
	 *
	 * @var string[]
	 */
	private const TAB_SLUGS = [ 'capture', 'privacy', 'retention', 'network', 'advanced' ];

	/**
	 * Register the Settings → REST API Logs options page.
	 *
	 * Capability is filterable via brl_admin_required_capability so site owners
	 * can swap manage_options for a custom role.
	 */
	public static function register_menu(): void {
		\add_options_page(
			\esc_html__( 'REST API Logs Settings', 'better-rest-api-logs' ),
			\esc_html__( 'REST API Logs', 'better-rest-api-logs' ),
			\apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ),
			'better-rest-api-logs-settings',
			[ self::class, 'render' ]
		);
	}

	/**
	 * Register settings sections and fields for all five tabs.
	 *
	 * Hooked to admin_init. Phase 2 already registered each tab's option group
	 * via Registry::register_with_wp(); this method adds the visible sections
	 * and field callbacks that do_settings_sections() renders.
	 *
	 * The flat-scalar brl_settings_delete_on_uninstall option is also registered
	 * here because Phase 1 D-11..D-15 requires a separate register_setting() call
	 * for it (it is not nested inside brl_settings_advanced).
	 */
	public static function register_fields(): void {
		foreach ( self::TAB_SLUGS as $slug ) {
			$group   = "brl_settings_{$slug}";
			$section = "brl_{$slug}_section";

			\add_settings_section( $section, '', '__return_null', $group );
			self::add_fields_for_tab( $slug, $section, $group );
		}

		// Flat-scalar uninstall opt-in — Phase 1 D-11..D-15 contract.
		// Registered in the advanced group so options.php saves it together with
		// the advanced tab form; it uses its own option name, not the nested array.
		\register_setting(
			'brl_settings_advanced',
			'brl_settings_delete_on_uninstall',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $v ): bool {
					return ! empty( $v );
				},
				'default'           => false,
				'show_in_rest'      => false,
			]
		);

		\add_settings_field(
			'brl_settings_delete_on_uninstall',
			\esc_html__( 'Delete all log data when this plugin is uninstalled', 'better-rest-api-logs' ),
			[ self::class, 'render_uninstall_checkbox' ],
			'brl_settings_advanced',
			'brl_advanced_section'
		);
	}

	/**
	 * Render the full settings page including tab nav and active tab form.
	 *
	 * No wp_die on capability failure in Phase 4 — the add_options_page()
	 * capability guard already blocks access. Called as the page callback by WP.
	 */
	public static function render(): void {
		$active = isset( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? \sanitize_key( \wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'capture';

		if ( ! \in_array( $active, self::TAB_SLUGS, true ) ) {
			$active = 'capture';
		}

		echo '<div class="wrap brl-admin">';
		printf( '<h1>%s</h1>', \esc_html__( 'REST API Logs Settings', 'better-rest-api-logs' ) );
		self::render_truncate_notice();
		self::render_tab_nav( $active );
		echo '<form action="options.php" method="post">';
		\settings_fields( "brl_settings_{$active}" );
		\do_settings_sections( "brl_settings_{$active}" );
		\submit_button();
		echo '</form>';

		if ( 'advanced' === $active ) {
			self::render_truncate_section();
		}

		echo '</div>';
	}

	/**
	 * Render the "Truncate all logs" panel on the Advanced tab.
	 *
	 * The button now submits to the intermediate brl_truncate_confirm action
	 * rather than directly to brl_truncate_all. The interstitial is a full-page
	 * wp_die() confirmation that the user must actively confirm — no JS dialog,
	 * no stray-Enter risk (TRUNC-01, Pitfall 6).
	 *
	 * @return void
	 */
	private static function render_truncate_section(): void {
		global $wpdb;

		$logs_table = Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor (STOR-04); zero-arg COUNT against our own table; per-page admin render.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );

		$action_url = \admin_url( 'admin-post.php' );

		$label = \sprintf(
			/* translators: %s: formatted number of rows currently stored. */
			\_n( 'Delete %s entry\u{2026}', 'Delete all %s entries\u{2026}', $count, 'better-rest-api-logs' ),
			\number_format_i18n( $count )
		);

		echo '<hr style="margin: 32px 0 16px;" />';
		echo '<div class="brl-truncate-panel">';
		\printf( '<h2>%s</h2>', \esc_html__( 'Truncate all logs', 'better-rest-api-logs' ) );
		\printf(
			'<p>%s</p>',
			\esc_html__( 'Removes every captured request from the database in one shot. The autoincrement counter resets so the next captured row starts at 1. This action cannot be undone.', 'better-rest-api-logs' )
		);

		\printf( '<form method="post" action="%s">', \esc_url( $action_url ) );
		echo '<input type="hidden" name="action" value="brl_truncate_confirm" />';
		\wp_nonce_field( 'brl_truncate_confirm' );

		\printf(
			'<button type="submit" class="button brl-button-danger"%s>%s</button>',
			$count > 0 ? '' : ' disabled',
			\esc_html( $label )
		);

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handler for admin-post.php?action=brl_truncate_confirm.
	 *
	 * Cap check + nonce check, then renders the wp_die() interstitial form.
	 * This step MUTATES NOTHING — it only presents the confirmation page that
	 * carries a fresh nonce for the terminal brl_truncate_all action (TRUNC-01).
	 *
	 * @return void
	 */
	public static function handle_truncate_confirm(): void {
		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ), '', [ 'response' => 403 ] );
		}

		\check_admin_referer( 'brl_truncate_confirm' );

		global $wpdb;
		$logs_table = Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor; per-request count for the interstitial copy only.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );

		$advanced = (array) \get_option( 'brl_settings_advanced', [] );
		$phrase   = isset( $advanced['truncate_confirm_copy'] ) ? (string) $advanced['truncate_confirm_copy'] : '';

		$settings_url = \admin_url( 'options-general.php?page=better-rest-api-logs-settings&tab=advanced' );
		$action_url   = \admin_url( 'admin-post.php' );

		$warning = \sprintf(
			/* translators: %s: formatted number of captured requests that will be deleted. */
			\_n(
				'This will permanently delete %s captured request and reset the log counter to zero. This cannot be undone.',
				'This will permanently delete all %s captured requests and reset the log counter to zero. This cannot be undone.',
				$count,
				'better-rest-api-logs'
			),
			\number_format_i18n( $count )
		);

		// Inline style scoped to this page only — the wp_die() interstitial renders
		// outside .brl-admin, so our enqueued stylesheets are unavailable here.
		$style = '<style>
.brl-button-danger{color:#b32d2e;border-color:#b32d2e;background:#f6f7f7;}
.brl-button-danger:hover,.brl-button-danger:focus{color:#fff;background:#b32d2e;border-color:#b32d2e;}
p.submit{display:flex;gap:8px;align-items:center;margin-top:16px;}
</style>';

		$form  = '<form method="post" action="' . \esc_url( $action_url ) . '">';
		$form .= '<input type="hidden" name="action" value="brl_truncate_all" />';
		$form .= \wp_nonce_field( 'brl_truncate_all', '_wpnonce', true, false );

		if ( '' !== $phrase ) {
			$prompt = \sprintf(
				/* translators: %s: the phrase the user configured as a confirmation guard. */
				\__( 'Type %s to confirm:', 'better-rest-api-logs' ),
				'"' . \esc_html( $phrase ) . '"'
			);
			$form .= '<p><label for="brl-truncate-phrase">' . \esc_html( $prompt ) . '</label><br />';
			$form .= '<input type="text" id="brl-truncate-phrase" name="truncate_phrase" value="" autocomplete="off" class="regular-text" /></p>';
		}

		$form .= '<p class="submit">';
		// Cancel is the safe action — it receives autofocus so stray Enter navigates away (A11Y-03).
		$form .= '<a href="' . \esc_url( $settings_url ) . '" class="button" autofocus>' . \esc_html__( 'Cancel', 'better-rest-api-logs' ) . '</a>';
		$form .= '<button type="submit" class="button brl-button-danger">' . \esc_html__( 'Delete all logs', 'better-rest-api-logs' ) . '</button>';
		$form .= '</p>';
		$form .= '</form>';

		$message = $style
			. '<p>' . \esc_html( $warning ) . '</p>'
			. $form;

		\wp_die(
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $warning is esc_html'd; $style is static; form fields are escaped at construction.
			\esc_html__( 'Delete all REST API logs?', 'better-rest-api-logs' ),
			[
				'response'  => 200,
				'back_link' => false,
			]
		);
	}

	/**
	 * Flash notice for the truncate redirect — green on success, red on phrase
	 * mismatch. Rendered above the tab nav so it's the first thing the admin
	 * sees after the redirect.
	 *
	 * @return void
	 */
	private static function render_truncate_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash arg set by handle_truncate_all's redirect.
		$status = isset( $_GET['brl_truncate'] ) ? \sanitize_key( \wp_unslash( (string) $_GET['brl_truncate'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash arg for display count.
		$removed = isset( $_GET['brl_truncate_n'] ) ? \absint( $_GET['brl_truncate_n'] ) : 0;

		switch ( $status ) {
			case 'done':
				\printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					\esc_html(
						\sprintf(
							/* translators: %d: number of log rows removed by the truncate action. */
							\_n( 'Removed %d log entry.', 'Removed %d log entries.', $removed, 'better-rest-api-logs' ),
							$removed
						)
					)
				);
				break;
			case 'phrase_mismatch':
				\printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					\esc_html__( 'Confirmation phrase did not match. No rows were removed.', 'better-rest-api-logs' )
				);
				break;
			case 'empty':
				\printf(
					'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
					\esc_html__( 'There were no log entries to remove.', 'better-rest-api-logs' )
				);
				break;
		}
	}

	/**
	 * Handler for admin-post.php?action=brl_truncate_all.
	 *
	 * Empties both wp_brl_logs and wp_brl_logs_bodies via TRUNCATE so the
	 * autoincrement counters reset. Verifies the typed confirmation phrase
	 * when one is configured under Advanced → Truncate confirmation phrase.
	 *
	 * No `exit` after wp_safe_redirect — integration tests assert DB state
	 * after the handler returns (same seam as BulkActionHandler::redirect_back).
	 * WP core's admin-post.php calls die() after the action fires so the
	 * redirect completes correctly in real HTTP context.
	 *
	 * @return void
	 */
	public static function handle_truncate_all(): void {
		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ), '', [ 'response' => 403 ] );
		}

		\check_admin_referer( 'brl_truncate_all' );

		$settings_url = \admin_url( 'options-general.php?page=better-rest-api-logs-settings&tab=advanced' );

		$advanced = (array) \get_option( 'brl_settings_advanced', [] );
		$required = isset( $advanced['truncate_confirm_copy'] ) ? (string) $advanced['truncate_confirm_copy'] : '';

		if ( '' !== $required ) {
			$typed = isset( $_POST['truncate_phrase'] )
				? \sanitize_text_field( \wp_unslash( (string) $_POST['truncate_phrase'] ) )
				: '';
			if ( $typed !== $required ) {
				\wp_safe_redirect( \add_query_arg( 'brl_truncate', 'phrase_mismatch', $settings_url ) );
				return;
			}
		}

		global $wpdb;
		$logs_table   = Database::logs_table();
		$bodies_table = Database::bodies_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor; count before truncate so the flash notice reports the actual rows removed.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $logs_table from Database accessor; TRUNCATE resets autoincrement, no user data in SQL.
		$wpdb->query( "TRUNCATE TABLE {$logs_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $bodies_table from Database accessor; TRUNCATE resets autoincrement, no user data in SQL.
		$wpdb->query( "TRUNCATE TABLE {$bodies_table}" );

		// A wiped table with a stale stats cache would lie to the REST /stats endpoint.
		// Cleared regardless of whether there were rows — a truncate on an already-empty
		// table still invalidates any count the cache may be holding.
		Registry::set_internal( 'stats_snapshot', [] );

		\do_action( 'brl_logs_truncated', $count );

		if ( 0 === $count ) {
			\wp_safe_redirect( \add_query_arg( 'brl_truncate', 'empty', $settings_url ) );
			return;
		}

		\wp_safe_redirect(
			\add_query_arg(
				[
					'brl_truncate'   => 'done',
					'brl_truncate_n' => $count,
				],
				$settings_url
			)
		);
	}

	/**
	 * Render the horizontal tab navigation bar using WP core .nav-tab-wrapper classes.
	 *
	 * @param string $active Currently active tab slug.
	 */
	private static function render_tab_nav( string $active ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::TAB_SLUGS as $slug ) {
			$url = \add_query_arg(
				[
					'page' => 'better-rest-api-logs-settings',
					'tab'  => $slug,
				],
				\admin_url( 'options-general.php' )
			);
			\printf(
				'<a class="%s" href="%s">%s</a>',
				\esc_attr( $slug === $active ? 'nav-tab nav-tab-active' : 'nav-tab' ),
				\esc_url( $url ),
				\esc_html( self::tab_label( $slug ) )
			);
		}
		echo '</h2>';
	}

	/**
	 * Render the flat-scalar "delete on uninstall" checkbox bound to its own option.
	 *
	 * Phase 1 D-11..D-15: this option is NOT nested inside brl_settings_advanced.
	 * uninstall.php reads brl_settings_delete_on_uninstall directly.
	 */
	public static function render_uninstall_checkbox(): void {
		$value = (bool) \get_option( 'brl_settings_delete_on_uninstall', false );
		// Hidden zero so unchecking the box submits the literal "0" — the boolean
		// sanitize_callback registered on this option treats missing as the
		// stored default rather than as "off".
		\printf(
			'<input type="hidden" name="brl_settings_delete_on_uninstall" value="0" /><label><input type="checkbox" name="brl_settings_delete_on_uninstall" value="1" %s /> %s</label>',
			\checked( $value, true, false ),
			\esc_html__( 'OFF by default. When ON, uninstalling the plugin drops the log tables and removes all plugin options.', 'better-rest-api-logs' )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a human-readable tab label without variables inside __() — I18N-03.
	 *
	 * The switch uses only literal strings so make-pot picks them up cleanly.
	 *
	 * @param  string $slug One of the five TAB_SLUGS.
	 * @return string Plain (unescaped) translated label; caller escapes at point of output.
	 */
	private static function tab_label( string $slug ): string {
		switch ( $slug ) {
			case 'capture':
				return \__( 'Capture', 'better-rest-api-logs' );
			case 'privacy':
				return \__( 'Privacy', 'better-rest-api-logs' );
			case 'retention':
				return \__( 'Retention', 'better-rest-api-logs' );
			case 'network':
				return \__( 'Network', 'better-rest-api-logs' );
			case 'advanced':
				return \__( 'Advanced', 'better-rest-api-logs' );
			default:
				return \__( 'Unknown', 'better-rest-api-logs' );
		}
	}

	/**
	 * Register all settings fields for a given tab.
	 *
	 * Iterates the per-tab defaults from Defaults::for_tab() and adds one field
	 * per key with an appropriate control type derived from the default value's PHP type.
	 *
	 * @param string $slug    Tab slug (capture|privacy|retention|network|advanced).
	 * @param string $section Settings section ID for this tab.
	 * @param string $group   Option group name (brl_settings_{$slug}).
	 */
	private static function add_fields_for_tab( string $slug, string $section, string $group ): void {
		$defaults = Defaults::for_tab( $slug );
		$values   = (array) \get_option( $group, $defaults );

		foreach ( $defaults as $key => $default_value ) {
			$label       = self::field_label( $slug, $key );
			$description = self::field_description( $slug, $key );

			\add_settings_field(
				$key,
				\esc_html( $label ),
				static function () use ( $slug, $key, $values, $default_value, $description ): void {
					$current = $values[ $key ] ?? $default_value;
					self::render_field( $slug, $key, $current, gettype( $default_value ), $description );
				},
				$group,
				$section
			);
		}
	}

	/**
	 * Render a single settings field control based on the PHP type of its default value.
	 *
	 * @param string $slug        Tab slug.
	 * @param string $key         Setting key within the tab.
	 * @param mixed  $value       Current stored value.
	 * @param string $type        PHP type string from gettype() of the default value.
	 * @param string $description Optional description shown below the control.
	 */
	private static function render_field( string $slug, string $key, $value, string $type, string $description ): void {
		$name = "brl_settings_{$slug}[{$key}]";

		switch ( $type ) {
			case 'boolean':
				// Hidden zero pairs with the checkbox so unchecking the box sends
				// the literal "0" instead of dropping the key from $_POST. Without
				// it, the sanitizer cannot tell "unchecked" from "field absent"
				// and a default-true field can never be disabled from the UI.
				\printf(
					'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
					\esc_attr( $name ),
					\checked( (bool) $value, true, false ),
					\esc_html( $description )
				);
				break;

			case 'integer':
				\printf(
					'<input type="number" name="%s" value="%d" class="small-text" />',
					\esc_attr( $name ),
					(int) $value
				);
				if ( '' !== $description ) {
					\printf( '<p class="description">%s</p>', \esc_html( $description ) );
				}
				break;

			case 'array':
				\printf(
					'<textarea name="%s" rows="5" class="large-text code">%s</textarea>',
					\esc_attr( $name ),
					\esc_textarea( \implode( "\n", (array) $value ) )
				);
				if ( '' !== $description ) {
					\printf( '<p class="description">%s</p>', \esc_html( $description ) );
				}
				break;

			default: // String.
				\printf(
					'<input type="text" name="%s" value="%s" class="regular-text" />',
					\esc_attr( $name ),
					\esc_attr( (string) $value )
				);
				if ( '' !== $description ) {
					\printf( '<p class="description">%s</p>', \esc_html( $description ) );
				}
				break;
		}
	}

	/**
	 * Return a human-readable label for a settings field.
	 *
	 * Hard-coded from UI-SPEC §"Settings field labels" — no variables inside __().
	 *
	 * @param string $slug Tab slug.
	 * @param string $key  Setting key within the tab.
	 * @return string Plain (unescaped) label string.
	 */
	private static function field_label( string $slug, string $key ): string {
		// Capture tab labels.
		if ( 'capture' === $slug ) {
			switch ( $key ) {
				case 'enabled':
					return __( 'Enable capture', 'better-rest-api-logs' );
				case 'route_allowlist':
					return __( 'Route allowlist', 'better-rest-api-logs' );
				case 'route_denylist':
					return __( 'Route denylist', 'better-rest-api-logs' );
				case 'method_filter':
					return __( 'HTTP methods to capture', 'better-rest-api-logs' );
				case 'status_class_filter':
					return __( 'Status classes to capture', 'better-rest-api-logs' );
				case 'body_size_cap_bytes':
					return __( 'Body size cap', 'better-rest-api-logs' );
				case 'body_content_type_allowlist':
					return __( 'Body content-type allowlist', 'better-rest-api-logs' );
				case 'body_spill_enabled':
					return __( 'Spill oversized bodies to a separate table', 'better-rest-api-logs' );
				case 'body_spill_threshold_bytes':
					return __( 'Body spill threshold', 'better-rest-api-logs' );
				case 'gzip_bodies':
					return __( 'Gzip stored bodies', 'better-rest-api-logs' );
			}
		}

		// Privacy tab labels.
		if ( 'privacy' === $slug ) {
			switch ( $key ) {
				case 'redact_extra_headers':
					return __( 'Extra headers to redact', 'better-rest-api-logs' );
				case 'redact_json_key_patterns':
					return __( 'Extra JSON key patterns to redact', 'better-rest-api-logs' );
				case 'anonymize_ip':
					return __( 'Anonymize stored IPs', 'better-rest-api-logs' );
				case 'auth_endpoint_allowlist':
					return __( 'Authentication endpoints', 'better-rest-api-logs' );
			}
		}

		// Retention tab labels.
		if ( 'retention' === $slug ) {
			switch ( $key ) {
				case 'retention_days':
					return __( 'Keep logs for', 'better-rest-api-logs' );
				case 'purge_batch_size':
					return __( 'Purge batch size', 'better-rest-api-logs' );
				case 'purge_max_tick_seconds':
					return __( 'Max purge duration per tick', 'better-rest-api-logs' );
			}
		}

		// Network tab labels.
		if ( 'network' === $slug ) {
			switch ( $key ) {
				case 'trusted_proxy_cidrs':
					return __( 'Trusted proxies', 'better-rest-api-logs' );
				case 'cloudflare_cidrs':
					return __( 'Cloudflare IP ranges', 'better-rest-api-logs' );
				case 'cidr_allowlist':
					return __( 'Capture only requests from these IPs', 'better-rest-api-logs' );
				case 'cidr_denylist':
					return __( 'Never capture requests from these IPs', 'better-rest-api-logs' );
			}
		}

		// Advanced tab labels.
		if ( 'advanced' === $slug ) {
			switch ( $key ) {
				case 'truncate_confirm_copy':
					return __( 'Truncate confirmation phrase', 'better-rest-api-logs' );
			}
		}

		// Fallback: humanize the key name.
		return (string) \ucfirst( \str_replace( '_', ' ', $key ) );
	}

	/**
	 * Return the description string for a settings field.
	 *
	 * Hard-coded from UI-SPEC §"Settings field labels" — no variables inside __().
	 *
	 * @param string $slug Tab slug.
	 * @param string $key  Setting key within the tab.
	 * @return string Plain (unescaped) description string, or empty string if none.
	 */
	private static function field_description( string $slug, string $key ): string {
		if ( 'capture' === $slug ) {
			switch ( $key ) {
				case 'enabled':
					return __( 'When OFF, no REST requests are logged. Existing rows are not touched.', 'better-rest-api-logs' );
				case 'route_allowlist':
					return __( 'One pattern per line. Globs supported, e.g. /wc/v3/*. Leave blank to allow everything.', 'better-rest-api-logs' );
				case 'route_denylist':
					return __( 'One pattern per line. Deny patterns win over allow patterns.', 'better-rest-api-logs' );
				case 'method_filter':
					return __( 'Leave blank to capture all methods.', 'better-rest-api-logs' );
				case 'status_class_filter':
					return __( 'Pick the response classes you want to log. Leave blank to capture all.', 'better-rest-api-logs' );
				case 'body_size_cap_bytes':
					return __( 'Bodies larger than this are truncated. The original byte size is recorded honestly.', 'better-rest-api-logs' );
				case 'body_content_type_allowlist':
					return __( 'One content-type per line. Leave blank to capture all content types.', 'better-rest-api-logs' );
				case 'body_spill_enabled':
					return __( 'Keeps the main logs table small. Body lookup adds one extra query on the detail view.', 'better-rest-api-logs' );
				case 'body_spill_threshold_bytes':
					return __( 'Bodies larger than this threshold spill to the secondary table when spill is enabled.', 'better-rest-api-logs' );
				case 'gzip_bodies':
					return __( 'Trades CPU for storage. OFF by default in v1.', 'better-rest-api-logs' );
			}
		}

		if ( 'privacy' === $slug ) {
			switch ( $key ) {
				case 'redact_extra_headers':
					return __( 'One header name per line. Authorization, Cookie, Set-Cookie, X-Api-Key, and Proxy-Authorization are always redacted.', 'better-rest-api-logs' );
				case 'redact_json_key_patterns':
					return __( 'One regex per line. password, secret, token, key, credit, card, cvv, and ssn are always redacted.', 'better-rest-api-logs' );
				case 'anonymize_ip':
					return __( 'Mask the last octet of IPv4 and the last 80 bits of IPv6 before storage.', 'better-rest-api-logs' );
				case 'auth_endpoint_allowlist':
					return __( 'One route per line. Bodies for these routes are never captured.', 'better-rest-api-logs' );
			}
		}

		if ( 'retention' === $slug ) {
			switch ( $key ) {
				case 'retention_days':
					return __( 'Days. Set to 0 to keep forever. The cron purge job runs hourly.', 'better-rest-api-logs' );
				case 'purge_batch_size':
					return __( 'Rows removed per cron tick. Lower values are gentler on large tables.', 'better-rest-api-logs' );
				case 'purge_max_tick_seconds':
					return __( 'Maximum seconds the purge job runs per cron tick before yielding.', 'better-rest-api-logs' );
			}
		}

		if ( 'network' === $slug ) {
			switch ( $key ) {
				case 'trusted_proxy_cidrs':
					return __( 'One CIDR per line. Leave blank if the site sits directly on the internet.', 'better-rest-api-logs' );
				case 'cloudflare_cidrs':
					return __( 'Only when REMOTE_ADDR is in a Cloudflare range. The plugin ships a static list of Cloudflare CIDRs.', 'better-rest-api-logs' );
				case 'cidr_allowlist':
					return __( 'One CIDR per line. Leave blank to capture from any IP.', 'better-rest-api-logs' );
				case 'cidr_denylist':
					return __( 'One CIDR per line. Deny patterns win over allow patterns.', 'better-rest-api-logs' );
			}
		}

		if ( 'advanced' === $slug ) {
			switch ( $key ) {
				case 'truncate_confirm_copy':
					return __( 'Optional. When set, the "Purge all entries now" button below requires this exact phrase to be typed before it runs. Leave blank to fall back to a plain browser confirm dialog.', 'better-rest-api-logs' );
			}
		}

		return '';
	}
}
