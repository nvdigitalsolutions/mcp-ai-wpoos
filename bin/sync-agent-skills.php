#!/usr/bin/env php
<?php
/**
 * Sync WordPress-specific bundled skills to .agents/skills/ for Zed.
 *
 * Copies every wp-* skill directory from includes/bundled-skills/ into
 * .agents/skills/ so the Zed editor discovers them as project-local agent
 * skills. Existing skills are overwritten so a plugin update that changes a
 * bundled SKILL.md is reflected after `composer run skills:sync`.
 *
 * Skills not starting with "wp-" (e.g. pdf, pptx, code-reviewer) are left
 * alone — those are general-purpose and overlap with the community skills.sh
 * registry. Developers install the general ones globally in ~/.agents/skills/.
 *
 * @package WP_MCP_AI
 * @since   1.11.0
 */

$repo_root = dirname( __DIR__ );

$bundled_dir = $repo_root . '/includes/bundled-skills';
$target_dir  = $repo_root . '/.agents/skills';

if ( ! is_dir( $bundled_dir ) ) {
	fwrite( STDERR, "Error: bundled skills directory not found: {$bundled_dir}\n" );
	exit( 1 );
}

if ( ! file_exists( $target_dir ) && ! mkdir( $target_dir, 0755, true ) ) {
	fwrite( STDERR, "Error: could not create target directory: {$target_dir}\n" );
	exit( 1 );
}

$copied  = 0;
$skipped = 0;
$failed  = 0;

foreach ( glob( $bundled_dir . '/*', GLOB_ONLYDIR ) as $src_dir ) {
	$skill_name = basename( $src_dir );

	// Only sync WordPress-specific skills (wp-* prefix).
	if ( 0 !== strpos( $skill_name, 'wp-' ) ) {
		++$skipped;
		continue;
	}

	$dst_dir = $target_dir . '/' . $skill_name;
	if ( ! file_exists( $dst_dir ) && ! mkdir( $dst_dir, 0755, true ) ) {
		fwrite( STDERR, "Error: could not create skill directory: {$dst_dir}\n" );
		++$failed;
		continue;
	}

	// Copy each file from the bundled skill directory.
	$files = glob( $src_dir . '/*' );
	if ( ! is_array( $files ) ) {
		continue;
	}

	$skill_ok = true;
	foreach ( $files as $src_file ) {
		$filename = basename( $src_file );
		$dst_file = $dst_dir . '/' . $filename;

		if ( ! copy( $src_file, $dst_file ) ) {
			fwrite( STDERR, "Error: could not copy {$src_file} to {$dst_file}\n" );
			++$failed;
			$skill_ok = false;
			break;
		}
	}

	if ( $skill_ok ) {
		++$copied;
	}
}

// Remove stale skills in target that no longer exist in bundled.
foreach ( glob( $target_dir . '/wp-*', GLOB_ONLYDIR ) as $dst_dir ) {
	$skill_name   = basename( $dst_dir );
	$bundled_path = $bundled_dir . '/' . $skill_name;

	if ( ! is_dir( $bundled_path ) ) {
		// Recursive removal.
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dst_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dst_dir );
		echo "Removed stale skill: {$skill_name}\n";
	}
}

echo sprintf(
	"Skills sync complete: %d copied, %d skipped (non-wp), %d failed.\n",
	$copied,
	$skipped,
	$failed
);

exit( $failed > 0 ? 1 : 0 );
