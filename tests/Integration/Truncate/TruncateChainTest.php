<?php
/**
 * Integration tests for the two-step truncate chain — TRUNC-01.
 *
 * RED-bar scaffold (Wave 0): admin_post_brl_truncate_confirm and
 * admin_post_brl_truncate_all handlers do not exist until Wave 1.
 * Tests are marked incomplete with a Wave 1 reason.
 *
 * Harness traps baked in per LESSONS-LEARNED:
 *  - ob_get_level() snapshot + restore (CLAUDE.md)
 *  - $_POST→$_REQUEST mirror for check_admin_referer (#4)
 *  - add_filter('wp_redirect','__return_false',99) (#5)
 *  - admin user + wp_set_current_user in set_up (#3)
 *  - $wpdb->suppress_errors() around intentional-failure sections (#6)
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Truncate;

use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\LogRepository;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Domain\Entry;
use BetterRestApiLogs\Domain\RequestSnapshot;
use BetterRestApiLogs\Domain\ResponseSnapshot;
use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;
use WP_UnitTestCase;

/**
 * Covers the confirm interstitial (no mutation) + full truncate + stats_snapshot
 * clear that make up the two-action truncate chain (TRUNC-01).
 * All tests are incomplete pending Wave 1.
 */
final class TruncateChainTest extends WP_UnitTestCase {

	/** @var int Snapshot ob_get_level before each test. */
	private int $ob_level_before = 0;

	/** @var int Admin user ID. */
	private int $admin_id = 0;

	/** @var int Subscriber user ID (cap-denial). */
	private int $subscriber_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );

		$this->subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->admin_id      = $this->factory->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $this->admin_id );

		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::logs_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . Database::bodies_table() );
		\wp_set_current_user( 0 );
		// Restore superglobals.
		$_POST    = [];
		$_REQUEST = [];
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Insert a handful of log rows so row-count assertions are meaningful. */
	private function seed_rows( int $count = 5 ): void {
		$entries = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$req               = new RequestSnapshot();
			$req->route        = '/wp/v2/posts';
			$req->method       = 'GET';
			$req->content_type = 'application/json';

			$res               = new ResponseSnapshot();
			$res->status       = 200;
			$res->status_class = 2;
			$res->content_type = 'application/json';

			$entries[] = Entry::from_snapshots(
				$req,
				$res,
				[
					'created_at' => \gmdate( 'Y-m-d H:i:s' ),
				]
			);
		}
		( new LogRepository() )->insert_batch( $entries );
	}

	/**
	 * Set up a nonce in $_POST and mirror it to $_REQUEST so check_admin_referer
	 * passes. Mirrors Lesson #4.
	 */
	private function prime_nonce( string $action ): void {
		$nonce                = \wp_create_nonce( $action );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * TRUNC-01 confirm step: admin_post_brl_truncate_confirm renders the
	 * wp_die() interstitial without mutating any rows.
	 *
	 * Filter token: Truncate
	 */
	public function test_truncate_confirm_renders_interstitial_without_mutation_Truncate(): void {
		global $wpdb;
		$this->seed_rows( 5 );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );

		$this->prime_nonce( 'brl_truncate_confirm' );
		\add_filter( 'wp_redirect', '__return_false', 99 );

		$die_message = '';
		\add_filter(
			'wp_die_handler',
			static function () use ( &$die_message ) {
				return static function ( $message ) use ( &$die_message ) {
					$die_message = (string) $message;
				};
			}
		);

		try {
			\do_action( 'admin_post_brl_truncate_confirm' );
		} finally {
			\remove_filter( 'wp_redirect', '__return_false', 99 );
		}

		// Row count must be unchanged — confirm step mutates nothing.
		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( $before, $after, 'Confirm step must not delete any rows.' );

		// The wp_die message must contain confirm-form HTML.
		$this->assertStringContainsString( 'form', \strtolower( $die_message ), 'Interstitial must contain a confirmation form.' );
	}

	/**
	 * TRUNC-01 execute step: admin_post_brl_truncate_all empties both tables.
	 *
	 * Filter token: Truncate
	 */
	public function test_truncate_all_empties_both_tables_Truncate(): void {
		global $wpdb;
		$this->seed_rows( 5 );

		$this->prime_nonce( 'brl_truncate_all' );
		\add_filter( 'wp_redirect', '__return_false', 99 );

		try {
			\do_action( 'admin_post_brl_truncate_all' );
		} finally {
			\remove_filter( 'wp_redirect', '__return_false', 99 );
		}

		$logs_count   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$bodies_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::bodies_table() );
		$this->assertSame( 0, $logs_count, 'Logs table must be empty after truncate_all.' );
		$this->assertSame( 0, $bodies_count, 'Bodies table must be empty after truncate_all.' );
	}

	/**
	 * TRUNC-01 stats clear: truncate_all must clear brl_internal.stats_snapshot.
	 *
	 * Filter token: Truncate
	 */
	public function test_truncate_all_clears_stats_snapshot_Truncate(): void {
		// Seed a stale stats_snapshot so we can confirm it gets cleared.
		Registry::set_internal(
			'stats_snapshot',
			[
				'computed_at'     => \time(),
				'by_status_class' => [ '2' => 100 ],
			]
		);

		$this->prime_nonce( 'brl_truncate_all' );
		\add_filter( 'wp_redirect', '__return_false', 99 );

		try {
			\do_action( 'admin_post_brl_truncate_all' );
		} finally {
			\remove_filter( 'wp_redirect', '__return_false', 99 );
		}

		$snapshot = Registry::get_internal( 'stats_snapshot' );
		$this->assertSame( [], $snapshot, 'stats_snapshot must be cleared to [] after truncate_all.' );
	}

	/**
	 * TRUNC-01 cap-denial: a subscriber must not be able to trigger truncate_all.
	 *
	 * Filter token: Truncate
	 */
	public function test_truncate_all_denied_for_subscriber_Truncate(): void {
		global $wpdb;
		$this->seed_rows( 3 );
		\wp_set_current_user( $this->subscriber_id );

		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );

		$this->prime_nonce( 'brl_truncate_all' );
		\add_filter( 'wp_redirect', '__return_false', 99 );

		$caught_die = false;
		\add_filter(
			'wp_die_handler',
			static function () use ( &$caught_die ) {
				return static function () use ( &$caught_die ) {
					$caught_die = true;
				};
			}
		);

		try {
			\do_action( 'admin_post_brl_truncate_all' );
		} finally {
			\remove_filter( 'wp_redirect', '__return_false', 99 );
		}

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::logs_table() );
		$this->assertSame( $before, $after, 'Subscriber must not be able to truncate logs.' );
	}
}
