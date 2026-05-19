<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Admin;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Query\QueryArgs;

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
		$table        = new ListTable( $repo );
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
		if ( ! \current_user_can( (string) \apply_filters( 'brl_admin_required_capability', 'manage_options', 'admin' ) ) ) {
			\wp_die( \esc_html__( 'Insufficient permissions.', 'better-rest-api-logs' ), '', [ 'response' => 403 ] );
		}

		// Silently recover from invalid filter args — a bad bookmark URL should
		// not wp_die; the table just shows unfiltered results.
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter form; no state-changing action.
			$args = QueryArgs::from_array(
				\array_map(
					static function ( $v ) {
						return \is_array( $v ) ? $v : \sanitize_text_field( \wp_unslash( (string) $v ) );
					},
					$_GET // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			$args = new QueryArgs();
		}

		$oldest_newest = $repo->oldest_newest();

		echo '<div class="wrap brl-admin">';
		\printf( '<h1>%s</h1>', \esc_html__( 'REST API Logs', 'better-rest-api-logs' ) );

		$notices->render();
		$filters_view->render( $args, $oldest_newest );

		// Bulk-action form wraps the list table so checkboxes submit to admin-post.php.
		\printf(
			'<form method="post" action="%s">',
			\esc_url( \admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="brl_bulk_action" />';
		\wp_nonce_field( 'brl_bulk', '_wpnonce' );

		$table->prepare_items();
		$table->display();

		echo '</form>';
		echo '</div>';
	}
}
