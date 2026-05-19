<?php
/**
 * Integration tests for BetterRestApiLogs\DB\LogRepository and BodyRepository.
 *
 * RED-bar baseline: all tests fail with Class not found until Plans 03-05/06
 * implement the repository INSERT methods. Covers CAP-06 (byte-faithful slash-
 * and unicode-preserving bodies), CAP-09 (body-spill 2-table sequence), and
 * IP-06 (ip_raw_remote always populated) per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use WP_UnitTestCase;

final class LogRepositoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Remove WP_UnitTestCase temporary-table rewrite — our custom tables need to be real.
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		parent::tear_down();
	}

	/**
	 * Build a minimal Entry for fixture use.
	 *
	 * @param string $route    REST route path.
	 * @param string $req_body Optional raw request body.
	 */
	private function make_entry( string $route = '/wp/v2/posts', string $req_body = '' ): Entry {
		$req               = new RequestSnapshot();
		$req->route        = $route;
		$req->method       = 'GET';
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                = Entry::from_snapshots( $req, $res, [] );
		$entry->request_body  = '' !== $req_body ? $req_body : null;
		$raw_packed           = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote = false !== $raw_packed ? $raw_packed : null;
		return $entry;
	}

	public function test_insert_batch_single_entry_writes_one_row(): void {
		$repo  = new LogRepository();
		$entry = $this->make_entry();
		$ids   = $repo->insert_batch( [ $entry ] );

		$this->assertCount( 1, $ids );
		$this->assertGreaterThan( 0, $ids[0] );
	}

	/** Multi-row INSERT must return contiguous IDs (MySQL auto-increment contract). */
	public function test_insert_batch_multi_row_returns_contiguous_ids(): void {
		$repo = new LogRepository();
		$ids  = $repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts' ),
				$this->make_entry( '/wp/v2/users' ),
				$this->make_entry( '/wp/v2/comments' ),
			]
		);

		$this->assertCount( 3, $ids );
		$this->assertSame( $ids[0] + 1, $ids[1] );
		$this->assertSame( $ids[0] + 2, $ids[2] );
	}

	public function test_insert_batch_empty_array_returns_empty_array(): void {
		$repo = new LogRepository();
		$this->assertSame( [], $repo->insert_batch( [] ) );
	}

	/** CAP-06: slash-escaped body bytes must survive the round-trip byte-for-byte. */
	public function test_body_bytes_roundtrip_preserves_slashes(): void {
		global $wpdb;
		$body  = '{"path":"\\/wp\\/v2\\/posts"}';
		$repo  = new LogRepository();
		$entry = $this->make_entry( '/wp/v2/posts', $body );
		$ids   = $repo->insert_batch( [ $entry ] );

		$stored = $wpdb->get_var(
			$wpdb->prepare( 'SELECT request_body FROM ' . Database::logs_table() . ' WHERE id = %d', $ids[0] )
		);
		$this->assertSame( $body, $stored, 'CAP-06: slash-escaped body must be byte-identical after round-trip.' );
	}

	/** CAP-06: Unicode and emoji payloads must survive the round-trip byte-for-byte. */
	public function test_body_bytes_roundtrip_preserves_unicode(): void {
		global $wpdb;
		$body  = '{"name":"café","emoji":"🚀"}';
		$repo  = new LogRepository();
		$entry = $this->make_entry( '/wp/v2/posts', $body );
		$ids   = $repo->insert_batch( [ $entry ] );

		$stored = $wpdb->get_var(
			$wpdb->prepare( 'SELECT request_body FROM ' . Database::logs_table() . ' WHERE id = %d', $ids[0] )
		);
		$this->assertSame( $body, $stored, 'CAP-06: Unicode body must be byte-identical after round-trip.' );
	}

	/** CAP-09: body-spill writes a secondary row to brl_logs_bodies. */
	public function test_bodies_spilled_writes_secondary_row(): void {
		global $wpdb;

		$req_body  = '{"request":"data"}';
		$resp_body = '{"response":"data"}';

		$repo  = new LogRepository();
		$entry = $this->make_entry( '/wp/v2/posts' );

		$entry->bodies_spilled = true;
		$entry->request_body   = null;
		$entry->response_body  = null;

		$ids = $repo->insert_batch( [ $entry ] );
		$this->assertCount( 1, $ids );

		$body_repo = new BodyRepository();
		$ok        = $body_repo->insert_spilled( $ids[0], $req_body, $resp_body );
		$this->assertTrue( $ok );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT log_id, request_body FROM ' . Database::bodies_table() . ' WHERE log_id = %d',
				$ids[0]
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( (string) $ids[0], (string) $row['log_id'] );
		$this->assertSame( $req_body, $row['request_body'] );
	}

	/** CAP-09: no secondary row when bodies_spilled is false. */
	public function test_bodies_spilled_skipped_when_not_flagged(): void {
		global $wpdb;

		$repo                  = new LogRepository();
		$entry                 = $this->make_entry( '/wp/v2/posts' );
		$entry->bodies_spilled = false;

		$ids = $repo->insert_batch( [ $entry ] );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d', $ids[0] )
		);
		$this->assertSame( 0, $count );
	}

	/** IP-06: ip_raw_remote must be non-NULL for every successfully inserted row. */
	public function test_ip_raw_remote_always_populated(): void {
		global $wpdb;

		$repo = new LogRepository();

		$with_resolved              = $this->make_entry( '/wp/v2/posts' );
		$packed_resolved            = \inet_pton( '::ffff:203.0.113.5' );
		$with_resolved->ip_resolved = false !== $packed_resolved ? $packed_resolved : null;

		$null_resolved              = $this->make_entry( '/wp/v2/users' );
		$null_resolved->ip_resolved = null;

		$ids = $repo->insert_batch( [ $with_resolved, $null_resolved ] );

		foreach ( $ids as $id ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare( 'SELECT ip_raw_remote FROM ' . Database::logs_table() . ' WHERE id = %d', $id )
			);
			$this->assertNotNull( $raw, "IP-06: ip_raw_remote must not be NULL for row $id." );
			$this->assertSame( 16, \strlen( $raw ), 'IP-06: ip_raw_remote must be 16 bytes.' );
		}
	}

	/** Failed INSERT must return empty array without throwing. */
	public function test_insert_returns_empty_array_on_wpdb_failure(): void {
		global $wpdb;

		$repo  = new LogRepository();
		$entry = $this->make_entry();

		// Drop the table to force a failure.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::logs_table() );

		// The INSERT below intentionally targets a missing table; the wpdb error
		// printout is the expected condition under test, not a failure signal.
		// suppress_errors() is what gates both error_log() and the HTML print —
		// hide_errors() only blocks the HTML print, which still leaves noise in CI.
		$prev_suppress = $wpdb->suppress_errors( true );
		try {
			$ids = $repo->insert_batch( [ $entry ] );
		} finally {
			$wpdb->suppress_errors( $prev_suppress );
		}
		$this->assertSame( [], $ids );

		// Restore the table for tear_down.
		Schema::install();
	}
}
