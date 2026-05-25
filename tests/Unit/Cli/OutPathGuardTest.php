<?php
/**
 * Unit tests for the path-traversal guard on Cli\Commands\ExportCommand.
 *
 * RED-bar scaffold (Wave 0): ExportCommand does not exist until Wave 1.
 * Uses class_exists() guard — skips cleanly rather than fatalling the unit
 * suite. Skip reason names the implementing wave.
 *
 * The guard is a pure-PHP static method; the tests cover both slash styles
 * and a range of traversal payloads to pin the CLI-04 requirement.
 *
 * @package BetterRestApiLogs
 */

declare(strict_types=1);

namespace BetterRestApiLogs\Tests\Unit\Cli;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Covers the --out path-traversal guard (CLI-04).
 * All assertions are RED until Cli\Commands\ExportCommand is implemented in Wave 1.
 */
final class OutPathGuardTest extends TestCase {

	private function skip_until_wave1(): void {
		if ( ! \class_exists( 'BetterRestApiLogs\\Cli\\Commands\\ExportCommand' ) ) {
			$this->markTestSkipped( 'Cli\\Commands\\ExportCommand not implemented yet — Wave 1' );
		}
	}

	/**
	 * Invoke the guard. The plan spec names `is_safe_out_path` as the expected
	 * static method but the actual name will be confirmed in Wave 1.
	 *
	 * @param string $path Path under test.
	 * @return bool
	 */
	private function call_guard( string $path ): bool {
		// @phpstan-ignore-next-line - class absent until Wave 1.
		return \BetterRestApiLogs\Cli\Commands\ExportCommand::is_safe_out_path( $path );
	}

	/**
	 * @dataProvider unsafe_paths_provider
	 * @param string $path  The path to test.
	 * @param string $label Human-readable label for failure messages.
	 */
	public function test_traversal_paths_are_rejected( string $path, string $label ): void {
		$this->skip_until_wave1();
		$this->assertFalse( $this->call_guard( $path ), "Path '{$label}' must be rejected." );
	}

	/** @return array<string,array{string,string}> */
	public function unsafe_paths_provider(): array {
		return [
			'relative dotdot'         => [ '../x', '../x' ],
			'nested dotdot'           => [ 'a/../b', 'a/../b' ],
			'bare dotdot'             => [ '..', '..' ],
			'windows-style dotdot'    => [ '..\\win', '..\\win' ],
			'leading-slash traversal' => [ '/etc/../..', '/etc/../..' ],
			'absolute with traversal' => [ '/var/www/../../../etc/passwd', '/var/www/../../../etc/passwd' ],
		];
	}

	/**
	 * @dataProvider safe_paths_provider
	 * @param string $path  The path to test.
	 * @param string $label Human-readable label for failure messages.
	 */
	public function test_safe_paths_are_accepted( string $path, string $label ): void {
		$this->skip_until_wave1();
		$this->assertTrue( $this->call_guard( $path ), "Path '{$label}' must be accepted." );
	}

	/** @return array<string,array{string,string}> */
	public function safe_paths_provider(): array {
		return [
			'flat csv filename'        => [ 'out.csv', 'out.csv' ],
			'subdirectory ndjson'      => [ 'sub/out.ndjson', 'sub/out.ndjson' ],
			'relative with single dot' => [ './export.csv', './export.csv' ],
			'deep safe subdirectory'   => [ 'exports/2026/brl-export.csv', 'exports/2026/brl-export.csv' ],
		];
	}
}
