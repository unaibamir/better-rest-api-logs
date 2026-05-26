<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Settings;

defined( 'ABSPATH' ) || exit;

use BetterRestApiLogs\Support\Arr;

/**
 * Single source of truth for every `brl_*` option (SET-01).
 *
 * Hides three storage shapes behind two read methods:
 *  - `get_setting('capture.enabled')` — per-tab user settings via dot-path (D-09).
 *  - `get_internal('db_version')` — flat scalar (D-01/D-03).
 *  - `get_internal('schema_broken')` — element of the `brl_internal` array (D-02/D-03).
 *
 * Locked contract per CONTEXT.md D-01, D-02, D-03, D-04, D-09, D-10, D-11 and
 * REQUIREMENTS.md SET-01, SET-02, SET-03, SET-05:
 *  - SET-01: every `brl_*` option flows through this class (or the activation /
 *    uninstall / schema-broken latch carve-outs enumerated in
 *    bin/check-settings-isolation.sh).
 *  - SET-02: `register_with_wp()` issues exactly one `register_setting()` call
 *    per tab with `option_group === option_name`; SET-04's structural isolation
 *    falls out for free (D-06).
 *  - SET-03: `get_setting()` overlays the stored option onto
 *    `Defaults::for_tab($tab)` via `array_replace_recursive` so newly added
 *    default keys surface without a database write.
 *  - SET-05: per-tab + internal in-memory cache, flushed by
 *    `invalidate_cache_on_option_change` hooked to `updated_option` /
 *    `added_option` (D-11 — Plan 02-07 wires the hook).
 *  - Pitfall 2: per-tab sanitizers always return an array, never null; unknown
 *    keys are silently dropped.
 *
 * `get_internal` and `set_internal` are declared static so integration-test
 * helpers and Phase 3 Logger classes can call them as `Registry::get_internal($k)`
 * without needing a container-managed instance. They delegate through
 * `self::global()` which returns the canonical instance (set via
 * `set_global_instance` in Plugin::boot) or builds a default one lazily.
 *
 * Repository is constructor-injected (D-30 test seam): unit tests substitute
 * an in-memory subclass — `tests/Unit/Settings/Fixtures/InMemoryRepository.php`
 * — so the dot-path + cache logic is exercised without a WordPress bootstrap.
 * Unit tests that cover get_internal / set_internal call `set_global_instance`
 * with a Registry backed by the InMemoryRepository, then reset to null in
 * tearDown.
 */
final class Registry {

	/** @var array<string,array<string,mixed>> */
	private array $cache_settings = [];

	/** @var array<string,mixed> */
	private array $cache_internal = [];

	/**
	 * True once `brl_internal` has been read into `$cache_internal`. Tracked
	 * separately from the cache array's keyspace so the cache stays a clean
	 * map of reserved keys (no sentinel pollution).
	 *
	 * @var bool
	 */
	private bool $internal_loaded = false;

	/**
	 * Global singleton used by the static get_internal / set_internal facades.
	 *
	 * Set via set_global_instance() in Plugin::boot so that static callers share
	 * the same in-memory cache as the container-managed instance. Falls back to a
	 * lazily-built Repository-backed instance in integration tests and CLI
	 * contexts where Plugin::boot has not run.
	 *
	 * @var self|null
	 */
	private static ?self $global_instance = null;

	private Repository $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register the canonical instance for static method calls.
	 *
	 * Plugin::boot() calls this after constructing the container-managed
	 * Registry so that Registry::get_internal() / Registry::set_internal()
	 * share the same in-memory cache as the injected instance.
	 *
	 * Unit tests that need to inject an InMemoryRepository into the static
	 * path call this in setUp() and pass null in tearDown() to restore the
	 * default.
	 *
	 * @param self|null $instance The container-managed instance, or null to reset.
	 */
	public static function set_global_instance( ?self $instance ): void {
		self::$global_instance = $instance;
	}

	/**
	 * Retrieve the canonical instance (same object that Plugin::boot() registered).
	 *
	 * Returns the lazily-built fallback when Plugin::boot() has not run yet,
	 * mirroring the self::global() behaviour used by the static get/set_internal
	 * facades. Tests that need to call instance methods on the global Registry
	 * (e.g. clearing the stats_snapshot between test cases) use this.
	 *
	 * @return self
	 */
	public static function get_global_instance(): self {
		return self::global_instance();
	}

	/**
	 * Read a user-facing setting via dot-path. First segment is the tab; the
	 * rest resolves as a nested key inside that tab's stored array overlaid
	 * onto `Defaults::for_tab( $tab )`.
	 *
	 * A dot-less path is invalid (D-09 — single API surface, no two-arg form):
	 * the caller-supplied default is returned untouched.
	 *
	 * @param  string $path     Dot-separated path (e.g. `capture.body_size_cap_bytes`).
	 * @param  mixed  $fallback Returned when the path is absent.
	 * @return mixed
	 */
	public function get_setting( string $path, $fallback = null ) {
		$dot = \strpos( $path, '.' );
		if ( false === $dot ) {
			return $fallback;
		}
		$tab     = \substr( $path, 0, $dot );
		$rest    = \substr( $path, $dot + 1 );
		$tab_arr = $this->load_tab( $tab );
		return Arr::get( $tab_arr, $rest, $fallback );
	}

	/**
	 * Read an internal marker. Dispatches across three storage shapes per D-03:
	 *
	 *  - `db_version`           → flat `brl_db_version` option (boot-critical).
	 *  - `delete_on_uninstall`  → flat `brl_settings_delete_on_uninstall` (D-04).
	 *  - anything else          → element of the `brl_internal` array option,
	 *                             unioned with `Internal::defaults()` so reserved
	 *                             keys never surface as `null` on a fresh install.
	 *
	 * Declared static so integration-test helpers and Phase 3 Logger classes can
	 * call it as Registry::get_internal($k) without a container-managed instance.
	 * Delegates through self::global() — see class docblock for the singleton
	 * lifecycle. Static methods can also be called on an instance reference
	 * ($registry->get_internal($k)) without error in PHP 8.
	 *
	 * @param  string $key Internal-marker key.
	 * @return mixed
	 */
	public static function get_internal( string $key ) {
		return self::global_instance()->read_internal( $key );
	}

	/**
	 * Write a single internal-marker key into the `brl_internal` array.
	 *
	 * Rejects unreserved keys via `Internal::is_known_key()` so typos cannot
	 * silently corrupt the shared `brl_internal` namespace. The guard is the
	 * whole reason `Internal::is_known_key()` exists; calling it here is the
	 * SET-01 contract — every write goes through this method, every write
	 * passes the reserved-key check.
	 *
	 * Declared static for the same reason as get_internal. Delegates through
	 * self::global_instance() so the in-memory cache stays coherent.
	 *
	 * Cache invalidation happens via the `updated_option` hook →
	 * `invalidate_cache_on_option_change` below. Plan 02-07 wires the hook.
	 *
	 * @param  string $key   One of the reserved keys declared in `Internal::defaults()`.
	 * @param  mixed  $value New value.
	 * @throws \InvalidArgumentException When `$key` is not a reserved `brl_internal` key.
	 */
	public static function set_internal( string $key, $value ): void {
		self::global_instance()->write_internal( $key, $value );
	}

	/**
	 * Flush the in-memory cache slot for `$option_name`.
	 *
	 * Wired by Plugin::boot to the `updated_option` and `added_option` hooks
	 * (Plan 02-07). The callback is no-op for option names outside the
	 * `brl_*` namespace — checked here so we can register a single
	 * unconditional hook rather than one per option.
	 *
	 * @param string $option_name Option name reported by WP.
	 */
	public function invalidate_cache_on_option_change( string $option_name ): void {
		if ( 0 === \strpos( $option_name, 'brl_settings_' ) ) {
			unset( $this->cache_settings[ $option_name ] );
			return;
		}
		if ( \in_array( $option_name, [ 'brl_internal', 'brl_db_version', 'brl_settings_delete_on_uninstall' ], true ) ) {
			$this->cache_internal  = [];
			$this->internal_loaded = false;
		}
	}

	/**
	 * Register one `register_setting()` per tab with `option_group === option_name`.
	 *
	 * Per D-06 the identity between group and option name is the SET-04
	 * structural enforcement: the Settings API form post carries
	 * `option_page=<group_name>`; core resolves that to its registered options
	 * and updates only those, so saving one tab cannot reach another tab's
	 * option key.
	 *
	 * Plan 02-07 wires this on `admin_init`.
	 */
	public function register_with_wp(): void {
		foreach ( [ 'capture', 'privacy', 'retention', 'network', 'advanced' ] as $tab ) {
			$option_name = "brl_settings_{$tab}";
			$group_name  = $option_name;

			\register_setting(
				$group_name,
				$option_name,
				[
					'type'              => 'array',
					'sanitize_callback' => [ $this, "sanitize_{$tab}" ],
					'default'           => Defaults::for_tab( $tab ),
					'show_in_rest'      => false,
				]
			);
		}
	}

	/**
	 * Return the global instance, creating a default one lazily if not yet set.
	 *
	 * The lazy default is Repository-backed (real WP options API), which is
	 * correct in integration tests and CLI contexts. Production code reaches
	 * here only before Plugin::boot has run — rare but handled.
	 */
	private static function global_instance(): self {
		if ( null === self::$global_instance ) {
			self::$global_instance = new self( new Repository() );
		}
		return self::$global_instance;
	}

	/**
	 * Instance-level get_internal implementation. Called by the static facade.
	 *
	 * @param  string $key Internal-marker key.
	 * @return mixed
	 */
	private function read_internal( string $key ) {
		if ( 'db_version' === $key ) {
			return $this->repository->get_option( 'brl_db_version', '0' );
		}
		if ( 'delete_on_uninstall' === $key ) {
			return (bool) $this->repository->get_option( 'brl_settings_delete_on_uninstall', '' );
		}

		if ( ! $this->internal_loaded ) {
			$stored = $this->repository->get_option( 'brl_internal', [] );
			if ( ! \is_array( $stored ) ) {
				$stored = [];
			}
			// Stored values WIN over Internal defaults for keys present in both.
			$this->cache_internal  = $stored + Internal::defaults();
			$this->internal_loaded = true;
		}

		return $this->cache_internal[ $key ] ?? null;
	}

	/**
	 * Instance-level set_internal implementation. Called by the static facade.
	 *
	 * @param  string $key   One of the reserved keys declared in `Internal::defaults()`.
	 * @param  mixed  $value New value.
	 * @throws \InvalidArgumentException When `$key` is not a reserved key.
	 */
	private function write_internal( string $key, $value ): void {
		if ( ! Internal::is_known_key( $key ) ) {
			throw new \InvalidArgumentException(
				\esc_html( \sprintf( 'Unknown brl_internal key: %s', $key ) )
			);
		}

		$current = $this->repository->get_option( 'brl_internal', [] );
		if ( ! \is_array( $current ) ) {
			$current = [];
		}
		$current[ $key ] = $value;
		$this->repository->update_option( 'brl_internal', $current );

		// Bust the instance cache so subsequent reads see the new value.
		$this->cache_internal  = [];
		$this->internal_loaded = false;
	}

	/**
	 * Load a tab's settings array, overlaying the stored option onto the
	 * registered defaults. Cached on first call; flushed by
	 * `invalidate_cache_on_option_change`.
	 *
	 * @param  string $tab Tab name (`capture`, `privacy`, `retention`, `network`, `advanced`).
	 * @return array<string,mixed>
	 */
	private function load_tab( string $tab ): array {
		$option_name = "brl_settings_{$tab}";
		if ( ! isset( $this->cache_settings[ $option_name ] ) ) {
			$stored                               = $this->repository->get_option( $option_name, [] );
			$defaults                             = Defaults::for_tab( $tab );
			$this->cache_settings[ $option_name ] = \array_replace_recursive(
				$defaults,
				\is_array( $stored ) ? $stored : []
			);
		}
		return $this->cache_settings[ $option_name ];
	}

	/*
	 * Per-tab sanitizers (D-08, Pitfall 2).
	 *
	 * Each sanitizer:
	 *  - Accepts mixed input (WP can hand non-arrays on garbage form posts;
	 *    PHP 7.4 strict_types rejects array type-hints when given a string).
	 *  - Returns an array, never null (returning null wipes the option to
	 *    the registered default — never what we want).
	 *  - Drops unknown keys silently — only keys present in
	 *    `Defaults::for_tab( $tab )` survive.
	 *  - Coerces per type: bool via cast, ints via `absint`, strings via
	 *    `sanitize_text_field`, arrays of strings via per-element filter.
	 */

	/**
	 * @param  mixed $input Form-post payload (expected array; defensive against scalars).
	 * @return array<string,mixed>
	 */
	private function sanitize_capture( $input ): array {
		$defaults = Defaults::for_tab( 'capture' );
		if ( ! \is_array( $input ) ) {
			return $defaults;
		}
		return [
			'enabled'                     => (bool) ( $input['enabled'] ?? $defaults['enabled'] ),
			'route_allowlist'             => self::clean_string_list( $input['route_allowlist'] ?? [] ),
			'route_denylist'              => self::clean_string_list( $input['route_denylist'] ?? $defaults['route_denylist'] ),
			'method_filter'               => self::clean_string_list( $input['method_filter'] ?? [] ),
			'status_class_filter'         => self::clean_int_list( $input['status_class_filter'] ?? [] ),
			'body_size_cap_bytes'         => \absint( $input['body_size_cap_bytes'] ?? $defaults['body_size_cap_bytes'] ),
			'body_content_type_allowlist' => self::clean_string_list(
				$input['body_content_type_allowlist'] ?? $defaults['body_content_type_allowlist']
			),
			'body_spill_enabled'          => (bool) ( $input['body_spill_enabled'] ?? $defaults['body_spill_enabled'] ),
			'body_spill_threshold_bytes'  => \absint(
				$input['body_spill_threshold_bytes'] ?? $defaults['body_spill_threshold_bytes']
			),
			'gzip_bodies'                 => (bool) ( $input['gzip_bodies'] ?? $defaults['gzip_bodies'] ),
		];
	}

	/**
	 * @param  mixed $input Form-post payload.
	 * @return array<string,mixed>
	 */
	private function sanitize_privacy( $input ): array {
		$defaults = Defaults::for_tab( 'privacy' );
		if ( ! \is_array( $input ) ) {
			return $defaults;
		}
		return [
			'redact_extra_headers'     => self::clean_string_list( $input['redact_extra_headers'] ?? [] ),
			'redact_json_key_patterns' => self::clean_redact_patterns( $input['redact_json_key_patterns'] ?? [] ),
			'anonymize_ip'             => (bool) ( $input['anonymize_ip'] ?? $defaults['anonymize_ip'] ),
			'auth_endpoint_allowlist'  => self::clean_string_list(
				$input['auth_endpoint_allowlist'] ?? $defaults['auth_endpoint_allowlist']
			),
		];
	}

	/**
	 * @param  mixed $input Form-post payload.
	 * @return array<string,mixed>
	 */
	private function sanitize_retention( $input ): array {
		$defaults = Defaults::for_tab( 'retention' );
		if ( ! \is_array( $input ) ) {
			return $defaults;
		}
		return [
			'retention_days'         => \absint( $input['retention_days'] ?? $defaults['retention_days'] ),
			'purge_batch_size'       => \absint( $input['purge_batch_size'] ?? $defaults['purge_batch_size'] ),
			'purge_max_tick_seconds' => \absint(
				$input['purge_max_tick_seconds'] ?? $defaults['purge_max_tick_seconds']
			),
		];
	}

	/**
	 * @param  mixed $input Form-post payload.
	 * @return array<string,mixed>
	 */
	private function sanitize_network( $input ): array {
		$defaults = Defaults::for_tab( 'network' );
		if ( ! \is_array( $input ) ) {
			return $defaults;
		}
		return [
			'trusted_proxy_cidrs' => self::clean_string_list( $input['trusted_proxy_cidrs'] ?? [] ),
			'cloudflare_cidrs'    => self::clean_string_list( $input['cloudflare_cidrs'] ?? [] ),
			'cidr_allowlist'      => self::clean_string_list( $input['cidr_allowlist'] ?? [] ),
			'cidr_denylist'       => self::clean_string_list( $input['cidr_denylist'] ?? [] ),
		];
	}

	/**
	 * @param  mixed $input Form-post payload.
	 * @return array<string,mixed>
	 */
	private function sanitize_advanced( $input ): array {
		$defaults = Defaults::for_tab( 'advanced' );
		if ( ! \is_array( $input ) ) {
			return $defaults;
		}
		// The uninstall opt-in is the flat `brl_settings_delete_on_uninstall` option
		// (Phase 1 D-11..D-15); it is NOT mirrored here. Phase 4's Advanced UI binds
		// the flat option directly via its own register_setting().
		return [
			'truncate_confirm_copy' => \sanitize_text_field(
				(string) ( $input['truncate_confirm_copy'] ?? $defaults['truncate_confirm_copy'] )
			),
		];
	}

	/**
	 * Reduce an arbitrary value to a re-indexed list of sanitized strings.
	 *
	 * The admin Settings UI renders array-valued settings as a single textarea,
	 * so options.php posts a newline-separated string for keys like
	 * `route_allowlist`. Accept that string form here and split on line breaks
	 * so the form path and the programmatic-array path land at the same shape.
	 * Empty lines are dropped after trimming.
	 *
	 * @param  mixed $value Raw input — array, newline-delimited string, or other scalar.
	 * @return array<int,string>
	 */
	private static function clean_string_list( $value ): array {
		if ( \is_string( $value ) ) {
			$lines = \preg_split( '/\r\n|\r|\n/', $value );
			$value = false === $lines ? [] : $lines;
		}
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$strings = \array_filter( $value, '\is_string' );
		$cleaned = \array_map( '\sanitize_text_field', $strings );
		$cleaned = \array_filter(
			$cleaned,
			static function ( string $s ): bool {
				return '' !== $s;
			}
		);
		return \array_values( $cleaned );
	}

	/**
	 * Sanitize the JSON key-redaction patterns and drop any that won't compile.
	 *
	 * Each entry is spliced into a `/.../i` alternation in the redactor after
	 * being preg_quote()'d against the `/` delimiter. Validating the same shape
	 * here means a value that would have produced an invalid regex — and thus
	 * either a blanked body or a skipped redaction — is rejected at save time
	 * instead of poisoning the capture pipeline silently.
	 *
	 * @param  mixed $value Raw input — array or newline-delimited string.
	 * @return array<int,string>
	 */
	private static function clean_redact_patterns( $value ): array {
		$patterns = self::clean_string_list( $value );

		// Each entry is treated as a literal key fragment: the Flusher quotes it
		// against the `/` delimiter before splicing it into the /.../i
		// alternation. Validate that exact quoted shape here and drop anything
		// that still won't compile, so a stored pattern can never break the
		// delimiter, blank a body, or silently disable redaction downstream.
		// Quoting keeps innocent values like "api/v2" working while guaranteeing
		// the assembled regex is always valid. A failed compile makes
		// preg_match() warn and return false; silence that probe with a scoped
		// handler rather than the `@` operator the project bans.
		$swallow = static function (): bool {
			return true;
		};
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped probe to detect an uncompilable assembled regex; restored immediately below.
		\set_error_handler( $swallow );
		$valid = \array_filter(
			$patterns,
			static function ( string $pattern ): bool {
				$quoted = \preg_quote( $pattern, '/' );
				return false !== \preg_match( '/(password|' . $quoted . ')/i', '' );
			}
		);
		\restore_error_handler();

		return \array_values( $valid );
	}

	/**
	 * Reduce an arbitrary value to a re-indexed list of integers.
	 *
	 * Accepts integers and digit-only strings (optionally leading `-`); drops
	 * floats, scientific notation, and whitespace-padded values that
	 * `is_numeric()` would accept. `intval('3.14')` is 3 and `intval('1e2')`
	 * is 1 — both bug-shaped coercions that the looser predicate let through.
	 *
	 * @param  mixed $value Raw input.
	 * @return array<int,int>
	 */
	private static function clean_int_list( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$ints = \array_filter(
			$value,
			static function ( $v ): bool {
				if ( \is_int( $v ) ) {
					return true;
				}
				if ( ! \is_string( $v ) ) {
					return false;
				}
				return 1 === \preg_match( '/^-?\d+$/', $v );
			}
		);
		return \array_values( \array_map( 'intval', $ints ) );
	}
}
