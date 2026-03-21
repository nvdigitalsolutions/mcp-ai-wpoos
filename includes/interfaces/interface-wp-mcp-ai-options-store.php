<?php
/**
 * Interface: Options Store
 *
 * Abstracts WordPress `get_option` / `update_option` / `delete_option` so that
 * application-layer and domain classes can read and write plugin settings without
 * depending on WordPress functions directly.
 *
 * Implement this interface in `includes/infrastructure/wp/class-wp-mcp-ai-wp-options-store.php`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction for reading and writing plugin options.
 *
 * @since 1.2.0
 */
interface Interface_WP_MCP_AI_Options_Store {

	/**
	 * Get an option value.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Default value if option does not exist.
	 * @return mixed
	 */
	public function get( $key, $default = null );

	/**
	 * Update an option value.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True on success, false on failure.
	 */
	public function update( $key, $value );

	/**
	 * Delete an option.
	 *
	 * @param string $key Option name.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key );
}
