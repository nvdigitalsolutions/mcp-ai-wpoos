<?php
/**
 * Extended Cognition — Sensor Access Trait
 *
 * Shared permission-check logic used by every Extended Cognition tool
 * that requires sensor access.  Use this trait instead of duplicating
 * the current_user_can_use_sensors() method.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait providing a shared sensor-access permission check.
 *
 * Checks (in order):
 *  1. Logged-in user with edit_posts capability.
 *  2. Guest access enabled in settings AND request context marked as guest.
 *
 * @since 1.8.1
 */
trait WP_MCP_AI_Ext_Cog_Sensor_Access {

	/**
	 * Check if the current user (or guest) is allowed to use sensors.
	 *
	 * @param array $context Execution context.
	 * @return bool
	 */
	private function current_user_can_use_sensors( array $context ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();
		if ( ! empty( $settings['guest_access'] ) && ! empty( $context['guest_request'] ) ) {
			return true;
		}

		return false;
	}
}
