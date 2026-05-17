<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Domain\RequestSnapshot.
 *
 * RED-bar baseline (Wave 0): targets STOR-07. Plan 02-03 turns these green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Domain;

use BetterRestApiLogs\Domain\RequestSnapshot;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RequestSnapshotTest extends TestCase {

	public function test_defaults(): void {
		$snap = new RequestSnapshot();
		$this->assertSame( '', $snap->method );
		$this->assertSame( '', $snap->route );
		$this->assertSame( '', $snap->route_prefix );
		$this->assertSame( '', $snap->content_type );
		$this->assertSame( [], $snap->headers );
		$this->assertNull( $snap->body );
		$this->assertSame( 0, $snap->body_bytes_original );
		$this->assertSame( 0, $snap->started_at_micros );
	}

	public function test_to_array_from_array_round_trips(): void {
		$snap                      = new RequestSnapshot();
		$snap->method              = 'POST';
		$snap->route               = '/wp/v2/posts';
		$snap->route_prefix        = 'wp/v2';
		$snap->query_string        = 'context=edit';
		$snap->content_type        = 'application/json';
		$snap->headers             = [ 'x-foo' => 'bar' ];
		$snap->body                = '{"a":1}';
		$snap->body_bytes_original = 7;
		$snap->started_at_micros   = 1_000_000_000;

		$arr     = $snap->to_array();
		$rebuilt = RequestSnapshot::from_array( $arr );

		$this->assertSame( $snap->method, $rebuilt->method );
		$this->assertSame( $snap->route, $rebuilt->route );
		$this->assertSame( $snap->route_prefix, $rebuilt->route_prefix );
		$this->assertSame( $snap->query_string, $rebuilt->query_string );
		$this->assertSame( $snap->content_type, $rebuilt->content_type );
		$this->assertSame( $snap->headers, $rebuilt->headers );
		$this->assertSame( $snap->body, $rebuilt->body );
		$this->assertSame( $snap->body_bytes_original, $rebuilt->body_bytes_original );
		$this->assertSame( $snap->started_at_micros, $rebuilt->started_at_micros );
	}
}
