<?php
/**
 * Unit test scaffold for BetterRestApiLogs\DB\Database table accessors.
 *
 * RED-bar baseline (Wave 0): stubs $wpdb with an anonymous class exposing
 * `prefix` and `get_charset_collate()` so the static accessors can be
 * exercised without bootstrapping WordPress. Plan 02-02 lands Database;
 * this file turns green then.
 *
 * Targets STOR-04 (centralized table-name accessor) per CONTEXT D-15..D-16.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\DB;

use BetterRestApiLogs\DB\Database;
use ReflectionMethod;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class DatabaseAccessorTest extends TestCase {

	/** @var mixed Holds the previous $GLOBALS['wpdb'] so we can restore it. */
	private $previous_wpdb = null;

	public function set_up(): void {
		parent::set_up();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new class() {
			public $prefix = 'wp_';
			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
			}
		};
	}

	public function tear_down(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		parent::tear_down();
	}

	public function test_logs_table_returns_prefixed_literal(): void {
		$this->assertSame( 'wp_brl_logs', Database::logs_table() );
	}

	public function test_bodies_table_returns_prefixed_literal(): void {
		$this->assertSame( 'wp_brl_logs_bodies', Database::bodies_table() );
	}

	public function test_charset_collate_delegates_to_wpdb(): void {
		$this->assertSame(
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci',
			Database::charset_collate()
		);
	}

	public function test_logs_table_method_is_static(): void {
		$ref = new ReflectionMethod( Database::class, 'logs_table' );
		$this->assertTrue( $ref->isStatic(), 'Database::logs_table() must be a static method.' );
	}

	public function test_bodies_table_method_is_static(): void {
		$ref = new ReflectionMethod( Database::class, 'bodies_table' );
		$this->assertTrue( $ref->isStatic(), 'Database::bodies_table() must be a static method.' );
	}

	public function test_charset_collate_method_is_static(): void {
		$ref = new ReflectionMethod( Database::class, 'charset_collate' );
		$this->assertTrue( $ref->isStatic(), 'Database::charset_collate() must be a static method.' );
	}
}
