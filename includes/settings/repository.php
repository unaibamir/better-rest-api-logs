<?php
declare(strict_types=1);

namespace BetterRestApiLogs\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin adapter over WordPress's options API.
 *
 * Exists so Settings\Registry can be unit-tested with an in-memory subclass
 * that mocks these signatures. Per CLAUDE.md "no interfaces until ≥2 concrete
 * implementations" — no OptionRepositoryInterface yet; the test double
 * (tests/Unit/Settings/Fixtures/InMemoryRepository.php) duck-types by
 * subclassing.
 *
 * Locked contract per CONTEXT.md D-30 ("Repository constructor seam") and
 * Claude's Discretion §"Settings\Repository vs Settings\Registry":
 *  - Three public instance methods, NOT static — Registry constructor-injects.
 *  - Byte-faithful: no transformation, no overlay, no defaults — Registry
 *    handles overlay; Repository just delegates.
 *  - Methods MUST NOT be `final` so the in-test InMemoryRepository can
 *    override them. The class itself is `final` — subclasses of a final
 *    class are forbidden, but extending it via subclassing IS allowed
 *    while the class is non-final. Tests subclass; production never does.
 *
 * Note on autoload mutation: WP's `update_option` cannot change the autoload
 * column once set. If a future migration needs to flip an option from
 * autoload=yes to autoload=no it must `delete_option` then `add_option`
 * with the new flag. That dance lives in migration code, not here.
 */
final class Repository {

	/**
	 * @param  string $name     Option name.
	 * @param  mixed  $fallback Returned when the option does not exist.
	 * @return mixed
	 */
	public function get_option( string $name, $fallback = false ) {
		return \get_option( $name, $fallback );
	}

	/**
	 * @param  string $name     Option name.
	 * @param  mixed  $value    Value to persist.
	 * @param  string $autoload Empty string = use WP's two-arg form (preserves
	 *                          the existing autoload column); otherwise pass
	 *                          'yes' / 'no' through to WP's three-arg form.
	 */
	public function update_option( string $name, $value, string $autoload = '' ): bool {
		return '' === $autoload
			? \update_option( $name, $value )
			: \update_option( $name, $value, $autoload );
	}

	/**
	 * @param  string $name     Option name.
	 * @param  mixed  $value    Initial value.
	 * @param  string $autoload 'yes' (default) or 'no'. Maps to WP's 4th arg;
	 *                          the 3rd arg is the deprecated $deprecated param
	 *                          which MUST be the empty string per WP docs.
	 */
	public function add_option( string $name, $value, string $autoload = 'yes' ): bool {
		return \add_option( $name, $value, '', $autoload );
	}
}
