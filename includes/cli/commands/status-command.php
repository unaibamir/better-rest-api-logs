<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Plugin;
use BetterRestApiLogs\Settings\Registry;

/**
 * Show capture, circuit-breaker, and schema status.
 *
 * ## EXAMPLES
 *
 *     wp better-logs status
 *     wp better-logs status --format=json
 */
final class StatusCommand extends \WP_CLI_Command {

	/**
	 * @param array<int, mixed>    $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$internal = (array) \get_option( 'brl_internal', [] );

		$registry = Plugin::instance()->container()->get( Registry::class );

		$capture_enabled = (bool) $registry->get_setting( 'capture.enabled', true );
		$circuit_open    = isset( $internal['circuit_open_until'] ) && (int) $internal['circuit_open_until'] > \time();
		$schema_broken   = (bool) ( $internal['schema_broken'] ?? false );

		$rows = [
			[ 'Component' => 'Capture',         'State' => $capture_enabled ? 'enabled' : 'disabled' ],
			[ 'Component' => 'Circuit breaker', 'State' => $circuit_open ? 'armed (logging paused)' : 'normal' ],
			[ 'Component' => 'Schema',          'State' => $schema_broken ? 'broken (admin notice live)' : 'healthy' ],
		];

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, [ 'Component', 'State' ] );
	}
}
