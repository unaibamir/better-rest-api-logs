<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Admin;
use BetterRestApiLogs\Admin\Assets;
use BetterRestApiLogs\Admin\BulkActionHandler;
use BetterRestApiLogs\Admin\DetailScreen;
use BetterRestApiLogs\Admin\FiltersView;
use BetterRestApiLogs\Admin\ListScreen;
use BetterRestApiLogs\Admin\ListTable;
use BetterRestApiLogs\Admin\Notices;
use BetterRestApiLogs\Admin\SettingsScreen;
use BetterRestApiLogs\Capture;
use BetterRestApiLogs\Capture\Compressor;
use BetterRestApiLogs\Cli;
use BetterRestApiLogs\Capture\Filter;
use BetterRestApiLogs\Capture\IpResolver;
use BetterRestApiLogs\Capture\Redactor;
use BetterRestApiLogs\Capture\Truncator;
use BetterRestApiLogs\DB\BodyRepository;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Logger;
use BetterRestApiLogs\Logger\Breaker;
use BetterRestApiLogs\Logger\Flusher;
use BetterRestApiLogs\Logger\Queue;
use BetterRestApiLogs\Rest;
use BetterRestApiLogs\Rest\ListController;
use BetterRestApiLogs\Rest\Routes;
use BetterRestApiLogs\Rest\SingleController;
use BetterRestApiLogs\Rest\StatsController;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Repository as SettingsRepository;
use BetterRestApiLogs\Support\Clock;

/**
 * Plugin entry-point singleton. Holds the container and wires hooks once on plugins_loaded.
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->container = new Container();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Translations: WP auto-loads them for plugins hosted on WordPress.org
		// since WP 4.6, and the manual loader was discouraged in 6.7+. No call
		// needed — the Text Domain header in better-rest-api-logs.php is enough.

		// Phase 2 — bind Settings services.
		$this->container->bind(
			SettingsRepository::class,
			static fn () => new SettingsRepository()
		);
		$this->container->bind(
			SettingsRegistry::class,
			static fn ( Container $c ) => new SettingsRegistry(
				$c->get( SettingsRepository::class )
			)
		);

		// Wire the Settings\Registry into WP hooks.
		$registry = $this->container->get( SettingsRegistry::class );
		// Closes the housekeeping concern from the capture phase verification:
		// share the in-memory cache with the static get_internal/set_internal
		// facades so Breaker and StatsController see the same snapshot.
		SettingsRegistry::set_global_instance( $registry );
		\add_action( 'admin_init', [ $registry, 'register_with_wp' ] );
		\add_action( 'updated_option', [ $registry, 'invalidate_cache_on_option_change' ] );
		\add_action( 'added_option', [ $registry, 'invalidate_cache_on_option_change' ] );

		// Schema diagnostics — admin notice (D-23) and Site Health (D-22).
		\add_action( 'admin_notices', [ Schema::class, 'maybe_render_broken_notice' ] );
		\add_filter( 'site_status_tests', [ Schema::class, 'register_site_health_tests' ] );

		// -----------------------------------------------------------------------
		// Phase 3 — Capture pipeline + Logger orchestration (D-34).
		//
		// Twelve shared container bindings cover every collaborator in the capture
		// hot path, then five hook registrations wire them to the WP lifecycle.
		// All bindings go through the container so a second get() returns the same
		// instance — the singleton-via-container shape used throughout Phase 2.
		// -----------------------------------------------------------------------

		// Shared clock — shared by both Flusher and Capture so timestamps in the
		// same request are produced by the same instance.
		$this->container->bind(
			Clock::class,
			static fn () => new Clock()
		);

		// Pure-PHP capture collaborators — no WP calls, fully unit-testable.
		$this->container->bind(
			Filter::class,
			static fn () => new Filter()
		);
		$this->container->bind(
			Redactor::class,
			static fn () => new Redactor()
		);
		$this->container->bind(
			IpResolver::class,
			static fn () => new IpResolver()
		);
		$this->container->bind(
			Truncator::class,
			static fn () => new Truncator()
		);
		$this->container->bind(
			Compressor::class,
			static fn () => new Compressor()
		);

		// Logger\Queue is fully static; the binding exists for type-resolution
		// consistency — the instance itself carries no state.
		$this->container->bind(
			Queue::class,
			static fn () => new Queue()
		);

		// Circuit breaker — depends on SettingsRegistry for get/set_internal.
		$this->container->bind(
			Breaker::class,
			static fn () => new Breaker()
		);

		// DB repositories that replaced the Phase 2 stubs (Plan 03-07).
		$this->container->bind(
			LogRepository::class,
			static fn () => new LogRepository()
		);
		$this->container->bind(
			BodyRepository::class,
			static fn () => new BodyRepository()
		);

		// Flusher — shutdown drain; depends on all eight collaborators above.
		$this->container->bind(
			Flusher::class,
			static fn ( Container $c ) => new Flusher(
				$c->get( Filter::class ),
				$c->get( Redactor::class ),
				$c->get( Truncator::class ),
				$c->get( Compressor::class ),
				$c->get( LogRepository::class ),
				$c->get( BodyRepository::class ),
				$c->get( Breaker::class ),
				$c->get( SettingsRegistry::class ),
				$c->get( Clock::class )
			)
		);

		// Logger façade — thin wrapper around Flusher + Breaker for callers that
		// want both without importing the full namespace tree.
		$this->container->bind(
			Logger::class,
			static fn ( Container $c ) => new Logger(
				$c->get( Flusher::class ),
				$c->get( Breaker::class )
			)
		);

		// Capture orchestrator — the WP-coupled entry point for REST dispatch.
		$this->container->bind(
			Capture::class,
			static fn ( Container $c ) => new Capture(
				$c->get( Filter::class ),
				$c->get( SettingsRegistry::class ),
				$c->get( Clock::class )
			)
		);

		// Resolve shared instances and register the five Phase 3 WP hooks.
		$capture = $this->container->get( Capture::class );
		$flusher = $this->container->get( Flusher::class );
		$breaker = $this->container->get( Breaker::class );

		// Priority 9999 on both dispatch hooks — observe the final accumulated
		// response value (D-01, D-02, D-02b). Passthrough discipline: every
		// callback returns its first arg unchanged so we never mutate the response.
		\add_filter( 'rest_pre_dispatch', [ $capture, 'on_pre_dispatch' ], 9999, 3 );
		\add_filter( 'rest_post_dispatch', [ $capture, 'on_post_dispatch' ], 9999, 3 );

		// rest_request_after_callbacks covers the rest_do_request() path that
		// skips rest_post_dispatch. Queue::backfill is idempotent so double-fire
		// on the full HTTP path is harmless.
		\add_filter( 'rest_request_after_callbacks', [ $capture, 'on_after_callbacks' ], 9999, 3 );

		// Shutdown at priority 1 — drain happens before most other handlers; the
		// response is already gone when this fires (D-06).
		\add_action( 'shutdown', [ $flusher, 'on_shutdown' ], 1 );

		// Breaker admin-surface hooks (T-03-41: these pages are authenticated-only).
		\add_action( 'admin_notices', [ $breaker, 'maybe_render_armed_notice' ] );
		\add_filter( 'site_status_tests', [ $breaker, 'register_site_health_test' ] );

		// -----------------------------------------------------------------------
		// Phase 4 — Admin UI (D-55).
		//
		// Container bindings are unconditional, but resolution is deferred into
		// the hook callbacks below. WP_List_Table::__construct calls
		// convert_to_screen() (declared in wp-admin/includes/template.php), and
		// template.php is only loaded by wp-admin/admin.php. Resolving the
		// admin chain eagerly on plugins_loaded would fatal on wp-login.php,
		// the front-end, and any other non-admin request.
		// -----------------------------------------------------------------------
		$this->container->bind(
			Assets::class,
			static fn () => new Assets()
		);
		$this->container->bind(
			Notices::class,
			static fn () => new Notices()
		);
		$this->container->bind(
			FiltersView::class,
			static fn () => new FiltersView()
		);
		$this->container->bind(
			ListTable::class,
			static function ( Container $c ): ListTable {
				// WP_List_Table is only loaded by wp-admin/includes/admin.php on
				// admin requests. Boot can run from the front-end or REST while
				// resolving the admin chain (Admin -> ListScreen -> ListTable),
				// so pull the parent class in here to avoid a fatal during class
				// autoload of ListTable on non-admin requests.
				if ( ! \class_exists( '\WP_List_Table' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				}
				return new ListTable(
					$c->get( LogRepository::class ),
					$c->get( FiltersView::class )
				);
			}
		);
		$this->container->bind(
			ListScreen::class,
			static fn ( Container $c ) => new ListScreen(
				$c->get( ListTable::class ),
				$c->get( FiltersView::class ),
				$c->get( Notices::class ),
				$c->get( LogRepository::class )
			)
		);
		$this->container->bind(
			DetailScreen::class,
			static fn ( Container $c ) => new DetailScreen(
				$c->get( LogRepository::class )
			)
		);
		$this->container->bind(
			Admin::class,
			static fn ( Container $c ) => new Admin(
				$c->get( ListScreen::class ),
				$c->get( DetailScreen::class ),
				$c->get( Assets::class )
			)
		);

		$this->container->bind(
			BulkActionHandler::class,
			static fn ( Container $c ) => new BulkActionHandler(
				$c->get( LogRepository::class )
			)
		);

		$container = $this->container;

		\add_action(
			'admin_menu',
			static function () use ( $container ): void {
				$container->get( Admin::class )->register_menus();
			},
			10
		);
		\add_action(
			'current_screen',
			static function ( $screen ) use ( $container ): void {
				$container->get( Assets::class )->maybe_enqueue( $screen );
			},
			10
		);
		\add_filter(
			'admin_body_class',
			static function ( $classes ) use ( $container ): string {
				return $container->get( Assets::class )->add_body_class( $classes );
			},
			10
		);

		// Settings page + bulk delete handlers. SettingsScreen is static — no
		// container binding needed; WP calls the methods directly.
		\add_action( 'admin_menu', [ SettingsScreen::class, 'register_menu' ], 10 );
		\add_action( 'admin_init', [ SettingsScreen::class, 'register_fields' ], 10 );
		\add_action( 'admin_post_brl_truncate_all', [ SettingsScreen::class, 'handle_truncate_all' ] );

		// Bulk delete is dispatched inline from ListScreen::render() now that the
		// list page lives inside a single <form method="get">. The single-row
		// "Delete" row action still posts to admin-post.php with a per-row nonce.
		\add_action(
			'admin_post_brl_delete_log',
			static function () use ( $container ): void {
				$container->get( BulkActionHandler::class )->handle_single_delete();
			}
		);

		// -----------------------------------------------------------------------
		// Phase 4 — REST surface.
		//
		// Three controllers + Routes registrar. LogRepository and
		// SettingsRegistry are already bound above.
		// -----------------------------------------------------------------------
		$this->container->bind(
			ListController::class,
			static fn ( Container $c ) => new ListController(
				$c->get( LogRepository::class )
			)
		);
		$this->container->bind(
			SingleController::class,
			static fn ( Container $c ) => new SingleController(
				$c->get( LogRepository::class )
			)
		);
		$this->container->bind(
			StatsController::class,
			static fn ( Container $c ) => new StatsController(
				$c->get( LogRepository::class ),
				$c->get( SettingsRegistry::class )
			)
		);
		$this->container->bind(
			Routes::class,
			static fn ( Container $c ) => new Routes(
				$c->get( ListController::class ),
				$c->get( SingleController::class ),
				$c->get( StatsController::class )
			)
		);
		$this->container->bind(
			Rest::class,
			static fn ( Container $c ) => new Rest(
				$c->get( Routes::class )
			)
		);

		$rest = $this->container->get( Rest::class );
		\add_action( 'rest_api_init', [ $rest, 'register_routes' ], 10 );

		// WP-CLI surface — only register when running under WP_CLI so the admin
		// stack does not pay the cost. The five `wp better-logs` verbs handle
		// their own defense-in-depth capability checks. `constant()` is used
		// instead of a bare `\WP_CLI` lookup so the truthy runtime value is
		// honoured even when static analysis sees the stub's placeholder.
		if ( \defined( 'WP_CLI' ) && \constant( 'WP_CLI' ) ) {
			Cli::register();
		}
	}

	public function container(): Container {
		return $this->container;
	}
}
