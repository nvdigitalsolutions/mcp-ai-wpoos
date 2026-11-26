<?php
/**
 * Cross-language documentation contract checker (Proposal 166, P8–P12).
 *
 * Resolves every first-party source file against `.github/docs-scopes.json`,
 * compares its SPDX identifier to the licence of its nearest manifest, and
 * checks that it opens with a purpose statement sized to its file class.
 *
 * Two enforcement modes:
 *
 *   --strict            the whole tree must be at or under its recorded budget
 *   --new-files-only    only files added since the base ref are checked, and
 *                       they must be fully compliant (the ratchet's ratchet)
 *
 * The budget mirrors `.github/scripts/phpcs-ratchet.php`: no scope over budget,
 * no stale slack, no raises without `--allow-increase`, named scopes only.
 *
 * @package WP_MCP_AI
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace WP_MCP_AI\Bin\CheckFileDocs;

require_once __DIR__ . '/lib/header-contract.php';

use function WP_MCP_AI\Bin\DocsContract\dirty_files;
use function WP_MCP_AI\Bin\DocsContract\file_class;
use function WP_MCP_AI\Bin\DocsContract\has_overview;
use function WP_MCP_AI\Bin\DocsContract\insert_spdx;
use function WP_MCP_AI\Bin\DocsContract\is_generated;
use function WP_MCP_AI\Bin\DocsContract\lang_for;
use function WP_MCP_AI\Bin\DocsContract\list_files;
use function WP_MCP_AI\Bin\DocsContract\load_config;
use function WP_MCP_AI\Bin\DocsContract\new_files;
use function WP_MCP_AI\Bin\DocsContract\path_excluded;
use function WP_MCP_AI\Bin\DocsContract\pending_product;
use function WP_MCP_AI\Bin\DocsContract\purpose_bar;
use function WP_MCP_AI\Bin\DocsContract\read_head;
use function WP_MCP_AI\Bin\DocsContract\resolve_licence;
use function WP_MCP_AI\Bin\DocsContract\root;
use function WP_MCP_AI\Bin\DocsContract\scope_for;
use function WP_MCP_AI\Bin\DocsContract\scopes_for;
use function WP_MCP_AI\Bin\DocsContract\spdx_from_head;

$root   = root();
$config = load_config( $root );

/**
 * Parse CLI options.
 *
 * @return array
 */
function parse_args(): array {
	$opts = array(
		'strict'          => false,
		'scope'           => array(),
		'json'            => false,
		'explain'         => '',
		'audit'           => false,
		'licence_only'    => false,
		'self_test'       => false,
		'update'          => false,
		'allow_increase'  => false,
		'reason'          => '',
		'new_files_only'  => false,
		'base'            => '',
		'allow_dirty'     => false,
		'timing'          => false,
		'folder_docs'     => false,
	);

	foreach ( array_slice( $GLOBALS['argv'], 1 ) as $arg ) {
		if ( '--strict' === $arg ) {
			$opts['strict'] = true;
		} elseif ( '--json' === $arg ) {
			$opts['json'] = true;
		} elseif ( '--audit-scopes' === $arg ) {
			$opts['audit'] = true;
		} elseif ( '--licence-only' === $arg ) {
			$opts['licence_only'] = true;
		} elseif ( '--self-test' === $arg ) {
			$opts['self_test'] = true;
		} elseif ( '--update' === $arg ) {
			$opts['update'] = true;
		} elseif ( '--allow-increase' === $arg ) {
			$opts['allow_increase'] = true;
		} elseif ( '--new-files-only' === $arg ) {
			$opts['new_files_only'] = true;
		} elseif ( '--allow-dirty' === $arg ) {
			$opts['allow_dirty'] = true;
		} elseif ( '--timing' === $arg ) {
			$opts['timing'] = true;
		} elseif ( '--folder-docs' === $arg ) {
			$opts['folder_docs'] = true;
		} elseif ( '--help' === $arg || '-h' === $arg ) {
			usage();
		} elseif ( 0 === strpos( $arg, '--scope=' ) ) {
			$opts['scope'] = array_filter( array_map( 'trim', explode( ',', substr( $arg, 8 ) ) ) );
		} elseif ( 0 === strpos( $arg, '--base=' ) ) {
			$opts['base'] = substr( $arg, 7 );
		} elseif ( 0 === strpos( $arg, '--reason=' ) ) {
			$opts['reason'] = substr( $arg, 9 );
		} elseif ( 0 === strpos( $arg, '--explain=' ) ) {
			$opts['explain'] = substr( $arg, 10 );
		} else {
			fwrite( STDERR, "Unknown argument: {$arg}\n" );
			usage( 2 );
		}
	}

	return $opts;
}

/**
 * Print usage.
 *
 * @param int $code Exit code.
 * @return void
 */
function usage( int $code = 0 ): void {
	$text = <<<TXT
Usage: php bin/check-file-docs.php [options]

  --strict                 Exit 1 when a scope is over budget (or has stale slack)
  --scope=a,b              Limit to named scopes
  --json                   Machine-readable output (deterministic)
  --explain=<file>         Explain the classification and checks for one file
  --audit-scopes           Report unscoped, unresolved, pending and overlapping paths
  --licence-only           Check SPDX presence/agreement only
  --new-files-only         Check only files added since --base (default: staged/untracked)
  --base=<ref>             Base ref for --new-files-only (e.g. origin/alpha-working)
  --allow-dirty            Skip files with uncommitted changes
  --update                 Rewrite .github/docs-budget.json from the current tree
  --allow-increase         Permit --update to raise a count (requires --reason)
  --reason=<text>          Justification for a budget increase
  --self-test              Run the header-inserter fixtures
  --folder-docs            Check the folder READMEs listed in docs-scopes.json
  --timing                 Print stage timings
  -h, --help               This help

Exit codes: 0 clean, 1 violation/over budget, 2 configuration error.
TXT;
	fwrite( 0 === $code ? STDOUT : STDERR, $text . "\n" );
	exit( $code );
}

/**
 * Load the committed budget.
 *
 * @param string $root Repository root.
 * @return array
 */
function load_budget( string $root ): array {
	$path = $root . '/.github/docs-budget.json';
	if ( ! is_file( $path ) ) {
		return array(
			'version'   => 1,
			'tolerance' => 25,
			'scopes'    => array(),
		);
	}

	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) || ! isset( $data['scopes'] ) ) {
		fwrite( STDERR, "Invalid budget file: {$path}\n" );
		exit( 2 );
	}

	return $data;
}

/**
 * Check one file.
 *
 * @param string $root    Repository root.
 * @param string $rel     Repository-relative path.
 * @param array  $scope   Matching scope.
 * @param array  $config  Scope map.
 * @param bool   $licence_only Licence-only mode.
 * @return array
 */
function check_file( string $root, string $rel, array $scope, array $config, bool $licence_only ): array {
	$lang     = (string) lang_for( $rel );
	$abs      = $root . '/' . $rel;
	$head     = read_head( $abs );
	$expected = resolve_licence( $root, $rel, $scope, $config );
	$actual   = spdx_from_head( $head );
	$class    = file_class( $rel, $lang );
	$generated = is_generated( $rel, $head );

	$result = array(
		'file'       => $rel,
		'scope'      => $scope['name'],
		'lang'       => $lang,
		'class'      => $class,
		'expected'   => $expected,
		'actual'     => $actual,
		'missing_spdx' => null === $actual,
		'wrong_spdx'   => null !== $actual && null !== $expected && 0 !== strcasecmp( $actual, $expected ),
		'missing_overview' => false,
		'generated'  => $generated,
	);

	if ( $licence_only ) {
		return $result;
	}

	if ( ! $generated ) {
		$has = has_overview( $head, $lang );
		$bar = $has && purpose_bar( $head, $lang, $class, $rel );
		$result['missing_overview'] = ! $bar;
		$result['has_overview']     = $has;
	}

	return $result;
}

/**
 * Build the resolved file list.
 *
 * @param string $root   Repository root.
 * @param array  $config Scope map.
 * @param array  $opts   CLI options.
 * @return array{files: array<int, array>, pending: array<string,int>, unscoped: string[], unresolved: string[], overlaps: array<string,string[]>}
 */
function build_inventory( string $root, array $config, array $opts ): array {
	$all     = list_files( $root );
	$dirty   = $opts['allow_dirty'] ? array() : dirty_files( $root );
	$files   = array();
	$pending = array();
	$unscoped = array();
	$unresolved = array();
	$overlaps = array();

	$scope_filter = $opts['scope'];

	foreach ( $all as $rel ) {
		if ( path_excluded( $rel, $config ) ) {
			continue;
		}

		if ( isset( $dirty[ $rel ] ) ) {
			continue;
		}

		$pending_prefix = pending_product( $rel, $config );
		if ( null !== $pending_prefix ) {
			$pending[ $pending_prefix ] = isset( $pending[ $pending_prefix ] ) ? $pending[ $pending_prefix ] + 1 : 1;
			continue;
		}

		$matches = scopes_for( $rel, $config );
		if ( count( $matches ) > 1 ) {
			$overlaps[ $rel ] = array_map(
				static function ( $scope ) {
					return $scope['name'];
				},
				$matches
			);
		}

		$scope = $matches[0] ?? null;
		if ( null === $scope ) {
			$unscoped[] = $rel;
			continue;
		}

		if ( ! empty( $scope_filter ) && ! in_array( $scope['name'], $scope_filter, true ) ) {
			continue;
		}

		$expected = resolve_licence( $root, $rel, $scope, $config );
		if ( null === $expected ) {
			$unresolved[] = $rel;
			continue;
		}

		$files[] = array(
			'rel'      => $rel,
			'scope'    => $scope,
			'expected' => $expected,
		);
	}

	return array(
		'files'      => $files,
		'pending'    => $pending,
		'unscoped'   => $unscoped,
		'unresolved' => $unresolved,
		'overlaps'   => $overlaps,
	);
}

/**
 * Run the inserter self-test.
 *
 * @param string $root Repository root.
 * @return int
 */
function self_test( string $root ): int {
	$cases_file = $root . '/tests/fixtures/headers/cases.json';
	if ( ! is_file( $cases_file ) ) {
		fwrite( STDERR, "Missing fixture manifest: {$cases_file}\n" );
		return 2;
	}

	$cases = json_decode( (string) file_get_contents( $cases_file ), true );
	if ( ! is_array( $cases ) ) {
		fwrite( STDERR, "Invalid fixture manifest: {$cases_file}\n" );
		return 2;
	}

	$failed = 0;
	foreach ( $cases as $case ) {
		$input    = $root . '/tests/fixtures/headers/' . $case['input'];
		$expected = $root . '/tests/fixtures/headers/' . $case['expected'];
		$code     = file_get_contents( $input );
		$want     = file_get_contents( $expected );

		if ( false === $code || false === $want ) {
			fwrite( STDOUT, sprintf( "FAIL %-24s (missing fixture)\n", $case['name'] ) );
			++$failed;
			continue;
		}

		$once  = insert_spdx( $code, $case['lang'], $case['licence'] );
		$twice = insert_spdx( $once, $case['lang'], $case['licence'] );

		if ( $once !== $want ) {
			fwrite( STDOUT, sprintf( "FAIL %-24s (output mismatch)\n", $case['name'] ) );
			++$failed;
			continue;
		}

		if ( $twice !== $once ) {
			fwrite( STDOUT, sprintf( "FAIL %-24s (not idempotent)\n", $case['name'] ) );
			++$failed;
			continue;
		}

		$found = spdx_from_head( $once );
		if ( $found !== $case['licence'] ) {
			fwrite( STDOUT, sprintf( "FAIL %-24s (SPDX read-back: %s)\n", $case['name'], (string) $found ) );
			++$failed;
			continue;
		}

		fwrite( STDOUT, sprintf( "PASS %-24s\n", $case['name'] ) );
	}

	return $failed > 0 ? 1 : 0;
}

/**
 * Check the folder READMEs listed in the scope map.
 *
 * Rust module directories are documented by `//!`, so only non-module folders
 * are listed. The list is explicit: a new folder is a reviewable change.
 *
 * @param string $root   Repository root.
 * @param array  $config Scope map.
 * @return int
 */
function check_folder_docs( string $root, array $config ): int {
	$folders = isset( $config['folder_readmes'] ) && is_array( $config['folder_readmes'] )
		? $config['folder_readmes']
		: array();

	if ( empty( $folders ) ) {
		fwrite( STDOUT, "No folder READMEs configured.\n" );
		return 0;
	}

	$required = array( 'purpose', 'tier', 'public surface', 'inputs / outputs / neighbors', 'conventions', 'tests', 'also load' );
	$failed   = 0;

	foreach ( $folders as $folder ) {
		$folder = trim( (string) $folder, '/' );
		$abs    = $root . '/' . $folder . '/README.md';

		if ( ! is_file( $abs ) ) {
			fwrite( STDOUT, "FAIL {$folder} (no README.md)\n" );
			++$failed;
			continue;
		}

		$text = (string) file_get_contents( $abs );
		preg_match_all( '/^##\s+(.+)$/m', $text, $matches );

		$heads = array();
		foreach ( $matches[1] as $head ) {
			$heads[] = strtolower( trim( (string) preg_replace( '/[*_`]/', '', $head ) ) );
		}

		$missing = array();
		foreach ( $required as $req ) {
			$found = false;
			foreach ( $heads as $head ) {
				if ( $head === $req || ( 'inputs / outputs / neighbors' === $req && 'inputs / outputs / neighbours' === $head ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $req;
			}
		}

		if ( ! empty( $missing ) ) {
			fwrite( STDOUT, "FAIL {$folder} (missing: " . implode( ', ', $missing ) . ")\n" );
			++$failed;
		} else {
			fwrite( STDOUT, "PASS {$folder}\n" );
		}
	}

	return $failed > 0 ? 1 : 0;
}

$opts  = parse_args();
$start = microtime( true );

if ( $opts['self_test'] ) {
	exit( self_test( $root ) );
}

if ( $opts['folder_docs'] ) {
	exit( check_folder_docs( $root, $config ) );
}

$inventory = build_inventory( $root, $config, $opts );

if ( $opts['audit'] ) {
	$out = array(
		'pending'    => $inventory['pending'],
		'unscoped'   => $inventory['unscoped'],
		'unresolved' => $inventory['unresolved'],
		'overlaps'   => $inventory['overlaps'],
	);
	if ( $opts['json'] ) {
		fwrite( STDOUT, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	} else {
		fwrite( STDOUT, "Scope audit\n===========\n\n" );
		foreach ( $inventory['pending'] as $prefix => $count ) {
			fwrite( STDOUT, sprintf( "PENDING   %-48s %4d files (licence decision required)\n", $prefix, $count ) );
		}
		foreach ( array_slice( $inventory['unscoped'], 0, 20 ) as $rel ) {
			fwrite( STDOUT, "UNSCOPED  {$rel}\n" );
		}
		foreach ( array_slice( $inventory['unresolved'], 0, 20 ) as $rel ) {
			fwrite( STDOUT, "NO-LICENCE {$rel}\n" );
		}
		foreach ( array_slice( $inventory['overlaps'], 0, 20, true ) as $rel => $scopes ) {
			fwrite( STDOUT, 'OVERLAP   ' . $rel . ' → ' . implode( ', ', $scopes ) . "\n" );
		}
		fwrite(
			STDOUT,
			sprintf(
				"\nTotals: %d pending, %d unscoped, %d unresolved, %d overlapping\n",
				array_sum( $inventory['pending'] ),
				count( $inventory['unscoped'] ),
				count( $inventory['unresolved'] ),
				count( $inventory['overlaps'] )
			)
		);
	}

	$bad = count( $inventory['unscoped'] ) + count( $inventory['unresolved'] ) + count( $inventory['overlaps'] );
	exit( $bad > 0 ? 2 : 0 );
}

$files = $inventory['files'];

if ( $opts['new_files_only'] ) {
	$new   = array_flip( new_files( $root, $opts['base'] ) );
	$files = array_values(
		array_filter(
			$files,
			static function ( $entry ) use ( $new ) {
				return isset( $new[ $entry['rel'] ] );
			}
		)
	);
}

if ( '' !== $opts['explain'] ) {
	$target = str_replace( '\\', '/', $opts['explain'] );
	$target = ltrim( str_replace( $root . '/', '', $target ), '/' );
	foreach ( $files as $entry ) {
		if ( $entry['rel'] !== $target ) {
			continue;
		}
		$result = check_file( $root, $entry['rel'], $entry['scope'], $config, $opts['licence_only'] );
		fwrite( STDOUT, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		exit( 0 );
	}
	fwrite( STDERR, "Not in scope: {$target}\n" );
	exit( 2 );
}

$counts = array();
$violations = array();

foreach ( $files as $entry ) {
	$result = check_file( $root, $entry['rel'], $entry['scope'], $config, $opts['licence_only'] );
	$name   = $entry['scope']['name'];

	if ( ! isset( $counts[ $name ] ) ) {
		$counts[ $name ] = array(
			'files'            => 0,
			'missing_spdx'     => 0,
			'wrong_spdx'       => 0,
			'missing_overview' => 0,
		);
	}

	++$counts[ $name ]['files'];
	$counts[ $name ]['missing_spdx']     += $result['missing_spdx'] ? 1 : 0;
	$counts[ $name ]['wrong_spdx']       += $result['wrong_spdx'] ? 1 : 0;
	$counts[ $name ]['missing_overview'] += $result['missing_overview'] ? 1 : 0;

	$bad = $result['missing_spdx'] || $result['wrong_spdx'] || ( ! $opts['licence_only'] && $result['missing_overview'] );
	if ( $bad ) {
		$violations[] = $result;
	}
}

ksort( $counts );

if ( $opts['new_files_only'] ) {
	$ok = 0 === count( $violations );
	if ( $opts['json'] ) {
		fwrite(
			STDOUT,
			json_encode(
				array(
					'files'      => count( $files ),
					'violations' => $violations,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n"
		);
	} else {
		fwrite( STDOUT, sprintf( "New files: %d, violations: %d\n", count( $files ), count( $violations ) ) );
		foreach ( array_slice( $violations, 0, 20 ) as $v ) {
			fwrite( STDOUT, '  ✗ ' . $v['file'] . "\n" );
		}
	}
	exit( $ok ? 0 : 1 );
}

$budget  = load_budget( $root );
$tol     = isset( $budget['tolerance'] ) ? (int) $budget['tolerance'] : 25;
$budget_scopes = isset( $budget['scopes'] ) && is_array( $budget['scopes'] ) ? $budget['scopes'] : array();

if ( $opts['update'] ) {
	$raised = array();
	foreach ( $counts as $name => $actual ) {
		$old = $budget_scopes[ $name ] ?? null;
		if ( is_array( $old ) ) {
			foreach ( $actual as $key => $value ) {
				if ( 'files' === $key ) {
					continue; // Informational: a new compliant file must not count as debt.
				}
				if ( isset( $old[ $key ] ) && $value > (int) $old[ $key ] ) {
					$raised[ $name . '.' . $key ] = array( (int) $old[ $key ], $value );
				}
			}
		}
	}

	if ( ! empty( $raised ) && ! $opts['allow_increase'] ) {
		fwrite( STDERR, "Refusing to raise the docs budget without --allow-increase:\n" );
		foreach ( $raised as $key => $pair ) {
			fwrite( STDERR, sprintf( "  %-32s %d → %d\n", $key, $pair[0], $pair[1] ) );
		}
		exit( 2 );
	}

	if ( ! empty( $raised ) && '' === trim( $opts['reason'] ) ) {
		fwrite( STDERR, "--allow-increase requires a non-empty --reason.\n" );
		exit( 2 );
	}

	$budget['scopes'] = $counts;
	$budget['tolerance'] = $tol;
	$budget['version']   = 1;
	file_put_contents(
		$root . '/.github/docs-budget.json',
		json_encode( $budget, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);
	fwrite( STDOUT, "Wrote .github/docs-budget.json\n" );
	exit( 0 );
}

$budget_violations = array();
foreach ( $counts as $name => $actual ) {
	$old = $budget_scopes[ $name ] ?? null;
	if ( ! is_array( $old ) ) {
		$budget_violations[] = "{$name}: no budget recorded (run --update)";
		continue;
	}
	foreach ( $actual as $key => $value ) {
		if ( 'files' === $key ) {
			continue; // Informational: growth from compliant files is the goal.
		}
		$limit = isset( $old[ $key ] ) ? (int) $old[ $key ] : 0;
		if ( $value > $limit ) {
			$budget_violations[] = sprintf( '%s.%s: %d > %d', $name, $key, $value, $limit );
		}
		if ( $value < $limit - $tol ) {
			$budget_violations[] = sprintf( '%s.%s: %d is %d under %d (record the improvement with --update)', $name, $key, $value, $limit - $value, $limit );
		}
	}
}

$elapsed = microtime( true ) - $start;

if ( $opts['json'] ) {
	$out = array(
		'scopes'      => $counts,
		'pending'     => $inventory['pending'],
		'unscoped'    => $inventory['unscoped'],
		'unresolved'  => $inventory['unresolved'],
		'overlaps'    => $inventory['overlaps'],
		'budget'      => $budget_violations,
		'violations'  => $violations,
		'elapsed_ms'  => (int) round( $elapsed * 1000 ),
	);
	fwrite( STDOUT, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
} else {
	fwrite( STDOUT, "Documentation contract\n======================\n\n" );
	foreach ( $counts as $name => $actual ) {
		$old  = $budget_scopes[ $name ] ?? array();
		$line = sprintf(
			"%-18s files=%4d  spdx_missing=%4d  spdx_wrong=%3d  overview_missing=%4d",
			$name,
			$actual['files'],
			$actual['missing_spdx'],
			$actual['wrong_spdx'],
			$actual['missing_overview']
		);
		if ( ! empty( $old ) ) {
			$line .= sprintf(
				'  | budget %d/%d/%d',
				(int) ( $old['missing_spdx'] ?? 0 ),
				(int) ( $old['wrong_spdx'] ?? 0 ),
				(int) ( $old['missing_overview'] ?? 0 )
			);
		}
		fwrite( STDOUT, $line . "\n" );
	}

	if ( ! empty( $inventory['pending'] ) ) {
		fwrite( STDOUT, "\nPending licence decisions:\n" );
		foreach ( $inventory['pending'] as $prefix => $count ) {
			fwrite( STDOUT, sprintf( "  %-46s %4d files\n", $prefix, $count ) );
		}
	}

	if ( ! empty( $budget_violations ) ) {
		fwrite( STDOUT, "\nBudget:\n" );
		foreach ( $budget_violations as $line ) {
			fwrite( STDOUT, "  ! {$line}\n" );
		}
	}

	if ( ! empty( $violations ) && ! empty( $budget_violations ) ) {
		fwrite( STDOUT, sprintf( "\n%d file(s) below the contract (budget violation):\n", count( $violations ) ) );
		foreach ( array_slice( $violations, 0, 10 ) as $v ) {
			$why = array();
			if ( $v['missing_spdx'] ) {
				$why[] = 'no SPDX';
			}
			if ( $v['wrong_spdx'] ) {
				$why[] = 'SPDX ' . $v['actual'] . ' ≠ ' . $v['expected'];
			}
			if ( ! empty( $v['missing_overview'] ) ) {
				$why[] = 'no purpose header';
			}
			fwrite( STDOUT, '  ✗ ' . $v['file'] . ' — ' . implode( ', ', $why ) . "\n" );
		}
	}

	if ( $opts['timing'] ) {
		fwrite( STDOUT, sprintf( "\nElapsed: %d ms\n", (int) round( $elapsed * 1000 ) ) );
	}
}

$failed = ! empty( $budget_violations );
if ( ! $opts['strict'] ) {
	exit( 0 );
}
exit( $failed ? 1 : 0 );
