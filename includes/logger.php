<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Logger\Breaker;
use BetterRestApiLogs\Logger\Flusher;

/**
 * Façade collecting the Flusher and Breaker logger concerns. Bound as a
 * shared singleton in the container so callers reach the underlying services
 * without importing the full namespace tree. Queue's static API is its own
 * public interface and needs no wrapper here.
 */
final class Logger {

	private Flusher $flusher;
	private Breaker $breaker;

	/**
	 * @param Flusher $flusher Shutdown drain pipeline.
	 * @param Breaker $breaker Circuit breaker for INSERT path.
	 */
	public function __construct( Flusher $flusher, Breaker $breaker ) {
		$this->flusher = $flusher;
		$this->breaker = $breaker;
	}

	/**
	 * Return the shutdown drain handler.
	 *
	 * @return Flusher
	 */
	public function flusher(): Flusher {
		return $this->flusher;
	}

	/**
	 * Return the circuit breaker instance.
	 *
	 * @return Breaker
	 */
	public function breaker(): Breaker {
		return $this->breaker;
	}
}
