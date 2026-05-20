<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

// D-43: only created_at and duration_ms are sortable in v1 — everything else rejected.

/**
 * Whitelist gate for SQL ORDER BY column + direction supplied by external input.
 */
final class Sort {

	private const ALLOWED_COLUMNS = [
		'created_at',
		'created_at_micros',
		'duration_ms',
		'route',
		'method',
		'status',
	];
	private const ALLOWED_DIRS    = [ 'ASC', 'DESC' ];

	/**
	 * Validates and normalises a sort column + direction pair.
	 *
	 * Both inputs are trimmed of leading/trailing whitespace. Direction is then
	 * upper-cased; column comparison stays case-sensitive after the trim because
	 * the Phase 2 schema uses lowercase column names only — accepting
	 * 'CREATED_AT' would silently mask a caller bug.
	 *
	 * @param string $column Sort column name.
	 * @param string $dir    Sort direction ('ASC' or 'DESC', case-insensitive).
	 * @return array{column: string, dir: string}
	 * @throws \InvalidArgumentException When column or direction is not whitelisted.
	 */
	public static function validate( string $column, string $dir ): array {
		$column_normalised = \trim( $column );
		$dir_normalised    = \strtoupper( \trim( $dir ) );

		if ( ! \in_array( $column_normalised, self::ALLOWED_COLUMNS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown sort column.' );
		}
		if ( ! \in_array( $dir_normalised, self::ALLOWED_DIRS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown sort direction.' );
		}

		return [
			'column' => $column_normalised,
			'dir'    => $dir_normalised,
		];
	}
}
