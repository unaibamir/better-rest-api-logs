<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Rest;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;

/**
 * Handles GET /better-rest-api-logs/v1/logs.
 *
 * Builds QueryArgs from the request's query params, runs the search, and
 * returns the D-25 response shape: items / next_cursor / has_more.
 *
 * Accepts per_page as an alias for limit so both the WP REST API convention
 * and the plugin's own cursor-pagination convention work interchangeably.
 */
final class ListController {

	private LogRepository $repo;

	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$params = $request->get_query_params();

		// Allow per_page as an alias for limit (WP REST API convention).
		if ( isset( $params['per_page'] ) && ! isset( $params['limit'] ) ) {
			$params['limit'] = $params['per_page'];
		}

		try {
			$args = QueryArgs::from_array( $params );
		} catch ( \InvalidArgumentException $e ) {
			// Cursor decode errors bubble up from Paginator::decode inside from_array.
			return new \WP_Error(
				'brl_invalid_cursor',
				\esc_html__( 'Malformed pagination cursor.', 'better-rest-api-logs' ),
				[ 'status' => 400 ]
			);
		}

		/** @var QueryArgs $args */
		$args = \apply_filters( 'brl_query_args', $args, 'rest' );

		try {
			$result = $this->repo->search( $args );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error(
				'brl_invalid_cursor',
				\esc_html__( 'Malformed pagination cursor.', 'better-rest-api-logs' ),
				[ 'status' => 400 ]
			);
		}

		$items = \array_map( static fn ( $entry ) => $entry->to_array(), $result['rows'] );

		$payload = [
			'items'       => $items,
			'next_cursor' => $result['next_cursor'],
			'has_more'    => $result['has_more'],
		];

		/** @var array<string,mixed> $payload */
		$payload = \apply_filters( 'brl_rest_response', $payload, '/logs', $request );

		return new \WP_REST_Response( $payload, 200 );
	}
}
