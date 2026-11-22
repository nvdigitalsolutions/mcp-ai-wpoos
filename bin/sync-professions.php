<?php
/**
 * Generate the Laravel profession catalogue from the WordPress base set.
 *
 * Proposal 149 §168. The WordPress plugin seeds its profession CPT from the
 * JSON documents in `includes/knowledge-base/professions` (311 professions
 * across 18 files). The Laravel adapter ships a nine-profession *starter* so
 * the package is usable without a catalogue; the app must not run on that
 * starter, because the console, `/admin/professions` and the team catalogue
 * would all advertise a fraction of what WordPress has.
 *
 * This script writes a base-only snapshot into the app skeleton, which is the
 * GPL side of the licence boundary — the adapter package is MIT and keeps its
 * starter set. The six `pro-*` packs are excluded: they are tier data supplied
 * by a licensed deployment, not part of the base catalogue. A deployment can
 * always point `oos.professions.path` at a fuller catalogue instead.
 *
 * Documents are copied byte-for-byte, so the diff is meaningful, and the
 * manifest carries a content hash as the `catalogue_version` the runtime
 * already reads. That makes the output deterministic: regenerating an
 * unchanged source produces identical bytes, which is what lets `--check`
 * be a gate.
 *
 * Usage:
 *   php bin/sync-professions.php           # write the snapshot
 *   php bin/sync-professions.php --check   # exit 1 when it is stale
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const PROFESSION_SOURCE           = 'includes/knowledge-base/professions';
const PROFESSION_TARGET           = 'docker/laravel/app-skeleton/resources/professions';
const PROFESSION_EXCLUDED_PREFIX  = 'pro-';
const PROFESSION_MANIFEST         = 'manifest.json';

/**
 * Build the snapshot in memory.
 *
 * @param string $source Absolute source directory.
 * @return array{files: array<string, string>, manifest: string, count: int, version: string}
 * @throws RuntimeException When the source is missing or a document is invalid.
 */
function nvoos_profession_snapshot( string $source ): array {
	$paths = glob( $source . '/*.json' );

	if ( false === $paths || array() === $paths ) {
		throw new RuntimeException( 'No profession JSON documents found in ' . $source );
	}

	sort( $paths );

	$files  = array();
	$count  = 0;
	$hashed = array();

	foreach ( $paths as $path ) {
		$name = basename( $path );

		// Tier data, supplied by a licensed deployment.
		if ( str_starts_with( $name, PROFESSION_EXCLUDED_PREFIX ) ) {
			continue;
		}

		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			throw new RuntimeException( 'Could not read ' . $name );
		}

		$doc = json_decode( $raw, true );

		if ( ! is_array( $doc ) || ! isset( $doc['professions'] ) || ! is_array( $doc['professions'] ) ) {
			throw new RuntimeException( $name . ' is not a profession document (no `professions` array).' );
		}

		$files[ $name ] = $raw;
		$count         += count( $doc['professions'] );
		$hashed[]       = $name . ':' . hash( 'sha256', $raw );
	}

	if ( array() === $files ) {
		throw new RuntimeException( 'Every profession document was excluded; nothing to generate.' );
	}

	$version = 'sha256:' . substr( hash( 'sha256', implode( "\n", $hashed ) ), 0, 16 );

	$manifest = array(
		'catalogue_version' => $version,
		'generated_by'      => 'bin/sync-professions.php',
		'source'            => PROFESSION_SOURCE,
		'professions'       => $count,
		'files'             => count( $files ),
		'excluded'          => array( PROFESSION_EXCLUDED_PREFIX . '*.json' ),
	);

	$encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( false === $encoded ) {
		throw new RuntimeException( 'Could not encode the manifest.' );
	}

	return array(
		'files'    => $files,
		'manifest' => $encoded . "\n",
		'count'    => $count,
		'version'  => $version,
	);
}

/**
 * Read a file, or null when it does not exist.
 */
function nvoos_profession_read( string $path ): ?string {
	if ( ! is_file( $path ) ) {
		return null;
	}

	$raw = file_get_contents( $path );

	return false === $raw ? null : $raw;
}

$root  = dirname( __DIR__ );
$check = in_array( '--check', array_slice( $argv, 1 ), true );

try {
	$snapshot = nvoos_profession_snapshot( $root . '/' . PROFESSION_SOURCE );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, 'error: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}

$target   = $root . '/' . PROFESSION_TARGET;
$existing = glob( $target . '/*.json' );

if ( false === $existing ) {
	$existing = array();
}

if ( $check ) {
	$stale = array();

	foreach ( $snapshot['files'] as $name => $expected ) {
		if ( nvoos_profession_read( $target . '/' . $name ) !== $expected ) {
			$stale[] = $name;
		}
	}

	if ( nvoos_profession_read( $target . '/' . PROFESSION_MANIFEST ) !== $snapshot['manifest'] ) {
		$stale[] = PROFESSION_MANIFEST;
	}

	foreach ( $existing as $path ) {
		$name = basename( $path );

		if ( ! isset( $snapshot['files'][ $name ] ) && PROFESSION_MANIFEST !== $name ) {
			$stale[] = $name . ' (no longer in the source snapshot)';
		}
	}

	if ( array() !== $stale ) {
		fwrite( STDERR, 'The profession snapshot is stale:' . PHP_EOL );

		foreach ( $stale as $name ) {
			fwrite( STDERR, '  - ' . $name . PHP_EOL );
		}

		fwrite( STDERR, 'Run: php bin/sync-professions.php' . PHP_EOL );
		exit( 1 );
	}

	echo sprintf(
		'Profession snapshot is in step: %d files, %d professions, %s%s',
		count( $snapshot['files'] ),
		$snapshot['count'],
		$snapshot['version'],
		PHP_EOL
	);
	exit( 0 );
}

if ( ! is_dir( $target ) && ! mkdir( $target, 0775, true ) && ! is_dir( $target ) ) {
	fwrite( STDERR, 'error: could not create ' . PROFESSION_TARGET . PHP_EOL );
	exit( 1 );
}

foreach ( $existing as $path ) {
	$name = basename( $path );

	if ( ! isset( $snapshot['files'][ $name ] ) && PROFESSION_MANIFEST !== $name ) {
		unlink( $path );
	}
}

foreach ( $snapshot['files'] as $name => $bytes ) {
	if ( false === file_put_contents( $target . '/' . $name, $bytes ) ) {
		fwrite( STDERR, 'error: could not write ' . $name . PHP_EOL );
		exit( 1 );
	}
}

if ( false === file_put_contents( $target . '/' . PROFESSION_MANIFEST, $snapshot['manifest'] ) ) {
	fwrite( STDERR, 'error: could not write ' . PROFESSION_MANIFEST . PHP_EOL );
	exit( 1 );
}

echo sprintf(
	'Wrote %d files, %d professions, %s%s',
	count( $snapshot['files'] ),
	$snapshot['count'],
	$snapshot['version'],
	PHP_EOL
);
