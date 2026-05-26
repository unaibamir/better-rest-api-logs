<?php
/**
 * Test fixture helper for the upstream wp-rest-api-log CPT data shape.
 *
 * Registers the source CPT + three taxonomies, seeds posts/postmeta/terms
 * exactly as a live wp-rest-api-log install would store them, and provides
 * a deregister_taxonomies() method so ReaderTest can exercise the MIG-02
 * direct-SQL fallback path when the taxonomy is no longer registered.
 *
 * Data shape sourced from the verified upstream contract in 06-RESEARCH.md
 * §"Verified Upstream wp-rest-api-log Data Contract".  Clean-room: no upstream
 * code is reused; this documents the shape as a test fixture only.
 *
 * @package BetterRestApiLogs\Tests\Integration\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Migration;

/**
 * Trait that integration test classes can use to seed upstream CPT data.
 *
 * Usage:
 *   use UpstreamFixture;
 *   // in set_up():
 *   $this->register_upstream_cpt_and_taxonomies();
 *   $this->seed_upstream_posts( 3 );
 */
trait UpstreamFixture {

	/** @var int[] IDs of the wp-rest-api-log posts seeded by this fixture. */
	private array $upstream_post_ids = [];

	/**
	 * Register the upstream CPT and three taxonomies so wp_insert_post and
	 * wp_get_object_terms work correctly during the seed phase.
	 * Call this early in set_up(), before seeding.
	 */
	private function register_upstream_cpt_and_taxonomies(): void {
		if ( ! \post_type_exists( 'wp-rest-api-log' ) ) {
			\register_post_type(
				'wp-rest-api-log',
				[
					'public'   => false,
					'supports' => [ 'title', 'editor' ],
				]
			);
		}

		foreach ( [ 'wp-rest-api-log-method', 'wp-rest-api-log-status', 'wp-rest-api-log-source' ] as $tax ) {
			if ( ! \taxonomy_exists( $tax ) ) {
				\register_taxonomy( $tax, 'wp-rest-api-log' );
			}
		}
	}

	/**
	 * Deregister the three source taxonomies so ReaderTest can assert that the
	 * direct-SQL fallback (MIG-02) is used when wp_get_object_terms() returns
	 * nothing or a WP_Error.
	 */
	public function deregister_taxonomies(): void {
		global $wp_taxonomies;
		foreach ( [ 'wp-rest-api-log-method', 'wp-rest-api-log-status', 'wp-rest-api-log-source' ] as $tax ) {
			unset( $wp_taxonomies[ $tax ] );
		}
	}

	/**
	 * Seed $count upstream log posts with all required postmeta keys.
	 *
	 * Each post mirrors the exact shape wp-rest-api-log stores:
	 *  - post_title  = the REST route
	 *  - post_content = pretty-printed JSON response body (with WP slashing applied
	 *    by wp_insert_post, matching what a raw $wpdb SELECT would return)
	 *  - Literal postmeta keys per the verified upstream contract
	 *  - Pipe-keyed header rows for request_headers and response_headers
	 *  - Three taxonomy terms (method, status, source) assigned by name
	 *
	 * @param  int    $count     Number of posts to insert (default 3).
	 * @param  string $route     Base route for post_title (index appended).
	 * @param  string $method    HTTP method term name (e.g. 'GET').
	 * @param  int    $status    HTTP status term name numeric value (e.g. 200).
	 * @return int[]             IDs of the inserted posts.
	 */
	public function seed_upstream_posts(
		int $count = 3,
		string $route = '/wp/v2/posts',
		string $method = 'GET',
		int $status = 200
	): array {
		$ids = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$post_route = $route . '/' . $i;

			// Response body mirrors upstream's wp_json_encode(..., JSON_PRETTY_PRINT)
			// with str_replace('\n', PHP_EOL, ...) — actual newlines in the string.
			$response_body_raw = \wp_json_encode(
				[
					'id'     => $i,
					'status' => 'publish',
					// Embed quotes to exercise the slash trap on raw $wpdb reads.
					'title'  => "Test \"post\" {$i}",
				],
				\JSON_PRETTY_PRINT
			);

			$post_id = \wp_insert_post(
				[
					'post_type'    => 'wp-rest-api-log',
					'post_status'  => 'publish',
					'post_title'   => $post_route,
					// wp_insert_post slashes content — raw $wpdb SELECT returns it slashed.
					'post_content' => $response_body_raw,
					'post_date'    => \gmdate( 'Y-m-d H:i:s', \strtotime( "2026-05-{$i} 10:00:00" ) ),
				],
				true
			);

			if ( \is_wp_error( $post_id ) || 0 === $post_id ) {
				continue;
			}

			// Literal postmeta keys per the verified upstream contract.
			\add_post_meta( $post_id, '_ip-address', '127.0.0.1' );
			\add_post_meta( $post_id, '_request_user', (string) $i );
			\add_post_meta( $post_id, '_http_x_forwarded_for', '' );
			\add_post_meta( $post_id, '_milliseconds', (string) ( $i * 100 ) );

			// Request body with embedded quotes — exercises the slash trap.
			$request_body = \wp_json_encode( [ 'key' => "val\"ue_{$i}" ] );
			\add_post_meta( $post_id, '_request_body', (string) $request_body );

			// Pipe-keyed request headers (one row per header).
			\add_post_meta( $post_id, 'request_headers|Content-Type', 'application/json' );
			\add_post_meta( $post_id, 'request_headers|Accept', 'application/json' );

			// Pipe-keyed response headers.
			\add_post_meta( $post_id, 'response_headers|X-Foo', "bar_{$i}" );
			\add_post_meta( $post_id, 'response_headers|Content-Type', 'application/json' );

			// Assign taxonomy terms by NAME (upstream stores method/status as term name).
			\wp_set_object_terms( $post_id, $method, 'wp-rest-api-log-method' );
			\wp_set_object_terms( $post_id, (string) $status, 'wp-rest-api-log-status' );
			\wp_set_object_terms( $post_id, 'rest-api', 'wp-rest-api-log-source' );

			$ids[]                     = $post_id;
			$this->upstream_post_ids[] = $post_id;
		}

		return $ids;
	}

	/**
	 * Clean up seeded posts and their meta.  Call in tear_down() if your test
	 * class does not extend WP_UnitTestCase (which auto-rolls back).
	 */
	private function cleanup_upstream_posts(): void {
		foreach ( $this->upstream_post_ids as $id ) {
			\wp_delete_post( $id, true );
		}
		$this->upstream_post_ids = [];
	}
}
