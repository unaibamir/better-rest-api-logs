<?php
/**
 * Unit tests for BetterRestApiLogs\DB\Query\QueryArgs.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-04
 * implements includes/db/query/query-args.php. Covers from_array() validation,
 * integer-only field rejection patterns, sort column whitelist enforcement, and
 * the server-side limit cap.
 *
 * The integer-only field validation must use preg_match('/^-?\d+$/', ...) or
 * ctype_digit — NOT is_numeric, which accepts '3.14', '1e2', and whitespace-
 * padded strings.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\DB\Query;

use BetterRestApiLogs\DB\Query\QueryArgs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers QueryArgs construction, defaults, and validation paths.
 */
final class QueryArgsTest extends TestCase {

	public function test_from_array_empty_input_returns_defaults(): void {
		$args = QueryArgs::from_array( [] );

		$this->assertNull( $args->method );
		$this->assertNull( $args->status );
		$this->assertNull( $args->status_class );
		$this->assertNull( $args->route_prefix );
		$this->assertNull( $args->user_id );
		$this->assertNull( $args->ip );
		$this->assertNull( $args->date_from_micros );
		$this->assertNull( $args->date_to_micros );
		$this->assertNull( $args->free_text );
		$this->assertNull( $args->cursor );
		$this->assertSame( 20, $args->limit );
		$this->assertSame( 'created_at', $args->order_by );
		$this->assertSame( 'DESC', $args->order_dir );
	}

	public function test_from_array_rejects_string_with_decimal_point_for_status(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'status' => '3.14' ] );
	}

	public function test_from_array_rejects_scientific_notation_for_status(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'status' => '1e2' ] );
	}

	public function test_from_array_rejects_whitespace_padded_string_for_status(): void {
		// is_numeric accepts ' 5 ' — the validator must not.
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'status' => ' 5 ' ] );
	}

	public function test_from_array_rejects_decimal_string_for_user_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'user_id' => '3.14' ] );
	}

	public function test_from_array_rejects_scientific_notation_for_user_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'user_id' => '1e2' ] );
	}

	public function test_from_array_rejects_whitespace_padded_string_for_user_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'user_id' => ' 5 ' ] );
	}

	public function test_from_array_rejects_out_of_range_status_below_100(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'status' => 99 ] );
	}

	public function test_from_array_rejects_out_of_range_status_above_599(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'status' => 600 ] );
	}

	public function test_from_array_accepts_valid_status_code(): void {
		$args = QueryArgs::from_array( [ 'status' => 200 ] );
		$this->assertSame( 200, $args->status );
	}

	public function test_from_array_rejects_unknown_order_by_column(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'order_by' => 'id' ] );
	}

	public function test_from_array_accepts_created_at_order_by(): void {
		$args = QueryArgs::from_array( [ 'order_by' => 'created_at' ] );
		$this->assertSame( 'created_at', $args->order_by );
	}

	public function test_from_array_accepts_duration_ms_order_by(): void {
		$args = QueryArgs::from_array( [ 'order_by' => 'duration_ms' ] );
		$this->assertSame( 'duration_ms', $args->order_by );
	}

	public function test_from_array_caps_limit_at_200(): void {
		$args = QueryArgs::from_array( [ 'limit' => 500 ] );
		$this->assertSame( 200, $args->limit );
	}

	public function test_from_array_rejects_scientific_notation_for_limit(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'limit' => '1e2' ] );
	}

	public function test_from_array_accepts_valid_method(): void {
		$args = QueryArgs::from_array( [ 'method' => 'POST' ] );
		$this->assertSame( 'POST', $args->method );
	}

	public function test_from_array_accepts_valid_status_class(): void {
		$args = QueryArgs::from_array( [ 'status_class' => '4xx' ] );
		$this->assertSame( '4xx', $args->status_class );
	}

	public function test_from_array_rejects_unknown_status_class(): void {
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'status_class' => '6xx' ] );
	}

	public function test_cursor_string_passes_through_to_args(): void {
		// The cursor is validated at Paginator::decode time, not here.
		$args = QueryArgs::from_array( [ 'cursor' => 'some-cursor-token' ] );
		$this->assertSame( 'some-cursor-token', $args->cursor );
	}

	public function test_from_array_rejects_malformed_ip(): void {
		// A bogus IP must surface as an InvalidArgumentException so REST and CLI
		// callers see a clear validation error. The previous behaviour silently
		// dropped the filter inside QueryBuilder, broadening the query instead.
		$this->expectException( \InvalidArgumentException::class );
		QueryArgs::from_array( [ 'ip' => 'not-an-ip' ] );
	}

	public function test_from_array_accepts_valid_ipv4(): void {
		$args = QueryArgs::from_array( [ 'ip' => '192.168.1.1' ] );
		$this->assertSame( '192.168.1.1', $args->ip );
	}

	public function test_from_array_accepts_valid_ipv6(): void {
		$args = QueryArgs::from_array( [ 'ip' => '::1' ] );
		$this->assertSame( '::1', $args->ip );
	}

	public function test_to_query_var_array_returns_array_with_set_fields(): void {
		$args = QueryArgs::from_array(
			[
				'method' => 'GET',
				'status' => 200,
			]
		);
		$vars = $args->to_query_var_array();

		$this->assertIsArray( $vars );
		$this->assertSame( 'GET', $vars['method'] );
		$this->assertSame( 200, $vars['status'] );
	}
}
