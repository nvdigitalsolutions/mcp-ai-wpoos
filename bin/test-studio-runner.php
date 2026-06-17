<?php
/**
 * WordPress Studio Test Runner
 *
 * Bridge script invoked by `composer run test:studio`.
 * Reads WP_STUDIO_SITE_SLUG from the environment and executes PHPUnit
 * against the matching WordPress Studio site.
 *
 * Usage:
 *   WP_STUDIO_SITE_SLUG=mysite composer run test:studio
 *   WP_STUDIO_SITE_SLUG=mysite composer run test:studio -- --filter=test_logger
 *
 * On Windows, use:
 *   set WP_STUDIO_SITE_SLUG=mysite && composer run test:studio
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

$site_slug = getenv( 'WP_STUDIO_SITE_SLUG' );

if ( empty( $site_slug ) ) {
	fwrite( STDERR, "\nError: WP_STUDIO_SITE_SLUG environment variable is not set.\n\n" );
	fwrite( STDERR, "Usage:\n" );
	fwrite( STDERR, "  WP_STUDIO_SITE_SLUG=mysite composer run test:studio\n" );
	fwrite( STDERR, "  WP_STUDIO_SITE_SLUG=mysite composer run test:studio -- --filter=test_logger\n\n" );
	fwrite( STDERR, "To discover available Studio sites:\n" );
	fwrite( STDERR, "  Run without WP_STUDIO_SITE_SLUG — the bootstrap will list detected sites.\n\n" );
	exit( 1 );
}

// Detect the Studio sites directory.
if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
	$studio_base = getenv( 'LOCALAPPDATA' ) . '/WordPress Studio/sites';
} else {
	$studio_base = getenv( 'HOME' ) . '/Library/Application Support/WordPress Studio/sites';
}

$wordpress_path = $studio_base . '/' . $site_slug . '/app/public';

if ( ! file_exists( $wordpress_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "\nError: WordPress Studio site '{$site_slug}' not found at:\n" );
	fwrite( STDERR, "  {$wordpress_path}\n\n" );
	fwrite( STDERR, "Available sites:\n" );

	if ( is_dir( $studio_base ) ) {
		$sites = scandir( $studio_base );
		foreach ( $sites as $site ) {
			if ( '.' === $site || '..' === $site ) {
				continue;
			}
			$wp_load = $studio_base . '/' . $site . '/app/public/wp-load.php';
			if ( file_exists( $wp_load ) ) {
				fwrite( STDERR, "  - {$site}\n" );
			}
		}
	} else {
		fwrite( STDERR, "  (Studio sites directory not found)\n" );
	}

	fwrite( STDERR, "\n" );
	exit( 1 );
}

// Export WP_CORE_DIR so the bootstrap picks it up directly.
putenv( 'WP_CORE_DIR=' . $wordpress_path );
$_ENV['WP_CORE_DIR'] = $wordpress_path;

fwrite( STDOUT, "\n┌──────────────────────────────────────────┐\n" );
fwrite( STDOUT, "│  WordPress Studio Test Runner            │\n" );
fwrite( STDOUT, "├──────────────────────────────────────────┤\n" );
fwrite( STDOUT, "│  Site:  {$site_slug}\n" );
fwrite( STDOUT, "│  Path:  {$wordpress_path}\n" );
fwrite( STDOUT, "└──────────────────────────────────────────┘\n\n" );

// Forward remaining arguments to PHPUnit.
$phpunit_args = array_slice( $argv, 1 );

// Build and execute the PHPUnit command.
$phpunit_bin  = dirname( __DIR__ ) . '/vendor/bin/phpunit';
$command  = escapeshellcmd( $phpunit_bin );
$command .= ' ' . implode( ' ', array_map( 'escapeshellarg', $phpunit_args ) );

fwrite( STDOUT, "Running: {$command}\n\n" );

$exit_code = 0;
passthru( $command, $exit_code );

exit( $exit_code );
