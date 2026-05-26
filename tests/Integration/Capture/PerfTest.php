<?php
/**
 * Capture-pipeline microbenchmarks.
 *
 * RED-bar baseline: tests fail with Class not found until production code
 * lands. Covers FILT-04 (denied request <50µs) and REL-02 (component
 * p95 <500µs) per REQUIREMENTS.md.
 *
 * Tagged @group perf — excluded from the default test run by phpunit.xml.dist.
 * Run explicitly on the PHP 8.3 CI cell via:
 *   vendor/bin/phpunit --group perf tests/Integration/Capture/PerfTest.php
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Capture;

use BetterRestApiLogs\Capture\Compressor;
use BetterRestApiLogs\Capture\Filter;
use BetterRestApiLogs\Capture\IpResolver;
use BetterRestApiLogs\Capture\Redactor;
use BetterRestApiLogs\Capture\Truncator;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * @group perf
 */
final class PerfTest extends WP_UnitTestCase {

	private const ITERATIONS = 1000;

	public function set_up(): void {
		parent::set_up();
		Plugin::instance()->boot();
		// Remove WP_UnitTestCase temporary-table rewrite.
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	/**
	 * Compute the p95 value from a sorted array of microsecond samples.
	 *
	 * @param float[] $samples Array of sample values (will be sorted in place).
	 */
	private function p95( array &$samples ): float {
		sort( $samples );
		$idx = (int) ( 0.95 * count( $samples ) );
		return $samples[ $idx ];
	}

	/** REL-02: Redactor::redact_headers p95 must be under 500µs. */
	public function test_redact_headers_p95_under_500us(): void {
		$redactor = new Redactor();
		$headers  = [
			'Authorization'   => [ 'Bearer secret-token' ],
			'Content-Type'    => [ 'application/json' ],
			'Accept'          => [ '*/*' ],
			'Cookie'          => [ 'session=xyz' ],
			'x-api-key'       => [ 'api-key-value' ],
			'User-Agent'      => [ 'TestClient/1.0' ],
			'Accept-Language' => [ 'en-US,en;q=0.9' ],
			'Cache-Control'   => [ 'no-cache' ],
			'x-custom-one'    => [ 'value1' ],
			'x-custom-two'    => [ 'value2' ],
		];

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			$redactor->redact_headers( $headers, [] );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 500, $p95_us, "redact_headers p95 = {$p95_us}µs (limit 500µs)." );
	}

	/** REL-02: Redactor::redact_json_body on a 4 KB nested payload p95 must be under 500µs. */
	public function test_redact_json_body_p95_under_500us(): void {
		$redactor = new Redactor();
		$payload  = wp_json_encode(
			[
				'user'     => [
					'password' => 'secret',
					'name'     => 'Alice',
					'email'    => 'alice@example.com',
					'token'    => 'tkn_abc',
				],
				'meta'     => [
					'secret' => 'meta-secret',
					'key'    => 'meta-key',
					'data'   => str_repeat( 'abcdefghij', 200 ),
				],
				'shipping' => [
					'address' => '123 Main St',
					'city'    => 'Anywhere',
				],
			]
		);

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			$redactor->redact_json_body( (string) $payload );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 500, $p95_us, "redact_json_body p95 = {$p95_us}µs (limit 500µs)." );
	}

	/** REL-02: IpResolver::resolve p95 must be under 500µs. */
	public function test_ip_resolve_p95_under_500us(): void {
		$server   = [
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.2',
		];
		$settings = [
			'network' => [
				'trusted_proxies'  => [ '10.0.0.0/8' ],
				'cloudflare_cidrs' => [],
			],
		];

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			IpResolver::resolve( $server, $settings );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 500, $p95_us, "IpResolver::resolve p95 = {$p95_us}µs (limit 500µs)." );
	}

	/** REL-02: Filter::should_capture_at_pre_dispatch p95 must be under 500µs. */
	public function test_filter_should_capture_at_pre_dispatch_p95_under_500us(): void {
		$settings = [
			'capture' => [
				'enabled'             => true,
				'methods'             => [],
				'route_allowlist'     => [],
				'route_denylist'      => [],
				'status_class_filter' => [],
				'cidr_allowlist'      => [],
				'cidr_denylist'       => [],
			],
		];
		$request  = new \WP_REST_Request( 'GET', '/wp/v2/posts' );

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			Filter::should_capture_at_pre_dispatch( $request, $settings );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 500, $p95_us, "Filter::should_capture_at_pre_dispatch p95 = {$p95_us}µs (limit 500µs)." );
	}

	/** FILT-04: a denied request (master disabled) must cost <50µs p95. */
	public function test_denied_request_under_50us(): void {
		$settings = [
			'capture' => [
				'enabled' => false,
			],
		];
		$request  = new \WP_REST_Request( 'GET', '/wp/v2/posts' );

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			Filter::should_capture_at_pre_dispatch( $request, $settings );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 50, $p95_us, "FILT-04 denied-request p95 = {$p95_us}µs (limit 50µs)." );
	}

	/** REL-02: Truncator::truncate on a 128 KB body p95 must be under 500µs. */
	public function test_truncate_p95_under_500us(): void {
		$body = \str_repeat( 'a', 128 * 1024 );

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			Truncator::truncate( $body, 64 * 1024 );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;
		}

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 500, $p95_us, "Truncator::truncate p95 = {$p95_us}µs (limit 500µs)." );
	}

	/** REL-02: LogRepository::insert_batch(1 entry) p95 must be under 500µs. */
	public function test_insert_batch_single_row_p95_under_500us(): void {
		global $wpdb;
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$repo              = new LogRepository();
		$req               = new RequestSnapshot();
		$req->route        = '/wp/v2/posts';
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                = Entry::from_snapshots( $req, $res, [] );
		$perf_packed          = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote = false !== $perf_packed ? $perf_packed : null;

		$samples = [];
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$t0 = \hrtime( true );
			$repo->insert_batch( [ $entry ] );
			$samples[] = ( \hrtime( true ) - $t0 ) / 1000;

			// Periodic cleanup to prevent table from growing unboundedly.
			if ( 0 === $i % 100 ) {
				$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
			}
		}
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );

		$p95_us = $this->p95( $samples );
		$this->assertLessThan( 500, $p95_us, "LogRepository::insert_batch (1 row) p95 = {$p95_us}µs (limit 500µs)." );
	}
}
