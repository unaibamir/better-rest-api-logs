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

	/** @var string[] Status classes for radio chips. */
	private const STATUS_CLASSES = [ '1xx', '2xx', '3xx', '4xx', '5xx' ];

	/**
	 * Emit the filter bar HTML.
	 *
	 * @param QueryArgs          $args          Current filter state derived from $_GET.
	 * @param array{oldest:string|null,newest:string|null} $oldest_newest Date range bounds from the log table.
	 * @return void
	 */
	public function render( QueryArgs $args, array $oldest_newest ): void {
		$page_slug  = 'better-rest-api-logs';
		$back_url   = \esc_url( \admin_url( 'tools.php?page=' . $page_slug ) );
		$oldest_iso = isset( $oldest_newest['oldest'] ) ? $this->micros_to_date( $oldest_newest['oldest'] ) : '';
		$newest_iso = isset( $oldest_newest['newest'] ) ? $this->micros_to_date( $oldest_newest['newest'] ) : '';

		echo '<form method="get" class="brl-filter-bar">';
		printf( '<input type="hidden" name="page" value="%s">', \esc_attr( $page_slug ) );
		echo '<div class="brl-filter-bar__row">';

		// Method select.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'Method', 'better-rest-api-logs' ) );
		printf( '<select name="method"><option value="">%s</option>', \esc_html__( 'Any method', 'better-rest-api-logs' ) );
		foreach ( self::METHODS as $method ) {
			printf(
				'<option value="%s"%s>%s</option>',
				\esc_attr( $method ),
				\selected( $args->method, $method, false ),
				\esc_html( $method )
			);
		}
		echo '</select></label>';

		// Status code text input.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'Status code', 'better-rest-api-logs' ) );
		printf(
			'<input type="text" name="status" inputmode="numeric" value="%s" placeholder="%s">',
			\esc_attr( null !== $args->status ? (string) $args->status : '' ),
			\esc_attr__( 'e.g. 200, 404', 'better-rest-api-logs' )
		);
		echo '</label>';

		// Status class radios.
		echo '<fieldset class="brl-filter-bar__status-class">';
		printf( '<legend class="screen-reader-text">%s</legend>', \esc_html__( 'Status class', 'better-rest-api-logs' ) );
		foreach ( self::STATUS_CLASSES as $class ) {
			printf(
				'<label><input type="radio" name="status_class" value="%s"%s> %s</label>',
				\esc_attr( $class ),
				\checked( $args->status_class, $class, false ),
				\esc_html( $class )
			);
		}
		echo '</fieldset>';

		// Route prefix.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'Route prefix', 'better-rest-api-logs' ) );
		printf(
			'<input type="text" name="route_prefix" value="%s" placeholder="%s">',
			\esc_attr( (string) ( $args->route_prefix ?? '' ) ),
			\esc_attr__( 'e.g. /wp/v2/', 'better-rest-api-logs' )
		);
		echo '</label>';

		// User ID.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'User ID', 'better-rest-api-logs' ) );
		printf(
			'<input type="number" name="user_id" min="0" value="%s" placeholder="%s">',
			\esc_attr( null !== $args->user_id ? (string) $args->user_id : '' ),
			\esc_attr__( 'Numeric ID', 'better-rest-api-logs' )
		);
		echo '</label>';

		// IP address.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'IP address', 'better-rest-api-logs' ) );
		printf(
			'<input type="text" name="ip" value="%s" placeholder="%s">',
			\esc_attr( (string) ( $args->ip ?? '' ) ),
			\esc_attr__( 'IPv4 or IPv6', 'better-rest-api-logs' )
		);
		echo '</label>';

		// Date from.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'From', 'better-rest-api-logs' ) );
		printf(
			'<input type="date" name="date_from" value="%s"%s>',
			\esc_attr( $this->micros_to_date( null !== $args->date_from_micros ? (string) $args->date_from_micros : '' ) ),
			$oldest_iso ? ' min="' . \esc_attr( $oldest_iso ) . '"' : ''
		);
		echo '</label>';

		// Date to.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'To', 'better-rest-api-logs' ) );
		printf(
			'<input type="date" name="date_to" value="%s"%s>',
			\esc_attr( $this->micros_to_date( null !== $args->date_to_micros ? (string) $args->date_to_micros : '' ) ),
			$newest_iso ? ' max="' . \esc_attr( $newest_iso ) . '"' : ''
		);
		echo '</label>';

		// Free-text search.
		echo '<label>';
		printf( '<span>%s</span>', \esc_html__( 'Search route', 'better-rest-api-logs' ) );
		printf(
			'<input type="text" name="free_text" value="%s" placeholder="%s">',
			\esc_attr( (string) ( $args->free_text ?? '' ) ),
			\esc_attr__( 'Substring match on /route', 'better-rest-api-logs' )
		);
		echo '</label>';

		// Submit + reset.
		printf( '<button type="submit" class="button button-primary">%s</button>', \esc_html__( 'Apply filters', 'better-rest-api-logs' ) );

		echo '</div>';
		echo '</form>';

		// Reset link — plain anchor, not a button.
		printf(
			'<a href="%s" class="brl-reset-link">%s</a>',
			$back_url,
			\esc_html__( 'Reset filters', 'better-rest-api-logs' )
		);
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
}
