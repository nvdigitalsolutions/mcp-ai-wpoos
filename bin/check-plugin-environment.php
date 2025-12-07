#!/usr/bin/env php
<?php
/**
 * Simple environment health check for WP oOS plugin.
 *
 * @package WP_MCP_AI
 */

// Polyfill for WordPress escaping functions in CLI context.
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping for HTML blocks.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

$min_php_version     = '7.4.0';
$current_php_version = PHP_VERSION;

printf( "PHP version: %s\n", $current_php_version );
if ( version_compare( $current_php_version, $min_php_version, '>=' ) ) {
	printf( "PHP requirement (>= %s): OK\n", $min_php_version );
} else {
	fprintf( STDERR, "PHP requirement (>= %s): FAIL\n", $min_php_version );
	exit( 1 );
}

$plugin_file = __DIR__ . '/../mcp-ai-wpoos.php';
if ( ! is_file( $plugin_file ) ) {
	fprintf( STDERR, "Plugin file not found at %s\n", $plugin_file );
	exit( 1 );
}

$plugin_contents = file_get_contents( $plugin_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI context, not web request.
if ( false === $plugin_contents ) {
	fprintf( STDERR, "Unable to read plugin file: %s\n", $plugin_file );
	exit( 1 );
}

$plugin_version = null;

if ( preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/mi', $plugin_contents, $matches ) ) {
	$plugin_version = trim( $matches[1] );
} elseif ( preg_match( "/define\\s*\\(\\s*'WP_MCP_AI_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin_contents, $matches ) ) {
	$plugin_version = trim( $matches[1] );
}

if ( ! $plugin_version ) {
	fprintf( STDERR, "Unable to detect plugin version from %s\n", $plugin_file );
	exit( 1 );
}

printf( "Detected plugin version: %s\n", esc_html( $plugin_version ) );

$changelog_file = __DIR__ . '/../CHANGELOG.md';
$latest_release = null;
if ( is_file( $changelog_file ) ) {
	$handle = fopen( $changelog_file, 'r' );
	if ( $handle ) {
		while ( ( $line = fgets( $handle ) ) !== false ) {
			if ( preg_match( '/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/', trim( $line ), $matches ) ) {
				$latest_release = $matches[1];
				break;
			}
		}
		fclose( $handle );
	}
}

if ( $latest_release ) {
	printf( "Latest release noted in changelog: %s\n", esc_html( $latest_release ) );
	if ( version_compare( $plugin_version, $latest_release, '==' ) ) {
		echo "Plugin is on the latest recorded version.\n";
	} elseif ( version_compare( $plugin_version, $latest_release, '<' ) ) {
		echo "Plugin is behind the changelog version; consider updating.\n";
	} else {
		echo "Plugin version is ahead of the changelog entry; verify changelog.\n";
	}
} else {
	echo "No release information found in changelog.\n";
}

$error_log_path = ini_get( 'error_log' );
if ( ! is_string( $error_log_path ) || '' === trim( $error_log_path ) ) {
	echo "PHP error_log path not configured; unable to inspect logs.\n";
	exit( 0 );
}

$error_log_path = trim( $error_log_path );

printf( "PHP error_log path: %s\n", esc_html( $error_log_path ) );

if ( 'syslog' === strtolower( $error_log_path ) ) {
	echo "Error log is set to syslog; manual inspection required.\n";
	exit( 0 );
}

if ( ! is_file( $error_log_path ) ) {
	echo "Error log file not found on disk; nothing to inspect.\n";
	exit( 0 );
}

$log_lines = file( $error_log_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional for graceful error handling.
if ( false === $log_lines ) {
	echo "Unable to read error log file.\n";
	exit( 0 );
}

$recent_lines  = array_slice( $log_lines, -20 );
$syntax_issues = array();
foreach ( $recent_lines as $line ) {
	if ( stripos( $line, 'syntax' ) !== false ) {
		$syntax_issues[] = trim( $line );
	}
}

if ( $syntax_issues ) {
	echo "Recent syntax-related log entries:\n";
	foreach ( $syntax_issues as $issue ) {
		printf( "  - %s\n", esc_html( $issue ) );
	}
} else {
	echo "No recent syntax-related entries detected in PHP error log.\n";
}
