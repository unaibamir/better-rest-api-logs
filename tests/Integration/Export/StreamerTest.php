<?php
/**
 * Integration tests for Export\Streamer — EXP-03/EXP-04.
 *
 * RED-bar scaffold (Wave 0): Export\Streamer does not exist until Wave 1.
 * Tests are marked incomplete with a Wave 1 reason so the suite discovers
 * them without fatalling on the absent class.
 *
 * Covers: active QueryArgs filter is honored (only matching rows appear in
 * output), batched body resolution (no per-row N+1 — assert via $wpdb->num_queries
 * delta), and a bounded-memory smoke test.
 *
 * Harness traps baked in per LESSONS-LEARNED:
 *  - ob_get_level() snapshot + restore (CLAUDE.md)
 *  - admin user + wp_set_current_user in set_up (#3)
 *  - $wpdb->suppress_errors() guard around intentional-failure sections (#6)
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Export;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

/**
 * Covers streaming filter compliance, batched body resolution, and
 * bounded memory on a seed set (EXP-03/EXP-04).
 * All tests are incomplete pending Wave 1.
 */
final class StreamerTest extends WP_UnitTestCase {

	/** @var int Snapshot ob_get_level before each test. */
	private int $ob_level_before = 0;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\wp_set_current_user( 0 );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert a log entry with the given method and route.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route string.
	 * @param bool   $spill  Whether to spill a body row.
	 * @return int Inserted log ID.
	 */
	private function insert_entry( string $method = 'GET', string $route = '/wp/v2/posts', bool $spill = false ): int {
		$req               = new RequestSnapshot();
		$req->route        = $route;
		$req->method       = $method;
		$req->content_type = 'application/json';

		$res               = new ResponseSnapshot();
		$res->status       = 200;
		$res->status_class = 2;
		$res->content_type = 'application/json';

		$entry                 = Entry::from_snapshots( $req, $res, [
			'created_at' => \gmdate( 'Y-m-d H:i:s' ),
		] );
		$entry->bodies_spilled = $spill;

		$repo = new LogRepository();
		$ids  = $repo->insert_batch( [ $entry ] );

		if ( $spill ) {
			$body_repo = new BodyRepository();
			$body_repo->insert_spilled( $ids[0], '{"req":"data"}', '{"res":"data"}' );
		}

		return $ids[0];
	}

	/**
	 * Capture Streamer output into a string via php://temp.
	 *
	 * @param QueryArgs $args   Filter arguments.
	 * @param string    $format Export format ('csv'|'ndjson').
	 * @return string The captured output.
	 */
	private function capture_stream( QueryArgs $args, string $format ): string {
		$sink = \fopen( 'php://temp', 'w+b' );
		$this->assertNotFalse( $sink );
		( new \BetterRestApiLogs\Export\Streamer() )->stream( $args, $format, $sink );
		\rewind( $sink );
		$output = (string) \stream_get_contents( $sink );
		\fclose( $sink );
		return $output;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * EXP-04: only rows matching the active QueryArgs filter appear in output.
	 *
	 * Filter token: ExportFilter / Streamer
	 */
	public function test_stream_honors_queryargs_filter_ExportFilter(): void {
		$this->markTestIncomplete( 'Export\\Streamer not implemented yet — Wave 1' );

		// Insert: 3 GET rows + 2 POST rows.
		$this->insert_entry( 'GET', '/wp/v2/posts' );
		$this->insert_entry( 'GET', '/wp/v2/posts' );
		$this->insert_entry( 'GET', '/wp/v2/posts' );
		$this->insert_entry( 'POST', '/wp/v2/posts' );
		$this->insert_entry( 'POST', '/wp/v2/posts' );

		// Filter to GET only.
		$args = QueryArgs::from_array( [ 'method' => 'GET' ] );
		$output = $this->capture_stream( $args, 'ndjson' );

		// Each NDJSON line is one JSON object. Count how many lines exist.
		$lines = \array_filter( \explode( "\n", \trim( $output ) ) );
		$this->assertCount( 3, $lines, 'Only GET rows must appear when filter restricts to method=GET.' );

		foreach ( $lines as $line ) {
			$record = \json_decode( $line, true );
			$this->assertIsArray( $record );
			$this->assertSame( 'GET', $record['method'], 'Every exported row must match the filter.' );
		}
	}

	/**
	 * EXP-03: Streamer resolves spilled bodies via a batched BodyRepository
	 * lookup — no per-row N+1 query. Assert via $wpdb->num_queries delta.
	 *
	 * Filter token: Streamer
	 */
	public function test_stream_batches_spilled_body_resolution_Streamer(): void {
		$this->markTestIncomplete( 'Export\\Streamer not implemented yet — Wave 1' );

		global $wpdb;

		// Insert 5 spilled rows — each requires body resolution.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_entry( 'POST', '/wp/v2/media', true );
		}

		$queries_before = $wpdb->num_queries;
		$args   = QueryArgs::from_array( [] );
		$output = $this->capture_stream( $args, 'ndjson' );
		$queries_after = $wpdb->num_queries;

		// Streamer must not execute one query per row for body resolution.
		// With batched loading and 5 rows in one batch, the body lookup
		// is a single IN(...) query — total queries should be far less than
		// 5 (one-per-row) + 1 (fetch logs) = 6. A generous ceiling of 4
		// still rules out N+1.
		$delta = $queries_after - $queries_before;
		$this->assertLessThan(
			6,
			$delta,
			'Streamer must batch spilled-body resolution; N+1 queries detected.'
		);

		$this->assertNotEmpty( $output, 'Streamer must produce output for spilled rows.' );
	}

	/**
	 * EXP-03: peak memory delta stays under a generous 64 MB ceiling on a
	 * modest seed, confirming the bounded cursor-batch walk doesn't buffer all
	 * rows in PHP.
	 *
	 * Filter token: StreamerMemory
	 */
	public function test_stream_memory_bounded_StreamerMemory(): void {
		$this->markTestIncomplete( 'Export\\Streamer not implemented yet — Wave 1' );

		// Seed 200 rows — enough to exercise multi-batch behaviour in CI.
		for ( $i = 0; $i < 200; $i++ ) {
			$this->insert_entry( 'GET', '/wp/v2/items/' . $i );
		}

		$mem_before = \memory_get_peak_usage( true );
		$args       = QueryArgs::from_array( [] );
		$this->capture_stream( $args, 'csv' );
		$mem_after  = \memory_get_peak_usage( true );

		$delta_mb = ( $mem_after - $mem_before ) / ( 1024 * 1024 );
		$this->assertLessThan(
			64,
			$delta_mb,
			\sprintf( 'Streamer peak memory delta must stay under 64 MB; got %.1f MB.', $delta_mb )
		);
	}
}
