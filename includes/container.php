<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny manual DI container; resolves shared instances by FQN.
 */
final class Container {

	/** @var array<string,callable> */
	private array $factories = [];

	/** @var array<string,object> */
	private array $shared = [];

	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->shared[ $id ] );
	}

	public function get( string $id ): object {
		if ( isset( $this->shared[ $id ] ) ) {
			return $this->shared[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( \esc_html( "No factory registered for {$id}" ) );
		}
		$instance            = ( $this->factories[ $id ] )( $this );
		$this->shared[ $id ] = $instance;
		return $instance;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->shared[ $id ] );
	}
}
