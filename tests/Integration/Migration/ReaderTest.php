<?php
/**
 * Integration test scaffold for Migration\Reader — MIG-02.
 *
 * Failing scaffold (Wave 0): Migration\Reader does not exist until plan 04.
 * Tests guard on class_exists and fail with a clear message rather than fatal.
 *
 * Covers:
 *  - Reader resolves method/status via direct-SQL when taxonomies are deregistered
 *  - Raw meta values are wp_unslash()ed before being handed to the Mapper
 *    (the slash-stripped-JSON trap, RESEARCH Pitfall 1)
 *
 * @package BetterRestApiLogs\Tests\Integration\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Migration;

use WP_UnitTestCase;

/**
 * Verifies the Reader's direct-SQL term fallback and slash-unescaping (MIG-02).
 */
final class ReaderTest extends WP_UnitTestCase {

	use UpstreamFixture;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );
		$this->register_upstream_cpt_and_taxonomies();
	}

	/**
	 * After deregistering the source taxonomies, Reader must fall back to the
	 * direct-SQL {term_relationships}→{term_taxonomy}→{terms} join and still
	 * resolve method/status by term NAME (not slug) — MIG-02.
	 */
	public function test_reader_uses_direct_sql_when_taxonomy_deregistered(): void {
		$this->assert_reader_implemented();

		$this->seed_upstream_posts( 1, '/wp/v2/posts', 'POST', 201 );

		// Deregister the taxonomies so wp_get_object_terms returns an error.
		$this->deregister_taxonomies();

		$reader = new \BetterRestApiLogs\Migration\Reader();
		$batch  = $reader->read_batch( 0, 10 );

		$this->assertNotEmpty( $batch, 'Reader must return at least one SourceEntry' );

		/** @var \BetterRestApiLogs\Migration\SourceEntry $entry */
		$entry = $batch[0];
		$this->assertSame( 'POST', $entry->method, 'Method term name must be resolved via direct SQL' );
		$this->assertSame( 201, $entry->status, 'Status term name must be resolved via direct SQL' );
	}

	/**
	 * Raw postmeta returned by a $wpdb SELECT is still slash-escaped.
	 * Reader must wp_unslash() meta values so bodies do not show \" everywhere.
	 */
	public function test_reader_unslashes_raw_meta_values(): void {
		$this->assert_reader_implemented();

		// Seed a post whose request body contains embedded quotes.
		// wp_insert_post + add_post_meta will slash-store it; raw $wpdb returns slashed.
		$this->seed_upstream_posts( 1 );

		$reader = new \BetterRestApiLogs\Migration\Reader();
		$batch  = $reader->read_batch( 0, 10 );

		$this->assertNotEmpty( $batch );

		/** @var \BetterRestApiLogs\Migration\SourceEntry $entry */
		$entry = $batch[0];

		// After unslashing, embedded quotes must be clean " not \".
		$this->assertStringNotContainsString(
			'\\"',
			$entry->request_body,
			'Reader must wp_unslash() raw meta; \" indicates the slash trap was not applied'
		);
	}


	private function assert_reader_implemented(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Migration\\Reader' ) ) {
			$this->fail( 'Migration\\Reader not implemented yet (plan 04 turns this green).' );
		}
	}
}
