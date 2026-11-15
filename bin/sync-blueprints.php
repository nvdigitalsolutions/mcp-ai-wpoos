#!/usr/bin/env php
<?php
/**
 * Mirror assistant blueprints into the Laravel app.
 *
 * A blueprint is a JSON file at
 * `addons/pro/includes/tools/<toolkit>/examples/<slug>.json`, read on
 * WordPress through `WP_MCP_AI_Blueprint_Installer::load_blueprint()`. A
 * Laravel deployment does not ship `addons/`, and `lib/` is excluded from
 * wp.org builds, so the catalogue has to be copied rather than shared.
 *
 * The copy is committed, and `--check` makes CI fail when the two trees
 * diverge, so the duplication stays explicit instead of going stale.
 *
 * Usage:
 *   php bin/sync-blueprints.php            # copy sources into the app
 *   php bin/sync-blueprints.php --check    # exit 1 if the mirror is stale
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$root    = dirname( __DIR__ );
$source  = $root . '/addons/pro/includes/tools';
$target  = $root . '/docker/laravel/app-skeleton/blueprints';
$check   = in_array( '--check', array_slice( $argv, 1 ), true );
$copied  = 0;
$stale   = array();
$toolkits = 0;

if ( ! is_dir( $source ) ) {
	fwrite( STDERR, "sync-blueprints: missing source tree {$source}\n" );
	exit( 1 );
}

$directories = glob( $source . '/*', GLOB_ONLYDIR );

if ( false === $directories ) {
	$directories = array();
}

sort( $directories );

foreach ( $directories as $toolkit_path ) {
	$toolkit  = basename( $toolkit_path );
	$examples = $toolkit_path . '/examples';

	if ( ! is_dir( $examples ) ) {
		continue;
	}

	$files = glob( $examples . '/*.json' );

	if ( false === $files ) {
		continue;
	}

	// `schema.json` describes the blueprint schema, it is not a blueprint.
	$files = array_values(
		array_filter(
			$files,
			static fn ( string $file ): bool => 'schema.json' !== basename( $file )
		)
	);

	if ( array() === $files ) {
		continue;
	}

	sort( $files );

	$destination = $target . '/' . $toolkit;
	$toolkits++;

	foreach ( $files as $file ) {
		$name     = basename( $file );
		$existing = $destination . '/' . $name;

		if ( is_file( $existing ) && file_get_contents( $existing ) === file_get_contents( $file ) ) {
			$copied++;
			continue;
		}

		if ( $check ) {
			$stale[] = $toolkit . '/' . $name;
			continue;
		}

		if ( ! is_dir( $destination ) && ! mkdir( $destination, 0777, true ) && ! is_dir( $destination ) ) {
			fwrite( STDERR, "sync-blueprints: cannot create {$destination}\n" );
			exit( 1 );
		}

		if ( ! copy( $file, $existing ) ) {
			fwrite( STDERR, "sync-blueprints: cannot copy {$file}\n" );
			exit( 1 );
		}

		$copied++;
	}
}

if ( $check ) {
	if ( array() !== $stale ) {
		fwrite( STDERR, "sync-blueprints: mirror is stale:\n  " . implode( "\n  ", $stale ) . "\n" );
		fwrite( STDERR, "Run `php bin/sync-blueprints.php` and commit the result.\n" );
		exit( 1 );
	}

	printf( "sync-blueprints: mirror is current (%d files, %d toolkits)\n", $copied, $toolkits );
	exit( 0 );
}

printf( "sync-blueprints: %d blueprints across %d toolkits -> %s\n", $copied, $toolkits, $target );
