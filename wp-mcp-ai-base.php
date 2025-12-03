<?php
/**
 * Base Version Entry Point
 *
 * This file serves as the entry point for the Base version of the plugin
 * when packaged as a standalone distribution (wp-mcp-ai-base-X.Y.Z.zip).
 *
 * It sets the WP_MCP_AI_BASE_VERSION constant to exclude Pro features,
 * then includes the main plugin file (wp-mcp-ai.php) which contains the
 * full plugin implementation and WordPress plugin headers.
 *
 * The Base version is identical to the full version except it excludes
 * the Pro add-ons directory (addons/pro).
 *
 * Note: This file intentionally does NOT contain WordPress plugin headers
 * to prevent it from being registered as a separate plugin. The actual
 * plugin registration happens in wp-mcp-ai.php.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the base version constant to exclude Pro features.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', true );
}

// Include the main plugin file.
require_once __DIR__ . '/wp-mcp-ai.php';
