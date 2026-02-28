<?php
/**
 * Telegram Mini App (WebApp) REST Controller.
 *
 * Provides a REST endpoint for validating Telegram Mini App initData
 * and issuing a guest token for inline authentication within the Mini App.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for Telegram Mini App WebApp authentication.
 */
class WP_MCP_AI_REST_Telegram_WebApp_Controller extends WP_MCP_AI_REST_Controller_Base {

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/telegram/webapp-auth',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_webapp_auth' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'init_data'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Telegram WebApp initData string.', 'mcp-ai-wpoos' ),
						),
						'assistant_id' => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Assistant ID for guest token scoping.', 'mcp-ai-wpoos' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Handle Telegram WebApp authentication.
	 *
	 * Validates initData from the Telegram Mini App SDK and returns a guest token
	 * scoped to the requested assistant.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webapp_auth( WP_REST_Request $request ) {
		$init_data    = $request->get_param( 'init_data' );
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );

		// Retrieve the bot token.
		$bot_token = WP_MCP_AI_Telegram_WebApp_Auth::get_bot_token();
		if ( empty( $bot_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_not_configured',
				__( 'Telegram bot token is not configured. Please set up a Telegram connection in the admin panel.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Validate the initData.
		$result = WP_MCP_AI_Telegram_WebApp_Auth::validate_init_data( $init_data, $bot_token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Generate a guest token for the authenticated Telegram user.
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return new WP_Error(
				'wp_mcp_ai_shortcode_unavailable',
				__( 'Guest token generation is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
		if ( empty( $guest_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_guest_token_failed',
				__( 'Failed to generate guest token.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$user      = isset( $result['user'] ) ? $result['user'] : array();
		$user_name = '';
		if ( ! empty( $user['first_name'] ) ) {
			$user_name = sanitize_text_field( $user['first_name'] );
			if ( ! empty( $user['last_name'] ) ) {
				$user_name .= ' ' . sanitize_text_field( $user['last_name'] );
			}
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'guest_token'  => $guest_token,
				'user'         => array(
					'id'         => isset( $user['id'] ) ? absint( $user['id'] ) : 0,
					'first_name' => isset( $user['first_name'] ) ? sanitize_text_field( $user['first_name'] ) : '',
					'last_name'  => isset( $user['last_name'] ) ? sanitize_text_field( $user['last_name'] ) : '',
					'username'   => isset( $user['username'] ) ? sanitize_text_field( $user['username'] ) : '',
				),
				'display_name' => $user_name,
			)
		);
	}
}
