<?php
/**
 * Unit test scaffold for BetterRestApiLogs\Domain\Entry.
 *
 * RED-bar baseline (Wave 0): asserts against the Entry DTO that Plan 02-03
 * will land. Currently fails with "Class not found" — that is the correct
 * RED state; Plan 02-03 turns these tests green.
 *
 * Targets STOR-07 (pure-PHP DTO round-trips without WP bootstrap).
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Domain;

use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class EntryTest extends TestCase {

	public function test_to_array_from_array_round_trips(): void {
		$entry                          = new Entry();
		$entry->id                      = 0;
		$entry->created_at              = '2026-05-18 02:00:00';
		$entry->created_at_micros       = 1_747_530_000_000_000;
		$entry->method                  = 'POST';
		$entry->route                   = '/wp/v2/posts';
		$entry->route_prefix            = 'wp/v2';
		$entry->query_string            = 'context=edit';
		$entry->status                  = 200;
		$entry->status_class            = 2;
		$entry->duration_ms             = 42;
		$entry->user_id                 = 7;
		$entry->ip_resolved             = \inet_pton( '1.2.3.4' );
		$entry->ip_raw_remote           = \inet_pton( '1.2.3.4' );
		$entry->request_content_type    = 'application/json';
		$entry->request_headers         = '{"x-foo":"bar"}';
		$entry->request_body            = '{"a":1}';
		$entry->request_body_bytes      = 7;
		$entry->request_body_truncated  = false;
		$entry->response_content_type   = 'application/json';
		$entry->response_headers        = '{"content-type":"application/json"}';
		$entry->response_body           = '{"ok":true}';
		$entry->response_body_bytes     = 11;
		$entry->response_body_truncated = false;
		$entry->bodies_spilled          = false;
		$entry->migration_source_id     = null;

		$arr     = $entry->to_array();
		$rebuilt = Entry::from_array( $arr + [ 'id' => $entry->id ] );

		$this->assertSame( $entry->method, $rebuilt->method );
		$this->assertSame( $entry->route, $rebuilt->route );
		$this->assertSame( $entry->route_prefix, $rebuilt->route_prefix );
		$this->assertSame( $entry->query_string, $rebuilt->query_string );
		$this->assertSame( $entry->status, $rebuilt->status );
		$this->assertSame( $entry->status_class, $rebuilt->status_class );
		$this->assertSame( $entry->duration_ms, $rebuilt->duration_ms );
		$this->assertSame( $entry->user_id, $rebuilt->user_id );
		$this->assertSame( $entry->ip_resolved, $rebuilt->ip_resolved );
		$this->assertSame( $entry->ip_raw_remote, $rebuilt->ip_raw_remote );
		$this->assertSame( $entry->request_content_type, $rebuilt->request_content_type );
		$this->assertSame( $entry->request_body, $rebuilt->request_body );
		$this->assertSame( $entry->request_body_bytes, $rebuilt->request_body_bytes );
		$this->assertSame( $entry->request_body_truncated, $rebuilt->request_body_truncated );
		$this->assertSame( $entry->response_content_type, $rebuilt->response_content_type );
		$this->assertSame( $entry->response_body, $rebuilt->response_body );
		$this->assertSame( $entry->response_body_bytes, $rebuilt->response_body_bytes );
		$this->assertSame( $entry->response_body_truncated, $rebuilt->response_body_truncated );
		$this->assertSame( $entry->bodies_spilled, $rebuilt->bodies_spilled );
		$this->assertSame( $entry->migration_source_id, $rebuilt->migration_source_id );
	}

	public function test_from_snapshots_computes_duration_and_status_class(): void {
		$req                    = new RequestSnapshot();
		$req->method            = 'GET';
		$req->route             = '/wp/v2/posts';
		$req->route_prefix      = 'wp/v2';
		$req->started_at_micros = 1_000_000_000;

		$res                     = new ResponseSnapshot();
		$res->status             = 404;
		$res->status_class       = 4;
		$res->finished_at_micros = 1_000_012_345;

		$entry = Entry::from_snapshots(
			$req,
			$res,
			[
				'created_at' => '2026-05-18 02:00:00',
			]
		);

		$this->assertSame( 12, $entry->duration_ms, 'round( (finished - started) / 1000 )' );
		$this->assertSame( 4, $entry->status_class, 'floor(404 / 100)' );
		$this->assertSame( 404, $entry->status );
	}

	public function test_from_snapshots_passes_context(): void {
		$req = new RequestSnapshot();
		$res = new ResponseSnapshot();
		$ctx = [
			'created_at'          => '2026-05-18 02:00:00',
			'user_id'             => 9,
			'ip_resolved'         => \inet_pton( '10.0.0.1' ),
			'ip_raw_remote'       => \inet_pton( '10.0.0.1' ),
			'bodies_spilled'      => true,
			'migration_source_id' => 'cpt-12345',
		];

		$entry = Entry::from_snapshots( $req, $res, $ctx );

		$this->assertSame( 9, $entry->user_id );
		$this->assertSame( $ctx['ip_resolved'], $entry->ip_resolved );
		$this->assertSame( $ctx['ip_raw_remote'], $entry->ip_raw_remote );
		$this->assertTrue( $entry->bodies_spilled );
		$this->assertSame( 'cpt-12345', $entry->migration_source_id );
	}
}
