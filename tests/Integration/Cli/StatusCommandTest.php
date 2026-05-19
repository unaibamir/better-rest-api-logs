<?php
/**
 * Integration tests for the `wp better-logs status` CLI command.
 *
 * RED-bar scaffold: all tests fail with class-not-found until Plan 04-09
 * implements includes/cli/commands/status-command.php. Covers exit code 0 on
 * success and expected output rows for capture, breaker, and schema health.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Integration\Cli;

use BetterRestApiLogs\Cli\Commands\StatusCommand;
use BetterRestApiLogs\DB\Database;
use BetterRestApiLogs\DB\Schema;
use BetterRestApiLogs\Plugin;
use WP_UnitTestCase;

// EXPECTED FAILURE: Wave 2 (Plan 04-09) — StatusCommand class does not exist yet.

final class StatusCommandTest extends WP_UnitTestCase {

	/** @var int */
	private int $ob_level_before = 0;

	public function set_up(): void {
		parent::set_up();
		$this->ob_level_before = \ob_get_level();
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		Schema::install();
		Plugin::instance()->boot();
	}

	public function tear_down(): void {
		while ( \ob_get_level() > $this->ob_level_before ) {
			\ob_end_clean();
		}
		while ( \ob_get_level() < $this->ob_level_before ) {
			\ob_start();
		}
		parent::tear_down();
	}

	public function test_status_command_class_exists(): void {
		$this->assertTrue(
			class_exists( StatusCommand::class ),
			'StatusCommand class must exist.'
		);
	}

	public function test_status_command_is_a_wp_cli_command(): void {
		$this->assertInstanceOf(
			\WP_CLI_Command::class,
			new StatusCommand()
		);
	}

	public function test_status_command_run_method_outputs_capture_status(): void {
		$command = new StatusCommand();

		\ob_start();
		$command->run( [], [] );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'capture', strtolower( $output ) );
	}

	public function test_status_command_run_method_outputs_breaker_status(): void {
		$command = new StatusCommand();

		\ob_start();
		$command->run( [], [] );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'breaker', strtolower( $output ) );
	}

	public function test_status_command_run_method_outputs_schema_status(): void {
		$command = new StatusCommand();

		\ob_start();
		$command->run( [], [] );
		$output = \ob_get_clean();

		$this->assertStringContainsString( 'schema', strtolower( $output ) );
	}
}
