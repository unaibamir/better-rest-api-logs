<?php
/**
 * Integration tests for the Phase 4 read path on LogRepository and BodyRepository.
 *
 * Covers: find/search/count_by_status_class/count_by_method/oldest_newest/delete/
 * delete_many and BodyRepository::find_by_log_id. Exercises cursor pagination with
 * tie-break on id, and verifies cascade-delete ordering (D-22).
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\Paginator;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\DB\Query\QueryBuilder;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use WP_UnitTestCase;

final class LogRepositoryReadTest extends WP_UnitTestCase {

	/** @var LogRepository */
	private $repo;

	/** @var BodyRepository */
	private $body_repo;

	public function set_up(): void {
		parent::set_up();
		// Real tables required — WP_UnitTestCase proxies CREATE to CREATE TEMPORARY.
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		$this->repo      = new LogRepository( new QueryBuilder() );
		$this->body_repo = new BodyRepository();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal Entry suitable for INSERT.
	 *
	 * @param string $route         REST route.
	 * @param string $method        HTTP method.
	 * @param int    $status        HTTP status code.
	 * @param int    $micros        created_at_micros value.
	 * @param bool   $spilled       Whether bodies are spilled.
	 */
	private function make_entry(
		string $route = '/wp/v2/posts',
		string $method = 'GET',
		int $status = 200,
		int $micros = 0,
		bool $spilled = false
	): Entry {
		$req               = new RequestSnapshot();
		$req->route        = $route;
		$req->route_prefix = '/' . ( explode( '/', ltrim( $route, '/' ) )[0] ?? 'wp' );
		$req->method       = $method;
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = $status;
		$res->status_class = (int) \floor( $status / 100 );
		$res->content_type = 'application/json';

		$entry                    = Entry::from_snapshots( $req, $res, [] );
		$entry->created_at_micros = $micros > 0 ? $micros : (int) ( microtime( true ) * 1_000_000 );
		$entry->bodies_spilled    = $spilled;
		$packed                   = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote     = false !== $packed ? $packed : null;

		return $entry;
	}

	/**
	 * Returns an Entry from find() for an existing primary key.
	 */
	public function test_find_returns_entry_for_existing_id(): void {
		$entry = $this->make_entry();
		$ids   = $this->repo->insert_batch( [ $entry ] );
		$this->assertCount( 1, $ids );

		$found = $this->repo->find( $ids[0] );
		$this->assertInstanceOf( Entry::class, $found );
		$this->assertSame( $ids[0], $found->id );
		$this->assertSame( 'GET', $found->method );
	}

	public function test_find_returns_null_for_missing_id(): void {
		$found = $this->repo->find( 99999 );
		$this->assertNull( $found );
	}

	public function test_find_joins_spilled_bodies(): void {
		$entry = $this->make_entry( '/wp/v2/posts', 'GET', 200, 0, true );
		$ids   = $this->repo->insert_batch( [ $entry ] );
		$this->body_repo->insert_spilled( $ids[0], '{"req":"data"}', '{"res":"data"}' );

		$found = $this->repo->find( $ids[0] );
		$this->assertNotNull( $found );
		$this->assertTrue( $found->bodies_spilled );
		$this->assertSame( '{"req":"data"}', $found->request_body );
		$this->assertSame( '{"res":"data"}', $found->response_body );
	}

	/**
	 * Returns an empty result set from search() against an empty table.
	 */
	public function test_search_returns_empty_on_empty_table(): void {
		$result = $this->repo->search( new QueryArgs() );
		$this->assertSame( [], $result['rows'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertNull( $result['next_cursor'] );
	}

	public function test_search_returns_entries_in_desc_order(): void {
		$now = (int) ( microtime( true ) * 1_000_000 );
		$e1  = $this->make_entry( '/wp/v2/posts', 'GET', 200, $now - 1000 );
		$e2  = $this->make_entry( '/wp/v2/posts', 'GET', 200, $now );
		$this->repo->insert_batch( [ $e1, $e2 ] );

		$result = $this->repo->search( new QueryArgs() );
		$this->assertCount( 2, $result['rows'] );
		// Newest first (DESC).
		$this->assertGreaterThan( $result['rows'][1]->created_at_micros, $result['rows'][0]->created_at_micros );
	}

	public function test_search_has_more_true_when_extra_row_returned(): void {
		// Insert limit+1 rows and assert has_more is true with default limit=20.
		$now     = (int) ( microtime( true ) * 1_000_000 );
		$entries = [];
		for ( $i = 0; $i < 21; $i++ ) {
			$entries[] = $this->make_entry( '/wp/v2/posts', 'GET', 200, $now - $i * 1000 );
		}
		$this->repo->insert_batch( $entries );

		$result = $this->repo->search( new QueryArgs() );
		$this->assertCount( 20, $result['rows'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertNotNull( $result['next_cursor'] );
	}

	public function test_search_filter_by_method(): void {
		$now = (int) ( microtime( true ) * 1_000_000 );
		$this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now ),
				$this->make_entry( '/wp/v2/posts', 'POST', 201, $now - 1000 ),
			]
		);

		$args   = QueryArgs::from_array( [ 'method' => 'POST' ] );
		$result = $this->repo->search( $args );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'POST', $result['rows'][0]->method );
	}

	public function test_search_filter_by_status_class(): void {
		$now = (int) ( microtime( true ) * 1_000_000 );
		$this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now ),
				$this->make_entry( '/wp/v2/posts', 'GET', 404, $now - 1000 ),
				$this->make_entry( '/wp/v2/posts', 'GET', 500, $now - 2000 ),
			]
		);

		$args   = QueryArgs::from_array( [ 'status_class' => '4xx' ] );
		$result = $this->repo->search( $args );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 404, $result['rows'][0]->status );
	}

	public function test_search_cursor_pagination_walks_without_skips(): void {
		// 30 rows with the same created_at_micros to stress-test id tie-breaking.
		$shared_micros = (int) ( microtime( true ) * 1_000_000 );
		$entries       = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$entries[] = $this->make_entry( '/wp/v2/posts', 'GET', 200, $shared_micros );
		}
		$this->repo->insert_batch( $entries );

		// Page 1: limit 10.
		$page1 = $this->repo->search( QueryArgs::from_array( [ 'limit' => 10 ] ) );
		$this->assertCount( 10, $page1['rows'] );
		$this->assertTrue( $page1['has_more'] );
		$this->assertNotNull( $page1['next_cursor'] );

		// Page 2: cursor from page 1.
		$page2 = $this->repo->search(
			QueryArgs::from_array(
				[
					'limit'  => 10,
					'cursor' => $page1['next_cursor'],
				]
			)
		);
		$this->assertCount( 10, $page2['rows'] );
		$this->assertTrue( $page2['has_more'] );

		// Page 3: cursor from page 2.
		$page3 = $this->repo->search(
			QueryArgs::from_array(
				[
					'limit'  => 10,
					'cursor' => $page2['next_cursor'],
				]
			)
		);
		$this->assertCount( 10, $page3['rows'] );
		$this->assertFalse( $page3['has_more'] );

		// Verify no row is skipped or duplicated across all three pages.
		$all_ids = array_merge(
			array_map(
				static function ( Entry $e ): int {
					return $e->id; },
				$page1['rows']
			),
			array_map(
				static function ( Entry $e ): int {
					return $e->id; },
				$page2['rows']
			),
			array_map(
				static function ( Entry $e ): int {
					return $e->id; },
				$page3['rows']
			)
		);
		$this->assertSame( 30, count( $all_ids ) );
		$this->assertSame( 30, count( array_unique( $all_ids ) ), 'Cursor walk produced duplicate or skipped rows.' );
	}

	public function test_search_cursor_asc_direction(): void {
		$now     = (int) ( microtime( true ) * 1_000_000 );
		$entries = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$entries[] = $this->make_entry( '/wp/v2/posts', 'GET', 200, $now + $i * 1000 );
		}
		$this->repo->insert_batch( $entries );

		$page1 = $this->repo->search(
			QueryArgs::from_array(
				[
					'limit'     => 3,
					'order_by'  => 'created_at',
					'order_dir' => 'ASC',
				]
			)
		);
		$this->assertCount( 3, $page1['rows'] );
		$this->assertTrue( $page1['has_more'] );

		$page2 = $this->repo->search(
			QueryArgs::from_array(
				[
					'limit'     => 3,
					'order_by'  => 'created_at',
					'order_dir' => 'ASC',
					'cursor'    => $page1['next_cursor'],
				]
			)
		);
		$this->assertCount( 2, $page2['rows'] );
		$this->assertFalse( $page2['has_more'] );

		// ASC: page2 rows should have higher micros than page1 last row.
		$page1_last = end( $page1['rows'] );
		$this->assertGreaterThan( $page1_last->created_at_micros, $page2['rows'][0]->created_at_micros );
	}

	/**
	 * Returns all five status buckets from count_by_status_class().
	 */
	public function test_count_by_status_class_returns_all_five_keys(): void {
		$result = $this->repo->count_by_status_class();
		$this->assertArrayHasKey( '1xx', $result );
		$this->assertArrayHasKey( '2xx', $result );
		$this->assertArrayHasKey( '3xx', $result );
		$this->assertArrayHasKey( '4xx', $result );
		$this->assertArrayHasKey( '5xx', $result );
	}

	public function test_count_by_status_class_sums_correctly(): void {
		$now = (int) ( microtime( true ) * 1_000_000 );
		$this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now ),
				$this->make_entry( '/wp/v2/posts', 'GET', 201, $now - 1000 ),
				$this->make_entry( '/wp/v2/posts', 'GET', 404, $now - 2000 ),
				$this->make_entry( '/wp/v2/posts', 'GET', 500, $now - 3000 ),
			]
		);

		$result = $this->repo->count_by_status_class();
		$this->assertSame( 2, $result['2xx'] );
		$this->assertSame( 1, $result['4xx'] );
		$this->assertSame( 1, $result['5xx'] );
		$this->assertSame( 0, $result['3xx'] );
	}

	/**
	 * Returns only the methods present in the table from count_by_method().
	 */
	public function test_count_by_method_returns_present_methods_only(): void {
		$now = (int) ( microtime( true ) * 1_000_000 );
		$this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now ),
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now - 1000 ),
				$this->make_entry( '/wp/v2/posts', 'POST', 201, $now - 2000 ),
			]
		);

		$result = $this->repo->count_by_method();
		$this->assertSame( 2, $result['GET'] );
		$this->assertSame( 1, $result['POST'] );
		$this->assertArrayNotHasKey( 'DELETE', $result );
	}

	public function test_count_by_method_empty_table(): void {
		$result = $this->repo->count_by_method();
		$this->assertSame( [], $result );
	}

	/**
	 * Returns nulls from oldest_newest() on an empty table.
	 */
	public function test_oldest_newest_returns_null_on_empty_table(): void {
		$result = $this->repo->oldest_newest();
		$this->assertNull( $result['oldest'] );
		$this->assertNull( $result['newest'] );
	}

	public function test_oldest_newest_returns_iso8601_strings(): void {
		$older_micros = 1716109800000000; // 2024-05-19 11:30:00 UTC
		$newer_micros = 1716196200000000; // 2024-05-20 11:30:00 UTC
		$this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $older_micros ),
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $newer_micros ),
			]
		);

		$result = $this->repo->oldest_newest();
		$this->assertNotNull( $result['oldest'] );
		$this->assertNotNull( $result['newest'] );
		// Verify ISO-8601 format with Z suffix.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['oldest'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['newest'] );
		// Oldest must be earlier than newest.
		$this->assertLessThan( $result['newest'], $result['oldest'] );
	}

	/**
	 * Removes the row via delete() and returns true.
	 */
	public function test_delete_removes_row_and_returns_true(): void {
		global $wpdb;
		$entry = $this->make_entry();
		$ids   = $this->repo->insert_batch( [ $entry ] );

		$ok = $this->repo->delete( $ids[0] );
		$this->assertTrue( $ok );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::logs_table() . ' WHERE id = %d', $ids[0] )
		);
		$this->assertSame( 0, $count );
	}

	public function test_delete_returns_false_for_missing_id(): void {
		$ok = $this->repo->delete( 99999 );
		$this->assertFalse( $ok );
	}

	public function test_delete_cascades_spilled_body(): void {
		global $wpdb;
		$entry = $this->make_entry( '/wp/v2/posts', 'GET', 200, 0, true );
		$ids   = $this->repo->insert_batch( [ $entry ] );
		$this->body_repo->insert_spilled( $ids[0], '{"req":"data"}', null );

		$this->repo->delete( $ids[0] );

		$body_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::bodies_table() . ' WHERE log_id = %d', $ids[0] )
		);
		$this->assertSame( 0, $body_count, 'D-22: cascade delete must remove the bodies row.' );
	}

	/**
	 * Removes the rows via delete_many() and returns the affected count.
	 */
	public function test_delete_many_removes_rows_and_returns_count(): void {
		global $wpdb;
		$now = (int) ( microtime( true ) * 1_000_000 );
		$ids = $this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now ),
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now - 1000 ),
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now - 2000 ),
			]
		);

		$affected = $this->repo->delete_many( [ $ids[0], $ids[1] ] );
		$this->assertSame( 2, $affected );

		$remaining = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( 1, $remaining );
	}

	public function test_delete_many_empty_array_returns_zero(): void {
		$affected = $this->repo->delete_many( [] );
		$this->assertSame( 0, $affected );
	}

	public function test_delete_many_filters_non_positive_ids(): void {
		$affected = $this->repo->delete_many( [ 0, -1, -999 ] );
		$this->assertSame( 0, $affected );
	}

	public function test_delete_many_cascades_spilled_bodies(): void {
		global $wpdb;
		$now = (int) ( microtime( true ) * 1_000_000 );
		$ids = $this->repo->insert_batch(
			[
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now, true ),
				$this->make_entry( '/wp/v2/posts', 'GET', 200, $now - 1000, true ),
			]
		);
		$this->body_repo->insert_spilled( $ids[0], '{"a":1}', null );
		$this->body_repo->insert_spilled( $ids[1], '{"b":2}', null );

		$this->repo->delete_many( $ids );

		$body_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::bodies_table() );
		$this->assertSame( 0, $body_count, 'D-22: bulk cascade delete must remove all bodies rows.' );
	}

	/**
	 * Returns null from BodyRepository::find_by_log_id() for a missing id.
	 */
	public function test_find_by_log_id_returns_null_for_missing(): void {
		$result = $this->body_repo->find_by_log_id( 99999 );
		$this->assertNull( $result );
	}

	public function test_find_by_log_id_returns_bodies(): void {
		$entry = $this->make_entry( '/wp/v2/posts', 'GET', 200, 0, true );
		$ids   = $this->repo->insert_batch( [ $entry ] );
		$this->body_repo->insert_spilled( $ids[0], '{"req":"data"}', '{"res":"data"}' );

		$result = $this->body_repo->find_by_log_id( $ids[0] );
		$this->assertNotNull( $result );
		$this->assertSame( '{"req":"data"}', $result['request_body'] );
		$this->assertSame( '{"res":"data"}', $result['response_body'] );
	}

	/**
	 * QueryBuilder emits ORDER BY (created_at_micros, id) regardless of the
	 * user-facing sort column. The cursor encodes (created_at_micros, id) so
	 * any other ORDER BY would mismatch the cursor predicate and skip or
	 * repeat rows across pages.
	 */
	public function test_query_builder_orders_by_micros_even_when_sort_is_created_at(): void {
		$args   = QueryArgs::from_array( [ 'order_by' => 'created_at' ] );
		$result = ( new QueryBuilder() )->build( $args );

		$this->assertStringContainsString( 'ORDER BY created_at_micros', $result['sql'] );
	}

	public function test_query_builder_orders_by_micros_even_when_sort_is_duration_ms(): void {
		// duration_ms is whitelisted in Sort::ALLOWED_COLUMNS for v1, but it is
		// not a meaningful cursor key — the cursor encodes (created_at_micros, id).
		// QueryBuilder must therefore always ORDER BY the same tuple the cursor
		// compares against, regardless of which sort column the caller asked for.
		$args   = QueryArgs::from_array( [ 'order_by' => 'duration_ms' ] );
		$result = ( new QueryBuilder() )->build( $args );

		$this->assertStringContainsString( 'ORDER BY created_at_micros', $result['sql'] );
		$this->assertStringNotContainsString( 'ORDER BY duration_ms', $result['sql'] );
	}

	public function test_search_cursor_pagination_works_with_duration_ms_sort(): void {
		// Inserting rows with distinct created_at_micros so cursor walking is
		// deterministic; the test asserts no row is skipped or repeated even
		// when the caller asks for sort=duration_ms.
		$base    = (int) ( microtime( true ) * 1_000_000 );
		$entries = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$entries[] = $this->make_entry( '/wp/v2/posts', 'GET', 200, $base - $i * 1000 );
		}
		$this->repo->insert_batch( $entries );

		$collected = [];
		$cursor    = null;
		$pages     = 0;

		do {
			$input = [
				'limit'    => 10,
				'order_by' => 'duration_ms',
			];
			if ( null !== $cursor ) {
				$input['cursor'] = $cursor;
			}
			$page = $this->repo->search( QueryArgs::from_array( $input ) );
			foreach ( $page['rows'] as $row ) {
				$collected[] = $row->id;
			}
			$cursor = $page['next_cursor'];
			++$pages;
			$this->assertLessThan( 5, $pages, 'Cursor walk should terminate well under 5 pages for 25 rows.' );
		} while ( $page['has_more'] && null !== $cursor );

		$this->assertCount( 25, $collected, 'All 25 inserted rows must be returned across cursor pages.' );
		$this->assertCount( 25, array_unique( $collected ), 'No row may appear on more than one page.' );
	}
}
