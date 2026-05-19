<?php
/**
 * PHPStan-only stubs for WP-CLI.
 *
 * Phase 1 shipped the WP_CLI constant definition so code guarded with
 * `defined('WP_CLI')` does not produce undefined-constant errors during
 * static analysis.
 *
 * Phase 4 extends this file with class and function declarations so that
 * references to \WP_CLI::*, \WP_CLI_Command, and \WP_CLI\Utils\format_items
 * inside includes/cli/ resolve cleanly at PHPStan level 6.
 *
 * This file is NEVER loaded at runtime — it is listed in the `bootstrapFiles`
 * directive of phpstan.neon.dist only. The real WP-CLI classes are provided by
 * the actual WP-CLI package when running in a WP-CLI context.
 *
 * In integration tests, \WP_CLI::error() throws \WP_CLI\ExitException so
 * that tests can catch error-path exits without PHP actually exiting.
 * \WP_CLI_Command::run() calls __invoke() so the command verb fires.
 */

namespace {
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', false );
	}
}

namespace {
	if ( ! class_exists( 'WP_CLI_Command', false ) ) {
		/**
		 * Base class for all WP-CLI command classes.
		 *
		 * Real WP-CLI dispatches via run(); commands override __invoke() or
		 * named methods. The stub's run() calls __invoke() so integration
		 * tests can exercise the full verb body without bootstrapping WP-CLI.
		 */
		class WP_CLI_Command {
			/**
			 * @param array<int, mixed>    $args       Positional arguments.
			 * @param array<string, mixed> $assoc_args Named/flag arguments.
			 */
			public function run( array $args, array $assoc_args ): void {
				$this->__invoke( $args, $assoc_args );
			}

			/**
			 * @param array<int, mixed>    $args
			 * @param array<string, mixed> $assoc_args
			 */
			public function __invoke( array $args, array $assoc_args ): void {}
		}
	}
}

namespace {
	if ( ! class_exists( 'WP_CLI', false ) ) {
		/**
		 * WP-CLI façade used by command classes.
		 *
		 * error() throws \WP_CLI\ExitException so integration tests can assert
		 * the error path without the PHP process exiting.
		 */
		class WP_CLI {
			/**
			 * @param string|array<mixed>|\WP_Error $message
			 * @param bool                          $exit
			 * @throws \WP_CLI\ExitException Always.
			 * @return never
			 */
			public static function error( $message, bool $exit = true ): void {
				$text = is_string( $message ) ? $message : (string) print_r( $message, true );
				throw new \WP_CLI\ExitException( $text, 1 );
			}

			/** @param string $message */
			public static function success( string $message ): void {
				echo '[success] ' . $message . "\n";
			}

			/** @param string $message */
			public static function log( string $message ): void {
				echo $message . "\n";
			}

			/** @param string $message */
			public static function line( string $message = '' ): void {
				echo $message . "\n";
			}

			/** @param string $message */
			public static function warning( string $message ): void {
				echo '[warning] ' . $message . "\n";
			}

			/** @param string $string */
			public static function colorize( string $string ): string {
				return $string;
			}

			/** @param int $code */
			public static function halt( int $code ): void {
				exit( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			/**
			 * @param string               $name
			 * @param string|callable|object $callable
			 * @param array<string, mixed> $args
			 */
			public static function add_command( string $name, $callable, array $args = [] ): void {}

			/**
			 * Interactive confirmation prompt.
			 *
			 * @param string               $question
			 * @param array<string, mixed> $assoc_args  Pass ['yes' => true] to skip the prompt.
			 */
			public static function confirm( string $question, array $assoc_args = [] ): void {}
		}
	}
}

namespace WP_CLI {
	if ( ! class_exists( 'WP_CLI\\ExitException', false ) ) {
		/** Thrown by WP_CLI::error() in test and stub contexts instead of calling exit(). */
		class ExitException extends \RuntimeException {}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
		/**
		 * Render $items as a table, JSON, CSV, or YAML block.
		 *
		 * In the stub, all formats fall back to JSON so integration tests that
		 * capture output can decode it regardless of the requested $format.
		 *
		 * @param string                         $format 'table'|'json'|'csv'|'yaml'
		 * @param array<int, array<string,mixed>> $items  Rows to render.
		 * @param array<int, string>              $fields Column names to include.
		 */
		function format_items( string $format, array $items, array $fields ): void {
			// Only pick declared fields.
			$filtered = [];
			foreach ( $items as $row ) {
				$picked = [];
				foreach ( $fields as $f ) {
					$picked[ $f ] = isset( $row[ $f ] ) ? $row[ $f ] : null;
				}
				$filtered[] = $picked;
			}

			if ( 'json' === $format ) {
				echo json_encode( $filtered ) . "\n";
				return;
			}

			if ( 'csv' === $format ) {
				if ( [] !== $filtered ) {
					echo implode( ',', $fields ) . "\n";
					foreach ( $filtered as $row ) {
						echo implode( ',', array_map( 'strval', array_values( $row ) ) ) . "\n";
					}
				}
				return;
			}

			// table / yaml — both fall back to a simple key: value block in the stub.
			foreach ( $filtered as $row ) {
				foreach ( $row as $k => $v ) {
					echo $k . ': ' . $v . "\n";
				}
				echo "---\n";
			}
		}
	}
}
