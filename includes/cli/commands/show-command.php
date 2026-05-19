<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Plugin;

/**
 * Show one captured REST log entry by its id.
 *
 * Joins spilled body rows automatically — callers always see a fully-populated
 * entry regardless of whether the body was written to the primary table or the
 * overflow table.
 *
 * ## OPTIONS
 *
 * <id>
 * : Numeric log entry id.
 *
 * [--format=<format>]
 * : Output format — table, json, csv, yaml.  Default table.
 *
 * ## EXAMPLES
 *
 *     wp better-logs show 12345
 *     wp better-logs show 12345 --format=json
 */
final class ShowCommand extends \WP_CLI_Command {

	/**
	 * @param array<int, mixed>    $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'Pass a log id, for example: wp better-logs show 12345' );
		}

		$raw = (string) $args[0];
		if ( 1 !== \preg_match( '/^\d+$/', $raw ) ) {
			\WP_CLI::error( 'Log id must be a positive integer.' );
		}

		$id = (int) $raw;
		if ( $id <= 0 ) {
			\WP_CLI::error( 'Log id must be a positive integer.' );
		}

		$repo  = Plugin::instance()->container()->get( LogRepository::class );
		$entry = $repo->find( $id );

		if ( null === $entry ) {
			\WP_CLI::error( \sprintf( 'Log entry %d does not exist.', $id ) );
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$row    = $entry->to_array();
		// Add id as the first field — to_array() omits it (insert shape), but
		// the CLI show command surfaces the primary key at the top.
		$row    = array_merge( [ 'id' => $entry->id ], $row );
		$fields = array_keys( $row );

		if ( 'json' === $format ) {
			// Output a single JSON object, not a 1-element array, so callers can
			// do `wp better-logs show 123 --format=json | jq .id` without [0].
			\WP_CLI::line( (string) \wp_json_encode( $row ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, [ $row ], $fields );
	}
}
