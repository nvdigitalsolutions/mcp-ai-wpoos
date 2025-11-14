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
		// /mcp - JSON-RPC 2.0 MCP protocol endpoint with SSE support.
		// Implements MCP 2024-11-05 specification with Streamable HTTP transport.
		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp',
			array(
				// POST - JSON-RPC 2.0 requests.
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
				// GET - SSE discovery and Streamable HTTP transport (MCP 2024-11-05).
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_mcp_get_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'Optional assistant ID for SSE stream.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
				// OPTIONS - CORS preflight.
				array(
					'methods'             => 'OPTIONS',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'handle_mcp_options' ),
				),
			),
			true
		);

		// /no-sse - Legacy endpoint for clients that don't support SSE.
		// Since GET /mcp now defaults to SSE, this endpoint provides non-SSE access.
		register_rest_route(
			self::REST_NAMESPACE,
			'/no-sse',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_no_sse_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant for directory listing.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
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
	 * General permission check for authenticated endpoints.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		// Validate main controller is available.
		if ( null === $this->main_controller ) {
			return new WP_Error(
				'wp_mcp_ai_controller_not_initialized',
				__( 'REST controller not properly initialized.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Delegate to main controller.
		return $this->main_controller->permissions_check( $request );
	}

	/**
	 * Permission check for MCP protocol endpoint.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_mcp( WP_REST_Request $request ) {
		// Validate main controller is available.
		if ( null === $this->main_controller ) {
			return new WP_Error(
				'wp_mcp_ai_controller_not_initialized',
				__( 'REST controller not properly initialized.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

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
		// Validate main controller is available.
		if ( null === $this->main_controller ) {
			return new WP_Error(
				'wp_mcp_ai_controller_not_initialized',
				__( 'REST controller not properly initialized.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

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
	 * Handle no-sse endpoint (non-SSE assistant directory).
	 *
	 * Since GET /mcp now defaults to SSE, this endpoint provides
	 * a way to get assistant directory without SSE streaming.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_no_sse_request( WP_REST_Request $request ) {
		// Return assistant directory as JSON (no SSE).
		return $this->handle_assistants_index( $request );
	}

	/**
	 * Handle SSE handshake (kept for internal use).
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

	/**
	 * Handle GET requests to /mcp endpoint.
	 *
	 * Returns MCP server discovery information as JSON by default.
	 * This ensures compatibility with MCP clients like LM Studio that expect
	 * JSON-RPC protocol information, not SSE streams.
	 *
	 * SSE streaming is opt-in via the 'stream' parameter ONLY.
	 * Accept header is NOT used for SSE detection because LM Studio and other
	 * MCP clients send "Accept: text/event-stream" by default even though they
	 * expect JSON-RPC responses, not SSE streams.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_mcp_get_request( WP_REST_Request $request ) {
		// Check if client explicitly requested SSE streaming via parameter.
		// NOTE: We do NOT check the Accept header because LM Studio and other
		// MCP clients send "Accept: text/event-stream" by default but expect
		// JSON-RPC responses for Streamable HTTP transport (MCP 2024-11-05 spec).
		$wants_streaming = $request->get_param( 'stream' ) === 'true' || $request->get_param( 'stream' ) === '1';

		if ( $wants_streaming ) {
			// Client explicitly wants SSE stream via ?stream=true parameter.
			return $this->handle_sse_handshake( $request );
		}

		// Default behavior: Return discovery JSON.
		// This is compatible with LM Studio and other Streamable HTTP (JSON-RPC) clients.
		return $this->return_discovery_info( $request );
	}

	/**
	 * Return MCP endpoint discovery information.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	protected function return_discovery_info( WP_REST_Request $request ) {
		// Return MCP endpoint discovery information.
		$response_data = array(
			'name'            => 'WP oOS MCP Server',
			'version'         => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			'protocolVersion' => '2024-11-05',
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => true ),
				'resources' => array(
					'subscribe'   => true,
					'listChanged' => true,
				),
				'prompts'   => array( 'listChanged' => true ),
				'sse'       => array(
					'enabled' => true,
					'default' => false,
					'note'    => 'SSE available via ?stream=true parameter only (use /sse endpoint for dedicated SSE)',
				),
			),
			'transports'      => array(
				'streamable_http' => array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/mcp' ),
					'methods'  => array( 'GET', 'POST' ),
					'default'  => true,
					'note'     => 'MCP 2024-11-05 Streamable HTTP - GET for discovery (JSON), POST for JSON-RPC 2.0',
				),
				'sse'             => array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/sse' ),
					'methods'  => array( 'GET' ),
					'default'  => false,
					'note'     => 'Optional SSE streaming - use /sse endpoint or /mcp?stream=true',
				),
			),
			'endpoints'       => array(
				'mcp'        => rest_url( self::REST_NAMESPACE . '/mcp' ),
				'sse'        => rest_url( self::REST_NAMESPACE . '/sse' ),
				'assistants' => rest_url( self::REST_NAMESPACE . '/assistants' ),
				'chat'       => rest_url( self::REST_NAMESPACE . '/chat' ),
				'tools'      => rest_url( self::REST_NAMESPACE . '/tools' ),
			),
			'usage'           => array(
				'discovery'      => 'GET /mcp (default - returns this discovery JSON for LM Studio, etc.)',
				'jsonrpc'        => 'POST /mcp (JSON-RPC 2.0 protocol for tool execution)',
				'sse_dedicated'  => 'GET /sse (dedicated SSE endpoint)',
				'sse_opt_in'     => 'GET /mcp?stream=true (opt-in SSE on /mcp endpoint)',
			),
		);

		$response = new WP_REST_Response( $response_data, 200 );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );

		// Add CORS headers.
		$this->add_cors_headers( $response );

		return $response;
	}

	/**
	 * Add CORS headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 */
	protected function add_cors_headers( $response ) {
		// Delegate to main controller if it has the method.
		if ( method_exists( $this->main_controller, 'add_cors_headers' ) ) {
			$this->main_controller->add_cors_headers( $response );
			return;
		}

		// Otherwise add basic CORS headers.
		$allow_origin = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );
		$response->header( 'Access-Control-Allow-Origin', $allow_origin );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest, Accept, Mcp-Session-Id' );
		$response->header( 'Access-Control-Max-Age', '3600' );
	}
}
