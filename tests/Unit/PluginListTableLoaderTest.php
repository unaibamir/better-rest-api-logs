<?php
/**
 * Unit test for the ListTable factory closure inside Plugin::boot().
 *
 * The container chain Admin -> ListScreen -> ListTable resolves on every
 * Plugin::boot() invocation, including boots that originate from front-end
 * or REST requests where wp-admin/includes/admin.php has not loaded
 * \WP_List_Table yet. Autoloading ListTable in that state fatals with
 * "Class \WP_List_Table not found".
 *
 * The fix loads class-wp-list-table.php inside the ListTable factory
 * closure before constructing the subclass. This test reads the source of
 * includes/plugin.php and asserts that guard is present so a refactor
 * cannot silently regress the boot path.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PluginListTableLoaderTest extends TestCase {

	public function test_list_table_factory_loads_parent_class_before_instantiation(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a unit test; wp_remote_get is for remote URLs.
		$source = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/includes/plugin.php' );

		$this->assertNotSame( '', $source, 'plugin.php must be readable.' );

		// The fix must require_once the WP core file inside the ListTable
		// factory closure so the parent class is present before autoload of
		// the subclass triggers `extends \WP_List_Table`.
		$this->assertStringContainsString(
			"require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'",
			$source,
			'plugin.php must require the WP_List_Table parent class file before constructing ListTable, or a non-admin boot fatals.'
		);
	}

	public function test_list_table_factory_guards_against_double_load(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file in a unit test; wp_remote_get is for remote URLs.
		$source = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/includes/plugin.php' );

		$this->assertStringContainsString(
			"class_exists( '\\WP_List_Table' )",
			$source,
			'The require must be guarded by a class_exists check so admin requests (where WP loads it for us) skip the re-include.'
		);
	}
}
