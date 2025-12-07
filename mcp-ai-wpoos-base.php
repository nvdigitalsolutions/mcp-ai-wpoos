<?php
/**
 * Plugin Name: Open Operator System (WP oOS)
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Works standalone with optional third-party plugin integrations.
 * Version: 1.1.0-dev
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7.1
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: mcp-ai-wpoos-base
 * Domain Path: /languages
 * Network: true
 *
 * @package WP_MCP_AI
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 *
 * ---
 *
 * This is the main entry point for the standalone version of WP oOS.
 * This version works with vanilla WordPress without any third-party dependencies.
 *
 * When distributed for WordPress.org, this file will be renamed to
 * mcp-ai-wpoos.php to match WordPress.org naming conventions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the base version constant to exclude Pro features.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
	define( 'WP_MCP_AI_BASE_VERSION', true );
}

// Include the main plugin file.
require_once __DIR__ . '/mcp-ai-wpoos.php';
