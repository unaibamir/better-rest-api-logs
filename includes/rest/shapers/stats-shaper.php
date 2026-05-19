<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Rest\Shapers;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes the /stats endpoint response from the persisted snapshot.
 *
 * Pure PHP — no WordPress functions, no $wpdb. Unit-testable without
 * bootstrapping WP. The five status-class keys are always present in the output
 * even when a class has zero entries in the snapshot.
 */
final class StatsShaper {

	/** @var string[] */
	private const STATUS_CLASSES = [ '1xx', '2xx', '3xx', '4xx', '5xx' ];

	/**
	 * Shape a raw snapshot array into the D-26 REST response contract.
	 *
	 * @param  array<string,mixed> $snapshot        Raw brl_internal.stats_snapshot value.
	 * @param  int                 $cache_age_seconds Seconds since the snapshot was computed.
	 * @return array<string,mixed>
	 */
	public static function shape( array $snapshot, int $cache_age_seconds ): array {
		$by_class_input = (array) ( $snapshot['by_status_class'] ?? [] );
		$by_class       = [];
		foreach ( self::STATUS_CLASSES as $class ) {
			$by_class[ $class ] = (int) ( $by_class_input[ $class ] ?? 0 );
		}

		$by_method_input = (array) ( $snapshot['by_method'] ?? [] );
		$by_method       = [];
		foreach ( $by_method_input as $method => $count ) {
			$by_method[ (string) $method ] = (int) $count;
		}

		return [
			'total_estimate'    => (int) ( $snapshot['total'] ?? 0 ),
			'by_status_class'   => $by_class,
			'by_method'         => $by_method,
			'oldest'            => (string) ( $snapshot['oldest'] ?? '' ),
			'newest'            => (string) ( $snapshot['newest'] ?? '' ),
			'table_size_bytes'  => (int) ( $snapshot['table_size_bytes'] ?? 0 ),
			'cache_age_seconds' => $cache_age_seconds,
		];
	}
}
