<?php
/**
 * Default capability trait for WP MCP AI tools.
 *
 * Provides a map-lookup → 'edit_posts' fallback implementation of
 * {@see WP_MCP_AI_Tool_Interface::get_required_capability()} so that
 * tool classes can satisfy the interface requirement without boilerplate.
 *
 * Usage: add `use WP_MCP_AI_Tool_Default_Capability;` inside any tool class
 * that implements WP_MCP_AI_Tool_Interface and does not declare its own
 * get_required_capability() method.
 *
 * @package WP_MCP_AI
 * @since   1.1.20
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a default get_required_capability() for tool classes.
 */
trait WP_MCP_AI_Tool_Default_Capability {

	/**
	 * WordPress capability required to execute this tool.
	 *
	 * Looks up the tool slug in WP_MCP_AI_Tool_Capability_Map when available,
	 * falling back to 'edit_posts' if no mapping is found.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		$slug = $this->get_slug();

		if ( class_exists( 'WP_MCP_AI_Tool_Capability_Map' ) ) {
			$cap = WP_MCP_AI_Tool_Capability_Map::get_capability( $slug );
			if ( $cap ) {
				return $cap;
			}
		}

		return 'edit_posts';
	}
}
