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
// All classes are loaded unconditionally because:
// - Credential_Resolver is called from WP_MCP_AI_Model_Config on all WP
// versions and handles WP < 7.0 gracefully internally.
// - WP70_Bridge methods all guard themselves with is_available() and are
// safe to define even when the WP 7.0 infrastructure is absent.
// - WordPress_Flush is needed whenever the oOS streaming engine runs,
// and its flushPlatformBuffers() method has its own function_exists guard.
// ---------------------------------------------------------------------------

// The WordPress flush adapter implements PlatformFlushInterface from
// lib/core, which may not have its Composer/PSR-4 autoloader registered
// yet (that happens in oos-bridge.php, loaded later in mcp-ai-wpoos.php).
// Require the interface directly so the adapter compiles.
$platform_flush_interface = WP_MCP_AI_PATH . 'lib/core/src/Infrastructure/Streaming/PlatformFlushInterface.php';
if ( file_exists( $platform_flush_interface ) && ! interface_exists( 'Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface' ) ) {
	require_once $platform_flush_interface;
}

require_once __DIR__ . '/class-wp-mcp-ai-wp70-bridge.php';
require_once __DIR__ . '/class-wp-mcp-ai-credential-resolver.php';
require_once __DIR__ . '/class-wp-mcp-ai-wordpress-flush.php';

// ---------------------------------------------------------------------------
// Bootstrap connector registration on wp_connectors_init — WP 7.0+ only.
// Credential_Resolver is stateless and needs no bootstrap.
// ---------------------------------------------------------------------------

if ( function_exists( 'wp_supports_ai' ) ) {
	WP_MCP_AI_WP70_Bridge::bootstrap();
}
