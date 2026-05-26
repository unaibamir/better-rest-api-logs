<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Migration;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Capture\Redactor;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Internal;
use BetterRestApiLogs\Support\Clock;

/**
 * Cron drain tick for the migration engine (MIG-05, D-07).
 *
 * Mirrors Cron\Purge::tick() method-for-method — same transient lock, same
 * try/finally release, same state-write-after-release pattern. No shared base
 * class; mirroring is intentional to keep the two drain loops independently
 * reviewable (D-07: do not invent a second scheduling abstraction).
 *
 * Tick flow:
 *  1. Bail if brl_migration_running transient is held (parallel tick).
 *  2. Set the transient lock (5-min TTL) + ignore_user_abort(true).
 *  3. Read cursor from brl_internal.migration.
 *  4. read_batch(cursor, 500) → for each entry: Mapper::map → re-redact →
 *     DedupIndex::insert; per-row errors are caught and counted as skipped.
 *  5. Release lock in finally (always).
 *  6. Write 7-key migration marker AFTER lock release, wrapped in try/catch Throwable.
 *  7. While batch was full (500), self-re-enqueue brl_migration_tick with a unique
 *     seq arg to defeat WP's 10-min dedup window; else set status='done'.
 */
final class Importer {

	/**
	 * Transient key for the running-lock (mirrors Purge::LOCK_KEY pattern).
	 */
	const LOCK_KEY = 'brl_migration_running';

	/**
	 * Batch size per tick (MIG-05 default 500).
	 */
	const BATCH_SIZE = 500;

	private Reader $reader;
	private Mapper $mapper;
	private DedupIndex $dedup;
	private Redactor $redactor;
	private Clock $clock;

	/**
	 * @param Reader|null           $reader   Upstream CPT reader.
	 * @param Mapper|null           $mapper   Pure-PHP SourceEntry → Entry mapper.
	 * @param DedupIndex|null       $dedup    Idempotent insert seam.
	 * @param Redactor|null         $redactor Live capture redactor (re-redacts imported bodies).
	 * @param SettingsRegistry|null $registry Accepted for DI symmetry with Purge; state is
	 *                                        accessed via the static SettingsRegistry facade
	 *                                        (same pattern as Purge::tick uses directly).
	 *                                        Parameter kept so callers can pass a registry without
	 *                                        wiring a separate bootstrap, but not stored.
	 * @param Clock|null            $clock    Monotonic clock for unique seq args.
	 */
	public function __construct(
		?Reader $reader = null,
		?Mapper $mapper = null,
		?DedupIndex $dedup = null,
		?Redactor $redactor = null,
		?SettingsRegistry $registry = null,
		?Clock $clock = null
	) {
		$this->reader   = $reader ?? new Reader();
		$this->mapper   = $mapper ?? new Mapper();
		$this->dedup    = $dedup ?? new DedupIndex();
		$this->redactor = $redactor ?? new Redactor();
		$this->clock    = $clock ?? new Clock();
		// $registry is accepted for constructor symmetry with the container wiring but
		// state reads/writes go through the static SettingsRegistry facade (mirrors Purge::tick).
		unset( $registry );
	}

	/**
	 * Run one bounded migration tick.
	 *
	 * Accepts an optional $args param because wp_schedule_single_event passes
	 * its third argument (the unique seq array) to the action callback; we ignore
	 * the value — it defeats WP's 10-min dedup window only (mirrors Purge::tick).
	 *
	 * @param mixed $args Ignored; absorbs the seq array from wp_schedule_single_event.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $args absorbs the seq array from wp_schedule_single_event follow-up; intentionally unused.
	public function tick( $args = null ): void {
		// Bail when a parallel tick is already running.
		if ( \get_transient( self::LOCK_KEY ) ) {
			return;
		}

		\set_transient( self::LOCK_KEY, 1, 5 * \MINUTE_IN_SECONDS );
		\ignore_user_abort( true );

		$processed = 0;
		$imported  = 0;
		$skipped   = 0;
		$cursor    = 0;
		$batch     = [];
		$error     = false;

		try {
			$state  = SettingsRegistry::get_internal( 'migration' );
			$state  = \is_array( $state ) ? $state : [];
			$cursor = (int) ( $state['cursor'] ?? 0 );

			// If this is the first tick, record when migration started.
			if ( empty( $state['started_at'] ) ) {
				$state['started_at'] = \time();
			}

			$state['status'] = 'running';

			$batch = $this->reader->read_batch( $cursor, self::BATCH_SIZE );

			foreach ( $batch as $source_entry ) {
				try {
					$mapped = $this->mapper->map( $source_entry );
					$entry  = $mapped['entry'];

					// D-06: re-redact with CURRENT config — never trust upstream redaction.
					// Headers are stored as JSON in the Entry; decode, redact, re-encode.
					$entry = $this->re_redact( $entry );

					$was_inserted = $this->dedup->insert( $entry );

					++$processed;
					$cursor = $source_entry->source_id;

					if ( $was_inserted ) {
						++$imported;
					} else {
						++$skipped;
					}
				} catch ( \Throwable $row_error ) {
					// Per-row failures skip the row but don't abort the batch (V7 error handling).
					++$skipped;
					++$processed;
					if ( $source_entry->source_id > 0 ) {
						$cursor = $source_entry->source_id;
					}
					unset( $row_error );
				}
			}
		} catch ( \Throwable $batch_error ) {
			$error = true;
			unset( $batch_error );
		} finally {
			// Always release the lock — even on exception (mirrors Purge::tick).
			\delete_transient( self::LOCK_KEY );
		}

		// Write the 7-key migration marker AFTER releasing the lock.
		// Wrapped in try/catch Throwable: MySQL may be the broken thing (mirrors Purge).
		try {
			$current = SettingsRegistry::get_internal( 'migration' );
			if ( ! \is_array( $current ) ) {
				$current = Internal::defaults()['migration'];
			}

			$current['processed'] = (int) ( $current['processed'] ?? 0 ) + $processed;
			$current['imported']  = (int) ( $current['imported'] ?? 0 ) + $imported;
			$current['skipped']   = (int) ( $current['skipped'] ?? 0 ) + $skipped;
			$current['cursor']    = $cursor;

			if ( empty( $current['started_at'] ) ) {
				$current['started_at'] = (int) ( $state['started_at'] ?? \time() );
			}

			if ( $error ) {
				$current['status'] = 'error';
			} elseif ( \count( $batch ) === self::BATCH_SIZE ) {
				$current['status'] = 'running';
			} else {
				$current['status'] = 'done';
			}

			SettingsRegistry::set_internal( 'migration', $current );
		} catch ( \Throwable $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Persistence is best-effort; the import already happened (mirrors Purge).
			unset( $e );
		}

		// D-07: while the batch was full, immediately enqueue a follow-up tick
		// with a unique seq arg so WP's 10-min dedup window doesn't swallow it.
		if ( ! $error && \count( $batch ) === self::BATCH_SIZE ) {
			\wp_schedule_single_event(
				\time(),
				'brl_migration_tick',
				[ [ 'seq' => $this->clock->now_micros() ] ]
			);
		}
	}

	/**
	 * Return the count of upstream posts remaining past $cursor (for dry-run).
	 *
	 * Does not write any state.
	 *
	 * @param  int $cursor Last processed upstream post ID (0 = count all).
	 * @return int
	 */
	public function count_source( int $cursor = 0 ): int {
		return $this->reader->count_remaining( $cursor );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Re-redact an Entry's request/response bodies and headers with the current
	 * Capture\Redactor config (D-06, MIG-06). Returns the mutated entry.
	 *
	 * The Redactor expects headers as array<string,array<int,string>>; the Entry
	 * stores them as a JSON string. We decode, redact, re-encode.
	 * Bodies are redacted as JSON (the upstream format is pretty-JSON for both).
	 *
	 * @param  \BetterRestApiLogs\Domain\Entry $entry Mapped entry.
	 * @return \BetterRestApiLogs\Domain\Entry
	 */
	private function re_redact( \BetterRestApiLogs\Domain\Entry $entry ): \BetterRestApiLogs\Domain\Entry {
		$extra_pattern = '';

		// Re-redact request body.
		if ( null !== $entry->request_body && '' !== $entry->request_body ) {
			$entry->request_body = $this->redactor->redact_json_body( $entry->request_body, $extra_pattern );
		}

		// Re-redact response body.
		if ( null !== $entry->response_body && '' !== $entry->response_body ) {
			$entry->response_body = $this->redactor->redact_json_body( $entry->response_body, $extra_pattern );
		}

		// Re-redact request headers (stored as JSON string in Entry).
		if ( null !== $entry->request_headers && '' !== $entry->request_headers ) {
			$decoded = \json_decode( $entry->request_headers, true );
			if ( \is_array( $decoded ) ) {
				// redact_headers expects array<string, array<int,string>>; normalise.
				$normalised = $this->normalise_headers_for_redactor( $decoded );
				$redacted   = $this->redactor->redact_headers( $normalised, [] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- re-encoding after redaction; no WP functions needed here.
				$encoded                = \json_encode( $this->flatten_redacted_headers( $redacted ) );
				$entry->request_headers = ( false === $encoded ) ? null : $encoded;
			}
		}

		// Re-redact response headers.
		if ( null !== $entry->response_headers && '' !== $entry->response_headers ) {
			$decoded = \json_decode( $entry->response_headers, true );
			if ( \is_array( $decoded ) ) {
				$normalised = $this->normalise_headers_for_redactor( $decoded );
				$redacted   = $this->redactor->redact_headers( $normalised, [] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- re-encoding after redaction; no WP functions needed here.
				$encoded                 = \json_encode( $this->flatten_redacted_headers( $redacted ) );
				$entry->response_headers = ( false === $encoded ) ? null : $encoded;
			}
		}

		return $entry;
	}

	/**
	 * Normalise a decoded headers array into the shape redact_headers expects:
	 * array<string, array<int, string>>.
	 *
	 * The Mapper stores headers as array<string,string>; redact_headers wants
	 * each value wrapped in an array (matching WP_REST_Request::get_headers).
	 *
	 * @param  array<string,mixed> $decoded JSON-decoded header array.
	 * @return array<string,array<int,string>>
	 */
	private function normalise_headers_for_redactor( array $decoded ): array {
		$out = [];
		foreach ( $decoded as $name => $value ) {
			if ( \is_array( $value ) ) {
				$out[ (string) $name ] = \array_values( \array_map( 'strval', $value ) );
			} else {
				$out[ (string) $name ] = [ (string) $value ];
			}
		}
		return $out;
	}

	/**
	 * Flatten array<string, array<int,string>> back to array<string,string>
	 * for JSON storage — takes the first value per header name.
	 *
	 * @param  array<string,array<int,string>> $headers Redacted headers from redact_headers.
	 * @return array<string,string>
	 */
	private function flatten_redacted_headers( array $headers ): array {
		$out = [];
		foreach ( $headers as $name => $values ) {
			$out[ $name ] = isset( $values[0] ) ? (string) $values[0] : '';
		}
		return $out;
	}
}
