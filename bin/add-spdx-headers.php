#!/usr/bin/env php
<?php
/**
 * add-spdx-headers.php — normalise licence declarations and add SPDX identifiers.
 *
 * The repo holds three differently-licensed products in one tree. Each source
 * file must declare the same licence as the package it belongs to, and expose
 * it in SPDX form so tooling can read it without parsing docblock tags.
 *
 *   includes/              GPL-3.0-or-later
 *   lib/core/src/          MIT                  (see lib/core/composer.json)
 *   addons/pro/includes/   Proprietary
 *   addons/canvas/         Proprietary
 *
 * For each file this script:
 *   1. Normalises the existing `@license` docblock tag to the tree's licence.
 *   2. Adds a `SPDX-License-Identifier:` line inside the file docblock.
 *
 * It is idempotent — files already carrying an SPDX identifier are skipped —
 * and dry-run by default.
 *
 * Usage:
 *   php bin/add-spdx-headers.php                 # report what would change
 *   php bin/add-spdx-headers.php --apply         # write the changes
 *   php bin/add-spdx-headers.php --tree=lib/core/src --apply
 *
 * @package WP_MCP_AI
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

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

$apply = false;
$only  = null;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--apply' === $arg ) {
		$apply = true;
	} elseif ( 0 === strpos( $arg, '--tree=' ) ) {
		$only = substr( $arg, 7 );
	} elseif ( '--help' === $arg || '-h' === $arg ) {
		fwrite( STDOUT, "Usage: php bin/add-spdx-headers.php [--apply] [--tree=<path>]\n" );
		exit( 0 );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

/**
 * Insert or normalise licence + SPDX in a single file's docblock.
 *
 * @param string $path    Absolute file path.
 * @param string $licence Docblock @license value for this tree.
 * @param string $spdx    SPDX identifier for this tree.
 * @param bool   $apply   Whether to write changes to disk.
 * @return string One of 'changed', 'unchanged', 'skipped', 'error'.
 */
function process_file( string $path, string $licence, string $spdx, bool $apply ): string {
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
		$php_pos = strpos( $code, '<?php' );
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

$total = array(
	'changed'   => 0,
	'unchanged' => 0,
	'skipped'   => 0,
	'error'     => 0,
);

fwrite( STDOUT, $apply ? "MODE: apply\n\n" : "MODE: dry-run (pass --apply to write)\n\n" );

foreach ( $LICENCE_MAP as $tree => $spec ) {
	if ( null !== $only && $only !== $tree ) {
		continue;
	}

	list( $licence, $spdx ) = $spec;

	$dir = $root . '/' . $tree;
	if ( ! is_dir( $dir ) ) {
		fwrite( STDOUT, sprintf( "%-22s (absent)\n", $tree ) );
		continue;
	}

	$files   = collect_php( $dir );
	$changed = array();

	foreach ( $files as $file ) {
		$result = process_file( $file, $licence, $spdx, $apply );
		++$total[ $result ];
		if ( 'changed' === $result ) {
			$changed[] = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
		}
	}

	fwrite(
		STDOUT,
		sprintf(
			"%-22s %-18s %5d files  %5d changed\n",
			$tree,
			$licence,
			count( $files ),
			count( $changed )
		)
	);

	if ( ! $apply && count( $changed ) > 0 ) {
		foreach ( array_slice( $changed, 0, 5 ) as $rel ) {
			fwrite( STDOUT, "    • {$rel}\n" );
		}
		if ( count( $changed ) > 5 ) {
			fwrite( STDOUT, '    … and ' . ( count( $changed ) - 5 ) . " more\n" );
		}
	}
}

fwrite(
	STDOUT,
	sprintf(
		"\nTotals: %d changed, %d already annotated, %d skipped, %d errors\n",
		$total['changed'],
		$total['unchanged'],
		$total['skipped'],
		$total['error']
	)
);

exit( $total['error'] > 0 ? 1 : 0 );
