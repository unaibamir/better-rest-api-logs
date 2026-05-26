<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Admin\BulkActionHandler;
use BetterRestApiLogs\DB\LogRepository;

/**
 * Render callback for the Tools → REST API Logs admin page (D-01).
 *
 * Provides both an instance render() for use as a WP menu callback (injected
 * into Admin::register_menus) and a static render() convenience for tests.
 *
 * Capability check is defense-in-depth — WP core already enforces the cap
 * declared in add_management_page before calling this callback (T-04-34).
 *
 * @package BetterRestApiLogs\Admin
 */
final class ListScreen {

	/** @var ListTable */
	private $table;

	/** @var FiltersView */
	private $filters_view;

	/** @var Notices */
	private $notices;

	/** @var LogRepository */
	private $repo;

	/**
	 * @param ListTable     $table        List table instance.
	 * @param FiltersView   $filters_view Filter bar emitter.
	 * @param Notices       $notices      Flash notice renderer.
	 * @param LogRepository $repo         Repository for oldest/newest bounds.
	 */
	public function __construct( ListTable $table, FiltersView $filters_view, Notices $notices, LogRepository $repo ) {
		$this->table        = $table;
		$this->filters_view = $filters_view;
		$this->notices      = $notices;
		$this->repo         = $repo;
	}

	/**
	 * Render the list page HTML (instance method — used as WP callback).
	 *
	 * @return void
	 */
	public function render_page(): void {
		self::render_with( $this->table, $this->filters_view, $this->notices, $this->repo );
	}

	/**
	 * Static convenience render — used by integration tests which call
	 * ListScreen::render() directly after Plugin::boot().
	 *
	 * @return void
	 */
	public static function render(): void {
		$repo         = new LogRepository();
		$filters_view = new FiltersView();
		$notices      = new Notices();
		$table        = new ListTable( $repo, $filters_view );
		self::render_with( $table, $filters_view, $notices, $repo );
	}

	/**
	 * Core render logic shared between instance and static entry points.
	 *
	 * @param  ListTable     $table        List table instance.
	 * @param  FiltersView   $filters_view Filter bar emitter.
	 * @param  Notices       $notices      Flash notice renderer.
	 * @param  LogRepository $repo         Repository for oldest/newest bounds.
	 * @return void
	 */
	private static function render_with( ListTable $table, FiltersView $filters_view, Notices $notices, LogRepository $repo ): void {
		unset( $filters_view, $repo ); // Threaded through ListTable; locals only kept to preserve the constructor signature.

		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ), '', [ 'response' => 403 ] );
		}

		// Bulk delete is handled inline from $_GET so the whole list page can
		// live inside a single <form method="get">, the same pattern wp-admin's
		// edit.php uses. The handler still runs check_admin_referer() (which
		// reads $_REQUEST) and the capability check, so a hand-typed URL
		// without a nonce can't trigger anything destructive.
		self::maybe_handle_bulk_delete();

		echo '<div class="wrap brl-admin">';
		\printf( '<h1>%s</h1>', \esc_html__( 'REST API Logs', 'better-rest-api-logs' ) );

		$notices->render();

		echo '<form id="brl-logs-form" method="get">';
		echo '<input type="hidden" name="page" value="better-rest-api-logs" />';

		// No manual bulk nonce here. WP_List_Table::display() emits its own
		// `bulk-logs` _wpnonce inside this same form; adding a second `brl_bulk`
		// field gave the form two inputs named _wpnonce, and on a GET submit the
		// browser kept the last one — so the bulk handlers validated the wrong
		// value and died with "link expired". Both bulk handlers verify the
		// `bulk-logs` nonce the list table actually submits.

		// Preserve advanced filter state from the URL across submits — REST
		// callers or bookmarks can still pin user_id / ip / free_text even
		// though they no longer have a visible input in the tablenav row.
		self::emit_hidden_inputs_for_passthrough_filters();

		$table->prepare_items();
		$table->display();

		echo '</form>';

		// The "Export current view" control posts to admin-post.php, so its form
		// must live outside the list's GET form — a nested form is invalid HTML
		// the browser drops. Rendered here, after </form>, it is a valid sibling.
		$table->render_export_current_view();

		echo '</div>';
	}

	/**
	 * Run the inline bulk-delete dispatch when the GET form posts back with
	 * `action=brl_delete` (or the bulk-action select's `-1` placeholder for
	 * "no action"). BulkActionHandler reads $_POST for the heavy lifting,
	 * so mirror the relevant fields across.
	 *
	 * @return void
	 */
	private static function maybe_handle_bulk_delete(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- nonce verified inside BulkActionHandler::handle() via check_admin_referer; this block only mirrors GET values into $_POST so the existing handler can read them.
		$top    = isset( $_GET['action'] ) ? \sanitize_key( \wp_unslash( (string) $_GET['action'] ) ) : '';
		$bottom = isset( $_GET['action2'] ) ? \sanitize_key( \wp_unslash( (string) $_GET['action2'] ) ) : '';
		$action = '' !== $top && '-1' !== $top ? $top : $bottom;

		if ( '' === $action || '-1' === $action ) {
			return;
		}

		// Export actions are owned by handle_early_export on load-{hook}, which
		// runs before admin-header.php emits any HTML. By the time this render
		// callback runs, output has already started — streaming a download here
		// would prepend admin chrome to the file. Only delete runs from here.
		if ( \in_array( $action, [ 'brl_export_csv', 'brl_export_ndjson' ], true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is run through absint() below before any use.
		$ids_raw = isset( $_GET['log_ids'] ) && \is_array( $_GET['log_ids'] )
			? \wp_unslash( $_GET['log_ids'] )
			: [];
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids = \array_values( \array_filter( \array_map( 'absint', (array) $ids_raw ) ) );

		// BulkActionHandler reads from $_POST; mirror the GET payload over so
		// the handler logic stays in one place. $_REQUEST is read by
		// check_admin_referer() and PHP already populated it from $_GET, but
		// we set it explicitly to be safe under PHPUnit too.
		$_POST['action']      = $action;
		$_POST['bulk_action'] = $action;
		$_POST['log_ids']     = $ids;
		if ( isset( $_GET['_wpnonce'] ) ) {
			$nonce                = \sanitize_text_field( \wp_unslash( (string) $_GET['_wpnonce'] ) );
			$_POST['_wpnonce']    = $nonce;
			$_REQUEST['_wpnonce'] = $nonce;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

		( new BulkActionHandler() )->handle();
	}

	/**
	 * Emit hidden inputs for any URL params the visible filter row doesn't
	 * expose (user_id, ip, free_text, sort state, page number). Without these,
	 * applying a filter would silently drop the rest of the URL state.
	 *
	 * @return void
	 */
	private static function emit_hidden_inputs_for_passthrough_filters(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only re-emit of URL state into hidden inputs.
		foreach ( [ 'user_id', 'ip', 'free_text', 'orderby', 'order', 'status' ] as $key ) {
			if ( ! isset( $_GET[ $key ] ) || \is_array( $_GET[ $key ] ) ) {
				continue;
			}
			$raw = \sanitize_text_field( \wp_unslash( (string) $_GET[ $key ] ) );
			if ( '' === $raw ) {
				continue;
			}
			\printf(
				'<input type="hidden" name="%s" value="%s" />',
				\esc_attr( $key ),
				\esc_attr( $raw )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
