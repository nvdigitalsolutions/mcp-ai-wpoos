<?php
/**
 * Node Package Install Hints
 *
 * Provides translatable install hint strings for optional Node.js/npm packages
 * used by Pro addon features. Centralising hints here ensures consistent
 * messaging across settings pages regardless of their class hierarchy.
 *
 * @package WP_MCP_AI_Pro
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
	 * Explains the correct version to install, the Node.js engine constraint
	 * introduced in canvas v3, and the EACCES permission workaround needed on
	 * shared hosting environments such as Cloudways.
	 *
	 * @return string Translated install hint.
	 */
	public static function get_canvas_install_hint() {
		return __( 'Run: npm install canvas@2 (v3+ requires Node >=20.9.0; canvas@2 supports Node 18/20.x). Requires system libs (cairo, pango). EACCES on shared hosts: mkdir node_modules && chmod 775 node_modules first.', 'mcp-ai-wpoos-pro' );
	}
}
