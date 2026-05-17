<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Sort specification — column + direction with whitelist enforcement.
 *
 * Phase 4 implements; column list is constrained to indexed columns only
 * to keep ORDER BY plans bounded.
 */
final class Sort {
	// Phase 4 lands the whitelist + by()/dir() accessors.
}
