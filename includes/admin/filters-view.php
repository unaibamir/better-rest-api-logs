<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Query\QueryArgs;

/**
 * Pure-presentation filter bar emitter for the admin list page.
 *
 * Receives a validated QueryArgs and the oldest/newest timestamps from the
 * repository; emits the horizontal <form method="get"> with all filter inputs.
 * Every form value re-emitted into attributes is escaped via esc_attr.
 *
 * @package BetterRestApiLogs\Admin
 */
final class FiltersView {

	/** @var string[] HTTP methods available for the method select. */
	private const METHODS = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD' ];

	/** @var string[] Status classes for the status-class select. */
	private const STATUS_CLASSES = [ '1xx', '2xx', '3xx', '4xx', '5xx' ];

	/**
	 * Emit the filter controls as inline tablenav children.
	 *
	 * The list view wraps everything in a single <form method="get"> (matching
	 * WP core's edit.php pattern), so this renders bare inputs — no outer form,
	 * no hidden page input. The bulk-action dropdown and Apply button live in
	 * the same row, courtesy of WP_List_Table::display_tablenav().
	 *
	 * @param  QueryArgs                                    $args          Current filter state derived from $_GET.
	 * @param  array{oldest:string|null,newest:string|null} $oldest_newest Date range bounds from the log table.
	 * @return void
	 */
	public function render_inline( QueryArgs $args, array $oldest_newest ): void {
		$oldest_iso = isset( $oldest_newest['oldest'] ) ? $this->micros_to_date( $oldest_newest['oldest'] ) : '';
		$newest_iso = isset( $oldest_newest['newest'] ) ? $this->micros_to_date( $oldest_newest['newest'] ) : '';

		echo '<div class="alignleft actions brl-filters">';

		// Date from.
		printf(
			'<label class="screen-reader-text" for="brl-filter-date-from">%s</label>',
			\esc_html__( 'From', 'better-rest-api-logs' )
		);
		printf(
			'<input type="date" id="brl-filter-date-from" name="date_from" value="%s" aria-label="%s"%s>',
			\esc_attr( $this->micros_to_date( null !== $args->date_from_micros ? (string) $args->date_from_micros : '' ) ),
			\esc_attr__( 'From date', 'better-rest-api-logs' ),
			$oldest_iso ? ' min="' . \esc_attr( $oldest_iso ) . '"' : ''
		);

		// Date to.
		printf(
			'<label class="screen-reader-text" for="brl-filter-date-to">%s</label>',
			\esc_html__( 'To', 'better-rest-api-logs' )
		);
		printf(
			'<input type="date" id="brl-filter-date-to" name="date_to" value="%s" aria-label="%s"%s>',
			\esc_attr( $this->micros_to_date( null !== $args->date_to_micros ? (string) $args->date_to_micros : '' ) ),
			\esc_attr__( 'To date', 'better-rest-api-logs' ),
			$newest_iso ? ' max="' . \esc_attr( $newest_iso ) . '"' : ''
		);

		// Method select.
		printf(
			'<label class="screen-reader-text" for="brl-filter-method">%s</label>',
			\esc_html__( 'Method', 'better-rest-api-logs' )
		);
		printf(
			'<select id="brl-filter-method" name="method"><option value="">%s</option>',
			\esc_html__( 'Any method', 'better-rest-api-logs' )
		);
		foreach ( self::METHODS as $method ) {
			printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( $method ),
				\selected( $args->method, $method, false ),
				\esc_html( $method )
			);
		}
		echo '</select>';

		// Status class select.
		printf(
			'<label class="screen-reader-text" for="brl-filter-status-class">%s</label>',
			\esc_html__( 'Status class', 'better-rest-api-logs' )
		);
		printf(
			'<select id="brl-filter-status-class" name="status_class"><option value="">%s</option>',
			\esc_html__( 'Any class', 'better-rest-api-logs' )
		);
		foreach ( self::STATUS_CLASSES as $class ) {
			printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( $class ),
				\selected( $args->status_class, $class, false ),
				\esc_html( $class )
			);
		}
		echo '</select>';

		// Route prefix text input.
		printf(
			'<label class="screen-reader-text" for="brl-filter-route">%s</label>',
			\esc_html__( 'Route prefix', 'better-rest-api-logs' )
		);
		printf(
			'<input type="text" id="brl-filter-route" name="route_prefix" value="%s" placeholder="%s">',
			\esc_attr( (string) ( $args->route_prefix ?? '' ) ),
			\esc_attr__( 'e.g. /wp/v2/* or */users/*', 'better-rest-api-logs' )
		);

		\submit_button(
			\__( 'Filter', 'better-rest-api-logs' ),
			'',
			'brl_filter',
			false,
			[ 'id' => 'brl-filter-submit' ]
		);

		echo '</div>';
	}

	/**
	 * Convert a microseconds-since-epoch string to a Y-m-d date string for date inputs.
	 *
	 * Returns empty string for empty/invalid input so the date input has no value.
	 *
	 * @param  string $micros_str Microseconds string or empty.
	 * @return string             ISO date string Y-m-d or empty.
	 */
	private function micros_to_date( string $micros_str ): string {
		if ( '' === $micros_str ) {
			return '';
		}
		if ( 1 !== \preg_match( '/^\d+$/', $micros_str ) ) {
			return '';
		}
		return \gmdate( 'Y-m-d', (int) ( (int) $micros_str / 1_000_000 ) );
	}

	/**
	 * Translate Y-m-d `date_from` / `date_to` form values into microsecond bounds
	 * for `QueryArgs::from_array()`.
	 *
	 * The filter form posts `date_from` and `date_to` as `<input type="date">`
	 * (Y-m-d) values, but QueryArgs only understands `date_from_micros` /
	 * `date_to_micros`. Without this translation the dates render but never
	 * narrow the query. Bounds are interpreted in the site's WordPress timezone
	 * (`wp_timezone()`) so an editor in UTC+5 picking "Jan 1" sees rows from
	 * their Jan 1 midnight, not the server's UTC Jan 1 midnight.
	 *
	 * `date_from` becomes the start-of-day (00:00:00.000000) in micros.
	 * `date_to` becomes inclusive end-of-day (23:59:59.999999) in micros.
	 * Malformed values are silently dropped — a typo in the URL never poisons
	 * the query, the row just goes unfiltered.
	 *
	 * @param  array<string,mixed> $input Sanitised input array (typically from $_GET).
	 * @return array<string,mixed>        Same array with date_from/date_to replaced by date_from_micros/date_to_micros.
	 */
	public static function normalize_date_inputs( array $input ): array {
		$tz = \function_exists( 'wp_timezone' ) ? \wp_timezone() : new \DateTimeZone( 'UTC' );

		if ( isset( $input['date_from'] ) && \is_string( $input['date_from'] ) && '' !== $input['date_from'] ) {
			$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $input['date_from'], $tz );
			if ( false !== $start ) {
				$input['date_from_micros'] = (string) ( $start->getTimestamp() * 1_000_000 );
			}
		}
		unset( $input['date_from'] );

		if ( isset( $input['date_to'] ) && \is_string( $input['date_to'] ) && '' !== $input['date_to'] ) {
			$end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $input['date_to'], $tz );
			if ( false !== $end ) {
				// Inclusive end of day — last microsecond.
				$end_of_day              = $end->setTime( 23, 59, 59, 999_999 );
				$input['date_to_micros'] = (string) ( $end_of_day->getTimestamp() * 1_000_000 + 999_999 );
			}
		}
		unset( $input['date_to'] );

		return $input;
	}
}
