<?php
/**
 * Unit tests for BetterRestApiLogs\Capture\Filter.
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-04
 * implements includes/capture/filter.php. Covers FILT-01..03, FILT-05,
 * PRIV-06 per the locked requirements in REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Capture;

use BetterRestApiLogs\Capture\Filter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class FilterTest extends TestCase {

	/**
	 * Returns a fully-populated settings array with capture enabled and no exclusions.
	 */
	private function settings_allow_all(): array {
		return [
			'capture' => [
				'enabled'             => true,
				'methods'             => [],
				'route_allowlist'     => [],
				'route_denylist'      => [],
				'status_class_filter' => [],
				'cidr_allowlist'      => [],
				'cidr_denylist'       => [],
			],
		];
	}

	/**
	 * Minimal request stub for pre-dispatch filter unit tests.
	 *
	 * Production Filter::should_capture_at_pre_dispatch must not use
	 * instanceof WP_REST_Request — accept any object with get_route/get_method.
	 * Integration tests use real WP_REST_Request where WP is booted.
	 *
	 * @param string $route  REST route path.
	 * @param string $method HTTP method.
	 */
	private function mock_request( string $route, string $method = 'GET' ): \stdClass {
		$req          = new \stdClass();
		$req->_route  = $route;
		$req->_method = $method;
		return $req;
	}

	/** FILT-01: master enable=false skips capture before any other filter step. */
	public function test_master_disable_skips_capture(): void {
		$settings                       = $this->settings_allow_all();
		$settings['capture']['enabled'] = false;
		$request                        = $this->mock_request( '/wp/v2/posts' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertFalse( $result );
	}

	/** CAP-05 / D-04: self-namespace route must be skipped. */
	public function test_self_namespace_skip(): void {
		$settings = $this->settings_allow_all();
		$request  = $this->mock_request( '/better-rest-api-logs/v1/logs' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertFalse( $result );
	}

	/** FILT-02: request method not in the allowlist excludes the request. */
	public function test_method_filter_excludes(): void {
		$settings                       = $this->settings_allow_all();
		$settings['capture']['methods'] = [ 'POST' ];
		$request                        = $this->mock_request( '/wp/v2/posts', 'GET' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertFalse( $result );
	}

	/** FILT-02: empty method list captures all methods. */
	public function test_method_filter_passes_when_empty(): void {
		$settings                       = $this->settings_allow_all();
		$settings['capture']['methods'] = [];
		$request                        = $this->mock_request( '/wp/v2/posts', 'GET' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertTrue( $result );
	}

	/** FILT-03: route matching the glob allowlist is captured. */
	public function test_route_glob_match_allows(): void {
		$settings                               = $this->settings_allow_all();
		$settings['capture']['route_allowlist'] = [ '/wp/v2/*' ];
		$request                                = $this->mock_request( '/wp/v2/posts' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertTrue( $result );
	}

	/** FILT-03: route matching the glob denylist is excluded. */
	public function test_route_glob_match_denies(): void {
		$settings                              = $this->settings_allow_all();
		$settings['capture']['route_denylist'] = [ '/wp/v2/users/*' ];
		$request                               = $this->mock_request( '/wp/v2/users/42' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertFalse( $result );
	}

	/** FILT-05: deny wins when both allowlist and denylist match the same route. */
	public function test_route_deny_wins_on_conflict(): void {
		$settings                               = $this->settings_allow_all();
		$settings['capture']['route_allowlist'] = [ '/wp/v2/*' ];
		$settings['capture']['route_denylist']  = [ '/wp/v2/users/*' ];
		$request                                = $this->mock_request( '/wp/v2/users/42' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertFalse( $result );
	}

	/** Empty allowlist captures every route (allow-all semantic). */
	public function test_empty_allowlist_treated_as_allow_all(): void {
		$settings                               = $this->settings_allow_all();
		$settings['capture']['route_allowlist'] = [];
		$settings['capture']['route_denylist']  = [];
		$request                                = $this->mock_request( '/any/route/here' );

		$result = Filter::should_capture_at_pre_dispatch( $request, $settings );

		$this->assertTrue( $result );
	}

	/** Glob_to_regex asterisk becomes a greedy .* that matches sub-paths. */
	public function test_glob_to_regex_handles_asterisk(): void {
		$regex = Filter::glob_to_regex( '/wp/v2/*' );

		$this->assertMatchesRegularExpression( $regex, '/wp/v2/posts' );
		$this->assertMatchesRegularExpression( $regex, '/wp/v2/posts/1/revisions' );
	}

	/** Glob_to_regex preg-quotes regex metacharacters like the literal dot. */
	public function test_glob_to_regex_quotes_metachars(): void {
		$regex = Filter::glob_to_regex( '/wp/v2/posts.json' );

		$this->assertMatchesRegularExpression( $regex, '/wp/v2/posts.json' );
		$this->assertDoesNotMatchRegularExpression( $regex, '/wp/v2/postsXjson' );
	}

	/** FILT-05: status-class filter excludes responses whose class is not in the list. */
	public function test_status_class_filter_excludes_when_set(): void {
		$settings = [
			'capture' => [
				'status_class_filter' => [ 4, 5 ],
			],
		];

		$this->assertFalse( Filter::should_capture_at_shutdown( 200, $settings ) );
		$this->assertTrue( Filter::should_capture_at_shutdown( 404, $settings ) );
	}

	/** FILT-05: empty status-class list captures every response code. */
	public function test_status_class_filter_passes_when_empty(): void {
		$settings = [ 'capture' => [ 'status_class_filter' => [] ] ];

		$this->assertTrue( Filter::should_capture_at_shutdown( 200, $settings ) );
	}

	/** PRIV-06: is_auth_endpoint matches a route in the suppression list. */
	public function test_auth_endpoint_match_returns_true_for_known_path(): void {
		$auth_paths = [ '/jwt-auth/v1/token' ];

		$this->assertTrue( Filter::is_auth_endpoint( '/jwt-auth/v1/token', $auth_paths ) );
	}

	/** PRIV-06: is_auth_endpoint returns false for routes not in the suppression list. */
	public function test_auth_endpoint_returns_false_for_non_auth_route(): void {
		$auth_paths = [ '/jwt-auth/v1/token' ];

		$this->assertFalse( Filter::is_auth_endpoint( '/wp/v2/posts', $auth_paths ) );
	}
}
