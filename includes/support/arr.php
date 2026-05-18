<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Dot-path read/write helpers for nested associative arrays.
 *
 * Used by Settings\Registry as the canonical resolver for dot-path setting
 * keys (e.g. `capture.body_spill_enabled`). Pure PHP — no WordPress calls.
 *
 * Locked contract per CONTEXT.md D-26:
 *  - get/has/set walk segments split by `.`.
 *  - set() is immutable: returns a new array, never mutates the input.
 */
final class Arr {

	/**
	 * Resolve a dot-path against an associative array.
	 *
	 * @param  array<string,mixed> $arr      Source array.
	 * @param  string              $path     Dot-separated key path.
	 * @param  mixed               $fallback Returned when any segment is missing or hits a non-array.
	 * @return mixed
	 */
	public static function get( array $arr, string $path, $fallback = null ) {
		$keys = \explode( '.', $path );
		$cur  = $arr;
		foreach ( $keys as $key ) {
			if ( ! \is_array( $cur ) || ! \array_key_exists( $key, $cur ) ) {
				return $fallback;
			}
			$cur = $cur[ $key ];
		}
		return $cur;
	}

	/**
	 * Whether the dot-path resolves to an existing key.
	 *
	 * @param array<string,mixed> $arr  Source array.
	 * @param string              $path Dot-separated key path.
	 */
	public static function has( array $arr, string $path ): bool {
		$keys = \explode( '.', $path );
		$cur  = $arr;
		foreach ( $keys as $key ) {
			if ( ! \is_array( $cur ) || ! \array_key_exists( $key, $cur ) ) {
				return false;
			}
			$cur = $cur[ $key ];
		}
		return true;
	}

	/**
	 * Return a new array with `$value` assigned at the dot-path. The input is never mutated.
	 *
	 * Intermediate segments that are absent or non-array are replaced with empty arrays
	 * along the path on the copy.
	 *
	 * @param  array<string,mixed> $arr   Source array (untouched).
	 * @param  string              $path  Dot-separated key path.
	 * @param  mixed               $value Value to assign at the leaf.
	 * @return array<string,mixed>
	 */
	public static function set( array $arr, string $path, $value ): array {
		$keys = \explode( '.', $path );
		$copy = $arr;

		// Walk a reference into the copy, materialising arrays as needed.
		$cursor = &$copy;
		foreach ( $keys as $i => $key ) {
			$is_leaf = ( $i === \count( $keys ) - 1 );
			if ( $is_leaf ) {
				$cursor[ $key ] = $value;
				break;
			}
			if ( ! isset( $cursor[ $key ] ) || ! \is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = [];
			}
			$cursor = &$cursor[ $key ];
		}
		unset( $cursor );

		return $copy;
	}
}
