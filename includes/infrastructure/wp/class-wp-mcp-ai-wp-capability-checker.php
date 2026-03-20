<?php
/**
 * WordPress Capability Checker Adapter
 *
 * Implements Interface_WP_MCP_AI_Capability_Checker using WordPress's native
 * `current_user_can` and `user_can` functions.
 *
 * Register this in the DI container as the canonical implementation:
 *
 *   $container->bind(
 *       'Interface_WP_MCP_AI_Capability_Checker',
 *       'WP_MCP_AI_WP_Capability_Checker'
 *   );
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-capability-checker.php';

/**
 * WordPress implementation of the Capability Checker interface.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_WP_Capability_Checker implements Interface_WP_MCP_AI_Capability_Checker {

	/**
	 * Check whether the currently authenticated user has a given capability.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Optional extra arguments.
	 * @return bool
	 */
	public function current_user_can( $capability, ...$args ) {
		return current_user_can( $capability, ...$args );
	}

	/**
	 * Check whether a specific user has a given capability.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Optional extra arguments.
	 * @return bool
	 */
	public function user_can( $user_id, $capability, ...$args ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return user_can( $user, $capability, ...$args );
	}
}
