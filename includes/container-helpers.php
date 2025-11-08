<?php
/**
 * Global Container Helper Functions
 *
 * Provides easy access to the DI container and its services.
 * Part of Phase 4 refactoring (Milestone 10).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the global DI container instance
 *
 * @return WP_MCP_AI_Container Container instance.
 */
function wp_mcp_ai_container() {
	return WP_MCP_AI_Container::get_instance();
}

/**
 * Resolve a service from the container
 *
 * Shorthand for wp_mcp_ai_container()->get($id)
 *
 * @param string $id Service identifier.
 * @return mixed Service instance.
 */
function wp_mcp_ai( $id ) {
	return wp_mcp_ai_container()->get( $id );
}

/**
 * Make an instance with dependency injection
 *
 * @param string $class  Class name.
 * @param array  $params Additional parameters.
 * @return object Instance.
 */
function wp_mcp_ai_make( $class, $params = array() ) {
	return wp_mcp_ai_container()->make( $class, $params );
}
