<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

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

		( new I18n() )->register();

		// Phase 2+ will resolve and register Hooks via the container here.
	}

	public function container(): Container {
		return $this->container;
	}
}
