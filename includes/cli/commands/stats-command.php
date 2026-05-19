<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;

/**
 * Show aggregate counts and table size.
 *
 * The stats snapshot is cached in `brl_internal` (key: stats_snapshot) with a
 * 5-minute TTL. A cache miss triggers a live DB aggregate — bounded to the
 * last 10 000 rows per the LogRepository contract.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : Output format.  One of: table, json, csv, yaml.  Default: table.
 *
 * ## EXAMPLES
 *
 *     wp better-logs stats
 *     wp better-logs stats --format=json
 */
final class StatsCommand extends \WP_CLI_Command {

	private const CACHE_TTL = 300; // 5 minutes.

	/**
	 * @param array<int, mixed>    $args       Positional command arguments from WP-CLI.
	 * @param array<string, mixed> $assoc_args Associative arguments from WP-CLI flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$shaped = $this->get_stats();
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) \wp_json_encode( $shaped ) );
			return;
		}

		$rows = [
			[
				'Metric' => 'Total rows (estimate)',
				'Value'  => (string) $shaped['total_estimate'],
			],
			[
				'Metric' => '1xx',
				'Value'  => (string) $shaped['by_status_class']['1xx'],
			],
			[
				'Metric' => '2xx',
				'Value'  => (string) $shaped['by_status_class']['2xx'],
			],
			[
				'Metric' => '3xx',
				'Value'  => (string) $shaped['by_status_class']['3xx'],
			],
			[
				'Metric' => '4xx',
				'Value'  => (string) $shaped['by_status_class']['4xx'],
			],
			[
				'Metric' => '5xx',
				'Value'  => (string) $shaped['by_status_class']['5xx'],
			],
			[
				'Metric' => 'Oldest',
				'Value'  => (string) $shaped['oldest'],
			],
			[
				'Metric' => 'Newest',
				'Value'  => (string) $shaped['newest'],
			],
			[
				'Metric' => 'Table size (bytes)',
				'Value'  => (string) $shaped['table_size_bytes'],
			],
			[
				'Metric' => 'Cache age (sec)',
				'Value'  => (string) $shaped['cache_age_seconds'],
			],
		];

		\WP_CLI\Utils\format_items( $format, $rows, [ 'Metric', 'Value' ] );
	}

	/**
	 * Return the shaped stats array, recomputing from the DB when the cached
	 * snapshot is absent or stale.
	 *
	 * Shape matches the REST /stats endpoint contract so CLI and REST stay in sync.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stats(): array {
		$snapshot = (array) Registry::get_internal( 'stats_snapshot' );
		$computed = isset( $snapshot['computed_at'] ) ? (int) $snapshot['computed_at'] : 0;
		$age      = $computed > 0 ? max( 0, \time() - $computed ) : PHP_INT_MAX;

		if ( $age < self::CACHE_TTL && [] !== $snapshot ) {
			return $this->shape( $snapshot, $age );
		}

		// Cache miss or stale — recompute live.
		$repo      = Plugin::instance()->container()->get( LogRepository::class );
		$by_class  = $repo->count_by_status_class();
		$by_method = $repo->count_by_method();
		$times     = $repo->oldest_newest();
		$total     = (int) array_sum( $by_class );

		$fresh = [
			'total'            => $total,
			'by_status_class'  => $by_class,
			'by_method'        => $by_method,
			'oldest'           => $times['oldest'] ?? '',
			'newest'           => $times['newest'] ?? '',
			'table_size_bytes' => 0,
			'computed_at'      => \time(),
		];

		// Best-effort cache write — ignore if brl_internal is unregistered.
		try {
			Registry::set_internal( 'stats_snapshot', $fresh );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $this->shape( $fresh, 0 );
	}

	/**
	 * Transform the raw snapshot into the canonical stats shape.
	 *
	 * All five status-class keys are always present (zero-filled when absent).
	 *
	 * @param  array<string, mixed> $snapshot Raw snapshot from brl_internal.
	 * @param  int                  $age      Cache age in seconds.
	 * @return array<string, mixed>
	 */
	private function shape( array $snapshot, int $age ): array {
		$raw_class = isset( $snapshot['by_status_class'] ) && is_array( $snapshot['by_status_class'] )
			? $snapshot['by_status_class']
			: [];

		$by_class = [
			'1xx' => (int) ( $raw_class['1xx'] ?? 0 ),
			'2xx' => (int) ( $raw_class['2xx'] ?? 0 ),
			'3xx' => (int) ( $raw_class['3xx'] ?? 0 ),
			'4xx' => (int) ( $raw_class['4xx'] ?? 0 ),
			'5xx' => (int) ( $raw_class['5xx'] ?? 0 ),
		];

		$raw_method = isset( $snapshot['by_method'] ) && is_array( $snapshot['by_method'] )
			? $snapshot['by_method']
			: [];

		$by_method = [];
		foreach ( $raw_method as $m => $n ) {
			$by_method[ (string) $m ] = (int) $n;
		}

		return [
			'total_estimate'    => isset( $snapshot['total'] ) ? (int) $snapshot['total'] : 0,
			'by_status_class'   => $by_class,
			'by_method'         => $by_method,
			'oldest'            => isset( $snapshot['oldest'] ) ? (string) $snapshot['oldest'] : '',
			'newest'            => isset( $snapshot['newest'] ) ? (string) $snapshot['newest'] : '',
			'table_size_bytes'  => isset( $snapshot['table_size_bytes'] ) ? (int) $snapshot['table_size_bytes'] : 0,
			'cache_age_seconds' => $age,
		];
	}
}
