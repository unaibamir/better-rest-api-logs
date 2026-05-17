<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Per-tab default arrays + activation-time seeder.
 *
 * Locked contract per CONTEXT.md D-05..D-08, D-18, D-19 and CAP-05:
 *  - D-05: five tabs (capture, privacy, retention, network, advanced);
 *    one option key per tab (`brl_settings_<tab>`).
 *  - D-07: defaults live here as a static map; Registry overlays the stored
 *    option onto these so newly added keys surface without a DB write.
 *  - D-18: `body_spill_enabled` ships OFF (CAP-09 opt-in).
 *  - D-19: spill threshold = 64 KB (matches CAP-08 inline-body cap).
 *  - CAP-05: own REST namespace hard-coded into `route_denylist`.
 *  - Pitfall 4: `brl_internal` seeded with autoload='no'.
 *  - SET-04: Phase 2 lands the per-tab keys; the `register_setting()` proof
 *    of structural isolation lands in Plan 02-06's Registry.
 *
 * `seed_all_tabs()` uses `add_option` (idempotent — no-op when the option
 * already exists), preserving user customisation on reactivation.
 */
final class Defaults {

	/**
	 * Per-tab default shape.
	 *
	 * Unknown tab returns the empty array (test contract).
	 *
	 * @param  string $tab One of: capture, privacy, retention, network, advanced.
	 * @return array<string,mixed>
	 */
	public static function for_tab( string $tab ): array {
		switch ( $tab ) {
			case 'capture':
				return [
					'enabled'                     => true,
					'route_allowlist'             => [],
					'route_denylist'              => [ '/better-rest-api-logs/v1/*' ],
					'method_filter'               => [],
					'status_class_filter'         => [],
					'body_size_cap_bytes'         => 65536,
					'body_content_type_allowlist' => [
						'application/json',
						'application/x-www-form-urlencoded',
						'multipart/form-data',
						'text/plain',
						'application/xml',
						'text/xml',
					],
					'body_spill_enabled'          => false,
					'body_spill_threshold_bytes'  => 65536,
					'gzip_bodies'                 => false,
				];
			case 'privacy':
				return [
					'redact_extra_headers'     => [],
					'redact_json_key_patterns' => [],
					'anonymize_ip'             => false,
					'auth_endpoint_allowlist'  => [
						'/jwt-auth/v1/token',
						'/wp/v2/users/me/application-passwords',
					],
				];
			case 'retention':
				return [
					'retention_days'         => 30,
					'purge_batch_size'       => 1000,
					'purge_max_tick_seconds' => 10,
				];
			case 'network':
				return [
					'trusted_proxy_cidrs' => [],
					'cloudflare_cidrs'    => [],
					'cidr_allowlist'      => [],
					'cidr_denylist'       => [],
				];
			case 'advanced':
				return [
					'delete_on_uninstall_mirror' => false,
					'truncate_confirm_copy'      => '',
				];
			default:
				return [];
		}
	}

	/**
	 * Activation-time seeder.
	 *
	 * Idempotent: `add_option` is a no-op when the option already exists,
	 * so existing user customisations survive reactivation. Called from
	 * Activator::activate() (Plan 02-07 wires the call).
	 *
	 * `brl_internal` is seeded with autoload='no' per Pitfall 4 — migration
	 * state can grow and would otherwise bloat every page load's options
	 * autoload row.
	 */
	public static function seed_all_tabs(): void {
		foreach ( [ 'capture', 'privacy', 'retention', 'network', 'advanced' ] as $tab ) {
			\add_option( "brl_settings_{$tab}", self::for_tab( $tab ) );
		}
		// 4th-arg `false` = autoload=no on the brl_internal row (Pitfall 4).
		\add_option( 'brl_internal', Internal::defaults(), '', false );
	}
}
