<?php
/**
 * Bridge bootstrap — loads the WP 7.0 Connectors API bridge classes and
 * registers hooks.
 *
 * This file is included by includes/bootstrap/loader.php after all core
 * classes are available but before admin-only loads. It is a no-op on
 * WordPress < 7.0 or when WP_AI_SUPPORT is disabled.
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
// Early bail: skip entirely when the WP 7.0 AI infrastructure is absent.
// This avoids loading the bridge classes on every WP < 7.0 site.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_supports_ai' ) ) {
	return;
}

// ---------------------------------------------------------------------------
// Load bridge classes.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/class-wp-mcp-ai-wp70-bridge.php';
require_once __DIR__ . '/class-wp-mcp-ai-credential-resolver.php';

// ---------------------------------------------------------------------------
// Bootstrap connector registration on wp_connectors_init.
// Credential_Resolver is stateless — no bootstrap needed.
// ---------------------------------------------------------------------------

WP_MCP_AI_WP70_Bridge::bootstrap();
