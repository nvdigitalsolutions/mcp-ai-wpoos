<?php
/**
 * Test helper functions for authenticated requests
 *
 * @package WP_MCP_AI
 */

/**
 * Helper class for creating authenticated test requests and test data.
 */
class WP_MCP_AI_Test_Helper {
	/**
	 * Create an authenticated admin user for tests.
	 *
	 * @return int User ID.
	 */
	public static function create_admin_user() {
		$admin_id = wp_create_user( 'test_admin_' . uniqid(), 'password', 'admin@example.com' );
		$admin    = new WP_User( $admin_id );
		$admin->set_role( 'administrator' );
		
		return $admin_id;
	}

	/**
	 * Create authenticated REST request.
	 *
	 * @param string $route  Route path.
	 * @param string $method HTTP method.
	 * @param array  $params Request parameters.
	 * @param int    $user_id Optional. User ID for authentication.
	 * @return WP_REST_Request
	 */
	public static function create_authenticated_request( $route, $method = 'GET', $params = array(), $user_id = null ) {
		// Set current user if provided.
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		} elseif ( ! get_current_user_id() ) {
			// Create admin user if no user is set.
			$user_id = self::create_admin_user();
			wp_set_current_user( $user_id );
		}

		$request = new WP_REST_Request( $method, $route );
		
		// Add nonce for authentication.
		$nonce = wp_create_nonce( 'wp_rest' );
		$request->set_header( 'X-WP-Nonce', $nonce );
		
		// Set nonce in $_SERVER for wp_verify_nonce checks.
		$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		
		// Add parameters.
		if ( ! empty( $params ) ) {
			if ( 'GET' === $method ) {
				$request->set_query_params( $params );
			} else {
				$request->set_body_params( $params );
			}
		}
		
		return $request;
	}

	/**
	 * Create test assistant.
	 *
	 * @param array $args Assistant arguments.
	 * @return int Assistant post ID.
	 */
	public static function create_test_assistant( $args = array() ) {
		$defaults = array(
			'post_title'  => 'Test Assistant ' . uniqid(),
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'publish',
			'meta_input'  => array(
				'_wp_mcp_ai_model'       => 'gpt-4.1-mini',
				'_wp_mcp_ai_temperature' => 0.7,
				'_wp_mcp_ai_provider'    => 'openai',
			),
		);
		
		$args = wp_parse_args( $args, $defaults );
		return wp_insert_post( $args );
	}

	/**
	 * Enable all capabilities for current user.
	 * Useful for testing admin-only features.
	 */
	public static function grant_all_capabilities() {
		add_filter(
			'user_has_cap',
			function ( $allcaps ) {
				$allcaps['manage_options'] = true;
				$allcaps['edit_posts']      = true;
				$allcaps['upload_files']    = true;
				$allcaps['edit_others_posts'] = true;
				$allcaps['delete_posts']    = true;
				return $allcaps;
			}
		);
	}

	/**
	 * Clean up test data created by helper methods.
	 *
	 * @param int $user_id Optional. User ID to delete.
	 */
	public static function cleanup( $user_id = null ) {
		if ( $user_id ) {
			wp_delete_user( $user_id );
		}
	}
}
