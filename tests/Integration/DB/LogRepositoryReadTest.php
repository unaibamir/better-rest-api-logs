<?php
/**
 * Integration tests for LogRepository read methods added in Phase 4.
 *
 * RED-bar scaffold: all tests fail until Plan 04-05 implements the read
 * methods in includes/db/log-repository.php. Covers find(), search(),
 * count_by_status_class(), count_by_method(), oldest_newest(), delete(),
 * and delete_many() against a 50-row seeded fixture.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 1 (Plan 04-05) — LogRepository read methods do not exist yet.

final class LogRepositoryReadTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	/** @var int[] IDs inserted during set_up. */
	private array $seeded_ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->seeded_ids = $this->seed_fixture( 50 );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		$this->seeded_ids = [];
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * Seed N rows split across methods and status classes for predictable counts.
	 *
	 * @param int $count Total rows to insert.
	 * @return int[] Inserted IDs.
	 */
	private function seed_fixture( int $count ): array {
		$methods  = [ 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ];
		$statuses = [ 200, 201, 301, 400, 404, 500 ];
		$entries  = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$method = $methods[ $i % count( $methods ) ];
			$status = $statuses[ $i % count( $statuses ) ];

			$req               = new RequestSnapshot();
			$req->route        = '/wp/v2/posts';
			$req->method       = $method;
			$req->content_type = 'application/json';

			$res               = new ResponseSnapshot();
			$res->status       = $status;
			$res->status_class = (int) floor( $status / 100 );
			$res->content_type = 'application/json';

			$entry                = Entry::from_snapshots( $req, $res, [] );
			$packed               = \inet_pton( '::ffff:127.0.0.1' );
			$entry->ip_raw_remote = false !== $packed ? $packed : null;
			$entries[]            = $entry;
		}

		$repo = new LogRepository();
		return $repo->insert_batch( $entries );
	}

	public function test_find_returns_entry_for_existing_id(): void {
		$repo  = new LogRepository();
		$first = $this->seeded_ids[0];
		$entry = $repo->find( $first );

		$this->assertNotNull( $entry );
		$this->assertInstanceOf( Entry::class, $entry );
		$this->assertSame( $first, $entry->id );
	}

	public function test_find_returns_null_for_missing_id(): void {
		$repo  = new LogRepository();
		$entry = $repo->find( 99999 );

		$this->assertNull( $entry );
	}

	public function test_search_returns_struct_with_required_keys(): void {
		$repo   = new LogRepository();
		$args   = QueryArgs::from_array( [] );
		$result = $repo->search( $args );

		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'has_more', $result );
		$this->assertArrayHasKey( 'next_cursor', $result );
	}

	public function test_search_with_method_filter_narrows_results(): void {
		$repo   = new LogRepository();
		$args   = QueryArgs::from_array( [ 'method' => 'GET' ] );
		$result = $repo->search( $args );

		foreach ( $result['rows'] as $entry ) {
			$this->assertSame( 'GET', $entry->method );
		}
	}

	public function test_search_has_more_true_when_rows_exceed_limit(): void {
		$repo   = new LogRepository();
		$args   = QueryArgs::from_array( [ 'limit' => 5 ] );
		$result = $repo->search( $args );

		$this->assertTrue( $result['has_more'], 'has_more must be true when fixture exceeds limit.' );
		$this->assertNotNull( $result['next_cursor'], 'next_cursor must be set when has_more is true.' );
	}

	public function test_count_by_status_class_returns_five_class_keys(): void {
		$repo   = new LogRepository();
		$counts = $repo->count_by_status_class();

		$this->assertArrayHasKey( '2xx', $counts );
		$this->assertArrayHasKey( '3xx', $counts );
		$this->assertArrayHasKey( '4xx', $counts );
		$this->assertArrayHasKey( '5xx', $counts );
	}

	public function test_count_by_status_class_sums_match_seeded_rows(): void {
		$repo   = new LogRepository();
		$counts = $repo->count_by_status_class();

		$total = array_sum( $counts );
		$this->assertSame( 50, $total, 'Status class counts must sum to the number of seeded rows.' );
	}

	public function test_count_by_method_returns_expected_methods(): void {
		$repo   = new LogRepository();
		$counts = $repo->count_by_method();

		$this->assertArrayHasKey( 'GET', $counts );
		$this->assertArrayHasKey( 'POST', $counts );
	}

	public function test_oldest_newest_returns_boundary_timestamps(): void {
		$repo   = new LogRepository();
		$result = $repo->oldest_newest();

		$this->assertArrayHasKey( 'oldest', $result );
		$this->assertArrayHasKey( 'newest', $result );
		$this->assertNotNull( $result['oldest'] );
		$this->assertNotNull( $result['newest'] );
	}

	public function test_delete_removes_one_row(): void {
		global $wpdb;
		$repo    = new LogRepository();
		$log_id  = $this->seeded_ids[0];

		$ok    = $repo->delete( $log_id );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE id = %d', $log_id )
		);

		$this->assertTrue( $ok, 'delete() must return true on success.' );
		$this->assertSame( 0, $count, 'Row must no longer exist after delete().' );
	}

	public function test_delete_cascades_spilled_body(): void {
		global $wpdb;
		$repo     = new LogRepository();
		$log_id   = $this->seeded_ids[1];

		// Insert a spill row manually.
		$body_repo = new BodyRepository();
		$body_repo->insert_spilled( $log_id, '{"req":"data"}', '{"res":"data"}' );

		$repo->delete( $log_id );

		$body_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d', $log_id )
		);
		$this->assertSame( 0, $body_count, 'Body spill must be cascade-deleted.' );
	}

	public function test_delete_many_removes_multiple_rows(): void {
		global $wpdb;
		$repo = new LogRepository();
		$ids  = [ $this->seeded_ids[2], $this->seeded_ids[3], $this->seeded_ids[4] ];

		$affected = $repo->delete_many( $ids );
		$this->assertSame( 3, $affected, 'delete_many() must return 3 for 3 IDs.' );

		foreach ( $ids as $id ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE id = %d', $id )
			);
			$this->assertSame( 0, $count, "Row {$id} must be deleted by delete_many()." );
		}
	}
}
