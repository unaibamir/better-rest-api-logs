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
		$where_parts = $this->compose_where( $args );
		$where       = $where_parts['where'];
		$bindings    = $where_parts['bindings'];

		$logs_table = Database::logs_table();
		$col_list   = implode( ', ', self::COLUMNS );

		// Cursor — tuple comparison for stable keyset pagination. Only the
		// cursor-paginated read path needs this clause; build_count and
		// build_paged deliberately skip it.
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
		$sort_dir  = $args->order_dir;
		$limit_n1  = $args->limit + 1; // N+1 for has_more detection; caller slices.

		// ORDER BY is always (created_at_micros, id) — the same tuple the cursor
		// token encodes. The user-facing $args->order_by names remain whitelisted
		// in Sort::ALLOWED_COLUMNS so URLs stay stable, but the physical sort
		// must match the cursor predicate or pagination skips/repeats rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Database accessor (STOR-04); column list from private constant; sort dir from Sort::validate whitelist; user values in $bindings only.
		$sql = "SELECT {$col_list} FROM {$logs_table}{$where_sql} ORDER BY created_at_micros {$sort_dir}, id {$sort_dir} LIMIT {$limit_n1}";

		return [
			'sql'      => $sql,
			'bindings' => $bindings,
		];
	}

	/**
	 * Compose a COUNT(*) query over the same WHERE the read path uses.
	 *
	 * Used by the offset-paginated admin list — cursor pagination intentionally
	 * skips COUNT for scale. Trade-off accepted at the admin UI for predictable
	 * page numbers; sites with multi-million-row tables can stay on cursor via
	 * the REST surface.
	 *
	 * @param  QueryArgs $args Validated filter set (cursor / limit / offset ignored).
	 * @return array{sql:string,bindings:array<int,scalar>}
	 */
	public function build_count( QueryArgs $args ): array {
		$where_parts = $this->compose_where( $args );
		$where_sql   = empty( $where_parts['where'] )
			? ''
			: ' WHERE ' . implode( ' AND ', $where_parts['where'] );

		$logs_table = Database::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from Database accessor (STOR-04); WHERE composed from compose_where; values flow through bindings.
		$sql = "SELECT COUNT(*) FROM {$logs_table}{$where_sql}";

		return [
			'sql'      => $sql,
			'bindings' => $where_parts['bindings'],
		];
	}

	/**
	 * Compose the offset-paginated SELECT used by the admin list page.
	 *
	 * @param  QueryArgs $args     Validated filter set (cursor ignored).
	 * @param  int       $offset   Zero-based row offset.
	 * @param  int       $per_page Page size from Screen Options.
	 * @return array{sql:string,bindings:array<int,scalar>}
	 */
	public function build_paged( QueryArgs $args, int $offset, int $per_page ): array {
		$where_parts = $this->compose_where( $args );
		$where_sql   = empty( $where_parts['where'] )
			? ''
			: ' WHERE ' . implode( ' AND ', $where_parts['where'] );

		$logs_table = Database::logs_table();
		$col_list   = implode( ', ', self::COLUMNS );
		$sort_dir   = $args->order_dir;
		$offset     = max( 0, $offset );
		$per_page   = max( 1, $per_page );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name + column list from constants/accessor; sort dir from Sort::validate whitelist; LIMIT/OFFSET integers (clamped above); user values flow through bindings.
		$sql        = "SELECT {$col_list} FROM {$logs_table}{$where_sql} ORDER BY created_at_micros {$sort_dir}, id {$sort_dir} LIMIT %d OFFSET %d";
		$bindings   = $where_parts['bindings'];
		$bindings[] = $per_page;
		$bindings[] = $offset;

		return [
			'sql'      => $sql,
			'bindings' => $bindings,
		];
	}

	/**
	 * Compose the WHERE clause + bindings shared by all three read shapes
	 * (cursor select, count, offset select). Cursor predicates live in build()
	 * because they don't apply to count or offset pagination.
	 *
	 * @param  QueryArgs $args Validated filter set.
	 * @return array{where:list<string>,bindings:array<int,scalar>}
	 */
	private function compose_where( QueryArgs $args ): array {
		$where    = [];
		$bindings = [];

		// Method.
		if ( null !== $args->method ) {
			$where[]    = 'method = %s';
			$bindings[] = $args->method;
		}

		// Status_code exact match takes precedence over class filter.
		if ( null !== $args->status ) {
			$where[]    = 'status = %d';
			$bindings[] = $args->status;
		} elseif ( null !== $args->status_class ) {
			// Status_class is stored as TINYINT (1..5); map '1xx'..'5xx' to integer.
			$class_int  = (int) $args->status_class[0];
			$where[]    = 'status_class = %d';
			$bindings[] = $class_int;
		}

		// Route filter — supports `*` as a wildcard. With no wildcard we still
		// match as a prefix (implicit trailing `%`) to keep older bookmarks
		// working. Match the full `route` column rather than `route_prefix`
		// because patterns with mid-string wildcards can't be served by the
		// prefix index anyway.
		if ( null !== $args->route_prefix ) {
			global $wpdb;
			$raw = $args->route_prefix;
			if ( false !== strpos( $raw, '*' ) ) {
				$escaped = $wpdb->esc_like( $raw );
				$pattern = str_replace( '*', '%', $escaped );
			} else {
				$pattern = $wpdb->esc_like( $raw ) . '%';
			}
			$where[]    = 'route LIKE %s';
			$bindings[] = $pattern;
		}

		// User_id.
		if ( null !== $args->user_id ) {
			$where[]    = 'user_id = %d';
			$bindings[] = $args->user_id;
		}

		// ip — QueryArgs::from_array already validated the literal so inet_pton
		// cannot fail here on supported address forms.
		if ( null !== $args->ip ) {
			$packed = \inet_pton( $args->ip );
			if ( false !== $packed ) {
				$where[]    = 'ip_resolved = %s';
				$bindings[] = $packed;
			}
		}

		// Date range — BETWEEN when both ends provided, single-sided otherwise.
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

		// Free_text — esc_like must run before the % wrapping (RESEARCH §6 pitfall).
		if ( null !== $args->free_text ) {
			global $wpdb;
			$where[]    = 'route LIKE %s';
			$bindings[] = '%' . $wpdb->esc_like( $args->free_text ) . '%';
		}

		return [
			'where'    => $where,
			'bindings' => $bindings,
		];
	}
}
