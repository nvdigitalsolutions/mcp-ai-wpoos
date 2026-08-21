<?php
/**
 * REST API controller for user restrictions.
 *
 * Exposes the restriction registry so admin tooling (Command Center,
 * external dashboards) can list flagged users and lift restrictions.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restrictions REST controller.
 *
 * Routes:
 * - GET  /mcp-ai/v1/restrictions                List active restrictions.
 * - GET  /mcp-ai/v1/users/{id}/restrictions      Per-user restriction records.
 * - POST /mcp-ai/v1/users/{id}/restrictions      Admin-applied manual block.
 * - DELETE /mcp-ai/v1/users/{id}/restrictions/{type} Lift a restriction.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_REST_Restrictions_Controller {

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST routes.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/restrictions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_restrictions' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'type'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'user_id'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>[\d]+)/restrictions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_user_restrictions' ),
					'permission_callback' => array( __CLASS__, 'check_user_or_admin_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_manual_restriction' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'id'         => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'reason'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'expires_in' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>[\d]+)/restrictions/(?P<type>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'lift_restriction' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'type' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * List active restrictions.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_restrictions( $request ) {
		$data = WP_MCP_AI_Restriction_Registry::get_active(
			array(
				'type'     => $request->get_param( 'type' ),
				'user_id'  => $request->get_param( 'user_id' ),
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
			)
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Get all restriction records for a single user.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_user_restrictions( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		$records = WP_MCP_AI_Restriction_Registry::get_for_user( $user_id );

		// Never leak records for missing users.
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_user_not_found',
				__( 'User not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'user_id'      => $user_id,
				'restrictions' => $records,
			)
		);
	}

	/**
	 * Add an admin-initiated manual restriction.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_manual_restriction( $request ) {
		$user_id    = absint( $request->get_param( 'id' ) );
		$expires_in = absint( $request->get_param( 'expires_in' ) );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_user_not_found',
				__( 'User not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$details = array(
			'reason' => $request->get_param( 'reason' ),
		);
		if ( $expires_in > 0 ) {
			$details['released_at'] = time() + $expires_in;
		}

		$record = WP_MCP_AI_Restriction_Registry::add_manual( $user_id, $details, get_current_user_id() );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		return rest_ensure_response(
			array(
				'user_id' => $user_id,
				'record'  => $record,
			)
		);
	}

	/**
	 * Lift a restriction for a user.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lift_restriction( $request ) {
		$user_id = absint( $request->get_param( 'id' ) );
		$type    = sanitize_key( $request->get_param( 'type' ) );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_user_not_found',
				__( 'User not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$result = WP_MCP_AI_Restriction_Registry::lift( $user_id, $type, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'user_id' => $user_id,
				'lifted'  => true,
			)
		);
	}

	/**
	 * Permission callback: administrators only.
	 *
	 * @since 1.2.0
	 *
	 * @return bool|WP_Error
	 */
	public static function check_admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You are not allowed to manage restrictions.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission callback: the affected user or an administrator.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function check_user_or_admin_permission( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_id = absint( $request->get_param( 'id' ) );
		if ( is_user_logged_in() && get_current_user_id() === $user_id ) {
			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_forbidden',
			__( 'You are not allowed to view these restrictions.', 'mcp-ai-wpoos' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
