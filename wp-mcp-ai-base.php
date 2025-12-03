<?php
/**
 * Plugin Name: WP Open Operator System (Base)
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Includes 35+ core tools. Standalone version without Pro add-ons.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * Text Domain: wp-mcp-ai
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

/**
 * Base Version Entry Point
 *
 * This file serves as the entry point for the Base version of the plugin.
 * It simply includes the main plugin file (wp-mcp-ai.php) which contains
 * the full plugin implementation.
 *
 * The Base version is identical to the full version except it excludes
 * the Pro add-ons directory (addons/pro).
 */

// Define the base version constant to exclude Pro features.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', true );
}

// Include the main plugin file.
require_once __DIR__ . '/wp-mcp-ai.php';
