<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Export;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Support\Json;

/**
 * Produces Newline Delimited JSON (NDJSON) from a list of Entry objects.
 *
 * Each output line is exactly one JSON object terminated by a single LF.
 * No BOM, no preamble — the format is self-describing and streamable.
 * Json::encode is the only encoder used here so that the locked flags
 * (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
 * are applied consistently and the byte output matches what the streamer
 * writes and what consumers expect per EXP-02.
 *
 * Pure PHP — no WordPress functions, no database access.
 */
final class NdjsonWriter {

	/**
	 * Returns empty string — NDJSON has no file preamble.
	 */
	public function preamble(): string {
		return '';
	}

	/**
	 * Serialise a list of entries to NDJSON lines.
	 *
	 * Each line is Json::encode($record)."\n". Callers can stream this output
	 * directly to a file handle without buffering the full export in memory.
	 *
	 * @param  Entry[] $entries Log entries to serialise.
	 * @return string           LF-terminated NDJSON lines.
	 */
	public function rows( array $entries ): string {
		$out = '';
		foreach ( $entries as $entry ) {
			$out .= Json::encode( $entry->to_array() ) . "\n";
		}
		return $out;
	}
}
