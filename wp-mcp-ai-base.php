<?php
/**
 * Plugin Name: Open Operator System (WP oOS)
 * Plugin URI: https://nvdigitalsolutions.com/wpoos
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Includes 35+ core tools. Patent Pending (Application #19/410,504).
 * Version: 1.0.0
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
 * Patent Pending: This software is the subject of a pending patent application
 * (Application #19/410,504) for "System and Method for Dynamic AI Orchestration
 * Layer with Real-Time Capability Gating and Resource Budgeting."
 *
 * ---
 *
 * This is the main entry point for the standalone base version of WP oOS.
 * This version includes 35+ core tools and works without any third-party
 * plugin dependencies.
 *
 * When distributed as the base version (mcp-ai-wpoos-base), this file will
 * be renamed to mcp-ai-wpoos-base.php to match WordPress.org naming conventions.
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
