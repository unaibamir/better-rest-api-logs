<?php
/**
 * Performance gate for LogRepository::search().
 *
 * Seeds 100k rows and asserts p95 latency under 100ms across 100
 * representative filter combinations. Runs only when explicitly invoked with
 * --group perf; excluded from the default test suite by phpunit.xml.dist.
 *
 * 5M-row scenario is a one-off via bin/seed-rows.php (dev-only, gitignored);
 * this file covers the 100k hard gate that runs on every CI cell.
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
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 1 (Plan 04-05) — LogRepository::search() does not exist yet.

final class LogRepositorySearchPerfTest extends WP_UnitTestCase {

	private const SEED_ROWS     = 100000;
	private const SEED_BATCH    = 5000;
	private const ITERATIONS    = 100;
	private const P95_LIMIT_MS  = 100;

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->seed_rows( self::SEED_ROWS );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	/**
	 * Seed rows in batches to avoid memory pressure.
	 *
	 * @param int $total_rows Total rows to insert.
	 */
	private function seed_rows( int $total_rows ): void {
		$methods  = [ 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ];
		$statuses = [ 200, 201, 301, 400, 404, 500 ];
		$routes   = [
			'/wp/v2/posts',
			'/wp/v2/users',
			'/wp/v2/comments',
			'/wp/v2/media',
			'/wp/v2/pages',
		];

		$repo      = new LogRepository();
		$remaining = $total_rows;

		while ( $remaining > 0 ) {
			$batch_size = min( self::SEED_BATCH, $remaining );
			$entries    = [];

			for ( $i = 0; $i < $batch_size; $i++ ) {
				$method = $methods[ $i % count( $methods ) ];
				$status = $statuses[ $i % count( $statuses ) ];
				$route  = $routes[ $i % count( $routes ) ];

				$req               = new RequestSnapshot();
				$req->route        = $route;
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

			$repo->insert_batch( $entries );
			$remaining -= $batch_size;
		}
	}

	/**
	 * Compute p95 from a sorted array of millisecond samples.
	 *
	 * @param float[] $samples
	 */
	private function p95( array &$samples ): float {
		sort( $samples );
		$idx = (int) ( 0.95 * count( $samples ) );
		return $samples[ $idx ];
	}

	/**
	 * REL-03 list-page half: search() p95 under 100ms across 100 filter combinations.
	 */
	public function test_search_p95_under_100ms_across_filter_combinations(): void {
		$repo = new LogRepository();

		$filter_sets = [
			[],
			[ 'method' => 'GET' ],
			[ 'method' => 'POST' ],
			[ 'status_class' => '2xx' ],
			[ 'status_class' => '4xx' ],
			[ 'route_prefix' => '/wp/v2/' ],
			[ 'method' => 'GET', 'status_class' => '2xx' ],
			[ 'method' => 'POST', 'status_class' => '2xx' ],
			[ 'route_prefix' => '/wp/v2/posts' ],
			[ 'status_class' => '5xx' ],
		];

		$samples = [];

		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$filter = $filter_sets[ $i % count( $filter_sets ) ];
			$args   = QueryArgs::from_array( $filter );

			$t0        = \hrtime( true );
			$repo->search( $args );
			$elapsed_ms = ( \hrtime( true ) - $t0 ) / 1_000_000;

			$samples[] = $elapsed_ms;
		}

		$p95_ms = $this->p95( $samples );

		$this->assertLessThan(
			self::P95_LIMIT_MS,
			$p95_ms,
			sprintf(
				'LogRepository::search() p95 = %.2fms (limit %dms). Indexes may be missing or queries need optimization.',
				$p95_ms,
				self::P95_LIMIT_MS
			)
		);
	}
}
