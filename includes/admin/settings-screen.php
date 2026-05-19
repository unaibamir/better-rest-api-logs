<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Settings\Defaults;

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
		self::render_tab_nav( $active );
		echo '<form action="options.php" method="post">';
		\settings_fields( "brl_settings_{$active}" );
		\do_settings_sections( "brl_settings_{$active}" );
		\submit_button();
		echo '</form></div>';
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
					return __( 'Custom confirmation phrase for the truncate action. Leave blank to use the default.', 'better-rest-api-logs' );
			}
		}

		return '';
	}
}
