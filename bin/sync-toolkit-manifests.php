#!/usr/bin/env php
<?php
/**
 * Mirror the Pro toolkit manifests into the Laravel app.
 *
 * A manifest is a JSON document at
 * `addons/pro/includes/toolkits/manifests/<slug>.json`. The Laravel runtime
 * reads `resources/pro-toolkits/<slug>.json` because a deployment does not
 * ship `addons/`, so the catalogue has to be copied rather than shared.
 *
 * The copy is committed, and `--check` makes CI fail when the two trees
 * diverge — the same contract as `sync-blueprints.php` and
 * `sync-schedule-presets.php`, so the duplication stays explicit instead of
 * going stale. Without this, editing the addon manifest and forgetting the
 * copy silently serves the old toolkit definition to the console.
 *
 * Usage:
 *   php bin/sync-toolkit-manifests.php            # copy sources into the app
 *   php bin/sync-toolkit-manifests.php --check    # exit 1 if the mirror is stale
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$root   = dirname( __DIR__ );
$source = $root . '/addons/pro/includes/toolkits/manifests';
$target = $root . '/docker/laravel/app-skeleton/resources/pro-toolkits';
$check  = in_array( '--check', array_slice( $argv, 1 ), true );

if ( ! is_dir( $source ) ) {
	fwrite( STDERR, "sync-toolkit-manifests: missing source tree {$source}\n" );
	exit( 1 );
}

if ( ! is_dir( $target ) && ! $check ) {
	wp_mcp_ai_guard_mkdir( $target );
}

$sources = glob( $source . '/*.json' );

if ( false === $sources ) {
	$sources = array();
}

sort( $sources );

$copied = 0;
$stale  = array();

foreach ( $sources as $path ) {
	$name    = basename( $path );
	$dest    = $target . '/' . $name;
	$wanted  = (string) file_get_contents( $path );
	$current = is_file( $dest ) ? (string) file_get_contents( $dest ) : null;

	if ( $current === $wanted ) {
		continue;
	}

	if ( $check ) {
		$stale[] = $name;
		continue;
	}

	file_put_contents( $dest, $wanted );
	++$copied;
}

// A manifest that left the addon must leave the mirror too, or the runtime
// keeps serving a toolkit that no longer exists upstream.
$mirrored = is_dir( $target ) ? glob( $target . '/*.json' ) : array();

if ( false === $mirrored ) {
	$mirrored = array();
}

$orphaned = array();

foreach ( $mirrored as $path ) {
	$name = basename( $path );

	if ( is_file( $source . '/' . $name ) ) {
		continue;
	}

	if ( $check ) {
		$orphaned[] = $name;
		continue;
	}

	unlink( $path );
	++$copied;
}

if ( $check ) {
	if ( array() === $stale && array() === $orphaned ) {
		echo 'sync-toolkit-manifests: mirror is current (' . count( $sources ) . " manifest(s))\n";
		exit( 0 );
	}

	if ( array() !== $stale ) {
		fwrite( STDERR, 'sync-toolkit-manifests: stale: ' . implode( ', ', $stale ) . "\n" );
	}

	if ( array() !== $orphaned ) {
		fwrite( STDERR, 'sync-toolkit-manifests: orphaned: ' . implode( ', ', $orphaned ) . "\n" );
	}

	fwrite( STDERR, "Run: php bin/sync-toolkit-manifests.php\n" );
	exit( 1 );
}

echo 'sync-toolkit-manifests: wrote ' . $copied . ' file(s), ' . count( $sources ) . " manifest(s) in mirror\n";
exit( 0 );

/**
 * Create the mirror directory.
 *
 * Kept as a function so the failure path reports a message instead of emitting
 * a raw PHP warning that CI renders as noise.
 */
function wp_mcp_ai_guard_mkdir( string $directory ): void {
	if ( ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		fwrite( STDERR, "sync-toolkit-manifests: cannot create {$directory}\n" );
		exit( 1 );
	}
}
