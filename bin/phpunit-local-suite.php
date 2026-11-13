#!/usr/bin/env php
<?php
/**
 * Run PHPUnit against an explicit file list.
 *
 * Why this exists
 * ---------------
 * PHPUnit expands a `<directory>` element with PHP's RecursiveDirectoryIterator.
 * On a Docker Desktop bind mount that iterator returns only part of a large
 * directory — in this repository `tests/` holds ~1,200 entries and the iterator
 * sees roughly half, so a local run silently covers a fraction of the suite
 * while CI (ext4) covers all of it. `find` uses getdents directly and is not
 * affected, so this script enumerates with `find`, rewrites each `<testsuite>`
 * as explicit `<file>` entries, and runs PHPUnit against the result.
 *
 * Nothing else about the config changes: the base document is read from
 * `phpunit.xml.dist` (or `--config`), so bootstrap, groups, source and future
 * settings stay in one place.
 *
 * Usage
 * -----
 *   php bin/phpunit-local-suite.php                     # whole suite
 *   php bin/phpunit-local-suite.php --list-tests        # inspect discovery
 *   php bin/phpunit-local-suite.php --filter PM_Parity  # any PHPUnit flag
 *   php bin/phpunit-local-suite.php --config phpunit-basepro.xml.dist
 *
 * Unrecognised arguments are forwarded to PHPUnit unchanged.
 *
 * Comparison: `vendor/bin/phpunit --list-tests` discovers ~6.6k tests here and
 * ~20.8k in CI; this script brings the local count in line.
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$base_config = 'phpunit.xml.dist';
$forwarded   = array();

$given = array_slice( $argv, 1 );

for ( $i = 0; $i < count( $given ); $i++ ) {
	if ( '--config' === $given[ $i ] ) {
		if ( ! isset( $given[ $i + 1 ] ) ) {
			fwrite( STDERR, "phpunit-local-suite: --config needs a value.\n" );
			exit( 1 );
		}

		$base_config = $given[ ++$i ];
		continue;
	}

	$forwarded[] = $given[ $i ];
}

$config_path = str_starts_with( $base_config, '/' ) || preg_match( '/^[A-Za-z]:[\\\\\/]/', $base_config )
	? $base_config
	: $root . '/' . $base_config;

if ( ! is_file( $config_path ) ) {
	fwrite( STDERR, 'phpunit-local-suite: config not found: ' . $config_path . "\n" );
	exit( 1 );
}

$document                     = new DOMDocument();
$document->preserveWhiteSpace = false;

if ( ! $document->load( $config_path ) ) {
	fwrite( STDERR, 'phpunit-local-suite: could not parse ' . $config_path . "\n" );
	exit( 1 );
}

$xpath = new DOMXPath( $document );

/**
 * List the files under one directory that carry the suite's suffix.
 *
 * Sorted, so two runs of the same checkout produce the same order.
 *
 * @param string $directory Absolute directory.
 * @param string $suffix    Filename suffix, e.g. `.php`.
 * @return list<string>
 */
function nvoos_suite_find_files( string $directory, string $suffix ): array {
	if ( ! is_dir( $directory ) ) {
		return array();
	}

	$command = 'find ' . escapeshellarg( $directory )
		. ' -type f -name ' . escapeshellarg( '*' . $suffix )
		. ' 2>/dev/null';

	$output = shell_exec( $command );

	if ( ! is_string( $output ) ) {
		return array();
	}

	$files = array_values( array_filter( array_map( 'trim', explode( "\n", $output ) ) ) );

	sort( $files );

	return $files;
}

/**
 * Whether a path is the excluded path or lives underneath it.
 *
 * @param string             $file     Absolute file path.
 * @param array<int, string> $excludes Absolute excluded paths.
 */
function nvoos_suite_is_excluded( string $file, array $excludes ): bool {
	foreach ( $excludes as $exclude ) {
		if ( $file === $exclude || str_starts_with( $file, rtrim( $exclude, '/' ) . '/' ) ) {
			return true;
		}
	}

	return false;
}

$suites = array();
$total  = 0;

foreach ( $xpath->query( '//testsuite' ) as $suite ) {
	if ( ! $suite instanceof DOMElement ) {
		continue;
	}

	$excludes = array();

	foreach ( $suite->getElementsByTagName( 'exclude' ) as $exclude ) {
		$excludes[] = $root . '/' . trim( (string) $exclude->textContent );
	}

	$files = array();
	$seen  = array();

	// Directories in the order the config lists them, files sorted within
	// each one: the declaration order of the config is preserved, which
	// matters when a test depends on state left by an earlier suite.
	foreach ( $suite->getElementsByTagName( 'directory' ) as $directory ) {
		$suffix = $directory->getAttribute( 'suffix' ) ?: '.php';

		foreach ( nvoos_suite_find_files( $root . '/' . trim( (string) $directory->textContent ), $suffix ) as $file ) {
			if ( isset( $seen[ $file ] ) || nvoos_suite_is_excluded( $file, $excludes ) ) {
				continue;
			}

			$seen[ $file ] = true;
			$files[]       = $file;
		}
	}

	foreach ( $suite->getElementsByTagName( 'file' ) as $file ) {
		$path = $root . '/' . trim( (string) $file->textContent );

		if ( isset( $seen[ $path ] ) || ! is_file( $path ) || nvoos_suite_is_excluded( $path, $excludes ) ) {
			continue;
		}

		$seen[ $path ] = true;
		$files[]       = $path;
	}

	$suites[] = array( $suite, $files );
	$total   += count( $files );
}

if ( 0 === $total ) {
	fwrite( STDERR, "phpunit-local-suite: no test files found — is `find` available?\n" );
	exit( 1 );
}

foreach ( $suites as $entry ) {
	list( $suite, $files ) = $entry;

	while ( $suite->firstChild ) {
		$suite->removeChild( $suite->firstChild );
	}

	foreach ( $files as $file ) {
		$node = $document->createElement( 'file' );
		$node->appendChild( $document->createTextNode( $file ) );
		$suite->appendChild( $node );
	}
}

// The generated config lives outside the checkout, so every path PHPUnit
// resolves relative to it has to become absolute.
if ( $document->documentElement instanceof DOMElement ) {
	$bootstrap = (string) $document->documentElement->getAttribute( 'bootstrap' );

	if ( '' !== $bootstrap && ! str_starts_with( $bootstrap, '/' ) ) {
		$document->documentElement->setAttribute( 'bootstrap', $root . '/' . $bootstrap );
	}

	if ( '' !== $document->documentElement->getAttribute( 'cacheDirectory' ) ) {
		$document->documentElement->setAttribute( 'cacheDirectory', $root . '/.phpunit.cache' );
	}
}

$generated = sys_get_temp_dir() . '/nvoos-phpunit-local.xml';

if ( false === $document->save( $generated ) ) {
	fwrite( STDERR, 'phpunit-local-suite: could not write ' . $generated . "\n" );
	exit( 1 );
}

fwrite( STDERR, sprintf( "phpunit-local-suite: %d files -> %s\n", $total, $generated ) );

$command = escapeshellarg( PHP_BINARY )
	. ' ' . escapeshellarg( $root . '/vendor/phpunit/phpunit/phpunit' )
	. ' -c ' . escapeshellarg( $generated );

foreach ( $forwarded as $argument ) {
	$command .= ' ' . escapeshellarg( $argument );
}

passthru( $command, $status );

exit( $status );
