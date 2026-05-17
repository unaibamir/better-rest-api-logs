<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Settings\Defaults.
 *
 * RED-bar baseline (Wave 0): asserts per-tab default shapes per CONTEXT.md
 * D-05..D-07 and RESEARCH Example B. Plan 02-05 turns these green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Settings;

use BetterRestApiLogs\Settings\Defaults;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class DefaultsTest extends TestCase {

	public function test_for_tab_capture_shape(): void {
		$capture = Defaults::for_tab( 'capture' );

		$this->assertIsArray( $capture );
		$this->assertArrayHasKey( 'enabled', $capture );
		$this->assertArrayHasKey( 'route_allowlist', $capture );
		$this->assertArrayHasKey( 'route_denylist', $capture );
		$this->assertArrayHasKey( 'method_filter', $capture );
		$this->assertArrayHasKey( 'status_class_filter', $capture );
		$this->assertArrayHasKey( 'body_size_cap_bytes', $capture );
		$this->assertArrayHasKey( 'body_content_type_allowlist', $capture );
		$this->assertArrayHasKey( 'body_spill_enabled', $capture );
		$this->assertArrayHasKey( 'body_spill_threshold_bytes', $capture );
		$this->assertArrayHasKey( 'gzip_bodies', $capture );

		$this->assertSame( 65536, $capture['body_size_cap_bytes'], 'CAP-08 default 64 KB.' );
		$this->assertFalse( $capture['body_spill_enabled'], 'D-18: body spill defaults OFF.' );
		$this->assertContains(
			'/better-rest-api-logs/v1/*',
			$capture['route_denylist'],
			'CAP-05: own REST namespace is hard-coded in the denylist.'
		);
	}

	public function test_for_tab_privacy_shape(): void {
		$privacy = Defaults::for_tab( 'privacy' );

		$this->assertIsArray( $privacy );
		$this->assertArrayHasKey( 'redact_extra_headers', $privacy );
		$this->assertArrayHasKey( 'redact_json_key_patterns', $privacy );
		$this->assertArrayHasKey( 'anonymize_ip', $privacy );
		$this->assertArrayHasKey( 'auth_endpoint_allowlist', $privacy );
	}

	public function test_for_tab_retention_shape(): void {
		$retention = Defaults::for_tab( 'retention' );

		$this->assertIsArray( $retention );
		$this->assertSame( 30, $retention['retention_days'], 'PURGE-01 default 30 days.' );
		$this->assertSame( 1000, $retention['purge_batch_size'] );
		$this->assertSame( 10, $retention['purge_max_tick_seconds'] );
	}

	public function test_for_tab_network_shape(): void {
		$network = Defaults::for_tab( 'network' );

		$this->assertIsArray( $network );
		$this->assertArrayHasKey( 'trusted_proxy_cidrs', $network );
		$this->assertArrayHasKey( 'cloudflare_cidrs', $network );
		$this->assertArrayHasKey( 'cidr_allowlist', $network );
		$this->assertArrayHasKey( 'cidr_denylist', $network );
	}

	public function test_for_tab_advanced_shape(): void {
		$advanced = Defaults::for_tab( 'advanced' );

		$this->assertIsArray( $advanced );
		$this->assertArrayHasKey( 'delete_on_uninstall_mirror', $advanced );
		$this->assertArrayHasKey( 'truncate_confirm_copy', $advanced );
	}

	public function test_for_tab_unknown_returns_empty_array(): void {
		$this->assertSame( array(), Defaults::for_tab( 'does-not-exist' ) );
	}
}
