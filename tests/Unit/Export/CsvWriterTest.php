<?php
/**
 * Unit tests for BetterRestApiLogs\Export\CsvWriter.
 *
 * RED-bar scaffold (Wave 0): class does not exist until Wave 1.
 * The autoloader will throw if the class is referenced without a guard, so
 * each test uses class_exists() to skip cleanly rather than fatal the unit
 * suite. The skip reason names the implementing wave so it is easy to track.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Export;

use BetterRestApiLogs\Domain\Entry;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers BOM, CRLF, forced-quote, and formula-injection-prefix rules for CsvWriter.
 * All assertions are RED until Export\CsvWriter is implemented in Wave 1.
 */
final class CsvWriterTest extends TestCase {

	private function skip_until_wave1(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Export\\CsvWriter' ) ) {
			$this->markTestSkipped( 'Export\\CsvWriter not implemented yet — Wave 1' );
		}
	}

	private function make_entry( string $route = '/wp/v2/posts', string $method = 'GET', int $status = 200 ): Entry {
		$e                      = new Entry();
		$e->id                  = 1;
		$e->created_at          = '2026-01-15 10:30:00';
		$e->created_at_micros   = 1705315800000000;
		$e->method              = $method;
		$e->route               = $route;
		$e->status              = $status;
		$e->status_class        = (int) \floor( $status / 100 );
		$e->duration_ms         = 42;
		$e->user_id             = 7;
		$e->ip_resolved         = '127.0.0.1';
		$e->request_body_bytes  = 128;
		$e->response_body_bytes = 512;
		return $e;
	}

	public function test_preamble_starts_with_utf8_bom(): void {
		$this->skip_until_wave1();
		/** @var \BetterRestApiLogs\Export\CsvWriter $writer */
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $writer->preamble() );
	}

	public function test_preamble_ends_with_crlf(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$this->assertStringEndsWith( "\r\n", $writer->preamble() );
	}

	public function test_rows_line_ends_with_crlf(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $this->make_entry() ] );
		// Every line (including the last) ends with CRLF.
		$this->assertMatchesRegularExpression( '/\r\n$/', $output );
	}

	public function test_all_cells_are_double_quoted(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $this->make_entry() ] );
		// Each comma-separated token on the row must start and end with a double quote.
		$line = \rtrim( $output, "\r\n" );
		foreach ( \explode( ',', $line ) as $cell ) {
			$this->assertMatchesRegularExpression( '/^".*"$/', $cell, 'Every cell must be double-quoted.' );
		}
	}

	public function test_embedded_double_quote_is_doubled(): void {
		$this->skip_until_wave1();
		// Inject a route that contains a double-quote character.
		$entry  = $this->make_entry( '/path/with"quote' );
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $entry ] );
		// RFC 4180: embedded " must be escaped as "".
		$this->assertStringContainsString( '""', $output );
	}

	/**
	 * Formula-injection test: a cell value beginning with = must be prefixed with '.
	 * Fixture: =cmd|'/C calc'!A0 — a real-world CSV injection payload.
	 */
	public function test_formula_prefix_equals_sign(): void {
		$this->skip_until_wave1();
		$entry  = $this->make_entry( '=cmd|\'/C calc\'!A0' );
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $entry ] );
		// The cell must begin with "'=" (quote, apostrophe, equals) inside the wrapping quotes.
		$this->assertStringContainsString( '"\'=', $output, 'Formula cell starting with = must be apostrophe-prefixed.' );
	}

	/**
	 * @dataProvider formula_prefix_provider
	 * @param string $trigger The trigger character to test.
	 * @param string $label   Human-readable label for failure messages.
	 */
	public function test_formula_prefix_all_triggers( string $trigger, string $label ): void {
		$this->skip_until_wave1();
		$entry  = $this->make_entry( $trigger . 'dangerous_value' );
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $entry ] );
		$this->assertStringContainsString(
			'"\'',
			$output,
			"Cell starting with {$label} must be apostrophe-prefixed."
		);
	}

	/** @return array<string,array{string,string}> */
	public function formula_prefix_provider(): array {
		return [
			'equals'          => [ '=', '=' ],
			'plus'            => [ '+', '+' ],
			'minus'           => [ '-', '-' ],
			'at'              => [ '@', '@' ],
			'tab'             => [ "\t", 'TAB' ],
			'cr'              => [ "\r", 'CR' ],
			'fullwidth-eq'    => [ '＝', 'full-width ＝' ],
			'fullwidth-plus'  => [ '＋', 'full-width ＋' ],
			'fullwidth-minus' => [ '－', 'full-width －' ],
			'fullwidth-at'    => [ '＠', 'full-width ＠' ],
		];
	}

	public function test_created_at_uses_iso8601_with_trailing_z(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $this->make_entry() ] );
		// created_at in CSV must be ISO-8601 with trailing Z (UTC indicator).
		$this->assertMatchesRegularExpression(
			'/"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/',
			$output,
			'created_at cell must be ISO-8601 with trailing Z.'
		);
	}

	public function test_rows_contains_expected_column_count(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\CsvWriter();
		$output = $writer->rows( [ $this->make_entry() ] );
		$line   = \rtrim( $output, "\r\n" );
		// 11 columns: id, created_at, method, route, status, status_class,
		// duration_ms, user_id, ip_resolved, request_body_bytes, response_body_bytes.
		$this->assertCount( 11, \str_getcsv( $line ) );
	}
}
