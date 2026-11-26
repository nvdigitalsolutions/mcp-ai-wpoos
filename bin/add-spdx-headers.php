<?php
/**
 * add-spdx-headers.php — normalise licence declarations and add SPDX identifiers.
 *
 * The repo holds four differently-licensed products in one tree. Each source
 * file must declare the same licence as the package it belongs to, and expose
 * it in SPDX form so tooling can read it without parsing docblock tags.
 *
 *   includes/              GPL-3.0-or-later
 *   lib/core/src/          MIT                  (see lib/core/composer.json)
 *   addons/pro/includes/   Proprietary
 *   addons/canvas/         Proprietary
 *
 * PHP files keep their file-level docblock (`@license` + SPDX). Every other
 * first-party source file gets a single SPDX line resolved from
 * `.github/docs-scopes.json`:
 *
 *   TS / JS / Astro   // SPDX-License-Identifier: …   (line 1, or after #!)
 *   Rust              // SPDX-License-Identifier: …   (line 1, before //!)
 *
 * This script never writes prose. A missing `@fileoverview` / `//!` purpose
 * header is left for the author; see `bin/check-file-docs.php`.
 *
 * It is idempotent — files already carrying an SPDX identifier are skipped —
 * and dry-run by default.
 *
 * Usage:
 *   php bin/add-spdx-headers.php                       # dry-run, all scopes + PHP trees
 *   php bin/add-spdx-headers.php --apply               # write
 *   php bin/add-spdx-headers.php --scope=rust:dictate  # one or more scopes
 *   php bin/add-spdx-headers.php --tree=lib/core/src   # legacy PHP tree selector
 *   php bin/add-spdx-headers.php --allow-dirty         # include files with uncommitted changes
 *   php bin/add-spdx-headers.php --json                # machine-readable summary
 *
 * @package WP_MCP_AI
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/header-contract.php';

use function WP_MCP_AI\Bin\DocsContract\dirty_files;
use function WP_MCP_AI\Bin\DocsContract\insert_spdx;
use function WP_MCP_AI\Bin\DocsContract\lang_for;
use function WP_MCP_AI\Bin\DocsContract\list_files;
use function WP_MCP_AI\Bin\DocsContract\load_config;
use function WP_MCP_AI\Bin\DocsContract\path_excluded;
use function WP_MCP_AI\Bin\DocsContract\pending_product;
use function WP_MCP_AI\Bin\DocsContract\resolve_licence;
use function WP_MCP_AI\Bin\DocsContract\root as repo_root;
use function WP_MCP_AI\Bin\DocsContract\scope_for;

$root = repo_root();

/**
 * tree => [docblock @license value, SPDX identifier]
 *
 * @var array<string, array{0: string, 1: string}>
 */
$LICENCE_MAP = array(
	'includes'            => array( 'GPL-3.0-or-later', 'GPL-3.0-or-later' ),
	'lib/core/src'        => array( 'MIT', 'MIT' ),
	'addons/pro/includes' => array( 'Proprietary', 'LicenseRef-Proprietary' ),
	'addons/canvas'       => array( 'Proprietary', 'LicenseRef-Proprietary' ),
);

$apply       = false;
$only        = null;
$scopes      = array();
$json        = false;
$allow_dirty = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--apply' === $arg ) {
		$apply = true;
	} elseif ( '--json' === $arg ) {
		$json = true;
	} elseif ( '--allow-dirty' === $arg ) {
		$allow_dirty = true;
	} elseif ( 0 === strpos( $arg, '--tree=' ) ) {
		$only = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--scope=' ) ) {
		$scopes = array_filter( array_map( 'trim', explode( ',', substr( $arg, 8 ) ) ) );
	} elseif ( '--help' === $arg || '-h' === $arg ) {
		$help = "Usage: php bin/add-spdx-headers.php [--apply] [--scope=a,b] [--tree=<path>]\n"
			. "       [--allow-dirty] [--json]\n";
		fwrite( STDOUT, $help );
		exit( 0 );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

/* -------------------------------------------------------------------------- */
/* PHP trees — preserved behaviour                                            */
/* -------------------------------------------------------------------------- */

/**
 * Insert or normalise licence + SPDX in a single PHP file's docblock.
 *
 * @param string $path    Absolute file path.
 * @param string $licence Docblock @license value for this tree.
 * @param string $spdx    SPDX identifier for this tree.
 * @param bool   $apply   Whether to write changes to disk.
 * @return string One of 'changed', 'unchanged', 'skipped', 'error'.
 */
function process_php_file( string $path, string $licence, string $spdx, bool $apply ): string {
	$code = file_get_contents( $path );
	if ( false === $code ) {
		return 'error';
	}

	// Already annotated — idempotent no-op.
	if ( false !== strpos( $code, 'SPDX-License-Identifier' ) ) {
		return 'skipped';
	}

	// Only transform real PHP files that open with <?php (allow a UTF-8 BOM).
	if ( ! preg_match( '/^(?:\xEF\xBB\xBF)?<\?php/', $code ) ) {
		return 'skipped';
	}

	$eol = ( false !== strpos( $code, "\r\n" ) ) ? "\r\n" : "\n";

	$doc_start  = strpos( $code, '/**' );
	$first_decl = preg_match(
		'/\b(class|interface|trait|function|namespace|declare)\b/',
		$code,
		$m,
		PREG_OFFSET_CAPTURE
	) ? $m[0][1] : strlen( $code );

	// No file-level docblock before the first declaration — synthesise one.
	if ( false === $doc_start || $doc_start > $first_decl ) {
		$php_pos  = strpos( $code, '<?php' );
		$line_end = strpos( $code, $eol, $php_pos );
		if ( false === $line_end ) {
			$line_end = strlen( $code );
		}

		$docblock = $eol . $eol
			. '/**' . $eol
			. ' * File header.' . $eol
			. ' *' . $eol
			. ' * @package WP_MCP_AI' . $eol
			. ' * @license ' . $licence . $eol
			. ' *' . $eol
			. ' * SPDX-License-Identifier: ' . $spdx . $eol
			. ' */';

		$code = substr( $code, 0, $line_end ) . $docblock . substr( $code, $line_end );

		if ( $apply ) {
			file_put_contents( $path, $code );
		}
		return 'changed';
	}

	$doc_end = strpos( $code, '*/', $doc_start );
	if ( false === $doc_end ) {
		return 'error';
	}

	$docblock = substr( $code, $doc_start, $doc_end - $doc_start );

	// 1. Normalise (or add) the @license tag, preserving alignment.
	if ( preg_match( '/(\*[ \t]*@license[ \t]+)([^\r\n*]+)/', $docblock ) ) {
		$docblock = preg_replace(
			'/(\*[ \t]*@license[ \t]+)([^\r\n*]+)/',
			'${1}' . str_replace( '$', '\\$', $licence ),
			$docblock,
			1
		);
	} else {
		$docblock = rtrim( $docblock, " \t\r\n" )
			. $eol . ' *' . $eol . ' * @license ' . $licence;
	}

	// 2. Append the SPDX identifier as its own docblock paragraph.
	$docblock = rtrim( $docblock, " \t\r\n" )
		. $eol . ' *' . $eol . ' * SPDX-License-Identifier: ' . $spdx . $eol . ' ';

	$code = substr( $code, 0, $doc_start ) . $docblock . substr( $code, $doc_end );

	if ( $apply ) {
		file_put_contents( $path, $code );
	}
	return 'changed';
}

/**
 * Recursively collect .php files under a directory.
 *
 * @param string $dir Directory.
 * @return string[]
 */
function collect_php( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$out      = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$path = str_replace( '\\', '/', $file->getPathname() );
		if ( false !== strpos( $path, '/node_modules/' ) || false !== strpos( $path, '/vendor/' ) ) {
			continue;
		}
		$out[] = $file->getPathname();
	}

	sort( $out );
	return $out;
}

$summary = array(
	'php'        => array( 'changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'error' => 0 ),
	'source'     => array( 'changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'error' => 0 ),
	'pending'    => array(),
	'unresolved' => array(),
	'by_scope'   => array(),
);

if ( ! $json ) {
	fwrite( STDOUT, $apply ? "MODE: apply\n\n" : "MODE: dry-run (pass --apply to write)\n\n" );
}

/* -------------------------------------------------------------------------- */
/* PHP trees                                                                  */
/* -------------------------------------------------------------------------- */

if ( empty( $scopes ) ) {
	foreach ( $LICENCE_MAP as $tree => $spec ) {
		if ( null !== $only && $only !== $tree ) {
			continue;
		}

		list( $licence, $spdx ) = $spec;

		$dir = $root . '/' . $tree;
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$files   = collect_php( $dir );
		$changed = 0;

		foreach ( $files as $file ) {
			$result = process_php_file( $file, $licence, $spdx, $apply );
			++$summary['php'][ $result ];
			if ( 'changed' === $result ) {
				++$changed;
			}
		}

		if ( ! $json ) {
			fwrite(
				STDOUT,
				sprintf(
					"%-22s %-18s %5d files  %5d changed\n",
					$tree,
					$licence,
					count( $files ),
					$changed
				)
			);
		}
	}
}

/* -------------------------------------------------------------------------- */
/* Non-PHP source trees                                                       */
/* -------------------------------------------------------------------------- */

$config = load_config( $root );
	$dirty  = $allow_dirty ? array() : dirty_files( $root );
$files  = list_files( $root );

foreach ( $files as $rel ) {
	if ( path_excluded( $rel, $config ) ) {
		continue;
	}

	$scope = scope_for( $rel, $config );
	if ( null === $scope ) {
		continue;
	}

	if ( ! empty( $scopes ) && ! in_array( $scope['name'], $scopes, true ) ) {
		continue;
	}

	$pending = pending_product( $rel, $config );
	if ( null !== $pending ) {
		$summary['pending'][ $pending ] = ( $summary['pending'][ $pending ] ?? 0 ) + 1;
		continue;
	}

	if ( isset( $dirty[ $rel ] ) ) {
		++$summary['source']['skipped'];
		continue;
	}

	$licence = resolve_licence( $root, $rel, $scope, $config );
	if ( null === $licence ) {
		$summary['unresolved'][] = $rel;
		++$summary['source']['error'];
		continue;
	}

	$lang = (string) lang_for( $rel );
	$abs  = $root . '/' . $rel;
	$code = file_get_contents( $abs );
	if ( false === $code ) {
		++$summary['source']['error'];
		continue;
	}

	$new = insert_spdx( $code, $lang, $licence );

	if ( $new === $code ) {
		++$summary['source']['unchanged'];
		continue;
	}

	if ( $apply ) {
		if ( false === file_put_contents( $abs, $new ) ) {
			++$summary['source']['error'];
			continue;
		}
	}

	++$summary['source']['changed'];
	$summary['by_scope'][ $scope['name'] ] = ( $summary['by_scope'][ $scope['name'] ] ?? 0 ) + 1;
}

if ( $json ) {
	fwrite( STDOUT, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
} else {
	if ( ! empty( $summary['by_scope'] ) ) {
		fwrite( STDOUT, "\nSource scopes:\n" );
		foreach ( $summary['by_scope'] as $name => $count ) {
			fwrite( STDOUT, sprintf( "  %-18s %5d changed\n", $name, $count ) );
		}
	}

	if ( ! empty( $summary['pending'] ) ) {
		fwrite( STDOUT, "\nPending licence decisions (skipped):\n" );
		foreach ( $summary['pending'] as $prefix => $count ) {
			fwrite( STDOUT, sprintf( "  %-46s %4d files\n", $prefix, $count ) );
		}
	}

	if ( ! empty( $summary['unresolved'] ) ) {
		fwrite( STDOUT, "\nUnresolved licences (skipped, first 10):\n" );
		foreach ( array_slice( $summary['unresolved'], 0, 10 ) as $rel ) {
			fwrite( STDOUT, "  - {$rel}\n" );
		}
	}

	fwrite(
		STDOUT,
		sprintf(
			"\nTotals: PHP %d changed / %d annotated / %d skipped / %d errors\n"
			. "        source %d changed / %d annotated / %d dirty-skipped / %d errors\n",
			$summary['php']['changed'],
			$summary['php']['unchanged'],
			$summary['php']['skipped'],
			$summary['php']['error'],
			$summary['source']['changed'],
			$summary['source']['unchanged'],
			$summary['source']['skipped'],
			$summary['source']['error']
		)
	);
}

exit( ( $summary['php']['error'] + $summary['source']['error'] ) > 0 ? 1 : 0 );
