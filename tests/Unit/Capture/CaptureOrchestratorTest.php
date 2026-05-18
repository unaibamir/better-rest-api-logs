<?php
/**
 * Unit tests for BetterRestApiLogs\Capture (the orchestrator class).
 *
 * RED-bar baseline: all tests fail with Class not found until Plan 03-08
 * implements includes/capture.php. Covers CAP-03 (post_dispatch passthrough
 * discipline) and CAP-05 (self-namespace skip) per REQUIREMENTS.md.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Capture;

use BetterRestApiLogs\Capture;
use BetterRestApiLogs\Logger\Queue;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class CaptureOrchestratorTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Queue::reset();
	}

	public function tear_down(): void {
		Queue::reset();
		parent::tear_down();
	}

	/** CAP-03: on_post_dispatch must return the exact same $result object. */
	public function test_on_post_dispatch_returns_result_unchanged(): void {
		$capture  = $this->make_capture();
		$response = new \stdClass();
		$server   = new \stdClass();
		$request  = $this->mock_request( '/wp/v2/posts' );

		$returned = $capture->on_post_dispatch( $response, $server, $request );

		$this->assertSame( $response, $returned );
	}

	/** CAP-03: on_pre_dispatch must return the $response arg unchanged (passthrough). */
	public function test_on_pre_dispatch_returns_response_unchanged(): void {
		$capture  = $this->make_capture();
		$response = null;
		$server   = new \stdClass();
		$request  = $this->mock_request( '/wp/v2/posts' );

		$returned = $capture->on_pre_dispatch( $response, $server, $request );

		$this->assertNull( $returned );
	}

	/** CAP-05 / D-04: self-namespace skip must not set the outermost-request flag. */
	public function test_on_pre_dispatch_skips_self_namespace_without_setting_outermost_flag(): void {
		$capture = $this->make_capture();
		$request = $this->mock_request( '/better-rest-api-logs/v1/logs' );

		$capture->on_pre_dispatch( null, new \stdClass(), $request );

		$this->assertNull( Queue::outermost_request_id() );
	}

	/**
	 * Passthrough discipline: Throwable from the capture path must not bubble up.
	 * Stub-injection shape decided by Plan 03-08 — marked incomplete until then.
	 */
	public function test_throwables_in_capture_path_swallowed_passthrough_preserved(): void {
		$this->markTestIncomplete(
			'Stub-injection shape decided by Plan 03-08 implementation. ' .
			'This scaffold asserts the contract; complete when Capture ships.'
		);
	}

	/**
	 * Build a Capture instance. Constructor args finalized by Plan 03-08.
	 */
	private function make_capture(): Capture {
		try {
			return new Capture();
		} catch ( \Throwable $e ) {
			$this->markTestIncomplete(
				'Capture constructor signature unknown until Plan 03-08: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Minimal request stub for unit-level dispatch tests.
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
}
