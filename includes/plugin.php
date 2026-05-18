<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Capture;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Logger\Flusher;
use BetterRestApiLogs\Settings\Registry as SettingsRegistry;
use BetterRestApiLogs\Settings\Repository as SettingsRepository;

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
		\add_action( 'admin_init', [ $registry, 'register_with_wp' ] );
		\add_action( 'updated_option', [ $registry, 'invalidate_cache_on_option_change' ] );
		\add_action( 'added_option', [ $registry, 'invalidate_cache_on_option_change' ] );

		// Schema diagnostics — admin notice (D-23) and Site Health (D-22).
		\add_action( 'admin_notices', [ Schema::class, 'maybe_render_broken_notice' ] );
		\add_filter( 'site_status_tests', [ Schema::class, 'register_site_health_tests' ] );

		// Capture + flush pipeline (Plan 03-08).
		$capture = new Capture( null, $registry );
		\add_filter( 'rest_pre_dispatch', [ $capture, 'on_pre_dispatch' ], 9999, 3 );
		\add_filter( 'rest_post_dispatch', [ $capture, 'on_post_dispatch' ], 9999, 3 );
		// rest_request_after_callbacks fires on ALL dispatch paths including rest_do_request().
		// rest_post_dispatch only fires on full HTTP requests (serve_request) + embed/batch.
		// Both hooks call Queue::backfill — idempotent, so double-fire on HTTP path is harmless.
		\add_filter( 'rest_request_after_callbacks', [ $capture, 'on_after_callbacks' ], 9999, 3 );

		$flusher = new Flusher( null, null, null, null, null, null, null, $registry );
		\add_action( 'shutdown', [ $flusher, 'on_shutdown' ], 1 );
	}

	public function container(): Container {
		return $this->container;
	}
}
