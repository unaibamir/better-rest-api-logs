<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\Plugin;

/**
 * List captured REST log rows.
 *
 * Shares the same QueryArgs + LogRepository path as the admin list table and
 * the REST /logs endpoint — filter behaviour cannot drift between surfaces.
 *
 * When --limit exceeds the server cap (200), this command walks cursor pages
 * transparently until the requested count is satisfied. Callers see rows, not
 * cursor tokens.
 *
 * ## OPTIONS
 *
 * [--method=<method>]
 * : Filter by HTTP method (GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD).
 *
 * [--status=<code>]
 * : Filter by exact HTTP status code (100-599).
 *
 * [--status_class=<class>]
 * : Filter by status class (1xx|2xx|3xx|4xx|5xx).
 *
 * [--route_prefix=<prefix>]
 * : Filter by route prefix, e.g. /wp/v2/.
 *
 * [--user_id=<id>]
 * : Filter by WordPress user id.
 *
 * [--ip=<address>]
 * : Filter by IP address (IPv4 or IPv6 literal).
 *
 * [--free_text=<text>]
 * : Substring filter on the route column.
 *
 * [--limit=<n>]
 * : Maximum rows to return.  Default 20.  Values above 200 trigger cursor-walking.
 *
 * [--format=<format>]
 * : Output format — table, json, csv, yaml.  Default table.
 *
 * ## EXAMPLES
 *
 *     wp better-logs list --method=POST --status_class=4xx
 *     wp better-logs list --limit=500 --format=json
 */
final class ListCommand extends \WP_CLI_Command {

	private const SERVER_CAP = 200;

	/**
	 * @param array<int, mixed>    $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$repo = Plugin::instance()->container()->get( LogRepository::class );

		$requested_limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$remaining       = $requested_limit;
		$cursor          = null;
		$collected       = [];

		while ( $remaining > 0 ) {
			$page_limit = min( $remaining, self::SERVER_CAP );

			// Build a clean input array for QueryArgs — only pass defined filter keys.
			$input = [];
			foreach ( [ 'method', 'status', 'status_class', 'route_prefix', 'user_id', 'ip', 'free_text' ] as $key ) {
				if ( isset( $assoc_args[ $key ] ) ) {
					$input[ $key ] = $assoc_args[ $key ];
				}
			}
			$input['limit']  = $page_limit;
			$input['cursor'] = $cursor;

			try {
				$query_args = QueryArgs::from_array( $input );
			} catch ( \InvalidArgumentException $e ) {
				\WP_CLI::error( $e->getMessage() );
				return; // unreachable — \WP_CLI::error throws; satisfies static analysis.
			}

			$query_args = \apply_filters( 'brl_query_args', $query_args, 'cli' );
			$result     = $repo->search( $query_args );

			foreach ( $result['rows'] as $entry ) {
				$row         = $entry->to_array();
				$row['id']   = $entry->id;
				$collected[] = $row;
				if ( count( $collected ) >= $requested_limit ) {
					break 2;
				}
			}

			if ( ! $result['has_more'] || null === $result['next_cursor'] ) {
				break;
			}

			$cursor    = $result['next_cursor'];
			$remaining = $requested_limit - count( $collected );
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$fields = [ 'id', 'created_at', 'method', 'status', 'status_class', 'route', 'duration_ms', 'user_id' ];
		\WP_CLI\Utils\format_items( $format, $collected, $fields );
	}
}
