<?php
/**
 * Integration tests for the CIDR allow/deny capture filter.
 *
 * The CIDR lists are saved under the Network tab (brl_settings_network) but the
 * capture orchestrator used to read them from the capture tab, so a deny CIDR
 * the admin entered never blocked anything. These tests store the lists under
 * the real network option and drive a request to prove the gate honours them.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Capture;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Logger\Queue;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

final class CidrFilterTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	/** @var string */
	private string $prev_remote_addr = '';

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before  = \ob_get_level();
		$this->prev_remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		Queue::reset();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		\delete_option( 'brl_settings_network' );
		$_SERVER['REMOTE_ADDR'] = $this->prev_remote_addr;
		Queue::reset();
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	private function boot_plugin(): void {
		Plugin::instance()->boot();
		\rest_get_server();
	}

	private function dispatch_and_count(): int {
		global $wpdb;
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		\rest_do_request( $request );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		\do_action( 'shutdown' );
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
	}

	/** A deny CIDR that covers the request IP must stop the row being logged. */
	public function test_deny_cidr_blocks_capture(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		\update_option(
			'brl_settings_network',
			[
				'trusted_proxy_cidrs' => [],
				'cloudflare_cidrs'    => [],
				'cidr_allowlist'      => [],
				'cidr_denylist'       => [ '203.0.113.0/24' ],
			]
		);

		$this->boot_plugin();

		$this->assertSame( 0, $this->dispatch_and_count(), 'A denied CIDR must block capture.' );
	}

	/** An IP outside the deny list is still captured. */
	public function test_ip_outside_deny_cidr_is_captured(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		\update_option(
			'brl_settings_network',
			[
				'trusted_proxy_cidrs' => [],
				'cloudflare_cidrs'    => [],
				'cidr_allowlist'      => [],
				'cidr_denylist'       => [ '203.0.113.0/24' ],
			]
		);

		$this->boot_plugin();

		$this->assertSame( 1, $this->dispatch_and_count(), 'An IP outside the deny list must still be captured.' );
	}
}
