<?php
/**
 * The Import tab must surface a real upstream count so the Start button is
 * reachable. source_total was never written to the migration marker, so the
 * tab always claimed there was nothing to import and disabled Start even when
 * wp-rest-api-log rows existed. render_import_tab now counts the source live.
 *
 * @package BetterRestApiLogs\Tests\Integration\Migration
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Migration;

use BetterRestApiLogs\Admin\SettingsScreen;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

final class ImportTabReachableTest extends WP_UnitTestCase {

	use UpstreamFixture;

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		Plugin::instance()->boot();
		\wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );
		$this->register_upstream_cpt_and_taxonomies();
	}

	public function tear_down(): void {
		unset( $_GET['tab'], $_GET['page'] );
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	private function render_import_tab(): string {
		$_GET['page'] = 'better-rest-api-logs-settings';
		$_GET['tab']  = 'import';
		\ob_start();
		SettingsScreen::render();
		return (string) \ob_get_clean();
	}

	/** With upstream rows present, the count shows and Start is not disabled. */
	public function test_import_tab_enables_start_when_source_rows_exist(): void {
		$this->seed_upstream_posts( 4 );

		$html = $this->render_import_tab();

		$this->assertStringContainsString( 'available to import', $html, 'The tab must report a real source count.' );
		$this->assertStringContainsString( 'Start import', $html );
		$this->assertStringNotContainsString(
			'No wp-rest-api-log entries were found to import',
			$html,
			'The empty-source notice must not show when rows exist.'
		);
		$this->assertStringNotContainsString(
			'button-primary" disabled',
			$html,
			'The Start button must be enabled when there is something to import.'
		);
	}

	/** With no upstream rows, the empty-source notice shows and Start is disabled. */
	public function test_import_tab_disables_start_when_no_source_rows(): void {
		$html = $this->render_import_tab();

		$this->assertStringContainsString( 'No wp-rest-api-log entries were found to import', $html );
		$this->assertStringContainsString( 'button-primary" disabled', $html );
	}
}
