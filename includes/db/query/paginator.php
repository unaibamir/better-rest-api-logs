<?php
declare(strict_types=1);

namespace BetterRestApiLogs\DB\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Cursor pagination — opaque created_at_micros+id token.
 *
 * No OFFSET, no full-table COUNT(*). Phase 4 implements (REL-03).
 */
final class Paginator {
	// Phase 4 lands encode($entry): string + decode(string): array{micros:int,id:int}.
}
