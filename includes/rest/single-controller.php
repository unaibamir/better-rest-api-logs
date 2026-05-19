<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Rest;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;

/**
 * Handles GET and DELETE /better-rest-api-logs/v1/logs/{id}.
 *
 * Read: fetches the full entry with spilled-body resolution; 404 on missing ID.
 * Delete: cascades to the body-spill row (D-22 order), fires brl_log_deleted,
 * returns 204 on success; 404 when the row never existed.
 */
final class SingleController {

	private LogRepository $repo;

	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * @param  \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_read( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$entry = $this->repo->find( $id );

		if ( null === $entry ) {
			return new \WP_Error(
				'brl_log_not_found',
				// Generic copy — do not echo the offending input back (T-04-24).
				\sprintf(
					/* translators: %d: log entry id. */
					\esc_html__( 'Log entry %d does not exist.', 'better-rest-api-logs' ),
					$id
				),
				[ 'status' => 404 ]
			);
		}

		$payload = $entry->to_array();

		/** @var array<string,mixed> $payload */
		$payload = \apply_filters( 'brl_rest_response', $payload, '/logs/{id}', $request );

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * @param  \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		$ok = $this->repo->delete( $id );

		if ( ! $ok ) {
			return new \WP_Error(
				'brl_log_not_found',
				\sprintf(
					/* translators: %d: log entry id. */
					\esc_html__( 'Log entry %d does not exist.', 'better-rest-api-logs' ),
					$id
				),
				[ 'status' => 404 ]
			);
		}

		\do_action( 'brl_log_deleted', $id, 'rest' );

		return new \WP_REST_Response( null, 204 );
	}
}
