#!/usr/bin/env php
<?php
/**
 * Sync WordPress-specific bundled skills to .agents/skills/ for Zed.
 *
 * Copies wp-* and project-owned skill directories from includes/bundled-skills/
 * into .agents/skills/ so the Zed editor discovers them as project-local agent
 * skills. Existing skills are overwritten so a plugin update that changes a
 * bundled SKILL.md is reflected after `composer run skills:sync`.
 *
 * General-purpose skills not starting with "wp-" (e.g. pdf, pptx, code-reviewer)
 * are left alone — those overlap with the community skills.sh registry and
 * developers install them globally in ~/.agents/skills/.
 *
 * Project-owned skills (listed in $project_skills below) are also synced.
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

// Project-owned skills that should also be synced (not wp-* but maintained
// in this repo). Add new project-specific skills here as they are created.
$project_skills = array(
	'mcp-ai-wpoos-plugin',
	'design-ai-assistant-admin',
	'design-analytics-reporting',
	'design-brand-kit',
	'design-campaign-orchestration',
	'design-color-systems',
	'design-communications',
	'design-content-calendar',
	'design-content-research',
	'design-crm',
	'design-deep-research',
	'design-document-generation',
	'design-email-marketing',
	'design-image-generation',
	'design-image-optimization',
	'design-media-workflow',
	'design-product-photography',
	'design-product-research',
	'design-pro-schedule-manager',
	'design-pro-workflow-builder',
	'design-project-management',
	'design-security-ops',
	'design-seo-content',
	'design-services',
	'design-social-content',
	'design-social-publishing',
	'design-team-management',
	'design-typography',
	'design-vault',
	'design-video-creation',
	'design-web-research',
);

$copied  = 0;
$skipped = 0;
$failed  = 0;

foreach ( glob( $bundled_dir . '/*', GLOB_ONLYDIR ) as $src_dir ) {
	$skill_name = basename( $src_dir );

	// Sync wp-* skills and explicit project-owned skills.
	$is_wp_skill     = ( 0 === strpos( $skill_name, 'wp-' ) );
	$is_project_skill = in_array( $skill_name, $project_skills, true );

	if ( ! $is_wp_skill && ! $is_project_skill ) {
		++$skipped;
		continue;
	}

	$dst_dir = $target_dir . '/' . $skill_name;
	if ( ! file_exists( $dst_dir ) && ! mkdir( $dst_dir, 0755, true ) ) {
		fwrite( STDERR, "Error: could not create skill directory: {$dst_dir}\n" );
		++$failed;
		continue;
	}

	// Copy every file from the bundled skill directory, including nested
	// companion directories (e.g. references/, reference/) so relative
	// markdown links inside SKILL.md keep resolving in the mirror.
	$skill_ok = true;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	$prefix = strlen( rtrim( $src_dir, '/\\' ) ) + 1;

	foreach ( $iterator as $src_file ) {
		$relative = str_replace( '\\', '/', substr( $src_file->getPathname(), $prefix ) );
		$dst_file = $dst_dir . '/' . $relative;

		if ( $src_file->isDir() ) {
			if ( ! is_dir( $dst_file ) && ! mkdir( $dst_file, 0755, true ) ) {
				fwrite( STDERR, "Error: could not create directory {$dst_file}\n" );
				++$failed;
				$skill_ok = false;
				break;
			}
			continue;
		}

		if ( ! copy( $src_file->getPathname(), $dst_file ) ) {
			fwrite( STDERR, "Error: could not copy {$src_file->getPathname()} to {$dst_file}\n" );
			++$failed;
			$skill_ok = false;
			break;
		}

		// Ensure SKILL.md has `type: Skill` in its frontmatter (OKF v0.1
		// conformance — the single required field).
		if ( 'SKILL.md' === $src_file->getFilename() ) {
			$content   = file_get_contents( $dst_file );
			$has_type  = ( false !== strpos( $content, "\ntype: Skill\n" ) );
			$has_delim = ( 0 === strpos( $content, "---\n" ) );

			if ( ! $has_type ) {
				if ( $has_delim ) {
					// Inject type: Skill as the first frontmatter key.
					$content = preg_replace(
						'/^(---\n)/',
						"\$1type: Skill\n",
						$content,
						1
					);
					file_put_contents( $dst_file, $content );
					echo "  ⚠  Injected missing `type: Skill` into {$skill_name}/SKILL.md\n";
				} else {
					fwrite( STDERR, "  ⚠  Warning: {$skill_name}/SKILL.md has no frontmatter — cannot inject type: Skill.\n" );
				}
			}
		}
	}

	if ( $skill_ok ) {
		++$copied;
	}
}

// Remove stale wp-* skills in target that no longer exist in bundled.
// Note: project-owned skills (e.g. mcp-ai-wpoos-plugin) are NOT cleaned up
// here — the glob deliberately only matches wp-* to avoid deleting non-wp
// skills that may have been added manually.
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
