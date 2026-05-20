<?php
/**
 * Unit tests for BetterRestApiLogs\DB\Query\QueryBuilder.
 *
 * Covers WHERE composition for filter inputs that are easy to get wrong:
 * route_prefix wildcard handling, the implicit prefix fallback, and the
 * resulting LIKE pattern. QueryBuilder reads the global $wpdb for esc_like,
 * so the test installs a tiny duck-typed stand-in.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\DB\Query;

use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\DB\Query\QueryBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class QueryBuilderTest extends TestCase {

	/** @var object|null Previous $wpdb if any (restored in tear_down). */
	private $prev_wpdb = null;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->prev_wpdb = $wpdb ?? null;

		// Minimal wpdb stand-in — only ->prefix and esc_like() are read on
		// the code paths under test. Database::logs_table() reads ->prefix.
		$wpdb = new class() {
			public string $prefix = 'wp_';
			public function esc_like( string $text ): string {
				return \addcslashes( $text, '_%\\' );
			}
		};
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb = $this->prev_wpdb;
		parent::tear_down();
	}

	public function test_route_prefix_wildcard_becomes_like_pattern(): void {
		$args = QueryArgs::from_array( [ 'route_prefix' => '/wp/v2/posts/*' ] );
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'route LIKE %s', $out['sql'] );
		$this->assertContains( '/wp/v2/posts/%', $out['bindings'] );
	}

	public function test_route_prefix_mid_wildcard_becomes_like_pattern(): void {
		$args = QueryArgs::from_array( [ 'route_prefix' => '*/users/*' ] );
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'route LIKE %s', $out['sql'] );
		$this->assertContains( '%/users/%', $out['bindings'] );
	}

	public function test_route_prefix_without_wildcard_gets_implicit_trailing_percent(): void {
		$args = QueryArgs::from_array( [ 'route_prefix' => '/wp/v2/' ] );
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'route LIKE %s', $out['sql'] );
		$this->assertContains( '/wp/v2/%', $out['bindings'] );
	}

	public function test_route_prefix_escapes_existing_percent_and_underscore_outside_wildcards(): void {
		// Underscore and percent are LIKE meta-characters; they must be escaped
		// so a literal `_` in the user input doesn't match any single char.
		$args = QueryArgs::from_array( [ 'route_prefix' => '/wp/_internal/*' ] );
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertContains( '/wp/\\_internal/%', $out['bindings'] );
	}

	public function test_default_sort_uses_created_at_micros_tuple(): void {
		$args = QueryArgs::from_array( [] );
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'ORDER BY created_at_micros DESC, id DESC', $out['sql'] );
	}

	public function test_sort_by_route_uses_route_with_tiebreak(): void {
		$args = QueryArgs::from_array(
			[
				'order_by'  => 'route',
				'order_dir' => 'asc',
			]
		);
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'ORDER BY route ASC, created_at_micros DESC, id DESC', $out['sql'] );
	}

	public function test_sort_by_status_desc(): void {
		$args = QueryArgs::from_array(
			[
				'order_by'  => 'status',
				'order_dir' => 'desc',
			]
		);
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'ORDER BY status DESC, created_at_micros DESC, id DESC', $out['sql'] );
	}

	public function test_sort_by_method_asc(): void {
		$args = QueryArgs::from_array(
			[
				'order_by'  => 'method',
				'order_dir' => 'asc',
			]
		);
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'ORDER BY method ASC, created_at_micros DESC, id DESC', $out['sql'] );
	}

	public function test_sort_by_duration_ms_desc(): void {
		$args = QueryArgs::from_array(
			[
				'order_by'  => 'duration_ms',
				'order_dir' => 'desc',
			]
		);
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		$this->assertStringContainsString( 'ORDER BY duration_ms DESC, created_at_micros DESC, id DESC', $out['sql'] );
	}

	public function test_legacy_created_at_alias_resolves_to_created_at_micros(): void {
		$args = QueryArgs::from_array(
			[
				'order_by'  => 'created_at',
				'order_dir' => 'asc',
			]
		);
		$out  = ( new QueryBuilder() )->build_paged( $args, 0, 10 );

		// The user-facing alias must produce the same tuple as the canonical
		// created_at_micros — both columns hold the same value.
		$this->assertStringContainsString( 'ORDER BY created_at_micros ASC, id ASC', $out['sql'] );
	}
}
