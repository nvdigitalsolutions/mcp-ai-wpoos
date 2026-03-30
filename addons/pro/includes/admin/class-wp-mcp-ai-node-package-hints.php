<?php
/**
 * Node Package Install Hints
 *
 * Provides translatable install hint strings for optional Node.js/npm packages
 * used by Pro addon features. Centralising hints here ensures consistent
 * messaging across settings pages regardless of their class hierarchy.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper class for npm package install hints.
 */
class WP_MCP_AI_Node_Package_Hints {

	/**
	 * Get install hint for the canvas npm package.
	 *
	 * Canvas is now distributed as the separate NV oOS Canvas Addon WordPress
	 * plugin, which bundles pre-compiled native binaries for Linux x64 and
	 * linux-arm64. Install that plugin to enable canvas support without
	 * needing Node.js or system-level build tools on your server.
	 *
	 * @return string Translated install hint.
	 */
	public static function get_canvas_install_hint() {
		return __( 'Install the NV oOS Canvas Addon plugin to enable canvas support with pre-compiled binaries (no npm or system libs required).', 'mcp-ai-wpoos-pro' );
	}
}
