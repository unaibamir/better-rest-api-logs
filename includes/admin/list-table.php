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
	 * Unicode glyph per status class (D-18).
	 *
	 * @var array<string,string>
	 */
	private static $glyphs = [
		'1xx' => "\u{2022}",          // •
		'2xx' => "\u{2713}",          // ✓
		'3xx' => "\u{2192}",          // →
		'4xx' => "\u{26A0}\u{FE0E}", // ⚠︎ text-presentation selector
		'5xx' => "\u{2715}",          // ✕
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
			'cb'        => '<input type="checkbox" />',
			'timestamp' => \esc_html__( 'Time', 'better-rest-api-logs' ),
			'method'    => \esc_html__( 'Method', 'better-rest-api-logs' ),
			'status'    => \esc_html__( 'Status', 'better-rest-api-logs' ),
			'route'     => \esc_html__( 'Route', 'better-rest-api-logs' ),
			'duration'  => \esc_html__( 'Duration', 'better-rest-api-logs' ),
			'user'      => \esc_html__( 'User', 'better-rest-api-logs' ),
			'ip'        => \esc_html__( 'IP', 'better-rest-api-logs' ),
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
			'timestamp' => [ 'created_at', true ],  // second element = currently sorted desc?
			'duration'  => [ 'duration_ms', false ],
		];
	}

	/**
	 * Bulk actions available on the list table.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions(): array {
		return [ 'brl_delete' => \esc_html__( 'Delete selected', 'better-rest-api-logs' ) ];
	}

	/**
	 * Inject the filter controls into the top tablenav row.
	 *
	 * WP_List_Table::display_tablenav() emits this method's output right after
	 * the bulk-actions <select> + Apply button, so the date / method / status /
	 * route_prefix inputs sit on the same row.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || null === $this->filters_view || null === $this->current_args ) {
			return;
		}
		$this->filters_view->render_inline( $this->current_args, $this->oldest_newest );
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
			'timestamp',                 // Primary column.
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
				return \esc_html( (string) $item->method );
			case 'status':
				$class = (string) ( $item->status_class ) . 'xx';
				return $this->render_status_pill( (int) $item->status, $class );
			case 'route':
				return \esc_html( (string) $item->route );
			case 'duration':
				return \esc_html( \sprintf( '%d ms', (int) $item->duration_ms ) );
			case 'user':
				$uid = (int) $item->user_id;
				return $uid ? \esc_html( (string) $uid ) : '&mdash;';
			case 'ip':
				$ip = ( null !== $item->ip_resolved && '' !== $item->ip_resolved )
					? \inet_ntop( $item->ip_resolved )
					: false;
				return ( false !== $ip && '' !== $ip ) ? \esc_html( $ip ) : '&mdash;';
		}
		return '';
	}

	/**
	 * Render the timestamp column with View / Delete row actions.
	 *
	 * View is always visible (not hover-reveal per UI-SPEC). Delete fires a
	 * JS confirm before following the nonce-signed URL (D-20 / T-04-33).
	 *
	 * @param  Entry $item Current row.
	 * @return string
	 */
	private function render_timestamp_column( Entry $item ): string {
		$micros = (int) $item->created_at_micros;
		$ts     = $micros > 0
			? \gmdate( 'Y-m-d H:i:s', (int) ( $micros / 1_000_000 ) )
			: \esc_html( (string) $item->created_at );

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

		$actions = [
			'view'   => sprintf(
				'<span class="view"><a href="%s">%s</a></span>',
				\esc_url( $view_url ),
				\esc_html__( 'View', 'better-rest-api-logs' )
			),
			'delete' => sprintf(
				'<span class="delete"><a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a></span>',
				\esc_url( $delete_url ),
				$confirm_msg,
				\esc_html__( 'Delete', 'better-rest-api-logs' )
			),
		];

		return \esc_html( $ts ) . $this->row_actions( $actions, true );
	}

	/**
	 * Status pill — color + glyph + screen-reader label (D-18, A11Y-02, T-04-32).
	 *
	 * Labels are literal __() calls — I18N-03 forbids variables inside __().
	 * The class name derives entirely from the server-side status_class column
	 * (validated at Phase 2 insertion). No user bytes flow into the pill HTML.
	 *
	 * @param  int    $code  HTTP status code.
	 * @param  string $class '1xx'..'5xx' — derived from DB column.
	 * @return string
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- the parameter mirrors the DB column name (`status_class`); renaming would drift the schema vocabulary.
	private function render_status_pill( int $code, string $class ): string {
		$glyph = self::$glyphs[ $class ] ?? "\u{2022}";

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
			'<span class="brl-pill brl-pill--%s" aria-hidden="false">%s %d<span class="screen-reader-text">%s</span></span>',
			\esc_attr( $class ),
			\esc_html( $glyph ),
			$code,
			\esc_html( $label )
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
		// Translate Y-m-d date_from / date_to into the micros bounds QueryArgs consumes.
		return FiltersView::normalize_date_inputs( $sanitised );
	}
}
