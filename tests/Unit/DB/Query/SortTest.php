<?php
/**
 * Unit tests for BetterRestApiLogs\DB\Query\Sort.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-03
 * implements includes/db/query/sort.php. Covers the column whitelist
 * (only created_at and duration_ms are valid sort columns), case-insensitive
 * direction normalization, and rejection of unknown columns or directions.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\DB\Query;

use BetterRestApiLogs\DB\Query\Sort;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the Sort column/direction whitelist validator.
 */
final class SortTest extends TestCase {

	public function test_validate_returns_normalized_pair_for_created_at_asc(): void {
		$result = Sort::validate( 'created_at', 'asc' );

		$this->assertSame( 'created_at', $result['column'] );
		$this->assertSame( 'ASC', $result['dir'] );
	}

	public function test_validate_returns_normalized_pair_for_created_at_desc(): void {
		$result = Sort::validate( 'created_at', 'desc' );

		$this->assertSame( 'created_at', $result['column'] );
		$this->assertSame( 'DESC', $result['dir'] );
	}

	public function test_validate_normalizes_uppercase_asc_unchanged(): void {
		$result = Sort::validate( 'duration_ms', 'ASC' );

		$this->assertSame( 'ASC', $result['dir'] );
	}

	public function test_validate_normalizes_uppercase_desc_unchanged(): void {
		$result = Sort::validate( 'duration_ms', 'DESC' );

		$this->assertSame( 'DESC', $result['dir'] );
	}

	public function test_validate_accepts_duration_ms_column(): void {
		$result = Sort::validate( 'duration_ms', 'DESC' );

		$this->assertSame( 'duration_ms', $result['column'] );
	}

	public function test_validate_rejects_unknown_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		Sort::validate( 'id', 'DESC' );
	}

	public function test_validate_rejects_route_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		Sort::validate( 'route', 'ASC' );
	}

	public function test_validate_rejects_status_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		Sort::validate( 'status', 'ASC' );
	}

	public function test_validate_rejects_unknown_direction(): void {
		$this->expectException( \InvalidArgumentException::class );
		Sort::validate( 'created_at', 'RANDOM' );
	}

	public function test_validate_trims_whitespace_from_column_before_validating(): void {
		$result = Sort::validate( ' created_at ', 'DESC' );
		$this->assertSame( 'created_at', $result['column'] );
	}

	public function test_validate_trims_whitespace_from_direction_before_validating(): void {
		$result = Sort::validate( 'created_at', ' asc ' );
		$this->assertSame( 'ASC', $result['dir'] );
	}

	public function test_validate_result_has_exactly_column_and_dir_keys(): void {
		$result = Sort::validate( 'created_at', 'DESC' );

		$this->assertArrayHasKey( 'column', $result );
		$this->assertArrayHasKey( 'dir', $result );
	}
}
