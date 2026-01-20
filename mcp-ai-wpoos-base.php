<?php
/**
 * Plugin Name: NV Digital Open Operator System (oOS) - Base
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Base version with core features and optional third-party plugin integrations.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mcp-ai-wpoos
 * Domain Path: /languages
 * Network: true
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
