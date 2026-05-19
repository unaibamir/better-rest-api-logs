<?php
/**
 * Search performance gate for LogRepository::search on 100k rows.
 *
 * Seeds 100k rows then runs 100 representative QueryArgs combinations, asserts
 * p95 wall-time < 100ms (REL-03 hard gate), and verifies the route_prefix query
 * uses an index via EXPLAIN.
 *
 * Tagged @group perf — excluded from the default composer test run via phpunit.xml.dist.
 * Run explicitly: composer test -- --group perf
 *
 * @group perf
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\DB;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\DB\Query\QueryBuilder;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use WP_UnitTestCase;

final class LogRepositorySearchPerfTest extends WP_UnitTestCase {

	private const SEED_ROWS     = 100_000;
	private const BATCH_SIZE    = 5_000;
	private const SAMPLES       = 100;
	private const P95_BUDGET_MS = 100;

	/** @var LogRepository */
	private $repo;

	/** @var int */
	private $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		// Remove WP_UnitTestCase temporary-table rewrite so our tables get real indexes.
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$this->repo = new LogRepository( new QueryBuilder() );
		$this->seed_rows( self::SEED_ROWS );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		// Restore ob_level to what it was before set_up (shutdown guard per CLAUDE.md).
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * Main perf gate: p95 of 100 diverse QueryArgs must be under 100ms.
	 */
	public function test_search_p95_under_budget(): void {
		$combinations = $this->build_filter_combinations();
		$samples      = [];
		foreach ( $combinations as $args ) {
			$t0 = microtime( true );
			$this->repo->search( $args );
			$samples[] = ( microtime( true ) - $t0 ) * 1000.0;
		}

		sort( $samples );
		$p95 = $samples[ (int) ( 0.95 * count( $samples ) ) ];

		// Report p95 in output for observability even on pass.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing a one-line perf metric to STDERR for visibility in CI output; WP_Filesystem is irrelevant for diagnostic stderr.
		fwrite( STDERR, sprintf( "\nLogRepositorySearchPerfTest: p95=%.2fms (budget=%dms, rows=%d)\n", $p95, self::P95_BUDGET_MS, self::SEED_ROWS ) );

		$this->assertLessThan(
			self::P95_BUDGET_MS,
			$p95,
			sprintf( 'search p95 = %.2fms; budget = %dms on %d rows', $p95, self::P95_BUDGET_MS, self::SEED_ROWS )
		);
	}

	/**
	 * EXPLAIN gate: route_prefix-filtered query must pick an index.
	 *
	 * Pitfall 6 from plan-checker: WP_UnitTestCase proxies EXPLAIN through the
	 * temp-table wrapper — removing the filter in set_up() bypasses this so EXPLAIN
	 * runs against a real table with real indexes.
	 */
	public function test_route_prefix_query_uses_index(): void {
		global $wpdb;

		$logs_table = Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$explain = $wpdb->get_results(
			$wpdb->prepare(
				"EXPLAIN SELECT id FROM {$logs_table} WHERE route_prefix = %s ORDER BY created_at DESC, id DESC LIMIT 21",
				'/wp/v2/'
			),
			ARRAY_A
		);

		$this->assertNotEmpty( $explain, 'EXPLAIN returned no rows.' );
		$row = $explain[0];

		// MariaDB + MySQL both populate `key` with the chosen index name; null/empty means full scan.
		$this->assertNotEmpty(
			$row['key'] ?? '',
			'EXPLAIN did not pick an index for route_prefix filter — schema may need an index review.'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Seed $n rows in BATCH_SIZE chunks.
	 *
	 * @param int $n Total rows to insert.
	 */
	private function seed_rows( int $n ): void {
		$methods        = [ 'GET', 'POST', 'PUT', 'DELETE' ];
		$status_codes   = [ 200, 201, 204, 301, 400, 404, 500 ];
		$route_prefixes = [ '/wp/v2/', '/wc/v3/', '/jwt-auth/v1/', '/custom/v1/' ];
		$now_micros     = (int) ( microtime( true ) * 1_000_000 );
		$entries        = [];

		for ( $i = 0; $i < $n; $i++ ) {
			$entries[] = $this->build_entry(
				$now_micros - $i * 1000,
				$methods[ $i % count( $methods ) ],
				$status_codes[ $i % count( $status_codes ) ],
				$route_prefixes[ $i % count( $route_prefixes ) ]
			);

			if ( count( $entries ) >= self::BATCH_SIZE ) {
				$this->repo->insert_batch( $entries );
				$entries = [];
			}
		}

		if ( [] !== $entries ) {
			$this->repo->insert_batch( $entries );
		}
	}

	/**
	 * Build a single Entry from simple primitive values.
	 *
	 * @param int    $micros        created_at_micros.
	 * @param string $method        HTTP method.
	 * @param int    $status        HTTP status code.
	 * @param string $route_prefix  Route prefix for filtering.
	 * @return Entry
	 */
	private function build_entry( int $micros, string $method, int $status, string $route_prefix ): Entry {
		$req               = new RequestSnapshot();
		$req->route        = $route_prefix . 'posts';
		$req->route_prefix = $route_prefix;
		$req->method       = $method;
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = $status;
		$res->status_class = (int) \floor( $status / 100 );
		$res->content_type = 'application/json';

		$entry                    = Entry::from_snapshots( $req, $res, [] );
		$entry->created_at_micros = $micros;
		$packed                   = \inet_pton( '::ffff:127.0.0.1' );
		$entry->ip_raw_remote     = false !== $packed ? $packed : null;

		return $entry;
	}

	/**
	 * Build 100 diverse QueryArgs covering the cost dimensions from RESEARCH §12.
	 *
	 * Mix: method+status_class, route_prefix, free_text, date ranges, cursor walks,
	 * sorted ASC, sorted DESC.
	 *
	 * @return QueryArgs[]
	 */
	private function build_filter_combinations(): array {
		$combinations   = [];
		$methods        = [ 'GET', 'POST', 'PUT', 'DELETE' ];
		$status_classes = [ '2xx', '3xx', '4xx', '5xx' ];
		$prefixes       = [ '/wp/v2/', '/wc/v3/', '/jwt-auth/v1/', '/custom/v1/' ];
		$now_micros     = (int) ( microtime( true ) * 1_000_000 );

		// 20 method+status_class combos.
		for ( $i = 0; $i < 20; $i++ ) {
			$combinations[] = QueryArgs::from_array(
				[
					'method'       => $methods[ $i % count( $methods ) ],
					'status_class' => $status_classes[ $i % count( $status_classes ) ],
					'limit'        => 20,
				]
			);
		}

		// 20 route_prefix queries (leverages the index).
		for ( $i = 0; $i < 20; $i++ ) {
			$combinations[] = QueryArgs::from_array(
				[
					'route_prefix' => $prefixes[ $i % count( $prefixes ) ],
					'limit'        => 20,
				]
			);
		}

		// 10 free_text queries (LIKE — no prefix index; narrowed by other filters in practice).
		for ( $i = 0; $i < 10; $i++ ) {
			$combinations[] = QueryArgs::from_array(
				[
					'free_text' => 'posts',
					'method'    => $methods[ $i % count( $methods ) ],
					'limit'     => 20,
				]
			);
		}

		// 10 date-range queries.
		for ( $i = 0; $i < 10; $i++ ) {
			$from           = $now_micros - ( 10000 + $i * 5000 ) * 1000;
			$to             = $now_micros - $i * 5000 * 1000;
			$combinations[] = QueryArgs::from_array(
				[
					'date_from_micros' => $from,
					'date_to_micros'   => $to,
					'limit'            => 20,
				]
			);
		}

		// 10 DESC cursor walks (simulating page 2 navigation).
		$page1_result = $this->repo->search( QueryArgs::from_array( [ 'limit' => 20 ] ) );
		for ( $i = 0; $i < 10; $i++ ) {
			$args = [ 'limit' => 20 ];
			if ( null !== $page1_result['next_cursor'] ) {
				$args['cursor'] = $page1_result['next_cursor'];
			}
			$combinations[] = QueryArgs::from_array( $args );
		}

		// 10 ASC + cursor walks.
		$page1_asc = $this->repo->search(
			QueryArgs::from_array(
				[
					'limit'     => 20,
					'order_dir' => 'ASC',
				]
			)
		);
		for ( $i = 0; $i < 10; $i++ ) {
			$args = [
				'limit'     => 20,
				'order_dir' => 'ASC',
			];
			if ( null !== $page1_asc['next_cursor'] ) {
				$args['cursor'] = $page1_asc['next_cursor'];
			}
			$combinations[] = QueryArgs::from_array( $args );
		}

		// 20 unfiltered default-sort queries (baseline).
		for ( $i = 0; $i < 20; $i++ ) {
			$combinations[] = QueryArgs::from_array( [ 'limit' => 20 ] );
		}

		return $combinations;
	}
}
