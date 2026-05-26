<?php
declare(strict_types=1);

namespace BetterRestApiLogs;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Cli\Commands\DeleteCommand;
use BetterRestApiLogs\Cli\Commands\ExportCommand;
use BetterRestApiLogs\Cli\Commands\ImportCommand;
use BetterRestApiLogs\Cli\Commands\ListCommand;
use BetterRestApiLogs\Cli\Commands\PurgeCommand;
use BetterRestApiLogs\Cli\Commands\ShowCommand;
use BetterRestApiLogs\Cli\Commands\StatsCommand;
use BetterRestApiLogs\Cli\Commands\StatusCommand;
use BetterRestApiLogs\Cli\Commands\TruncateCommand;

/**
 * WP-CLI registrar — wires the eight `wp better-logs` verbs.
 *
 * Called from Plugin::boot() only when `defined('WP_CLI') && \WP_CLI`, so the
 * command classes are never loaded on normal HTTP requests.
 */
final class Cli {

	public static function register(): void {
		\WP_CLI::add_command( 'better-logs status', StatusCommand::class );
		\WP_CLI::add_command( 'better-logs stats', StatsCommand::class );
		\WP_CLI::add_command( 'better-logs list', ListCommand::class );
		\WP_CLI::add_command( 'better-logs show', ShowCommand::class );
		\WP_CLI::add_command( 'better-logs delete', DeleteCommand::class );
		\WP_CLI::add_command( 'better-logs purge', PurgeCommand::class );
		\WP_CLI::add_command( 'better-logs truncate', TruncateCommand::class );
		\WP_CLI::add_command( 'better-logs export', ExportCommand::class );
		\WP_CLI::add_command( 'better-logs import', ImportCommand::class );
	}
}
