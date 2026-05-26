<?php
/**
 * Unit test scaffold for Migration\Mapper — MIG-03.
 *
 * Failing scaffold (Wave 0): Migration\Mapper does not exist until plan 02
 * implements it.  Each test guards on class_exists and fails with an explicit
 * "not implemented" message so the suite reports a failing assertion rather
 * than a fatal error.  Plan 02 turns these tests green.
 *
 * Pure-PHP tier: no WordPress boot, no $wpdb, hand-built SourceEntry fixtures.
 *
 * @package BetterRestApiLogs\Tests\Unit\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Migration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifies that Mapper::map(SourceEntry) returns a correctly populated Entry
 * plus meta array without any WP or DB dependency (MIG-03).
 */
final class MapperTest extends TestCase {

	/**
	 * Building a SourceEntry by hand and calling Mapper::map() must yield an
	 * Entry whose scalar fields come from the expected source fields.
	 */
	public function test_map_produces_entry_from_source_entry(): void {
		$this->assert_mapper_implemented();

		// Hand-built source fixture (no WP boot needed).
		/** @var \BetterRestApiLogs\Migration\SourceEntry $src */
		$src                = new \BetterRestApiLogs\Migration\SourceEntry();
		$src->source_id     = 42;
		$src->route         = '/wp/v2/posts';
		$src->method        = 'GET';
		$src->status        = 200;
		$src->duration_ms   = 150;
		$src->created_at    = '2026-05-01 10:00:00';
		$src->ip_address    = '1.2.3.4';
		$src->user          = '7';
		$src->response_body = '{"id":1,"title":"Hello"}';
		$src->request_body  = '{"q":"test"}';

		$result = \BetterRestApiLogs\Migration\Mapper::map( $src );

		$this->assertArrayHasKey( 'entry', $result, 'Mapper::map() must return ["entry" => Entry, ...]' );
		$this->assertArrayHasKey( 'meta', $result, 'Mapper::map() must return [..., "meta" => array]' );

		/** @var \BetterRestApiLogs\Domain\Entry $entry */
		$entry = $result['entry'];
		$this->assertSame( '/wp/v2/posts', $entry->route );
		$this->assertSame( 'GET', $entry->method );
		$this->assertSame( 200, $entry->status );
		$this->assertSame( 150, $entry->duration_ms );
		$this->assertSame( 42, $entry->migration_source_id );
	}

	/**
	 * Derive status_class as floor(status / 100), matching Entry's
	 * own derivation rule (Pitfall 5 — status_class = 0 breaks filtering).
	 */
	public function test_map_derives_status_class_from_status(): void {
		$this->assert_mapper_implemented();

		$src         = new \BetterRestApiLogs\Migration\SourceEntry();
		$src->status = 404;

		$result = \BetterRestApiLogs\Migration\Mapper::map( $src );
		$entry  = $result['entry'];

		$this->assertSame( 4, $entry->status_class, 'status_class must equal intdiv(status, 100)' );
	}

	/**
	 * Imported rows carry created_at_micros = 0; upstream had only — upstream had only
	 * second-resolution timestamps (Pitfall 5).
	 */
	public function test_map_sets_created_at_micros_to_zero(): void {
		$this->assert_mapper_implemented();

		$src             = new \BetterRestApiLogs\Migration\SourceEntry();
		$src->created_at = '2026-05-01 10:00:00';

		$result = \BetterRestApiLogs\Migration\Mapper::map( $src );
		$entry  = $result['entry'];

		$this->assertSame( 0, $entry->created_at_micros, 'Imported rows carry 0 for created_at_micros' );
	}

	/**
	 * Route comes from post_title, response_body from post_content,
	 * request_body from the _request_body meta (already unslashed by Reader).
	 */
	public function test_map_copies_body_fields(): void {
		$this->assert_mapper_implemented();

		$src                = new \BetterRestApiLogs\Migration\SourceEntry();
		$src->response_body = '{"ok":true}';
		$src->request_body  = '{"q":"search"}';

		$result = \BetterRestApiLogs\Migration\Mapper::map( $src );
		$entry  = $result['entry'];

		$this->assertSame( '{"ok":true}', $entry->response_body );
		$this->assertSame( '{"q":"search"}', $entry->request_body );
	}

	/**
	 * The migration_source_id is the upstream post ID so the UNIQUE constraint
	 * prevents duplicate rows on re-run (MIG-04).
	 */
	public function test_map_sets_migration_source_id(): void {
		$this->assert_mapper_implemented();

		$src            = new \BetterRestApiLogs\Migration\SourceEntry();
		$src->source_id = 99;

		$result = \BetterRestApiLogs\Migration\Mapper::map( $src );
		$entry  = $result['entry'];

		$this->assertSame( 99, $entry->migration_source_id );
	}

	// -------------------------------------------------------------------------

	/**
	 * Fail with an explicit not-implemented message rather than a fatal.
	 * This makes Wave 0 show a clean RED bar instead of a suite error.
	 */
	private function assert_mapper_implemented(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Migration\\Mapper' ) ) {
			$this->fail( 'Migration\\Mapper not implemented yet (plan 02 turns this green).' );
		}
		if ( ! \class_exists( 'BetterRestApiLogs\\Migration\\SourceEntry' ) ) {
			$this->fail( 'Migration\\SourceEntry not implemented yet (plan 02 turns this green).' );
		}
	}
}
