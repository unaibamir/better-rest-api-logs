<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\Database;

/**
 * Composes the read SQL for the logs table from a validated QueryArgs object.
 *
 * Single SQL-composition site for the entire read path — three consumers
 * (Admin, REST, CLI) share one query shape so bugs fix in one place.
 * Every user-supplied value flows through $wpdb->prepare via the returned
 * $bindings array; no user input is ever concatenated into the SQL string.
 */
final class QueryBuilder {

	/**
	 * Columns fetched on every SELECT — the id column is added first for hydration.
	 *
	 * Matches Entry::from_array() expectations plus the id primary key.
	 * Keep in sync with LogRepository::COLUMNS (write path) so hydration
	 * never sees a column the INSERT didn't track.
	 *
	 * @var string[]
	 */
	private const COLUMNS = [
		'id',
		'created_at',
		'created_at_micros',
		'method',
		'route',
		'route_prefix',
		'query_string',
		'status',
		'status_class',
		'duration_ms',
		'user_id',
		'ip_resolved',
		'ip_raw_remote',
		'request_content_type',
		'request_headers',
		'request_body',
		'request_body_bytes',
		'request_body_truncated',
		'response_content_type',
		'response_headers',
		'response_body',
		'response_body_bytes',
		'response_body_truncated',
		'bodies_spilled',
		'migration_source_id',
	];

	/**
	 * Compose a $wpdb->prepare-ready SQL string + bindings array from a QueryArgs.
	 *
	 * Table name comes from Database::logs_table() (never $wpdb->prefix interpolation).
	 * Sort column + direction come from QueryArgs->order_by / order_dir, already gated
	 * by Sort::validate in QueryArgs::from_array. User values flow through $bindings only.
	 *
	 * @param  QueryArgs $args Validated filter + pagination arguments.
	 * @return array{sql:string,bindings:array<int,scalar>}
	 */
	public function build( QueryArgs $args ): array {
		$logs_table = Database::logs_table();
		$col_list   = implode( ', ', self::COLUMNS );
		$where      = [];
		$bindings   = [];

		// method
		if ( null !== $args->method ) {
			$where[]    = 'method = %s';
			$bindings[] = $args->method;
		}

		// status_code exact match takes precedence over class filter
		if ( null !== $args->status ) {
			$where[]    = 'status = %d';
			$bindings[] = $args->status;
		} elseif ( null !== $args->status_class ) {
			// status_class is stored as TINYINT (1..5); map '1xx'..'5xx' to integer
			$class_int  = (int) $args->status_class[0];
			$where[]    = 'status_class = %d';
			$bindings[] = $class_int;
		}

		// route_prefix — exact match leverages the route_prefix index
		if ( null !== $args->route_prefix ) {
			$where[]    = 'route_prefix = %s';
			$bindings[] = $args->route_prefix;
		}

		// user_id
		if ( null !== $args->user_id ) {
			$where[]    = 'user_id = %d';
			$bindings[] = $args->user_id;
		}

		// ip — pre-validate with filter_var so inet_pton never sees malformed input.
		// CLAUDE.md "No @ on built-ins" — filter_var guard eliminates the warning path.
		if ( null !== $args->ip && \filter_var( $args->ip, FILTER_VALIDATE_IP ) ) {
			$packed = \inet_pton( $args->ip );
			if ( false !== $packed ) {
				$where[]    = 'ip_resolved = %s';
				$bindings[] = $packed;
			}
		}

		// date range — BETWEEN when both ends provided, single-sided otherwise
		if ( null !== $args->date_from_micros && null !== $args->date_to_micros ) {
			$where[]    = 'created_at_micros BETWEEN %d AND %d';
			$bindings[] = $args->date_from_micros;
			$bindings[] = $args->date_to_micros;
		} elseif ( null !== $args->date_from_micros ) {
			$where[]    = 'created_at_micros >= %d';
			$bindings[] = $args->date_from_micros;
		} elseif ( null !== $args->date_to_micros ) {
			$where[]    = 'created_at_micros <= %d';
			$bindings[] = $args->date_to_micros;
		}

		// free_text — esc_like must run before the % wrapping (RESEARCH §6 pitfall)
		if ( null !== $args->free_text ) {
			global $wpdb;
			$where[]    = 'route LIKE %s';
			$bindings[] = '%' . $wpdb->esc_like( $args->free_text ) . '%';
		}

		// cursor — tuple comparison for stable keyset pagination.
		// Cursor stores created_at_micros; direction determines walk direction.
		// Throws \InvalidArgumentException on malformed token — propagated to search().
		if ( null !== $args->cursor ) {
			$point = Paginator::decode( $args->cursor );
			$cmp   = ( 'DESC' === $args->order_dir ) ? '<' : '>';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- comparison operator is one of '<' or '>' from a ternary on a const; not user input.
			$where[]    = "( created_at_micros {$cmp} %d OR ( created_at_micros = %d AND id {$cmp} %d ) )";
			$bindings[] = $point['micros'];
			$bindings[] = $point['micros'];
			$bindings[] = $point['id'];
		}

		$where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
		$sort_col  = $args->order_by;  // Sort::validate already gated this
		$sort_dir  = $args->order_dir;
		$limit_n1  = $args->limit + 1; // N+1 for has_more detection; caller slices

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Database accessor (STOR-04); column list from private constant; sort col + dir from Sort::validate whitelist; user values in $bindings only.
		$sql = "SELECT {$col_list} FROM {$logs_table}{$where_sql} ORDER BY {$sort_col} {$sort_dir}, id {$sort_dir} LIMIT {$limit_n1}";

		return [ 'sql' => $sql, 'bindings' => $bindings ];
	}
}
