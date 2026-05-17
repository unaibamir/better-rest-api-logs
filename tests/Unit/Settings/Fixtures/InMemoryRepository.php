<?php
/**
 * In-memory test double for BetterRestApiLogs\Settings\Repository.
 *
 * Per CONTEXT.md D-30 + CLAUDE.md "no interfaces until ≥2 concrete
 * implementations": this subclass duck-types the production signatures so
 * Settings\Registry can be unit-tested without bootstrapping WordPress.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Settings\Fixtures;

use BetterRestApiLogs\Settings\Repository;

final class InMemoryRepository extends Repository {

	/** @var array<string,mixed> */
	private $store = array();

	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Default to return if the option is absent.
	 * @return mixed
	 */
	public function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
	}

	/**
	 * @param string $name     Option name.
	 * @param mixed  $value    Value to persist.
	 * @param string $autoload Ignored in the test double.
	 */
	public function update_option( string $name, $value, string $autoload = '' ): bool {
		$this->store[ $name ] = $value;
		return true;
	}

	/**
	 * @param string $name     Option name.
	 * @param mixed  $value    Initial value.
	 * @param string $autoload Ignored in the test double.
	 */
	public function add_option( string $name, $value, string $autoload = 'yes' ): bool {
		if ( array_key_exists( $name, $this->store ) ) {
			return false;
		}
		$this->store[ $name ] = $value;
		return true;
	}
}
