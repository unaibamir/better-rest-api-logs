<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for reading from / writing to {$wpdb->prefix}brl_logs.
 *
 * Phase 3 implements insert(Entry); Phase 4 implements find(int)/find_many(QueryArgs).
 * Phase 2 ships the skeleton + namespace + classmap entry only.
 */
final class LogRepository {
	// Phase 3 lands insert(Entry $entry): int.
	// Phase 4 lands find(int $id): ?Entry and find_many(QueryArgs $args): array.
}
