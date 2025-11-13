<?php
/**
 * MCP Protocol Controller for REST API
 *
 * Handles MCP (Model Context Protocol) specific endpoints including
 * JSON-RPC 2.0 protocol, SSE streaming, and assistant directory.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Protocol Controller Class
 *
 * Manages all MCP protocol-related REST API endpoints:
 * - /mcp - JSON-RPC 2.0 MCP protocol endpoint
 * - /sse - Server-Sent Events streaming
 * - /assistants - MCP-compliant assistant directory listing
 */
class WP_MCP_AI_REST_MCP_Controller extends WP_MCP_AI_REST_Controller_Base {
	/**
	 * Reference to the main REST controller for shared functionality.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $main_controller;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST                    $main_controller Main REST controller.
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator   Authentication handler (optional, for DI).
	 * @param WP_MCP_AI_REST_Validator|null     $validator       Request validator (optional, for DI).
	 */
	public function __construct( $main_controller = null, $authenticator = null, $validator = null ) {
		parent::__construct( $authenticator, $validator );
		$this->main_controller = $main_controller;
	}

	/**
	 * Register MCP protocol routes.
	 */
	public function register_routes() {
		// /mcp - JSON-RPC 2.0 MCP protocol endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_mcp' ),
					'callback'            => array( $this, 'handle_mcp_request' ),
					'args'                => array(
						'jsonrpc' => array(
							'description'       => __( 'JSON-RPC version. Must be "2.0".', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( '2.0' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'id'      => array(
							'description' => __( 'Request identifier. Omit for notifications.', 'wp-mcp-ai' ),
							'oneOf'       => array(
								array( 'type' => 'string' ),
								array( 'type' => 'integer' ),
							),
							'required'    => false,
						),
						'method'  => array(
							'description'       => __( 'MCP method name to invoke.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'enum'              => array(
								'initialize',
								'tools/list',
								'tools/call',
								'resources/list',
								'prompts/list',
							),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'params'  => array(
							'description'       => __( 'Method parameters object.', 'wp-mcp-ai' ),
							'type'              => 'object',
							'required'          => false,
							'default'           => array(),
							'validate_callback' => array( $this->validator, 'validate_mcp_params' ),
						),
					),
				),
				array(
					'methods'             => 'OPTIONS',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'handle_mcp_options' ),
				),
			),
			true
		);

		// /sse - Server-Sent Events streaming endpoint.
		// GET is standard, POST is optional for LM Studio compatibility.
		$sse_handlers = array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'permissions_check' ),
				'callback'            => array( $this, 'handle_sse_handshake' ),
				'args'                => array(
					'assistant_id' => array(
						'description'       => __( 'ID of the assistant for SSE handshake.', 'wp-mcp-ai' ),
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			),
		);

		// Add POST support if enabled in settings (non-standard, for LM Studio bugs).
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( ! empty( $settings['sse_enable_post_method'] ) ) {
			$sse_handlers[] = array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'permissions_check' ),
				'callback'            => array( $this, 'handle_sse_handshake' ),
				'args'                => array(
					'assistant_id' => array(
						'description'       => __( 'ID of the assistant for SSE handshake.', 'wp-mcp-ai' ),
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/sse',
			$sse_handlers,
			true
		);

		// /assistants - MCP-compliant assistant directory.
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_assistants_index' ),
					'args'                => array(
						'search'  => array(
							'description'       => __( 'Search term to filter assistants by title or content.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include' => array(
							'description' => __( 'Limit results to specific assistant IDs.', 'wp-mcp-ai' ),
							'type'        => 'array',
							'required'    => false,
							'items'       => array(
								'type' => 'integer',
							),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_assistant_create' ),
					'callback'            => array( $this, 'handle_assistants_index' ),
				),
			),
			true
		);
	}

	/**
	 * Permission check for MCP protocol endpoint.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_mcp( WP_REST_Request $request ) {
		// Delegate to main controller for now.
		return $this->main_controller->permissions_check_mcp( $request );
	}

	/**
	 * Permission check for assistant creation.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_assistant_create( WP_REST_Request $request ) {
		// Delegate to main controller for now.
		return $this->main_controller->permissions_check_assistant_create( $request );
	}

	/**
	 * Handle MCP protocol request (JSON-RPC 2.0).
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_mcp_request( WP_REST_Request $request ) {
		// Delegate to main controller's trait method.
		return $this->main_controller->handle_mcp_request( $request );
	}

	/**
	 * Handle MCP OPTIONS request for CORS.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public function handle_mcp_options( WP_REST_Request $request ) {
		// Delegate to main controller.
		return $this->main_controller->handle_mcp_options( $request );
	}

	/**
	 * Handle SSE (Server-Sent Events) handshake.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_sse_handshake( WP_REST_Request $request ) {
		// Delegate to main controller.
		return $this->main_controller->handle_sse_handshake( $request );
	}

	/**
	 * Handle assistants index endpoint.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistants_index( WP_REST_Request $request ) {
		// Delegate to main controller.
		return $this->main_controller->handle_assistants_index( $request );
	}
}
