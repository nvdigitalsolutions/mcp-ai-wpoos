<?php
/**
 * REST controller — chat profile bridge.
 *
 * Exposes the chat-profile surface under `/mcp-ai/v1/chat-profile` so chat
 * clients can read the catalogue and the current profile, and capability
 * holders can switch it. The route is deliberately NOT a registered tool:
 * the agent must never be able to widen its own grant mid-run.
 *
 * Routes:
 *  - GET  /chat-profile   List profiles + the current profile (cap: read).
 *  - POST /chat-profile   Switch the current profile (cap:
 *                         wp_mcp_ai_change_chat_profile → manage_options;
 *                         downgrades also allowed for holders of the target
 *                         profile's declared capability).
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the chat profile surface.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_REST_Chat_Profile_Controller extends WP_MCP_AI_REST_Controller_Base {

	/**
	 * Register the chat-profile routes.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'check_permission' ),
					'callback'            => array( $this, 'get_profile_state' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'check_permission' ),
					'callback'            => array( $this, 'set_profile' ),
					'args'                => array(
						'profile' => array(
							'description'       => __( 'Profile slug to switch to.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — any logged-in user with read access.
	 *
	 * @since 2.2.0
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in to manage chat profiles.', 'mcp-ai-wpoos' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * GET handler: profiles catalogue + current profile.
	 *
	 * @since 2.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_profile_state( $request ) {
		unset( $request );

		$user_id   = get_current_user_id();
		$current   = WP_MCP_AI_Chat_Profile_Manager::resolve_slug( $user_id, null );
		$profiles  = array();
		$catalogue = WP_MCP_AI_Chat_Profile_Registry::get_profiles();

		foreach ( $catalogue as $slug => $profile ) {
			$profiles[] = $profile->to_array( WP_MCP_AI_Chat_Profile_Registry::can_user_select( $user_id, $slug ) );
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'profiles' => $profiles,
				'current'  => $current,
				'enabled'  => WP_MCP_AI_Chat_Profile_Manager::is_enabled(),
			)
		);
	}

	/**
	 * POST handler: switch the current profile.
	 *
	 * @since 2.2.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_profile( $request ) {
		$user_id = get_current_user_id();
		$slug    = $request->get_param( 'profile' );

		$result = WP_MCP_AI_Chat_Profile_Manager::set_user_profile( $user_id, $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_profile_state( $request );
	}
}
