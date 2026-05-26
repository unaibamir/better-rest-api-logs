<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Export;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Repository as SettingsRepository;
use BetterRestApiLogs\Support\Bytes;

/**
 * Streams a cursor-batched export to an open file handle without buffering
 * the full result set in PHP.
 *
 * Each batch is written and flushed independently so peak memory stays bounded
 * to approximately one batch's worth of rows — well under the 64 MB EXP-03
 * ceiling even at BATCH=1000 rows. NDJSON resolves spilled bodies per-batch
 * with a single IN(...) query (D-13), never per-row.
 *
 * Do NOT call fastcgi_finish_request() here — Flusher::on_shutdown owns it and
 * must not be pre-empted by an in-progress export.
 *
 * @package BetterRestApiLogs\Export
 */
final class Streamer {

	/**
	 * Rows per cursor page — direct prop write intentionally bypasses the
	 * QueryArgs::from_array() LIMIT_MAX=200 clamp which applies to UI requests,
	 * not server-side export batches.
	 */
	public const BATCH = 1000;

	private LogRepository $repo;
	private BodyRepository $bodies;
	private SettingsRegistry $registry;

	/**
	 * @param LogRepository|null    $repo     Defaults to a new real instance.
	 * @param BodyRepository|null   $bodies   Defaults to a new real instance.
	 * @param SettingsRegistry|null $registry Settings source for the anonymize-IP flag.
	 */
	public function __construct( ?LogRepository $repo = null, ?BodyRepository $bodies = null, ?SettingsRegistry $registry = null ) {
		$this->repo     = $repo ?? new LogRepository();
		$this->bodies   = $bodies ?? new BodyRepository();
		$this->registry = $registry ?? new SettingsRegistry( new SettingsRepository() );
	}

	/**
	 * Stream an export to $sink.
	 *
	 * Sends the preamble (BOM+header for CSV, empty for NDJSON), then walks
	 * the cursor-paginated search() result set in BATCH-sized pages. Each page
	 * is serialised via the appropriate writer, written to $sink, and flushed
	 * when running over HTTP. The loop terminates when has_more is false.
	 *
	 * NDJSON resolves spilled-body rows once per batch via a single
	 * BodyRepository::find_by_log_ids() call so there is no N+1 query per row.
	 *
	 * The brl_export_query_args filter fires after $args is received and before
	 * the first batch query, so extensions can restrict or modify the exported
	 * data set per surface (e.g. limit REST exports to the current user's own
	 * requests). When no filter is attached behaviour is identical to before.
	 *
	 * @param QueryArgs $args    Filter + sort arguments — the exact QueryArgs
	 *                           the caller built from the active UI filter set,
	 *                           so EXP-04 (filter-honoring) is a free consequence.
	 * @param string    $format  'csv' or 'ndjson'.
	 * @param resource  $sink    Writable file handle (php://output, php://temp, etc.).
	 * @param bool      $is_http True when called from an HTTP request (calls flush()
	 *                           after each batch); false for CLI (suppresses flush()).
	 * @param string    $surface Surface identifier passed to brl_export_query_args:
	 *                           'admin' | 'rest' | 'cli'. Defaults to 'admin'.
	 */
	public function stream( QueryArgs $args, string $format, $sink, bool $is_http = true, string $surface = 'admin' ): void {
		// Keep running even when the client disconnects mid-download.
		\ignore_user_abort( true );

		/**
		 * Mutate the QueryArgs before the export cursor walk begins.
		 *
		 * Fires once at the start of stream(), after the caller has built and
		 * validated $args but before the first batch query runs. Use $surface
		 * to distinguish admin bulk action ('admin'), the REST one-shot URL
		 * ('rest'), and WP-CLI ('cli').
		 *
		 * @since 1.0.0
		 *
		 * @param QueryArgs $args    Validated filter set.
		 * @param string    $surface 'admin' | 'rest' | 'cli'.
		 */
		$args = \apply_filters( 'brl_export_query_args', $args, $surface );

		$anonymize_ip = (bool) $this->registry->get_setting( 'privacy.anonymize_ip', false );
		$writer       = 'csv' === $format ? new CsvWriter() : new NdjsonWriter( $anonymize_ip );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- $sink is php://output or php://temp (streaming handles, not local filesystem paths); WP_Filesystem has no equivalent for in-memory or stdout handles.
		\fwrite( $sink, $writer->preamble() );

		// Direct prop write — bypasses the LIMIT_MAX=200 clamp that from_array()
		// enforces on UI requests. Export batches are server-initiated and safe.
		$args->limit  = self::BATCH;
		$args->cursor = null; // Start from the first page.

		do {
			$page = $this->repo->search( $args );

			if ( 'ndjson' === $format && [] !== $page['rows'] ) {
				// Resolve spilled bodies for the whole batch in a single round-trip
				// (D-13 — no N+1). Build the log_id list, fetch once, then attach.
				$spilled_ids = [];
				foreach ( $page['rows'] as $entry ) {
					if ( $entry->bodies_spilled ) {
						$spilled_ids[] = $entry->id;
					}
				}

				if ( [] !== $spilled_ids ) {
					$body_map = $this->bodies->find_by_log_ids( $spilled_ids );
					foreach ( $page['rows'] as $entry ) {
						if ( $entry->bodies_spilled && isset( $body_map[ $entry->id ] ) ) {
							// Decompress if gzip_bodies was enabled when the row was stored.
							// Bytes::gunzip() passes non-gzip input through unchanged via a
							// magic-byte check, so calling it unconditionally is safe.
							$raw_req              = $body_map[ $entry->id ]['request_body'];
							$raw_res              = $body_map[ $entry->id ]['response_body'];
							$entry->request_body  = null !== $raw_req ? Bytes::gunzip( $raw_req ) : null;
							$entry->response_body = null !== $raw_res ? Bytes::gunzip( $raw_res ) : null;
						}
					}
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming to php://output or php://temp; WP_Filesystem does not support these handles.
			\fwrite( $sink, $writer->rows( $page['rows'] ) );

			if ( $is_http ) {
				\flush();
			}

			$args->cursor = $page['next_cursor'];
		} while ( $page['has_more'] );
	}

	/**
	 * Send streaming-friendly download headers before the export body.
	 *
	 * Content-Type identifies the format to the browser. Content-Disposition
	 * triggers a Save As dialog. X-Accel-Buffering: no disables nginx proxy
	 * buffering so the browser receives bytes in real time (EXP-03).
	 *
	 * @param string $format   'csv' or 'ndjson'.
	 * @param string $filename Suggested download filename (before the filter).
	 */
	public function send_download_headers( string $format, string $filename ): void {
		/**
		 * Filter the suggested filename for an export download.
		 *
		 * @param string $filename Default filename derived from the export format.
		 * @param string $format   'csv' or 'ndjson'.
		 */
		$filename = (string) \apply_filters( 'brl_export_filename', $filename, $format );

		// Sanitize the (potentially filtered) filename so a third-party filter
		// cannot inject CR/LF header-splitting characters. sanitize_file_name()
		// strips path separators and control chars; addslashes() only escapes
		// backslash and quote but leaves CR/LF intact.
		$filename = \sanitize_file_name( $filename );

		$content_type = 'csv' === $format ? 'text/csv' : 'application/x-ndjson';

		\header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
		\header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		\header( 'X-Accel-Buffering: no' );
		\header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	}
}
