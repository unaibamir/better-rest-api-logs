<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Composes prepared SQL from QueryArgs against Database::logs_table().
 *
 * Phase 4 implements — emits $wpdb->prepare-safe statements with bound
 * placeholders only; never interpolates user input.
 */
final class QueryBuilder {
	// Phase 4 lands the build method returning a sql-and-args tuple.
}
