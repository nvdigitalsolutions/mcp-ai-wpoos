#!/usr/bin/env php
<?php
/**
 * Clear Admin Menu Cache
 *
 * This script clears WordPress admin menu cache to fix issues where
 * menu URLs are cached with old slugs or incorrect formats.
 *
 * Usage:
 *   php bin/clear-admin-menu-cache.php
 *   OR
 *   wp eval-file bin/clear-admin-menu-cache.php
 *
 * @package WP_MCP_AI
 * @since 2.0.1
 */

// If running via WP-CLI, WordPress is already loaded.
if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
	// Try to load WordPress.
	$wp_load_paths = array(
		__DIR__ . '/../../../../wp-load.php', // Standard plugin location.
		__DIR__ . '/../../../wp-load.php',    // Alternative.
		__DIR__ . '/../../wp-load.php',       // Another alternative.
	);

	$wp_loaded = false;
	foreach ( $wp_load_paths as $wp_load ) {
		if ( file_exists( $wp_load ) ) {
			require_once $wp_load;
			$wp_loaded = true;
			break;
		}
	}

	if ( ! $wp_loaded ) {
		echo "Error: Could not load WordPress. Please run this script from your WordPress installation.\n";
		echo "Or use: wp eval-file bin/clear-admin-menu-cache.php\n";
		exit( 1 );
	}
}

/**
 * Clear all admin menu related caches.
 */
function wp_mcp_ai_clear_admin_menu_cache() {
	global $wpdb;

	$cleared = array();

	// 1. Delete WordPress transients related to menus.
	$transients = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		WHERE option_name LIKE '_transient_%menu%'
		OR option_name LIKE '_site_transient_%menu%'"
	);

	foreach ( $transients as $transient ) {
		delete_option( $transient );
		$cleared[] = $transient;
	}

	// 2. Clear object cache if available.
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
		$cleared[] = 'Object cache flushed';
	}

	// 3. Clear any admin page hooks cache.
	delete_option( 'wp_mcp_ai_admin_pages_cache' );
	$cleared[] = 'Plugin admin pages cache cleared';

	// 4. Flush rewrite rules (sometimes needed for admin pages).
	flush_rewrite_rules();
	$cleared[] = 'Rewrite rules flushed';

	return $cleared;
}

// Execute the cache clearing.
echo "=== Clearing Admin Menu Cache ===\n\n";

$cleared = wp_mcp_ai_clear_admin_menu_cache();

echo "Cleared " . count( $cleared ) . " cache entries:\n";
foreach ( $cleared as $item ) {
	echo "  - " . $item . "\n";
}

echo "\n✓ Cache clearing complete!\n\n";
echo "Next steps:\n";
echo "1. Clear your browser cache or open an incognito window\n";
echo "2. Log into WordPress admin\n";
echo "3. Navigate to NV oOS Pro > Pro Workflows\n";
echo "4. Verify the URL is: /wp-admin/admin.php?page=nvoos-pro-workflow-builder\n\n";

// If running via PHP-CLI, exit with success code.
if ( php_sapi_name() === 'cli' && ! defined( 'WP_CLI' ) ) {
	exit( 0 );
}
