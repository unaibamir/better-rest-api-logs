<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin text domain at the right moment.
 */
final class I18n {

	public function register(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	public function load_textdomain(): void {
		// WP 6.6+ requires text-domain loading on `init`; earlier hooks trigger doing_it_wrong.
		load_plugin_textdomain(
			'better-rest-api-logs',
			false,
			dirname( BRL_BASENAME ) . '/languages'
		);
	}
}
