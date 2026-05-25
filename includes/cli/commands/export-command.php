<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\Export\Streamer;
use BetterRestApiLogs\Plugin;

/**
 * Export log entries as CSV or NDJSON.
 *
 * Without --out the export streams to STDOUT so operators can pipe it to
 * gzip, split, or any other Unix tool. With --out the bytes are written to
 * the specified file path. Any path segment that equals `..` is rejected
 * before a file handle is opened (CLI-04 / EXP-05 path-traversal guard).
 *
 * Filter flags (--method, --status, etc.) map 1:1 to the QueryArgs fields
 * exposed by the admin list view, so the same filter set works in the UI,
 * the REST API, and the CLI without duplication (EXP-04).
 *
 * CSV output starts with a UTF-8 BOM (\xEF\xBB\xBF) so Excel on Windows
 * opens the file with the correct encoding without a manual import wizard.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : Export format. One of: csv, ndjson. Default: ndjson.
 *
 * [--out=<path>]
 * : Write output to this file path. Omit to stream to STDOUT. Any path
 * : containing a `..` segment is rejected before any file is written.
 *
 * [--method=<method>]
 * : Filter by HTTP method (GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD).
 *
 * [--status=<status>]
 * : Filter by exact HTTP status code (100–599).
 *
 * [--status_class=<class>]
 * : Filter by status class (1xx, 2xx, 3xx, 4xx, 5xx).
 *
 * [--route_prefix=<prefix>]
 * : Filter by route prefix (e.g. /wp/v2/).
 *
 * [--date_from_micros=<micros>]
 * : Lower bound on created_at_micros (Unix microseconds).
 *
 * [--date_to_micros=<micros>]
 * : Upper bound on created_at_micros (Unix microseconds).
 *
 * ## EXAMPLES
 *
 *     wp better-logs export --format=csv --out=/tmp/logs.csv --user=1
 *     wp better-logs export --format=ndjson | gzip > logs.ndjson.gz
 *     wp better-logs export --format=csv --method=POST --status_class=5xx --user=1
 */
final class ExportCommand extends \WP_CLI_Command {

	/**
	 * Reject any path that contains a `..` segment, regardless of OS separator.
	 *
	 * Both forward-slash and backslash are treated as separators so
	 * `../escape` and `..\escape` are both caught on all platforms.
	 *
	 * @param  string $path Raw --out value from the operator.
	 * @return bool  True when the path is safe; false when a traversal is detected.
	 */
	public static function is_safe_out_path( string $path ): bool {
		$segments = \preg_split( '#[/\\\\]#', $path );
		if ( ! \is_array( $segments ) ) {
			return false;
		}
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, mixed>    $args       Positional command arguments from WP-CLI.
	 * @param array<string, mixed> $assoc_args Associative arguments from WP-CLI flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$required_cap = (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'cli' );
		if ( ! \current_user_can( $required_cap ) ) {
			\WP_CLI::error( \sprintf( 'Insufficient capabilities (%s required).', $required_cap ) );
		}

		// Format — default to ndjson when not supplied.
		$format = isset( $assoc_args['format'] ) ? \strtolower( \trim( (string) $assoc_args['format'] ) ) : 'ndjson';
		if ( ! \in_array( $format, [ 'csv', 'ndjson' ], true ) ) {
			\WP_CLI::error( 'Invalid --format. Accepted values: csv, ndjson.' );
		}

		// --out path guard — reject traversal before opening any file handle.
		$out_path = isset( $assoc_args['out'] ) ? (string) $assoc_args['out'] : null;
		if ( null !== $out_path ) {
			if ( ! self::is_safe_out_path( $out_path ) ) {
				\WP_CLI::error( \sprintf( 'Unsafe --out path rejected: %s', $out_path ) );
			}
		}

		// Build QueryArgs from filter flags, forwarding the same keys the admin
		// list view exposes so CLI and UI filter sets are interchangeable (EXP-04).
		$filter_keys = [
			'method',
			'status',
			'status_class',
			'route_prefix',
			'user_id',
			'ip',
			'date_from_micros',
			'date_to_micros',
			'free_text',
		];

		$raw = [];
		foreach ( $filter_keys as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$raw[ $key ] = $assoc_args[ $key ];
			}
		}

		try {
			$query_args = QueryArgs::from_array( $raw );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( 'Invalid filter argument: ' . $e->getMessage() );
		}

		// Open the destination handle — file or STDOUT.
		if ( null !== $out_path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fopen('php://output') and arbitrary writable paths are not supported by WP_Filesystem; streaming to a file path chosen by the operator is the intended use case.
			$sink = \fopen( $out_path, 'wb' );
			if ( false === $sink ) {
				\WP_CLI::error( \sprintf( 'Could not open output file for writing: %s', $out_path ) );
			}
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to php://output; WP_Filesystem has no equivalent for stdout handles.
			$sink = \fopen( 'php://output', 'wb' );
			if ( false === $sink ) {
				\WP_CLI::error( 'Could not open STDOUT for writing.' );
			}
		}

		/** @var resource $sink */
		$streamer = Plugin::instance()->container()->get( Streamer::class );
		$streamer->stream( $query_args, $format, $sink, false, 'cli' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle opened by fopen() above; WP_Filesystem has no equivalent.
		\fclose( $sink );

		if ( null !== $out_path ) {
			\WP_CLI::success( \sprintf( 'Exported to %s.', $out_path ) );
		}
	}
}
