<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Typed query parameters for log searches.
 *
 * Carries method, status, status_class, route_prefix, user_id, ip, date range,
 * and cursor token. Phase 4 implements.
 */
final class QueryArgs {
	// Phase 4 lands typed properties + from_array() factory.
}
