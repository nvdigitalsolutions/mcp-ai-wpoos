<?php
/**
 * Open Operator System (WP oOS) - Base Version Entry Point
 *
 * This file serves as the entry point for the standalone base version
 * when built for WordPress.org. In the repository, it does NOT have a
 * plugin header to prevent WordPress from detecting it as a separate plugin.
 *
 * The build script (bin/build-plugin-zip.sh) adds the plugin header when
 * creating the base version distribution for WordPress.org, where this file
 * gets renamed to mcp-ai-wpoos.php.
 *
 * @package WP_MCP_AI
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent double-loading if the main plugin is already loaded.
if ( function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	return;
}

// Define the base version constant to exclude Pro features.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', true );
}

// Define the plugin file constant before including the main file.
// This ensures activation hooks and plugin paths reference the correct entry point.
if ( ! defined( 'WP_MCP_AI_FILE' ) ) {
	define( 'WP_MCP_AI_FILE', __FILE__ );
}

// Include the main plugin file.
require_once __DIR__ . '/mcp-ai-wpoos.php';
