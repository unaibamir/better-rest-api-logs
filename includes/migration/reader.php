<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Reads upstream wp-rest-api-log CPT rows directly from core tables.
 *
 * WP-coupled — uses $wpdb on core posts/postmeta/term_* props. The upstream
 * CPT is typically deregistered at migration time, so WP_Query / get_posts
 * return nothing; we must hit the tables directly (D-01).
 *
 * SLASH TRAP: raw $wpdb SELECTs return postmeta and post_content still
 * slash-escaped because get_post_meta()'s unslash is bypassed. Every raw
 * value is wp_unslash()'d before the SourceEntry is built — missing this
 * makes imported bodies show \" instead of " everywhere (RESEARCH Pitfall 1).
 *
 * Term resolution (D-02): tries wp_get_object_terms() first; falls back to
 * a direct {term_relationships}→{term_taxonomy}→{terms} join when the
 * taxonomy is deregistered (the common case). Reads term NAME, not slug —
 * upstream stores method/status as the term name ("GET", "200").
 */
final class Reader {

	/**
	 * Read a bounded batch of upstream posts with ID greater than $cursor.
	 *
	 * @param  int $cursor Last processed upstream post ID (0 = start from beginning).
	 * @param  int $limit  Maximum number of posts to return.
	 * @return SourceEntry[] Raw upstream entries, ordered by ID ascending.
	 */
	public function read_batch( int $cursor, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- source CPT is deregistered; no caching applies to a one-shot import. Tables are core $wpdb props, not brl_ tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a core WP property; no user input in table identifier.
				"SELECT ID, post_title, post_content, post_date
				   FROM {$wpdb->posts}
				  WHERE post_type = %s
				    AND ID > %d
				  ORDER BY ID ASC
				  LIMIT %d",
				'wp-rest-api-log',
				$cursor,
				$limit
			)
		);

		if ( ! \is_array( $rows ) || [] === $rows ) {
			return [];
		}

		$entries = [];
		foreach ( $rows as $row ) {
			$entries[] = $this->build_entry( (int) $row->ID, $row );
		}
		return $entries;
	}

	/**
	 * Count upstream posts with ID greater than $cursor (for dry-run / source_total).
	 *
	 * @param  int $cursor Last processed upstream post ID (0 = count all).
	 * @return int
	 */
	public function count_remaining( int $cursor ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- source CPT; one-shot import; no caching applies.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a core WP property; no user input.
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d",
				'wp-rest-api-log',
				$cursor
			)
		);

		return (int) $count;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Build a SourceEntry from one upstream post row plus its meta and terms.
	 *
	 * @param  int    $post_id  Upstream post ID.
	 * @param  object $row      Object from $wpdb->get_results (post_title, post_content, post_date).
	 * @return SourceEntry
	 */
	private function build_entry( int $post_id, object $row ): SourceEntry {
		$entry = new SourceEntry();

		$entry->source_id = $post_id;

		// SLASH TRAP: raw $wpdb results are still slashed; wp_unslash before use.
		$entry->route         = \wp_unslash( (string) $row->post_title );
		$entry->response_body = \wp_unslash( (string) $row->post_content );
		$entry->created_at    = (string) $row->post_date;

		$this->populate_meta( $entry, $post_id );
		$this->populate_terms( $entry, $post_id );

		return $entry;
	}

	/**
	 * Fetch all postmeta for $post_id and partition into SourceEntry fields.
	 *
	 * Literal meta keys map directly; pipe-keyed rows (e.g. "request_headers|Content-Type")
	 * are split on the first pipe and aggregated into the header arrays.
	 *
	 * @param SourceEntry $entry   Mutated in place.
	 * @param int         $post_id Upstream post ID.
	 */
	private function populate_meta( SourceEntry $entry, int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- source postmeta; one-shot import; no caching applies.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a core WP property; no user input in table identifier.
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$post_id
			)
		);

		if ( ! \is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $meta ) {
			$key = (string) $meta->meta_key;
			// SLASH TRAP: raw meta_value is still slashed — wp_unslash before use.
			$value = \wp_unslash( (string) $meta->meta_value );

			if ( \strpos( $key, '|' ) !== false ) {
				// Pipe-keyed row: "request_headers|Content-Type" or "response_headers|X-Foo".
				// Split on the first pipe only to handle header values that may contain pipes.
				$pipe_pos = \strpos( $key, '|' );
				$group    = \substr( $key, 0, $pipe_pos );
				$name     = \substr( $key, $pipe_pos + 1 );

				if ( 'request_headers' === $group ) {
					$entry->request_headers[ $name ] = $value;
				} elseif ( 'response_headers' === $group ) {
					$entry->response_headers[ $name ] = $value;
				}
				// Ignore other pipe-keyed groups (request_query_params, request_body_params).
				continue;
			}

			// Literal meta key mapping.
			switch ( $key ) {
				case '_ip-address':
					$entry->ip_address = $value;
					break;
				case '_request_user':
					$entry->user = $value;
					break;
				case '_milliseconds':
					$entry->duration_ms = (int) $value;
					break;
				case '_request_body':
					$entry->request_body = $value;
					break;
				// _http_x_forwarded_for and _wp_rest_api_log_migrated_id are intentionally ignored.
			}
		}
	}

	/**
	 * Resolve method/status/source terms for $post_id.
	 *
	 * Tries wp_get_object_terms() first (D-02: API path). On empty result or
	 * WP_Error (deregistered taxonomy is the common production case), falls back
	 * to a direct-SQL join reading term NAME — not slug, because upstream stores
	 * the value as the term name ("GET", "200", "rest-api").
	 *
	 * @param SourceEntry $entry   Mutated in place.
	 * @param int         $post_id Upstream post ID.
	 */
	private function populate_terms( SourceEntry $entry, int $post_id ): void {
		$taxonomies = [
			'wp-rest-api-log-method',
			'wp-rest-api-log-status',
			'wp-rest-api-log-source',
		];

		// API path: wp_get_object_terms works when the taxonomy is still registered.
		$api_terms = \wp_get_object_terms( $post_id, $taxonomies );

		if ( ! \is_wp_error( $api_terms ) && ! empty( $api_terms ) ) {
			foreach ( $api_terms as $term ) {
				$this->assign_term( $entry, $term->taxonomy, $term->name );
			}
			return;
		}

		// Fallback: direct-SQL join for the deregistered-taxonomy case (MIG-02).
		$this->populate_terms_via_sql( $entry, $post_id );
	}

	/**
	 * Direct-SQL term fallback — reads term NAME from the WP taxonomy tables.
	 *
	 * @param SourceEntry $entry   Mutated in place.
	 * @param int         $post_id Upstream post ID.
	 */
	private function populate_terms_via_sql( SourceEntry $entry, int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- taxonomy API unavailable (deregistered); direct join is the only path. Core $wpdb->term_* props.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->term_relationships/$wpdb->term_taxonomy/$wpdb->terms are core WP properties; no user input in identifiers.
				"SELECT t.name, tt.taxonomy
				   FROM {$wpdb->term_relationships} tr
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				   JOIN {$wpdb->terms} t           ON t.term_id = tt.term_id
				  WHERE tr.object_id = %d
				    AND tt.taxonomy IN ('wp-rest-api-log-method','wp-rest-api-log-status','wp-rest-api-log-source')",
				$post_id
			)
		);

		if ( ! \is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$this->assign_term( $entry, (string) $row->taxonomy, (string) $row->name );
		}
	}

	/**
	 * Write a resolved term name into the appropriate SourceEntry field.
	 *
	 * @param SourceEntry $entry    Mutated in place.
	 * @param string      $taxonomy Taxonomy slug.
	 * @param string      $name     Term name (e.g. 'GET', '200', 'rest-api').
	 */
	private function assign_term( SourceEntry $entry, string $taxonomy, string $name ): void {
		switch ( $taxonomy ) {
			case 'wp-rest-api-log-method':
				$entry->method = $name;
				break;
			case 'wp-rest-api-log-status':
				$entry->status = (int) $name;
				break;
			// wp-rest-api-log-source is informational; not stored in SourceEntry.
		}
	}
}
