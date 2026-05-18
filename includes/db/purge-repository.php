<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for cron-batched retention purge (PURGE-01..08).
 *
 * Phase 5 implements delete_older_than(string $cutoff_at, int $limit) + the
 * lock + follow-up-tick contract. Phase 2 stub only.
 */
final class PurgeRepository {
	// Phase 5 lands delete_older_than + the PURGE-07 spill-row cascade.
}
