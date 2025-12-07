<?php
/**
 * Base Version Explicit Mode Entry Point (Optional)
 *
 * IMPORTANT: This file is NOT included in standalone base distributions
 * (wp-mcp-ai-base-X.Y.Z.zip) to prevent WordPress from detecting two plugins.
 *
 * This file can be used in custom deployments where you want to explicitly
 * enable "base version mode" which disables optional third-party plugin
 * integrations (JetEngine, Elementor, etc.) even when those plugins are
 * installed.
 *
 * The standalone base distribution (wp-mcp-ai-base-X.Y.Z.zip) works without
 * this file - it uses mcp-ai-wpoos.php directly. The base distribution excludes
 * addons/pro but includes optional integration code that activates only when
 * the third-party plugins are present.
 *
 * To use this file:
 * 1. Place it in your custom deployment
 * 2. It will set WP_MCP_AI_BASE_VERSION=true
 * 3. This disables optional integrations even if third-party plugins exist
 *
 * For most users, this file is not needed. Use mcp-ai-wpoos.php directly.
 *
 * Note: This file intentionally does NOT contain WordPress plugin headers
 * to prevent it from being registered as a separate plugin. The actual
 * plugin registration happens in mcp-ai-wpoos.php.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 *
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 *
 * Patent Pending: This software is the subject of a pending patent application
 * (Application #19/410,504) for "System and Method for Dynamic AI Orchestration
 * Layer with Real-Time Capability Gating and Resource Budgeting."
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
