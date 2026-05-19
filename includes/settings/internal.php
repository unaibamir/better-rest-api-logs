<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shape + defaults for the `brl_internal` array option (autoload=no).
 *
 * Locked contract per CONTEXT.md D-02 + Pitfall 4 — reserved keys for v1.0,
 * each owned by the phase that writes it. Other code may read any key but
 * MUST go through Registry::get_internal()/set_internal() so the autoload-off
 * storage shape is hidden from call sites.
 *
 *  - circuit_open_until            (Phase 3 — Logger circuit breaker)
 *  - circuit_consecutive_failures  (Phase 3 — Logger circuit breaker)
 *  - circuit_window_started_at     (Phase 3 — Logger circuit breaker)
 *  - schema_broken                 (Phase 2 — set by Schema::set_broken_flag)
 *  - stats_snapshot                (Phase 4 — cached aggregate counts for the stats endpoint)
 *  - purge_state                   (Phase 5 — last-run timestamp, row count)
 *  - migration                     (Phase 6 — nested 7-key MIG-07 marker array)
 *
 * `brl_internal` is seeded with `autoload='no'` by Defaults::seed_all_tabs().
 * Migration markers and breaker counters can grow; autoloading them would
 * bloat the options autoload row on every page request.
 */
final class Internal {

	/**
	 * Reserved-key shape with safe initial values.
	 *
	 * Scalars where possible (cheap reads); nested arrays only where the
	 * value genuinely needs sub-structure (stats_snapshot, purge_state, migration).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'circuit_open_until'           => 0,
			'circuit_consecutive_failures' => 0,
			'circuit_window_started_at'    => 0,
			'schema_broken'                => false,
			// Cached aggregate counts written by the stats endpoint (StatsController).
			// Shape: array{computed_at:int, by_status_class:array, by_method:array,
			// oldest:string, newest:string, table_size_bytes:int}
			'stats_snapshot'               => [],
			'purge_state'                  => [],
			'migration'                    => [],
		];
	}

	/**
	 * Guard used by Registry::set_internal to reject typos that would write
	 * an unreserved key into the shared `brl_internal` array.
	 *
	 * @param string $key Candidate key.
	 */
	public static function is_known_key( string $key ): bool {
		return \array_key_exists( $key, self::defaults() );
	}
}
