<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Rest\Routes;

/**
 * REST API boot entry — exposes register_routes() for the rest_api_init hook.
 *
 * Mirrors the Logger façade shape (Group A pattern): constructor-injected
 * collaborators, single public method that Plugin::boot() wires to a WP hook.
 * No hook registration happens inside the constructor.
 */
final class Rest {

	private Routes $routes;

	public function __construct( Routes $routes ) {
		$this->routes = $routes;
	}

	/**
	 * Register all better-rest-api-logs/v1 routes via the injected Routes registrar.
	 *
	 * Called from rest_api_init priority 10 by Plugin::boot().
	 */
	public function register_routes(): void {
		$this->routes->register();
	}
}
