<?php
/**
 * Unit tests for BetterRestApiLogs\Capture\IpResolver.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-03
 * implements includes/capture/ip-resolver.php. Covers IP-01..06 per the
 * locked requirements in REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Capture;

use BetterRestApiLogs\Capture\IpResolver;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class IpResolverTest extends TestCase {

	/**
	 * Build a v4-mapped 16-byte packed string for a dotted-decimal IPv4 address.
	 *
	 * @param string $ip Dotted-decimal IPv4 address.
	 */
	private function pack_v4( string $ip ): string {
		$packed = \inet_pton( '::ffff:' . $ip );
		if ( false === $packed ) {
			$this->fail( "inet_pton failed for $ip" );
		}
		return $packed;
	}

	/**
	 * Build a minimal network settings array with no proxies configured.
	 */
	private function settings_no_proxy(): array {
		return [
			'network' => [
				'trusted_proxies'  => [],
				'cloudflare_cidrs' => [],
			],
		];
	}

	/** IP-01: REMOTE_ADDR used when no trusted proxies are configured. */
	public function test_remote_addr_default_when_no_proxy_configured(): void {
		$server = [ 'REMOTE_ADDR' => '203.0.113.5' ];
		$result = IpResolver::resolve( $server, $this->settings_no_proxy() );

		$expected = $this->pack_v4( '203.0.113.5' );
		$this->assertSame( $expected, $result['resolved'] );
		$this->assertSame( $expected, $result['raw'] );
	}

	/** IP-02: XFF walk picks rightmost non-trusted IP. */
	public function test_xff_right_to_left_skips_trusted_proxies(): void {
		$settings = [
			'network' => [
				'trusted_proxies'  => [ '10.0.0.0/8' ],
				'cloudflare_cidrs' => [],
			],
		];
		$server   = [
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.2, 10.0.0.1',
		];
		$result   = IpResolver::resolve( $server, $settings );

		$this->assertSame( $this->pack_v4( '1.2.3.4' ), $result['resolved'] );
	}

	/** IP-02: XFF header is ignored entirely when REMOTE_ADDR is not in the trusted list. */
	public function test_xff_ignored_when_remote_addr_not_trusted(): void {
		$settings = [
			'network' => [
				'trusted_proxies'  => [ '10.0.0.0/8' ],
				'cloudflare_cidrs' => [],
			],
		];
		$server   = [
			'REMOTE_ADDR'          => '203.0.113.5',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		];
		$result   = IpResolver::resolve( $server, $settings );

		$this->assertSame( $this->pack_v4( '203.0.113.5' ), $result['resolved'] );
	}

	/** IP-03: CF-Connecting-IP honored when REMOTE_ADDR is inside Cloudflare CIDRs. */
	public function test_cf_connecting_ip_honored_only_inside_cloudflare_cidrs(): void {
		$server = [
			'REMOTE_ADDR'           => '173.245.48.1',
			'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
		];
		$result = IpResolver::resolve( $server, $this->settings_no_proxy() );

		$this->assertSame( $this->pack_v4( '1.2.3.4' ), $result['resolved'] );
	}

	/** IP-03: CF-Connecting-IP ignored when REMOTE_ADDR is not in a Cloudflare range. */
	public function test_cf_connecting_ip_ignored_when_remote_addr_outside_cloudflare(): void {
		$server = [
			'REMOTE_ADDR'           => '8.8.8.8',
			'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
		];
		$result = IpResolver::resolve( $server, $this->settings_no_proxy() );

		$this->assertSame( $this->pack_v4( '8.8.8.8' ), $result['resolved'] );
	}

	/** IP-04: RFC 7239 Forwarded header is walked right-to-left like XFF. */
	public function test_rfc7239_forwarded_for_parsed(): void {
		$settings = [
			'network' => [
				'trusted_proxies'  => [ '10.0.0.0/8' ],
				'cloudflare_cidrs' => [],
			],
		];
		$server   = [
			'REMOTE_ADDR'    => '10.0.0.1',
			'HTTP_FORWARDED' => 'for=192.0.2.43, for="[2001:db8:cafe::17]:4711"',
		];
		$result   = IpResolver::resolve( $server, $settings );

		$this->assertSame( $this->pack_v4( '192.0.2.43' ), $result['resolved'] );
	}

	/** IP-04: parse_forwarded strips brackets and port from IPv6 for= tokens. */
	public function test_forwarded_strips_brackets_and_port(): void {
		$hops = IpResolver::parse_forwarded( 'for="[2001:db8::1]:80"' );
		$this->assertContains( '2001:db8::1', $hops );
	}

	/** IP-05: Private-range IPs in XFF are rejected when not in the trusted list. */
	public function test_private_ranges_rejected_from_headers_when_proxy_not_trusted(): void {
		$settings = [
			'network' => [
				'trusted_proxies'  => [ '10.0.0.0/8' ],
				'cloudflare_cidrs' => [],
			],
		];
		$server   = [
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '192.168.1.1',
		];
		$result   = IpResolver::resolve( $server, $settings );

		if ( null !== $result['resolved'] ) {
			$this->assertNotSame( $this->pack_v4( '192.168.1.1' ), $result['resolved'] );
		} else {
			$this->assertNull( $result['resolved'] );
		}
	}

	/** IP-05: Private-range IPs are accepted when the trusted list explicitly includes them. */
	public function test_private_ranges_accepted_when_trusted_proxy_includes_them(): void {
		$settings = [
			'network' => [
				'trusted_proxies'  => [ '10.0.0.0/8', '192.168.0.0/16' ],
				'cloudflare_cidrs' => [],
			],
		];
		$server   = [
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '192.168.1.1, 10.0.0.2',
		];
		$result   = IpResolver::resolve( $server, $settings );

		$this->assertNotNull( $result['resolved'] );
	}

	/** IP-01: Non-IP REMOTE_ADDR returns null for both resolved and raw. */
	public function test_invalid_remote_addr_returns_null_resolved(): void {
		$result = IpResolver::resolve( [ 'REMOTE_ADDR' => 'not-an-ip' ], $this->settings_no_proxy() );

		$this->assertNull( $result['resolved'] );
		$this->assertNull( $result['raw'] );
	}

	public function test_ip_in_cidr_v4_inside(): void {
		$packed = IpResolver::pack_ip( '10.5.5.5' );
		$this->assertNotNull( $packed );
		$this->assertTrue( IpResolver::ip_in_cidr( $packed, '10.0.0.0/8' ) );
	}

	public function test_ip_in_cidr_v4_outside(): void {
		$packed = IpResolver::pack_ip( '10.5.5.5' );
		$this->assertNotNull( $packed );
		$this->assertFalse( IpResolver::ip_in_cidr( $packed, '11.0.0.0/8' ) );
	}

	public function test_ip_in_cidr_v6_inside(): void {
		$packed = IpResolver::pack_ip( '2001:db8::1' );
		$this->assertNotNull( $packed );
		$this->assertTrue( IpResolver::ip_in_cidr( $packed, '2001:db8::/32' ) );
	}

	/** IP-06: Both resolved and raw must be exactly 16-byte VARBINARY values. */
	public function test_resolved_and_raw_both_16_bytes(): void {
		$server = [ 'REMOTE_ADDR' => '203.0.113.5' ];
		$result = IpResolver::resolve( $server, $this->settings_no_proxy() );

		$this->assertSame( 16, \strlen( (string) $result['resolved'] ) );
		$this->assertSame( 16, \strlen( (string) $result['raw'] ) );
	}

	/** IP-03: CLOUDFLARE_CIDRS_V4 and CLOUDFLARE_CIDRS_V6 must be populated. */
	public function test_cloudflare_cidr_class_constant_populated(): void {
		$this->assertGreaterThanOrEqual( 15, \count( IpResolver::CLOUDFLARE_CIDRS_V4 ) );
		$this->assertGreaterThanOrEqual( 7, \count( IpResolver::CLOUDFLARE_CIDRS_V6 ) );
	}

	/** IP-05: PRIVATE_CIDRS must include IPv6 loopback, unique-local, and link-local. */
	public function test_private_cidrs_class_constant_includes_v6_loopback_and_unique_local(): void {
		$this->assertContains( '::1/128', IpResolver::PRIVATE_CIDRS );
		$this->assertContains( 'fc00::/7', IpResolver::PRIVATE_CIDRS );
		$this->assertContains( 'fe80::/10', IpResolver::PRIVATE_CIDRS );
	}
}
