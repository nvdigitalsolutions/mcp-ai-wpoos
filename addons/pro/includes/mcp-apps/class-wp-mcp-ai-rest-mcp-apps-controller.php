<?php
/**
 * REST Controller for MCP Apps management.
 *
 * Provides REST API endpoints for testing MCP App connections
 * and discovering available tools from remote MCP servers.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for MCP Apps.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_REST_MCP_Apps_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST routes.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_connection' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
							'description'       => __( 'Remote MCP server endpoint URL.', 'mcp-ai-wpoos-pro' ),
						),
						'auth_type'   => array(
							'type'              => 'string',
							'default'           => 'none',
							'enum'              => array( 'none', 'bearer', 'header' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'token'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'header_name' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'timeout'     => array(
							'type'    => 'integer',
							'default' => 30,
							'minimum' => 1,
							'maximum' => 120,
						),
						'verify_ssl'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/discover',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'discover_tools' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'server_url'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'esc_url_raw',
						),
						'auth_type'   => array(
							'type'              => 'string',
							'default'           => 'none',
							'enum'              => array( 'none', 'bearer', 'header' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'token'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'header_name' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'timeout'     => array(
							'type'    => 'integer',
							'default' => 30,
							'minimum' => 1,
							'maximum' => 120,
						),
						'verify_ssl'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/mcp-apps/(?P<assistant_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_assistant_apps' ),
					'permission_callback' => array( $this, 'check_admin_permissions' ),
					'args'                => array(
						'assistant_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for admin-only endpoints.
	 *
	 * @since 1.8.0
	 * @return bool|WP_Error
	 */
	public function check_admin_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage MCP Apps.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Test connection to a remote MCP server.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection( WP_REST_Request $request ) {
		$config = array(
			'server_url'  => $request->get_param( 'server_url' ),
			'auth_type'   => $request->get_param( 'auth_type' ),
			'token'       => $request->get_param( 'token' ),
			'header_name' => $request->get_param( 'header_name' ),
			'timeout'     => $request->get_param( 'timeout' ),
			'verify_ssl'  => $request->get_param( 'verify_ssl' ),
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$result   = $registry->test_connection( $config );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Discover tools from a remote MCP server.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function discover_tools( WP_REST_Request $request ) {
		$config = array(
			'server_url'  => $request->get_param( 'server_url' ),
			'auth_type'   => $request->get_param( 'auth_type' ),
			'token'       => $request->get_param( 'token' ),
			'header_name' => $request->get_param( 'header_name' ),
			'timeout'     => $request->get_param( 'timeout' ),
			'verify_ssl'  => $request->get_param( 'verify_ssl' ),
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$tools    = $registry->discover_tools( $config );

		if ( is_wp_error( $tools ) ) {
			return $tools;
		}

		// Format tools for display.
		$formatted = array();
		foreach ( $tools as $tool ) {
			$formatted_tool = array(
				'name'        => isset( $tool['name'] ) ? $tool['name'] : '',
				'description' => isset( $tool['description'] ) ? $tool['description'] : '',
				'has_ui'      => ! empty( $tool['_meta']['ui']['resourceUri'] ) || ! empty( $tool['_meta']['ui/resourceUri'] ),
			);

			if ( isset( $tool['inputSchema'] ) ) {
				$formatted_tool['parameters'] = $tool['inputSchema'];
			}

			$formatted[] = $formatted_tool;
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'tool_count' => count( $formatted ),
				'tools'      => $formatted,
			)
		);
	}

	/**
	 * Get MCP Apps configured for an assistant.
	 *
	 * @since 1.8.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_assistant_apps( WP_REST_Request $request ) {
		$assistant_id = $request->get_param( 'assistant_id' );

		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_not_found',
				__( 'Assistant not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $assistant_id );

		// Mask tokens in response.
		$safe_apps = array();
		foreach ( $apps as $app ) {
			$app_copy = $app;
			if ( ! empty( $app_copy['token'] ) ) {
				$app_copy['token'] = '••••••••' . substr( $app_copy['token'], -4 );
			}
			$safe_apps[] = $app_copy;
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'assistant_id' => $assistant_id,
				'apps'         => $safe_apps,
				'app_count'    => count( $safe_apps ),
			)
		);
	}
}
