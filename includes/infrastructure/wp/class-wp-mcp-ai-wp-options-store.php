<?php
/**
 * WordPress Options Store Adapter
 *
 * Implements Interface_WP_MCP_AI_Options_Store using WordPress's native
 * `get_option` / `update_option` / `delete_option` functions.
 *
 * Register this in the DI container as the canonical implementation:
 *
 *   $container->bind(
 *       'Interface_WP_MCP_AI_Options_Store',
 *       'WP_MCP_AI_WP_Options_Store'
 *   );
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-options-store.php';

/**
 * WordPress implementation of the Options Store interface.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_WP_Options_Store implements Interface_WP_MCP_AI_Options_Store {

	/**
	 * Get an option value.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Default value if option does not exist.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return get_option( $key, $default );
	}

	/**
	 * Update an option value.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True on success, false on failure or if value was unchanged.
	 */
	public function update( $key, $value ) {
		return update_option( $key, $value );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $key Option name.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key ) {
		return delete_option( $key );
	}
}
