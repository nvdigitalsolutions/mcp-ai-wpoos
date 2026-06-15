<?php
/**
 * Bridge bootstrap — loads the WP 7.0 Connectors API bridge classes and
 * registers hooks.
 *
 * This file is included by includes/bootstrap/loader.php after all core
 * classes are available but before admin-only loads. Classes are loaded
 * on all WP versions (they contain internal guards); connector
 * registration hooks run only on WordPress 7.0+.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Load bridge classes.
//
// Both classes are loaded unconditionally because:
// - Credential_Resolver is called from WP_MCP_AI_Model_Config on all WP
// versions and handles WP < 7.0 gracefully internally.
// - WP70_Bridge methods all guard themselves with is_available() and are
// safe to define even when the WP 7.0 infrastructure is absent.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/class-wp-mcp-ai-wp70-bridge.php';
require_once __DIR__ . '/class-wp-mcp-ai-credential-resolver.php';

// ---------------------------------------------------------------------------
// Bootstrap connector registration on wp_connectors_init — WP 7.0+ only.
// Credential_Resolver is stateless and needs no bootstrap.
// ---------------------------------------------------------------------------

if ( function_exists( 'wp_supports_ai' ) ) {
	WP_MCP_AI_WP70_Bridge::bootstrap();
}
