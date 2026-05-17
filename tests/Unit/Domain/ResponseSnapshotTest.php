<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Domain\ResponseSnapshot.
 *
 * RED-bar baseline (Wave 0): targets STOR-07. Plan 02-03 turns these green.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Domain;

use BetterRestApiLogs\Domain\ResponseSnapshot;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ResponseSnapshotTest extends TestCase {

	public function test_defaults(): void {
		$snap = new ResponseSnapshot();
		$this->assertSame( 0, $snap->status );
		$this->assertSame( 0, $snap->status_class );
		$this->assertSame( '', $snap->content_type );
		$this->assertSame( [], $snap->headers );
		$this->assertNull( $snap->body );
		$this->assertSame( 0, $snap->body_bytes_original );
		$this->assertSame( 0, $snap->finished_at_micros );
	}

	public function test_to_array_from_array_round_trips(): void {
		$snap                      = new ResponseSnapshot();
		$snap->status              = 200;
		$snap->status_class        = 2;
		$snap->content_type        = 'application/json';
		$snap->headers             = [ 'content-type' => 'application/json' ];
		$snap->body                = '{"ok":true}';
		$snap->body_bytes_original = 11;
		$snap->finished_at_micros  = 1_000_012_345;

		$arr     = $snap->to_array();
		$rebuilt = ResponseSnapshot::from_array( $arr );

		$this->assertSame( $snap->status, $rebuilt->status );
		$this->assertSame( $snap->status_class, $rebuilt->status_class );
		$this->assertSame( $snap->content_type, $rebuilt->content_type );
		$this->assertSame( $snap->headers, $rebuilt->headers );
		$this->assertSame( $snap->body, $rebuilt->body );
		$this->assertSame( $snap->body_bytes_original, $rebuilt->body_bytes_original );
		$this->assertSame( $snap->finished_at_micros, $rebuilt->finished_at_micros );
	}
}
