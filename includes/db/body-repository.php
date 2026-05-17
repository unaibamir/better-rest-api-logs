<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for the body-spill table (CAP-09).
 *
 * Phase 3 implements insert(int $log_id, ?string $req_body, ?string $res_body)/
 * fetch(int)/delete(int). Phase 2 stub only.
 */
final class BodyRepository {
	// Phase 3 lands insert/fetch/delete against {$wpdb->prefix}brl_logs_bodies.
}
