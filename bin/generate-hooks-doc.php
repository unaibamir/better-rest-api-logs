<?php
/**
 * Dev-time script: generate docs/HOOKS.md from includes/hooks.php.
 *
 * Run via:  composer hooks:gen
 *
 * Reads the Hooks class docblock, splits on the box-drawing divider lines,
 * parses each Filter/Action block, and emits a deterministic docs/HOOKS.md.
 *
 * NOT a shipped runtime class — lives in bin/ which .distignore excludes.
 *
 * Usage: php bin/generate-hooks-doc.php [--dry-run]
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

$repo_root  = dirname( __DIR__ );
$hooks_file = $repo_root . '/includes/hooks.php';
$out_file   = $repo_root . '/docs/HOOKS.md';
$dry_run    = in_array( '--dry-run', $argv ?? [], true );

if ( ! file_exists( $hooks_file ) ) {
	fwrite( STDERR, "ERROR: includes/hooks.php not found at {$hooks_file}\n" );
	exit( 1 );
}

$source = file_get_contents( $hooks_file );
if ( $source === false ) {
	fwrite( STDERR, "ERROR: Could not read {$hooks_file}\n" );
	exit( 1 );
}

// Extract the class docblock — everything between the opening /** of the Hooks
// class doc and the final */ before `final class Hooks`.
if ( ! preg_match( '#/\*\*(.*?)\*/\s*final class Hooks#s', $source, $m ) ) {
	fwrite( STDERR, "ERROR: Could not locate the Hooks class docblock in {$hooks_file}\n" );
	exit( 1 );
}

$docblock = $m[1];

/**
 * Strip the leading " * " or " *" docblock decoration from a single line.
 *
 * @param string $line Raw line from the docblock.
 * @return string
 */
function strip_doc_prefix( $line ) {
	// Remove leading whitespace + optional `*` + optional single space.
	return preg_replace( '/^\s*\*\s?/', '', $line );
}

// Split on the box-drawing divider lines (lines that are only `* ─────…`).
// The dividers appear as " * ──────…" in the raw docblock.
$segments = preg_split( '/^\s*\*\s*[─]+\s*$/m', $docblock );

// Parse each segment into a hook record.
$hooks = [];
foreach ( $segments as $seg ) {
	$seg = trim( $seg );
	if ( $seg === '' ) {
		continue;
	}

	// Must contain "Filter: brl_…" or "Action: brl_…" on a line (with docblock * prefix).
	if ( ! preg_match( '/^\s*\*?\s*(Filter|Action):\s*(brl_[a-z_]+)\s*$/m', $seg, $hm ) ) {
		continue;
	}

	$hook = [
		'type'    => strtolower( $hm[1] ), // 'filter' or 'action'
		'name'    => $hm[2],
		'desc'    => '',
		'since'   => '',
		'params'  => [],
		'example' => '',
	];

	$raw_lines  = explode( "\n", $seg );
	$in_desc    = false;
	$in_example = false;
	$example_lines = [];
	$desc_lines    = [];

	foreach ( $raw_lines as $raw_line ) {
		$line = strip_doc_prefix( $raw_line );

		// Hook header line — start collecting description on the next non-empty line.
		if ( preg_match( '/^(Filter|Action):\s*brl_/', $line ) ) {
			$in_desc    = true;
			$in_example = false;
			continue;
		}

		// Inside the @example block.
		if ( $in_example ) {
			// Another @tag after example ends the example block.
			if ( preg_match( '/^@[a-z]/', $line ) ) {
				$in_example = false;
				// Fall through to handle the new @tag below.
			} else {
				$example_lines[] = $line;
				continue;
			}
		}

		if ( preg_match( '/^@since\s+(.+)$/', $line, $sm ) ) {
			$hook['since'] = trim( $sm[1] );
			$in_desc       = false;
			continue;
		}

		if ( preg_match( '/^@param\s+(.+)$/', $line, $pm ) ) {
			$hook['params'][] = trim( $pm[1] );
			$in_desc          = false;
			continue;
		}

		if ( preg_match( '/^@example\s*$/', $line ) ) {
			$in_desc    = false;
			$in_example = true;
			continue;
		}

		// Skip any other @tag.
		if ( preg_match( '/^@[a-z]/', $line ) ) {
			$in_desc = false;
			continue;
		}

		if ( $in_desc ) {
			$desc_lines[] = $line;
		}
	}

	// Trim leading/trailing blank lines from description.
	while ( count( $desc_lines ) > 0 && trim( $desc_lines[0] ) === '' ) {
		array_shift( $desc_lines );
	}
	while ( count( $desc_lines ) > 0 && trim( $desc_lines[ count( $desc_lines ) - 1 ] ) === '' ) {
		array_pop( $desc_lines );
	}

	// Trim leading/trailing blank lines from example.
	while ( count( $example_lines ) > 0 && trim( $example_lines[0] ) === '' ) {
		array_shift( $example_lines );
	}
	while ( count( $example_lines ) > 0 && trim( $example_lines[ count( $example_lines ) - 1 ] ) === '' ) {
		array_pop( $example_lines );
	}

	$hook['desc']    = implode( "\n", $desc_lines );
	$hook['example'] = implode( "\n", $example_lines );

	$hooks[ $hook['name'] ] = $hook;
}

if ( count( $hooks ) === 0 ) {
	fwrite( STDERR, "ERROR: No hooks parsed from {$hooks_file}. Check the docblock format.\n" );
	exit( 1 );
}

// Sort alphabetically for deterministic output.
ksort( $hooks );

// --- Emit docs/HOOKS.md ---

$out_lines = [];
$out_lines[] = '# Better REST API Logs — Hook Reference';
$out_lines[] = '';
$out_lines[] = 'Every `brl_*` action and filter the plugin fires, with parameters and usage examples.';
$out_lines[] = '';
$out_lines[] = '> **Generated** from `includes/hooks.php` by `composer hooks:gen`.';
$out_lines[] = '> Do not edit by hand — changes will be overwritten on the next generation.';
$out_lines[] = '';
$out_lines[] = '---';
$out_lines[] = '';

// Table of contents.
$out_lines[] = '## Table of Contents';
$out_lines[] = '';
foreach ( $hooks as $name => $hook ) {
	$anchor     = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $name ) );
	$type_label = ucfirst( $hook['type'] );
	$out_lines[] = "- [{$type_label}: `{$name}`](#{$anchor})";
}
$out_lines[] = '';
$out_lines[] = '---';
$out_lines[] = '';

// Individual hook sections.
foreach ( $hooks as $name => $hook ) {
	$type_label = ucfirst( $hook['type'] );

	$out_lines[] = "## {$name}";
	$out_lines[] = '';
	$out_lines[] = "**Type:** {$type_label}  ";
	$out_lines[] = "**Since:** {$hook['since']}";
	$out_lines[] = '';

	if ( $hook['desc'] !== '' ) {
		$out_lines[] = $hook['desc'];
		$out_lines[] = '';
	}

	if ( count( $hook['params'] ) > 0 ) {
		$out_lines[] = '**Parameters:**';
		$out_lines[] = '';
		foreach ( $hook['params'] as $param ) {
			$out_lines[] = "- `{$param}`";
		}
		$out_lines[] = '';
	}

	if ( $hook['example'] !== '' ) {
		$out_lines[] = '**Example:**';
		$out_lines[] = '';
		$out_lines[] = '```php';

		$eg_lines = explode( "\n", $hook['example'] );

		// Detect the minimum leading indent across non-blank lines so we
		// can normalise the example block without losing relative indentation.
		$min_indent = PHP_INT_MAX;
		foreach ( $eg_lines as $el ) {
			if ( trim( $el ) === '' ) {
				continue;
			}
			$indent = strlen( $el ) - strlen( ltrim( $el, " \t" ) );
			if ( $indent < $min_indent ) {
				$min_indent = $indent;
			}
		}
		if ( $min_indent === PHP_INT_MAX ) {
			$min_indent = 0;
		}

		foreach ( $eg_lines as $el ) {
			$out_lines[] = ( strlen( $el ) >= $min_indent ) ? substr( $el, $min_indent ) : ltrim( $el );
		}

		$out_lines[] = '```';
		$out_lines[] = '';
	}

	$out_lines[] = '---';
	$out_lines[] = '';
}

$output = implode( "\n", $out_lines );

if ( $dry_run ) {
	echo $output;
	exit( 0 );
}

if ( file_put_contents( $out_file, $output ) === false ) {
	fwrite( STDERR, "ERROR: Could not write to {$out_file}\n" );
	exit( 1 );
}

$count = count( $hooks );
echo "generate-hooks-doc: wrote {$count} hooks to docs/HOOKS.md\n";
exit( 0 );
