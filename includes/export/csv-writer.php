<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Export;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Domain\Entry;

/**
 * Produces RFC-4180 CSV from a list of Entry objects.
 *
 * PHP's fputcsv cannot satisfy the export spec on the 7.4 floor: no CRLF
 * parameter (added in 8.1), no BOM, no forced quoting, and no formula-prefix
 * guard. This writer handles all four requirements inline, making it safe to
 * open in Excel or Google Sheets without injection risk.
 *
 * Pure PHP — no WordPress functions, no database access. Instantiable so the
 * streamer can call new CsvWriter() in the context of an HTTP response.
 */
final class CsvWriter {

	/**
	 * Column headers for the export. Order is the display-facing contract
	 * documented in EXP-01; do not reorder without bumping the export version.
	 */
	private const HEADERS = [
		'id',
		'created_at',
		'method',
		'route',
		'status',
		'status_class',
		'duration_ms',
		'user_id',
		'ip_resolved',
		'request_body_bytes',
		'response_body_bytes',
	];

	/**
	 * Characters that spreadsheet engines (Excel, Calc, Sheets) interpret as
	 * formula starters. Includes standard ASCII and Unicode full-width variants
	 * so an attacker cannot bypass the guard by widening the character.
	 * See OWASP CSV Injection for the threat model this list covers.
	 */
	private const FORMULA_PREFIXES = [ '=', '+', '-', '@', "\t", "\r", '＝', '＋', '－', '＠' ];

	/**
	 * UTF-8 BOM followed by the header row (CRLF-terminated).
	 */
	public function preamble(): string {
		return "\xEF\xBB\xBF" . $this->row( self::HEADERS ) . "\r\n";
	}

	/**
	 * Serialise a list of entries to CSV rows (each CRLF-terminated, no BOM).
	 *
	 * @param  Entry[] $entries Log entries to serialise.
	 * @return string           One CRLF-terminated CSV line per entry.
	 */
	public function rows( array $entries ): string {
		$out = '';
		foreach ( $entries as $entry ) {
			$out .= $this->row( $this->project( $entry ) ) . "\r\n";
		}
		return $out;
	}

	/**
	 * Project an Entry onto the ordered column list.
	 *
	 * Created_at is emitted as ISO-8601 with a trailing Z so import tools parse
	 * it as UTC without guessing. We derive it from created_at_micros (integer
	 * microseconds since the Unix epoch) — same idiom used by LogRepository.
	 *
	 * @param  Entry $entry Log entry to project.
	 * @return string[]
	 */
	private function project( Entry $entry ): array {
		$iso = \gmdate( 'Y-m-d\TH:i:s\Z', (int) ( $entry->created_at_micros / 1000000 ) );
		return [
			(string) $entry->id,
			$iso,
			$entry->method,
			$entry->route,
			(string) $entry->status,
			(string) $entry->status_class,
			(string) $entry->duration_ms,
			(string) $entry->user_id,
			(string) $entry->ip_resolved,
			(string) $entry->request_body_bytes,
			(string) $entry->response_body_bytes,
		];
	}

	/**
	 * Join a list of string values into a comma-separated RFC-4180 line.
	 * Does NOT append CRLF — callers add the line terminator.
	 *
	 * @param  string[] $values Cell values to join.
	 * @return string
	 */
	private function row( array $values ): string {
		return \implode( ',', \array_map( [ $this, 'cell' ], $values ) );
	}

	/**
	 * Wrap a single value in RFC-4180 double-quotes, doubling any embedded
	 * double-quote characters. If the value begins with a formula-injection
	 * trigger character (including after any leading ASCII spaces), prefix it
	 * with a single apostrophe so spreadsheet engines treat the cell as a
	 * literal string rather than a formula.
	 *
	 * Checking the space-stripped value catches payloads like " =cmd" that
	 * bypass a plain first-character check because spreadsheets trim leading
	 * spaces before evaluating formulas. Only ASCII space (0x20) is stripped,
	 * not tab or CR — those are formula triggers themselves and must be caught
	 * by the trigger check, not discarded. The apostrophe is prepended to the
	 * ORIGINAL value (not the stripped one) so the stored content is unchanged.
	 *
	 * @param  string $value Raw cell value.
	 * @return string        Force-quoted, injection-safe cell.
	 */
	private function cell( string $value ): string {
		// Formula-injection guard: strip leading ASCII spaces only (not tab/CR
		// which are themselves trigger chars), then test against every known
		// trigger prefix. Apostrophe is prepended to $value (original).
		if ( '' !== $value ) {
			$check = \ltrim( $value, ' ' );
			foreach ( self::FORMULA_PREFIXES as $prefix ) {
				if ( \strpos( $check, $prefix ) === 0 ) {
					$value = "'" . $value;
					break;
				}
			}
		}

		// RFC-4180: double any embedded double-quote characters, then wrap the
		// whole value in double-quotes. Every field is force-quoted regardless of
		// content so consumers can rely on a consistent, unambiguous format.
		$escaped = \str_replace( '"', '""', $value );
		return '"' . $escaped . '"';
	}
}
