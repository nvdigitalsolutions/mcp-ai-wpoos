#!/usr/bin/env php
<?php
/**
 * Audit AJAX handler coverage across the codebase.
 *
 * Greps `wp_ajax_*` and `wp_ajax_nopriv_*` registrations across `includes/` and
 * `addons/`, resolves each to its callback, source file, line, required
 * capability, and nonce action, then cross-references which handlers are
 * referenced by anything in `tests/`. Produces:
 *
 *   - docs/developer/testing-docs/ajax-handler-inventory.md  (human-readable summary table)
 *   - docs/developer/testing-docs/ajax-handler-inventory.csv (machine-readable, for diffing)
 *
 * Usage:
 *   php bin/audit-ajax-handlers.php           # regenerate inventory files
 *   php bin/audit-ajax-handlers.php --check   # CI mode: exit 1 if there are
 *                                             # untested handlers not on the
 *                                             # explicit allow-list
 *
 * The allow-list lives at tests/ajax-coverage-allowlist.txt; one handler name
 * per line (e.g. `wp_mcp_ai_get_dashboard_data`), `#` for comments.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals -- CLI script.

$plugin_root = dirname( __DIR__ );

$check_mode = in_array( '--check', $argv, true );

$scan_dirs = array(
	$plugin_root . '/includes',
	$plugin_root . '/addons',
);

$handlers = array(); // action => array of registrations.

foreach ( $scan_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}

	$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iter as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$path = $file->getPathname();
		if ( substr( $path, -4 ) !== '.php' ) {
			continue;
		}
		// Skip vendor, node_modules and tests inside addons (they're separate).
		if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/node_modules/' ) !== false ) {
			continue;
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			continue;
		}

		$lines = explode( "\n", $contents );

		// Find add_action( 'wp_ajax_…' or 'wp_ajax_nopriv_…' lines.
		foreach ( $lines as $idx => $line ) {
			if ( ! preg_match(
				"/add_action\\(\\s*['\"](wp_ajax(?:_nopriv)?_(wp_mcp_ai_[a-z0-9_]+))['\"]\\s*,\\s*(.+?)\\)\\s*;/i",
				$line,
				$matches
			) ) {
				continue;
			}

			$full_action = $matches[1];
			$handler_key = $matches[2];
			$callback    = trim( $matches[3] );

			// Look back up to 80 lines for nonce action and required capability hints.
			$context_start = max( 0, $idx - 5 );
			$context_end   = min( count( $lines ) - 1, $idx + 80 );
			$context       = implode( "\n", array_slice( $lines, $context_start, $context_end - $context_start + 1 ) );

			$nonce_action = '';
			if ( preg_match( "/check_ajax_referer\\(\\s*['\"]([a-z0-9_\\-]+)['\"]/i", $context, $n_match ) ) {
				$nonce_action = $n_match[1];
			}

			$capability = '';
			if ( preg_match( "/current_user_can\\(\\s*['\"]([a-z0-9_]+)['\"]/i", $context, $c_match ) ) {
				$capability = $c_match[1];
			}

			$rel = ltrim( str_replace( $plugin_root, '', $path ), '/' );

			$handlers[ $handler_key ][] = array(
				'action'     => $full_action,
				'nopriv'     => strpos( $full_action, 'wp_ajax_nopriv_' ) === 0,
				'file'       => $rel,
				'line'       => $idx + 1,
				'callback'   => preg_replace( '/\\s+/', ' ', $callback ),
				'nonce'      => $nonce_action,
				'capability' => $capability,
				'pro'        => strpos( $rel, 'addons/pro/' ) === 0,
			);
		}
	}
}

ksort( $handlers );

// Cross-reference with tests/.
$tested = array();
$tests_dir = $plugin_root . '/tests';
if ( is_dir( $tests_dir ) ) {
	$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tests_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$path = $file->getPathname();
		if ( substr( $path, -4 ) !== '.php' && substr( $path, -3 ) !== '.md' ) {
			continue;
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			continue;
		}
		if ( preg_match_all( '/wp_mcp_ai_[a-z0-9_]+/i', $contents, $m ) ) {
			foreach ( $m[0] as $name ) {
				if ( isset( $handlers[ $name ] ) ) {
					$tested[ $name ] = true;
				}
			}
		}
	}
}

// Load allow-list.
$allowlist_file = $plugin_root . '/tests/ajax-coverage-allowlist.txt';
$allowlist      = array();
if ( is_file( $allowlist_file ) ) {
	foreach ( file( $allowlist_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || $line[0] === '#' ) {
			continue;
		}
		$allowlist[ $line ] = true;
	}
}

// Aggregate.
$total      = count( $handlers );
$base_count = 0;
$pro_count  = 0;
$untested   = array();

foreach ( $handlers as $name => $regs ) {
	$is_pro = false;
	foreach ( $regs as $r ) {
		if ( $r['pro'] ) {
			$is_pro = true;
			break;
		}
	}
	if ( $is_pro ) {
		++$pro_count;
	} else {
		++$base_count;
	}
	if ( ! isset( $tested[ $name ] ) ) {
		$untested[] = $name;
	}
}

$tested_count   = $total - count( $untested );
$untested_count = count( $untested );

if ( $check_mode ) {
	$gaps = array();
	foreach ( $untested as $name ) {
		if ( ! isset( $allowlist[ $name ] ) ) {
			$gaps[] = $name;
		}
	}
	if ( empty( $gaps ) ) {
		printf(
			"AJAX coverage: %d/%d handlers covered (%d allow-listed). No new gaps.\n",
			$tested_count,
			$total,
			count( $allowlist )
		);
		exit( 0 );
	}
	fprintf(
		STDERR,
		"AJAX coverage check FAILED: %d untested handler(s) are not on the allow-list:\n",
		count( $gaps )
	);
	foreach ( $gaps as $g ) {
		fprintf( STDERR, "  - %s\n", $g );
	}
	fprintf( STDERR, "\nAdd a test that references the handler name, or add it to tests/ajax-coverage-allowlist.txt with a reason.\n" );
	exit( 1 );
}

// Emit Markdown inventory.
$md  = "# AJAX Handler Inventory\n\n";
$md .= "_Generated by `bin/audit-ajax-handlers.php` — do not edit by hand._\n\n";
$md .= "Run `php bin/audit-ajax-handlers.php` to regenerate.\n\n";
$md .= "## Coverage Summary\n\n";
$md .= "| Metric | Count |\n|---|---:|\n";
$md .= sprintf( "| Total registered AJAX handlers | %d |\n", $total );
$md .= sprintf( "| - Base (`includes/`) | %d |\n", $base_count );
$md .= sprintf( "| - Pro (`addons/pro/`) | %d |\n", $pro_count );
$md .= sprintf( "| Tested (referenced in `tests/`) | %d |\n", $tested_count );
$md .= sprintf( "| Untested | %d |\n", $untested_count );
$md .= sprintf( "| On coverage allow-list | %d |\n", count( $allowlist ) );
$md .= sprintf(
	"| Coverage | %.1f%% |\n\n",
	$total > 0 ? ( $tested_count / $total ) * 100 : 0.0
);

$md .= "## Handler Table\n\n";
$md .= "| Handler | Source | Capability | Nonce | Tested |\n|---|---|---|---|:---:|\n";

foreach ( $handlers as $name => $regs ) {
	$first      = $regs[0];
	$is_tested  = isset( $tested[ $name ] );
	$tested_str = $is_tested ? '✅' : ( isset( $allowlist[ $name ] ) ? '⏭ allow-list' : '❌' );
	$source     = sprintf( '`%s:%d`', $first['file'], $first['line'] );
	$cap        = '' !== $first['capability'] ? '`' . $first['capability'] . '`' : '_n/a_';
	$nonce      = '' !== $first['nonce'] ? '`' . $first['nonce'] . '`' : '_n/a_';
	$md        .= sprintf(
		"| `%s` | %s | %s | %s | %s |\n",
		$name,
		$source,
		$cap,
		$nonce,
		$tested_str
	);
}

$md_file = $plugin_root . '/docs/developer/testing-docs/ajax-handler-inventory.md';
if ( ! is_dir( dirname( $md_file ) ) ) {
	mkdir( dirname( $md_file ), 0755, true );
}
file_put_contents( $md_file, $md );

// Emit CSV.
$csv_file = $plugin_root . '/docs/developer/testing-docs/ajax-handler-inventory.csv';
$fh       = fopen( $csv_file, 'w' );
fputcsv( $fh, array( 'handler', 'file', 'line', 'capability', 'nonce_action', 'is_pro', 'is_tested', 'on_allowlist' ) );
foreach ( $handlers as $name => $regs ) {
	$first = $regs[0];
	fputcsv(
		$fh,
		array(
			$name,
			$first['file'],
			$first['line'],
			$first['capability'],
			$first['nonce'],
			$first['pro'] ? '1' : '0',
			isset( $tested[ $name ] ) ? '1' : '0',
			isset( $allowlist[ $name ] ) ? '1' : '0',
		)
	);
}
fclose( $fh );

printf(
	"Wrote %s (%d handlers, %d tested, %d untested).\n",
	str_replace( $plugin_root . '/', '', $md_file ),
	$total,
	$tested_count,
	$untested_count
);
printf(
	"Wrote %s.\n",
	str_replace( $plugin_root . '/', '', $csv_file )
);
