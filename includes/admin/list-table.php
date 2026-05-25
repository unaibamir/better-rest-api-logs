<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Admin\FiltersView;

/**
 * WP_List_Table subclass for the REST API log list page.
 *
 * Uses standard WP pagination — page numbers + Screen Options per_page (default 50).
 * REST and CLI surfaces still expose cursor pagination via LogRepository::search();
 * the admin trades the COUNT(*) scan for a familiar pager.
 *
 * Status pill renders color + Unicode glyph + screen-reader label (A11Y-02).
 * I18N-03: status labels are literal __() calls so wp-i18n make-pot sees them.
 *
 * @package BetterRestApiLogs\Admin
 */
final class ListTable extends \WP_List_Table {

	/** @var int Default page size when the user has not picked one via Screen Options. */
	public const DEFAULT_PER_PAGE = 50;

	/** @var string Screen Options key under which per_page is stored. */
	public const PER_PAGE_OPTION = 'better_rest_api_logs_per_page';

	/** @var LogRepository */
	private $repo;

	/** @var FiltersView|null */
	private $filters_view = null;

	/** @var QueryArgs|null Cached filter state from prepare_items(), reused by extra_tablenav(). */
	private $current_args = null;

	/** @var array{oldest:string|null,newest:string|null} */
	private $oldest_newest = [
		'oldest' => null,
		'newest' => null,
	];


	/**
	 * @param LogRepository    $repo         Data access layer.
	 * @param FiltersView|null $filters_view Optional filter renderer used by extra_tablenav.
	 */
	public function __construct( LogRepository $repo, ?FiltersView $filters_view = null ) {
		parent::__construct(
			[
				'singular' => 'log',
				'plural'   => 'logs',
				'ajax'     => false,
			]
		);
		$this->repo         = $repo;
		$this->filters_view = $filters_view;
	}

	/**
	 * Column definitions for the list table (D-10 / UI-02).
	 *
	 * Filterable via brl_list_columns so extensions can add or reorder.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		$columns = [
			'cb'            => '<input type="checkbox" />',
			'route'         => \esc_html__( 'Route', 'better-rest-api-logs' ),
			'method'        => \esc_html__( 'Method', 'better-rest-api-logs' ),
			'status'        => \esc_html__( 'Status', 'better-rest-api-logs' ),
			'ip'            => \esc_html__( 'IP', 'better-rest-api-logs' ),
			'duration'      => \esc_html__( 'Duration', 'better-rest-api-logs' ),
			'response_size' => \esc_html__( 'Response Size', 'better-rest-api-logs' ),
			'user'          => \esc_html__( 'User', 'better-rest-api-logs' ),
			'timestamp'     => \esc_html__( 'Time', 'better-rest-api-logs' ),
		];
		return (array) \apply_filters( 'brl_list_columns', $columns );
	}

	/**
	 * Sortable columns — whitelist mirrors Sort::ALLOWED_COLUMNS (D-43).
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function get_sortable_columns(): array {
		return [
			'route'     => [ 'route', false ],
			'method'    => [ 'method', false ],
			'status'    => [ 'status', false ],
			'duration'  => [ 'duration_ms', false ],
			'timestamp' => [ 'created_at_micros', true ],  // Default sort is DESC.
		];
	}

	/**
	 * Bulk actions available on the list table.
	 *
	 * Export bulk actions route to admin-post.php?action=brl_export via the
	 * handle() dispatch so the streaming download can complete before shutdown.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions(): array {
		return [
			'brl_delete'        => \esc_html__( 'Delete selected', 'better-rest-api-logs' ),
			'brl_export_csv'    => \esc_html__( 'Export selected (CSV)', 'better-rest-api-logs' ),
			'brl_export_ndjson' => \esc_html__( 'Export selected (NDJSON)', 'better-rest-api-logs' ),
		];
	}

	/**
	 * Inject the filter controls and the "Export current view" control into the
	 * top tablenav row.
	 *
	 * WP_List_Table::display_tablenav() emits this method's output right after
	 * the bulk-actions <select> + Apply button, so the date / method / status /
	 * route_prefix inputs sit on the same row.
	 *
	 * The export control is its own mini-form posting to admin-post.php so the
	 * active QueryArgs filter set flows through as hidden inputs — EXP-04 is a
	 * free consequence rather than extra serialization logic.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || null === $this->filters_view || null === $this->current_args ) {
			return;
		}
		$this->filters_view->render_inline( $this->current_args, $this->oldest_newest );
		$this->render_export_current_view();
	}

	/**
	 * Render the "Export current view" control appended to the top tablenav row.
	 *
	 * Posts to admin-post.php (action=brl_export) with the active QueryArgs
	 * serialised as hidden inputs so the export honours the active filter exactly.
	 * The button is disabled — with accessible title + screen-reader text — when
	 * zero rows match the active filters (UI-SPEC §4, A11Y-03).
	 *
	 * @return void
	 */
	private function render_export_current_view(): void {
		$total    = $this->get_pagination_arg( 'total_items' );
		$disabled = ( (int) $total <= 0 ) ? ' disabled' : '';
		$nonce    = \wp_create_nonce( 'brl_export' );

		$action_url = \admin_url( 'admin-post.php' );

		echo '<span class="brl-export-current">';

		// Visually hidden label for the format selector (A11Y-03).
		\printf(
			'<label for="brl-export-format" class="screen-reader-text">%s</label>',
			\esc_html__( 'Export format', 'better-rest-api-logs' )
		);

		\printf( '<form method="post" action="%s" style="display:inline;">', \esc_url( $action_url ) );

		echo '<input type="hidden" name="action" value="brl_export" />';
		\printf( '<input type="hidden" name="_wpnonce" value="%s" />', \esc_attr( $nonce ) );

		// Replay active filter state so the export honours the current view (EXP-04).
		if ( null !== $this->current_args ) {
			foreach ( $this->current_args->to_query_var_array() as $key => $value ) {
				\printf(
					'<input type="hidden" name="%s" value="%s" />',
					\esc_attr( (string) $key ),
					\esc_attr( (string) $value )
				);
			}
		}

		// Format selector — NDJSON first (recommended default, formula-safe by construction).
		echo '<select id="brl-export-format" name="format">';
		\printf( '<option value="ndjson">%s</option>', \esc_html__( 'NDJSON', 'better-rest-api-logs' ) );
		\printf( '<option value="csv">%s</option>', \esc_html__( 'CSV', 'better-rest-api-logs' ) );
		echo '</select>';

		if ( '' !== $disabled ) {
			\printf(
				'<button type="submit" class="button button-primary"%s title="%s">%s</button>',
				\esc_attr( $disabled ),
				\esc_attr__( 'No rows match the current filters.', 'better-rest-api-logs' ),
				\esc_html__( 'Export current view', 'better-rest-api-logs' )
			);
			\printf(
				'<span class="screen-reader-text">%s</span>',
				\esc_html__( 'No rows to export.', 'better-rest-api-logs' )
			);
		} else {
			\printf(
				'<button type="submit" class="button button-primary">%s</button>',
				\esc_html__( 'Export current view', 'better-rest-api-logs' )
			);
		}

		echo '</form>';
		echo '</span>';
	}

	/**
	 * Execute the DB query and populate $this->items from the filter state.
	 *
	 * Reads $_GET (sanitised via collect_input) and builds QueryArgs. On invalid
	 * filter values (e.g. bad cursor token) we recover gracefully by falling back
	 * to default args instead of wp_die-ing — the filter form will just reset.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		try {
			$args = QueryArgs::from_array( $this->collect_input() );
		} catch ( \InvalidArgumentException $_unused ) {
			$args = new QueryArgs();
		}

		$args = \apply_filters( 'brl_query_args', $args, 'admin' );

		$this->current_args  = $args;
		$this->oldest_newest = $this->repo->oldest_newest();

		$per_page    = self::resolve_per_page();
		$current     = \max( 1, (int) $this->get_pagenum() );
		$offset      = ( $current - 1 ) * $per_page;
		$total_items = $this->repo->count( $args );

		$this->items = $this->repo->search_paged( $args, $offset, $per_page );

		$this->_column_headers = [
			$this->get_columns(),
			[],                          // Hidden columns.
			$this->get_sortable_columns(),
			'route',                     // Primary column hosts the row actions.
		];

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) \ceil( $total_items / $per_page ),
			]
		);
	}

	/**
	 * Read per_page from Screen Options (clamped), falling back to the default.
	 *
	 * Exposed as a static helper so ListScreen can register a matching
	 * `screen_settings` panel without depending on a constructed ListTable.
	 *
	 * @return int Effective rows-per-page.
	 */
	public static function resolve_per_page(): int {
		$user     = \function_exists( 'get_current_user_id' ) ? \get_current_user_id() : 0;
		$stored   = $user > 0 ? (int) \get_user_meta( $user, self::PER_PAGE_OPTION, true ) : 0;
		$per_page = $stored > 0 ? $stored : self::DEFAULT_PER_PAGE;

		// Upper clamp keeps the COUNT-paged query honest if someone hand-edits user_meta.
		return $per_page > 500 ? 500 : $per_page;
	}

	/**
	 * Checkbox column.
	 *
	 * @param Entry $item Current row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="log_ids[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Default column renderer — handles every column not given its own method.
	 *
	 * @param Entry  $item        Current row.
	 * @param string $column_name Column slug.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'timestamp':
				return $this->render_timestamp_column( $item );
			case 'method':
				return $this->render_method_pill( (string) $item->method );
			case 'status':
				$class = (string) ( $item->status_class ) . 'xx';
				return $this->render_status_pill( (int) $item->status, $class );
			case 'route':
				return $this->render_route_column( $item );
			case 'duration':
				return \esc_html( \sprintf( '%d ms', (int) $item->duration_ms ) );
			case 'response_size':
				return $this->render_response_size_column( $item );
			case 'user':
				return $this->render_user_column( $item );
			case 'ip':
				return $this->render_ip_column( $item );
		}
		return '';
	}

	/**
	 * Render the timestamp column — plain text only. Row actions now live on
	 * the primary `route` cell.
	 *
	 * @param  Entry $item Current row.
	 * @return string
	 */
	private function render_timestamp_column( Entry $item ): string {
		$micros = (int) $item->created_at_micros;
		$ts     = $micros > 0
			? \gmdate( 'Y-m-d H:i:s', (int) ( $micros / 1_000_000 ) )
			: (string) $item->created_at;

		return \esc_html( $ts );
	}

	/**
	 * Render the route column with a link to the detail view and the
	 * View / Delete row actions. WP convention puts row actions on the
	 * primary column; that's also what the column-headers configuration
	 * declares (see prepare_items()).
	 *
	 * @param  Entry $item Current row.
	 * @return string
	 */
	private function render_route_column( Entry $item ): string {
		$view_url = \add_query_arg(
			[
				'page'   => 'better-rest-api-logs-detail',
				'log_id' => (int) $item->id,
			],
			\admin_url( 'tools.php' )
		);

		$delete_url = \wp_nonce_url(
			\add_query_arg(
				[
					'action' => 'brl_delete_log',
					'log_id' => (int) $item->id,
				],
				\admin_url( 'admin-post.php' )
			),
			'brl_delete_' . (int) $item->id,
			'_wpnonce'
		);

		$confirm_msg = \esc_js(
			\sprintf(
				/* translators: %d: log entry id */
				\__( 'Delete log #%d? This cannot be undone.', 'better-rest-api-logs' ),
				(int) $item->id
			)
		);

		$route_link = \sprintf(
			'<strong><a href="%s">%s</a></strong>',
			\esc_url( $view_url ),
			\esc_html( (string) $item->route )
		);

		$actions = [
			'view'   => \sprintf(
				'<span class="view"><a href="%s">%s</a></span>',
				\esc_url( $view_url ),
				\esc_html__( 'View', 'better-rest-api-logs' )
			),
			'delete' => \sprintf(
				'<span class="delete"><a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a></span>',
				\esc_url( $delete_url ),
				$confirm_msg,
				\esc_html__( 'Delete', 'better-rest-api-logs' )
			),
		];

		return $route_link . $this->row_actions( $actions );
	}

	/**
	 * Render the response-size column. Stored as an integer byte count; we
	 * pick the right unit so a 256-byte response reads as "256 B" rather
	 * than "0.3 KB", and bigger payloads roll up into MB / GB cleanly.
	 *
	 * @param  Entry $item Current row.
	 * @return string
	 */
	private function render_response_size_column( Entry $item ): string {
		$bytes = (int) $item->response_body_bytes;
		if ( $bytes <= 0 ) {
			return '&mdash;';
		}
		return \esc_html( self::format_bytes( $bytes ) );
	}

	/**
	 * Format a byte count using the IEC-ish progression most admins expect:
	 * bytes under 1 KiB stay as B, then KB, MB, GB with one decimal place.
	 * Kept as a pure static helper so unit tests can exercise the edges
	 * without bootstrapping WordPress.
	 *
	 * @param  int $bytes Non-negative byte count.
	 * @return string
	 */
	public static function format_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return \sprintf( '%d B', $bytes );
		}
		$kb = $bytes / 1024;
		if ( $kb < 1024 ) {
			return \sprintf( '%.1f KB', $kb );
		}
		$mb = $kb / 1024;
		if ( $mb < 1024 ) {
			return \sprintf( '%.1f MB', $mb );
		}
		return \sprintf( '%.1f GB', $mb / 1024 );
	}

	/**
	 * Render the user column. Authenticated rows link to user-edit.php with
	 * the captured login; guests show as a literal "(guest)". Deleted users
	 * (where get_userdata returns false) degrade to "User #N" so the row
	 * still tells you which id was active.
	 *
	 * Performance note: this fires one get_userdata() per row. Per-page is
	 * capped via Screen Options so the worst case is ~500 lookups per page;
	 * a future commit could prefetch in bulk if it ever shows up in profiles.
	 *
	 * @param  Entry $item Current row.
	 * @return string
	 */
	private function render_user_column( Entry $item ): string {
		$uid = (int) $item->user_id;
		if ( $uid <= 0 ) {
			return \esc_html__( '(guest)', 'better-rest-api-logs' );
		}

		$user = \get_userdata( $uid );
		if ( false === $user ) {
			return \esc_html(
				\sprintf(
				/* translators: %d: user ID */
					\__( 'User #%d', 'better-rest-api-logs' ),
					$uid
				)
			);
		}

		$edit_url = \add_query_arg( 'user_id', $uid, \admin_url( 'user-edit.php' ) );
		return \sprintf(
			'<a href="%s">%s</a>',
			\esc_url( $edit_url ),
			\esc_html( (string) $user->user_login )
		);
	}

	/**
	 * Render the IP column. Prefer the resolved IP and fall back to the raw
	 * remote address. inet_ntop returns IPv4 form already for native v4
	 * addresses, but ::ffff:127.0.0.1 (IPv4-mapped IPv6) prints as
	 * ::ffff:127.0.0.1 on some platforms; strip the prefix manually so the
	 * column reads consistently.
	 *
	 * @param  Entry $item Current row.
	 * @return string
	 */
	private function render_ip_column( Entry $item ): string {
		$packed = ( null !== $item->ip_resolved && '' !== $item->ip_resolved )
			? $item->ip_resolved
			: $item->ip_raw_remote;
		if ( null === $packed || '' === $packed ) {
			return '&mdash;';
		}
		$printable = \inet_ntop( $packed );
		if ( false === $printable || '' === $printable ) {
			return '&mdash;';
		}
		// IPv4-mapped IPv6 prefix — show the v4 form for readability.
		if ( 0 === \strncasecmp( $printable, '::ffff:', 7 ) ) {
			$printable = \substr( $printable, 7 );
		}
		return \esc_html( $printable );
	}

	/**
	 * Status pill — solid-fill color block with the numeric code. The
	 * accessible name combines the code and a class-specific label
	 * (`200 Success`, `404 Client error`) so screen readers announce both.
	 *
	 * The class name derives entirely from the server-side status_class
	 * column, validated at Phase 2 insertion; no user bytes flow into the
	 * pill HTML.
	 *
	 * @param  int    $code  HTTP status code.
	 * @param  string $class '1xx'..'5xx' — derived from DB column.
	 * @return string
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- the parameter mirrors the DB column name (`status_class`); renaming would drift the schema vocabulary.
	private function render_status_pill( int $code, string $class ): string {
		switch ( $class ) {
			case '1xx':
				$label = \__( 'Informational', 'better-rest-api-logs' );
				break;
			case '2xx':
				$label = \__( 'Success', 'better-rest-api-logs' );
				break;
			case '3xx':
				$label = \__( 'Redirect', 'better-rest-api-logs' );
				break;
			case '4xx':
				$label = \__( 'Client error', 'better-rest-api-logs' );
				break;
			case '5xx':
				$label = \__( 'Server error', 'better-rest-api-logs' );
				break;
			default:
				$label = \__( 'Unknown', 'better-rest-api-logs' );
				break;
		}

		return \sprintf(
			'<span class="brl-pill brl-pill--status brl-pill--status-%s" aria-label="%s">%d</span>',
			\esc_attr( $class ),
			\esc_attr( \sprintf( '%d %s', $code, $label ) ),
			$code
		);
	}

	/**
	 * Method pill — solid-fill color block with the HTTP verb.
	 *
	 * The modifier class is derived from lower-cased method; falls back to
	 * a neutral pill for anything outside the documented method list.
	 *
	 * @param  string $method HTTP method (uppercase as captured).
	 * @return string
	 */
	private function render_method_pill( string $method ): string {
		$slug = \strtolower( $method );
		if ( ! \in_array( $slug, [ 'get', 'post', 'put', 'patch', 'delete', 'head', 'options' ], true ) ) {
			$slug = 'other';
		}
		return \sprintf(
			'<span class="brl-pill brl-pill--method brl-pill--method-%s">%s</span>',
			\esc_attr( $slug ),
			\esc_html( $method )
		);
	}

	/**
	 * Empty state markup (UI-SPEC).
	 *
	 * @return void
	 */
	public function no_items(): void {
		$reset_url = \admin_url( 'tools.php?page=better-rest-api-logs' );
		echo '<div class="brl-empty-state">';
		\printf( '<p class="brl-empty-state__heading">%s</p>', \esc_html__( 'No logs match these filters.', 'better-rest-api-logs' ) );
		\printf(
			'<p class="brl-empty-state__body">%s <a href="%s" class="brl-reset-link">%s</a>.</p>',
			\esc_html__( 'Try widening the date range, picking a less specific route prefix, or', 'better-rest-api-logs' ),
			\esc_url( $reset_url ),
			\esc_html__( 'reset filters', 'better-rest-api-logs' )
		);
		echo '</div>';
	}

	/**
	 * Sanitise $_GET values at the filter-form boundary.
	 *
	 * Calls wp_unslash before sanitize_text_field per wordpress-safety.md rules.
	 * Read-only narrowing form — no state-changing action.
	 *
	 * @return array<string,mixed>
	 */
	private function collect_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter form; no state-changing action.
		$raw       = $_GET;
		$sanitised = \array_map(
			static function ( $v ) {
				if ( \is_array( $v ) ) {
					return $v;
				}
				return \sanitize_text_field( \wp_unslash( (string) $v ) );
			},
			$raw
		);

		// WP_List_Table emits `orderby` / `order` on the sort-column links;
		// translate into the order_by / order_dir vocabulary QueryArgs uses.
		if ( isset( $sanitised['orderby'] ) && ! isset( $sanitised['order_by'] ) ) {
			$sanitised['order_by'] = $sanitised['orderby'];
		}
		if ( isset( $sanitised['order'] ) && ! isset( $sanitised['order_dir'] ) ) {
			$sanitised['order_dir'] = $sanitised['order'];
		}

		// Translate Y-m-d date_from / date_to into the micros bounds QueryArgs consumes.
		return FiltersView::normalize_date_inputs( $sanitised );
	}
}
