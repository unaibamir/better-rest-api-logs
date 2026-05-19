<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all routes under the better-rest-api-logs/v1 namespace.
 *
 * Each route declares an explicit permission_callback — never __return_true.
 * The admin_only() closure applies the brl_admin_required_capability filter so
 * site owners can swap manage_options for a custom role without forking a controller.
 *
 * Route param `id` uses preg_match rather than is_numeric to stay clean under
 * CLAUDE.md's integer-field rule (which targets QueryArgs filter fields; route
 * params validated via the WP REST args array are a separate boundary).
 */
final class Routes {

	private const NAMESPACE = 'better-rest-api-logs/v1';

	private ListController $list;
	private SingleController $single;
	private StatsController $stats;

	public function __construct( ListController $list, SingleController $single, StatsController $stats ) {
		$this->list   = $list;
		$this->single = $single;
		$this->stats  = $stats;
	}

	/**
	 * Register the four REST routes. Called from rest_api_init.
	 */
	public function register(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/logs',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this->list, 'handle' ],
				'permission_callback' => self::admin_only(),
				'args'                => self::list_args(),
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/logs/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this->single, 'handle_read' ],
					'permission_callback' => self::admin_only(),
					'args'                => self::id_arg(),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this->single, 'handle_delete' ],
					'permission_callback' => self::admin_only(),
					'args'                => self::id_arg(),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/stats',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this->stats, 'handle' ],
				'permission_callback' => self::admin_only(),
			]
		);
	}

	/**
	 * Returns the permission_callback closure used on every route.
	 *
	 * Returns false for anonymous (unauthenticated) requests. Uses the
	 * brl_admin_required_capability filter so site owners can adjust the
	 * required capability without touching plugin code.
	 */
	private static function admin_only(): callable {
		return static function ( \WP_REST_Request $request ): bool {
			return \current_user_can(
				\apply_filters( 'brl_admin_required_capability', 'manage_options', 'rest' )
			);
		};
	}

	/**
	 * Shared validate_callback for the {id} route parameter.
	 *
	 * preg_match ensures only positive integers pass — no scientific notation,
	 * no floats, no negative values. The (int) cast is a sanity belt.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function id_arg(): array {
		return [
			'id' => [
				'validate_callback' => static function ( $v ): bool {
					return 1 === \preg_match( '/^\d+$/', (string) $v ) && (int) $v > 0;
				},
			],
		];
	}

	/**
	 * Declared args for the GET /logs route.
	 *
	 * sanitize_callback strips HTML/control chars at the WP REST boundary before
	 * the values reach QueryArgs::from_array(), which applies its own per-field
	 * validation. Double-sanitisation is the WP REST API idiom.
	 *
	 * per_page maps to limit for WP REST API convention compatibility; callers
	 * may pass either — QueryArgs::from_array() reads the `limit` key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function list_args(): array {
		return [
			'method'           => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'           => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status_class'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'route_prefix'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'user_id'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'ip'               => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_from_micros' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_to_micros'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'free_text'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'cursor'           => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'limit'            => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'per_page'         => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'order_by'         => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'order_dir'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
