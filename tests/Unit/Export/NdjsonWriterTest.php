<?php
/**
 * Unit tests for BetterRestApiLogs\Export\NdjsonWriter.
 *
 * RED-bar scaffold (Wave 0): class does not exist until Wave 1.
 * Uses class_exists() guard — skips cleanly rather than fatalling the unit
 * suite. Skip reason names the implementing wave.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Export;

use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Support\Json;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers NDJSON line shape, JSON round-trip, no BOM, and exact-bytes contract.
 * All assertions are RED until Export\NdjsonWriter is implemented in Wave 1.
 */
final class NdjsonWriterTest extends TestCase {

	private function skip_until_wave1(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Export\\NdjsonWriter' ) ) {
			$this->markTestSkipped( 'Export\\NdjsonWriter not implemented yet — Wave 1' );
		}
	}

	private function make_entry(): Entry {
		$e                      = new Entry();
		$e->id                  = 42;
		$e->created_at          = '2026-01-15 10:30:00';
		$e->created_at_micros   = 1705315800000000;
		$e->method              = 'POST';
		$e->route               = '/wp/v2/posts';
		$e->status              = 201;
		$e->status_class        = 2;
		$e->duration_ms         = 88;
		$e->user_id             = 3;
		// Stored as packed inet_pton bytes, the way capture writes it.
		$packed                 = \inet_pton( '10.0.0.1' );
		$e->ip_resolved         = false !== $packed ? $packed : null;
		$e->request_body_bytes  = 256;
		$e->response_body_bytes = 1024;
		return $e;
	}

	public function test_single_entry_produces_exactly_one_newline_terminated_line(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\NdjsonWriter();
		$output = $writer->rows( [ $this->make_entry() ] );
		// One entry = exactly one LF at the end, no other bare LF inside the line.
		$this->assertSame( 1, \substr_count( $output, "\n" ) );
		$this->assertStringEndsWith( "\n", $output );
	}

	public function test_no_bom_prefix(): void {
		$this->skip_until_wave1();
		$writer = new \BetterRestApiLogs\Export\NdjsonWriter();
		$output = $writer->rows( [ $this->make_entry() ] );
		$this->assertStringNotContainsString( "\xEF\xBB\xBF", $output, 'NDJSON must not emit a UTF-8 BOM.' );
	}

	public function test_line_is_valid_json(): void {
		$this->skip_until_wave1();
		$writer  = new \BetterRestApiLogs\Export\NdjsonWriter();
		$output  = $writer->rows( [ $this->make_entry() ] );
		$line    = \rtrim( $output, "\n" );
		$decoded = \json_decode( $line, true );
		$this->assertIsArray( $decoded, 'Each NDJSON line must be a valid JSON object.' );
	}

	public function test_json_round_trips_entry_fields(): void {
		$this->skip_until_wave1();
		$entry   = $this->make_entry();
		$writer  = new \BetterRestApiLogs\Export\NdjsonWriter();
		$output  = $writer->rows( [ $entry ] );
		$line    = \rtrim( $output, "\n" );
		$decoded = \json_decode( $line, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( $entry->id, (int) $decoded['id'], 'id must round-trip.' );
		$this->assertSame( $entry->method, $decoded['method'], 'method must round-trip.' );
		$this->assertSame( $entry->route, $decoded['route'], 'route must round-trip.' );
		$this->assertSame( $entry->status, (int) $decoded['status'], 'status must round-trip.' );
	}

	public function test_bytes_match_json_encode_with_locked_flags(): void {
		$this->skip_until_wave1();
		$entry  = $this->make_entry();
		$writer = new \BetterRestApiLogs\Export\NdjsonWriter();
		$output = $writer->rows( [ $entry ] );

		// The NdjsonWriter serialises the export projection — packed IP columns
		// rendered printable and ip_raw_remote honouring the anonymize setting —
		// not the raw to_array() (which carries packed binary).
		$record   = $entry->to_export_array( false );
		$expected = Json::encode( $record ) . "\n";
		$this->assertSame( $expected, $output, 'Line bytes must match Json::encode($entry->to_export_array())."\\n".' );
	}

	public function test_multiple_entries_produce_multiple_lines(): void {
		$this->skip_until_wave1();
		$writer     = new \BetterRestApiLogs\Export\NdjsonWriter();
		$entry1     = $this->make_entry();
		$entry2     = clone $entry1;
		$entry2->id = 99;
		$output     = $writer->rows( [ $entry1, $entry2 ] );
		$lines      = \array_filter( \explode( "\n", \rtrim( $output, "\n" ) ) );
		$this->assertCount( 2, $lines, 'Two entries must produce two lines.' );
	}
}
