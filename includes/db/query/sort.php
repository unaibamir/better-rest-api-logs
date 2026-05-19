<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

// D-43: only created_at and duration_ms are sortable in v1 — everything else rejected.

/**
 * Whitelist gate for SQL ORDER BY column + direction supplied by external input.
 */
final class Sort {

	private const ALLOWED_COLUMNS = [ 'created_at', 'duration_ms' ];
	private const ALLOWED_DIRS    = [ 'ASC', 'DESC' ];

	/**
	 * Validates and normalises a sort column + direction pair.
	 *
	 * Direction is normalised to upper-case after trimming whitespace; column
	 * comparison is case-sensitive (schema columns are lowercase).
	 *
	 * @param string $column Sort column name.
	 * @param string $dir    Sort direction ('ASC' or 'DESC', case-insensitive).
	 * @return array{column: string, dir: string}
	 * @throws \InvalidArgumentException When column or direction is not whitelisted.
	 */
	public static function validate( string $column, string $dir ): array {
		$dir_normalised = \strtoupper( \trim( $dir ) );

		if ( ! \in_array( $column, self::ALLOWED_COLUMNS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown sort column.' );
		}
		if ( ! \in_array( $dir_normalised, self::ALLOWED_DIRS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown sort direction.' );
		}

		return [
			'column' => $column,
			'dir'    => $dir_normalised,
		];
	}
}
