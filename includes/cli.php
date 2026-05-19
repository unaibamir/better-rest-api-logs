<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Cli\Commands\DeleteCommand;
use BetterRestApiLogs\Cli\Commands\ListCommand;
use BetterRestApiLogs\Cli\Commands\ShowCommand;
use BetterRestApiLogs\Cli\Commands\StatsCommand;
use BetterRestApiLogs\Cli\Commands\StatusCommand;

/**
 * WP-CLI registrar — wires the five `wp better-logs` verbs.
 *
 * Called from Plugin::boot() only when `defined('WP_CLI') && \WP_CLI`, so the
 * command classes are never loaded on normal HTTP requests.
 */
final class Cli {

	public static function register(): void {
		\WP_CLI::add_command( 'better-logs status', StatusCommand::class );
		\WP_CLI::add_command( 'better-logs stats',  StatsCommand::class );
		\WP_CLI::add_command( 'better-logs list',   ListCommand::class );
		\WP_CLI::add_command( 'better-logs show',   ShowCommand::class );
		\WP_CLI::add_command( 'better-logs delete', DeleteCommand::class );
	}
}
