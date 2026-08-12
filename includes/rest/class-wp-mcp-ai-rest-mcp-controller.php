<?php
/**
 * MCP Protocol Controller for REST API
 *
 * Handles MCP (Model Context Protocol) specific endpoints including
 * JSON-RPC 2.0 protocol, SSE streaming, and assistant directory.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 *
	 * Registers all MCP (Model Context Protocol) related REST API endpoints:
	 * - POST /mcp: JSON-RPC 2.0 protocol endpoint for MCP method calls
	 * - GET /mcp: SSE discovery and Streamable HTTP transport (MCP 2024-11-05 spec)
	 * - OPTIONS /mcp: CORS preflight handling for cross-origin requests
	 * - GET /no-sse: Legacy endpoint for clients that don't support Server-Sent Events
	 * - GET /assistants: MCP-compliant assistant directory with SSE support
	 * - POST /assistants: Create new assistant via REST API
	 * - DELETE /assistants/{id}: Delete an existing assistant
	 *
	 * The /mcp endpoint implements the MCP 2024-11-05 specification with:
	 * - JSON-RPC 2.0 protocol for method invocation
	 * - Streamable HTTP transport for SSE streaming
	 * - Support for initialize, tools/list, tools/call, resources/list, prompts/list methods
	 *
	 * Authentication is handled via:
	 * - WordPress REST nonces (same-origin)
	 * - Assistant-issued bearer tokens (scoped to specific assistant)
	 * - Auth0 bearer tokens (enterprise integrations)
	 * - Mesh API keys (inter-service communication)
	 *
	 * @since 1.0.0
	 * @see https://spec.modelcontextprotocol.io/specification/2024-11-05/
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
							'description'       => __( 'JSON-RPC version. Must be "2.0".', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( '2.0' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'id'      => array(
							'description' => __( 'Request identifier. Omit for notifications.', 'mcp-ai-wpoos' ),
							'oneOf'       => array(
								array( 'type' => 'string' ),
								array( 'type' => 'integer' ),
							),
							'required'    => false,
						),
						'method'  => array(
							'description'       => __( 'MCP method name to invoke.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_mcp_method' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'params'  => array(
							'description'       => __( 'Method parameters object.', 'mcp-ai-wpoos' ),
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
							'description'       => __( 'Optional assistant ID for SSE stream.', 'mcp-ai-wpoos' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
				// OPTIONS - CORS preflight.
				// `__return_true` is correct here: CORS preflight (OPTIONS)
				// must be answered without authentication so the browser can
				// proceed to the authenticated GET/POST. The handler
				// `handle_mcp_options()` only emits CORS headers and never
				// returns sensitive data.
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
					'permission_callback' => array( $this, 'permissions_check_assistant_list' ),
					'callback'            => array( $this, 'handle_no_sse_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant for directory listing.', 'mcp-ai-wpoos' ),
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
					'permission_callback' => array( $this, 'permissions_check_assistant_list' ),
					'callback'            => array( $this, 'handle_assistants_index' ),
					'args'                => array(
						'search'   => array(
							'description'       => __( 'Search term to filter assistants by title or content.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include'  => array(
							'description' => __( 'Limit results to specific assistant IDs.', 'mcp-ai-wpoos' ),
							'type'        => 'array',
							'required'    => false,
							'items'       => array(
								'type' => 'integer',
							),
						),
						'per_page' => array(
							'description' => __( 'Maximum number of assistants to return. Use -1 (default) to return all.', 'mcp-ai-wpoos' ),
							'type'        => 'integer',
							'required'    => false,
							'minimum'     => -1,
							'maximum'     => 100,
						),
						'page'     => array(
							'description'       => __( 'Page of results to return when per_page is a positive integer. Defaults to 1.', 'mcp-ai-wpoos' ),
							'type'              => 'integer',
							'required'          => false,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'_fields'  => array(
							'description'       => __( 'Comma-separated list of assistant fields to include in each item. Always includes id.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_assistant_create' ),
					'callback'            => array( $this, 'handle_assistant_create' ),
					'args'                => array(
						'title'         => array(
							'description'       => __( 'The title for the assistant. When omitted the request acts as a connectivity check and returns the directory listing.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'   => array(
							'description'       => __( 'The description for the assistant.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'wp_kses_post',
						),
						'provider'      => array(
							'description'       => __( 'AI provider.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'openai', 'gemini', 'ollama', 'anthropic', 'lm_studio', 'huggingface', 'cloudflare', 'nvidia', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'model'         => array(
							'description'       => __( 'Model identifier (e.g., gpt-4, gemini-pro).', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'temperature'   => array(
							'description' => __( 'Temperature setting (0.0 to 2.0).', 'mcp-ai-wpoos' ),
							'type'        => 'number',
							'required'    => false,
							'minimum'     => 0.0,
							'maximum'     => 2.0,
						),
						'system_prompt' => array(
							'description'       => __( 'System prompt for the assistant.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'wp_kses_post',
						),
						'tools'         => array(
							'description' => __( 'Array of tool slugs to enable for this assistant.', 'mcp-ai-wpoos' ),
							'type'        => 'array',
							'required'    => false,
							'items'       => array(
								'type' => 'string',
							),
						),
						'status'        => array(
							'description'       => __( 'Post status (publish, draft, private).', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'publish', 'draft', 'private' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			),
			true
		);

		// /assistants/{id} - Individual assistant operations.
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => array( $this, 'permissions_check_assistant_delete' ),
					'callback'            => array( $this, 'handle_assistant_delete' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Unique identifier for the assistant.', 'mcp-ai-wpoos' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);

		// /sse - Dedicated Server-Sent Events endpoint for MCP clients that expect
		// a separate /sse handshake URL (e.g. Claude Desktop, LM Studio).
		register_rest_route(
			self::REST_NAMESPACE,
			'/sse',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_assistant_list' ),
					'callback'            => array( $this, 'handle_sse_handshake' ),
				),
			),
			true
		);
	}

	/**
	 * Validate the MCP method name for the /mcp endpoint.
	 *
	 * Performs structural validation only. The previous strict enum allowed
	 * five methods and rejected everything else with an HTTP 400 before the
	 * request ever reached the JSON-RPC handler. That broke standard MCP
	 * client handshakes: clients such as Hermes, Claude Desktop, and Zed
	 * send `ping`, `notifications/initialized`, `notifications/cancelled`,
	 * `resources/read`, and `prompts/get` during a normal session.
	 *
	 * Unknown-but-well-formed method names now pass through to the handler,
	 * which answers with a spec-compliant JSON-RPC -32601 "method not found"
	 * error (see WP_MCP_AI_REST_MCP_Methods::route_mcp_method()).
	 *
	 * @since 1.1.52
	 *
	 * @param string          $value   Raw method name from the request.
	 * @param WP_REST_Request $request Current REST request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error True when the method name is structurally valid.
	 */
	public function validate_mcp_method( $value, $request, $param ) {
		unset( $request, $param ); // Context only; not used for structural checks.

		if ( ! is_string( $value ) || '' === $value ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_method',
				__( 'The MCP method name must be a non-empty string.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $value ) > 200 ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_method',
				__( 'The MCP method name exceeds the maximum length of 200 characters.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^[a-z0-9_\-\/.]+$/i', $value ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_method',
				__( 'The MCP method name contains characters that are not allowed.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * General permission check for authenticated endpoints.
	 *
	 * Supports multiple authentication methods:
	 * - WordPress nonce (for same-origin requests)
	 * - Bearer token (for API access)
	 * - Guest token (for public chat surfaces)
	 *
	 * Falls back to base class authentication if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_check( WP_REST_Request $request ) {
		// Try main controller first for full functionality.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'permissions_check' ) ) {
			return $this->main_controller->permissions_check( $request );
		}

		// Fallback: Use base class authentication.
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Permission check for listing assistants.
	 *
	 * Falls back to base class authentication if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_check_assistant_list( WP_REST_Request $request ) {
		// Try main controller first for full functionality.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'permissions_check_assistant_list' ) ) {
			return $this->main_controller->permissions_check_assistant_list( $request );
		}

		// Fallback: Use base class authentication.
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Permission check for MCP protocol endpoint.
	 *
	 * Falls back to base class authentication if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_check_mcp( WP_REST_Request $request ) {
		// Try main controller first for full functionality.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'permissions_check_mcp' ) ) {
			return $this->main_controller->permissions_check_mcp( $request );
		}

		// Fallback: Use base class authentication.
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Permission check for assistant creation.
	 *
	 * Requires admin capabilities (manage_options). Falls back to base class
	 * admin permission check if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_check_assistant_create( WP_REST_Request $request ) {
		// Try main controller first for full functionality.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'permissions_check_assistant_create' ) ) {
			return $this->main_controller->permissions_check_assistant_create( $request );
		}

		// Fallback: Require admin capabilities.
		return $this->permissions_check_admin( $request );
	}

	/**
	 * Permission check for assistant deletion.
	 *
	 * Requires admin capabilities (manage_options). Falls back to base class
	 * admin permission check if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_check_assistant_delete( WP_REST_Request $request ) {
		// Try main controller first for full functionality.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'permissions_check_assistant_delete' ) ) {
			return $this->main_controller->permissions_check_assistant_delete( $request );
		}

		// Fallback: Require admin capabilities.
		return $this->permissions_check_admin( $request );
	}

	/**
	 * Handle assistant deletion.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistant_delete( WP_REST_Request $request ) {
		// Delegate to main controller if available.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'handle_assistant_delete' ) ) {
			return $this->main_controller->handle_assistant_delete( $request );
		}

		// Self-contained fallback implementation.
		$assistant_id = absint( $request->get_param( 'id' ) );

		if ( ! $assistant_id ) {
			return $this->error(
				'wp_mcp_ai_missing_assistant_id',
				__( 'Assistant ID is required.', 'mcp-ai-wpoos' ),
				400
			);
		}

		// Check if assistant exists.
		$assistant = get_post( $assistant_id );
		if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type ) {
			return $this->error(
				'wp_mcp_ai_assistant_not_found',
				__( 'Assistant not found.', 'mcp-ai-wpoos' ),
				404
			);
		}

		// Delete the assistant.
		$result = wp_delete_post( $assistant_id, true );
		if ( ! $result ) {
			return $this->error(
				'wp_mcp_ai_delete_failed',
				__( 'Failed to delete assistant.', 'mcp-ai-wpoos' ),
				500
			);
		}

		return $this->success(
			array(
				'deleted' => true,
				'id'      => $assistant_id,
			)
		);
	}

	/**
	 * Handle assistant creation.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistant_create( WP_REST_Request $request ) {
		// Delegate to main controller if available.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'handle_assistant_create' ) ) {
			return $this->main_controller->handle_assistant_create( $request );
		}

		// Self-contained fallback implementation.
		$title = sanitize_text_field( $request->get_param( 'title' ) );

		if ( empty( $title ) ) {
			return $this->error(
				'wp_mcp_ai_missing_title',
				__( 'Assistant title is required.', 'mcp-ai-wpoos' ),
				400
			);
		}

		// Validate and sanitize post status.
		$allowed_statuses = array( 'draft', 'publish', 'private' );
		$status           = sanitize_key( $request->get_param( 'status' ) );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		// Create the assistant post.
		$post_data = array(
			'post_type'   => 'mcp_ai_assistant',
			'post_title'  => $title,
			'post_status' => $status,
		);

		$description = $request->get_param( 'description' );
		if ( ! empty( $description ) ) {
			$post_data['post_content'] = wp_kses_post( $description );
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $this->error(
				'wp_mcp_ai_create_failed',
				$post_id->get_error_message(),
				500
			);
		}

		// Save meta fields if provided with proper sanitization.
		$provider = $request->get_param( 'provider' );
		if ( null !== $provider ) {
			update_post_meta( $post_id, '_wp_mcp_ai_provider', sanitize_key( $provider ) );
		}

		$model = $request->get_param( 'model' );
		if ( null !== $model ) {
			update_post_meta( $post_id, '_wp_mcp_ai_model', sanitize_text_field( $model ) );
		}

		$temperature = $request->get_param( 'temperature' );
		if ( null !== $temperature ) {
			$temperature = floatval( $temperature );
			// Validate temperature is within acceptable range (0.0 to 2.0).
			$temperature = max( 0.0, min( 2.0, $temperature ) );
			update_post_meta( $post_id, '_wp_mcp_ai_temperature', $temperature );
		}

		$system_prompt = $request->get_param( 'system_prompt' );
		if ( null !== $system_prompt ) {
			update_post_meta( $post_id, '_wp_mcp_ai_system_prompt', wp_kses_post( $system_prompt ) );
		}

		$tools = $request->get_param( 'tools' );
		if ( null !== $tools && is_array( $tools ) ) {
			// Sanitize each tool slug.
			$tools = array_map( 'sanitize_key', $tools );
			update_post_meta( $post_id, '_wp_mcp_ai_tools', $tools );
		}

		return $this->success(
			array(
				'id'     => $post_id,
				'title'  => $title,
				'status' => get_post_status( $post_id ),
			),
			201
		);
	}

	/**
	 * Handle MCP protocol request (JSON-RPC 2.0).
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_mcp_request( WP_REST_Request $request ) {
		// Delegate to main controller's trait method if available.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'handle_mcp_request' ) ) {
			return $this->main_controller->handle_mcp_request( $request );
		}

		// Self-contained fallback: Return error indicating MCP not fully configured.
		return $this->error(
			'wp_mcp_ai_mcp_unavailable',
			__( 'MCP protocol handler is not available. Please ensure the plugin is properly configured.', 'mcp-ai-wpoos' ),
			503
		);
	}

	/**
	 * Handle MCP OPTIONS request for CORS.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public function handle_mcp_options( WP_REST_Request $request ) {
		// Delegate to main controller if available.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'handle_mcp_options' ) ) {
			return $this->main_controller->handle_mcp_options( $request );
		}

		// Self-contained fallback: Provide basic CORS headers.
		$allow_origin = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Access-Control-Allow-Origin', $allow_origin );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest, Accept, Mcp-Session-Id' );
		$response->header( 'Access-Control-Max-Age', '3600' );

		return $response;
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
		// Delegate to main controller if available.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'handle_sse_handshake' ) ) {
			return $this->main_controller->handle_sse_handshake( $request );
		}

		// Self-contained fallback: Return discovery info instead of SSE.
		return $this->return_discovery_info( $request );
	}

	/**
	 * Handle assistants index endpoint.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistants_index( WP_REST_Request $request ) {
		// Delegate to main controller if available.
		if ( null !== $this->main_controller && method_exists( $this->main_controller, 'handle_assistants_index' ) ) {
			return $this->main_controller->handle_assistants_index( $request );
		}

		// Self-contained fallback implementation.
		$assistants = array();

		// Query for published assistants.
		$query_args = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== $search ) {
			$query_args['s'] = sanitize_text_field( $search );
		}

		$query = new WP_Query( $query_args );

		foreach ( $query->posts as $post ) {
			$provider = get_post_meta( $post->ID, '_wp_mcp_ai_provider', true );
			$model    = get_post_meta( $post->ID, '_wp_mcp_ai_model', true );

			$assistants[] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'description' => wp_strip_all_tags( $post->post_content ),
				'provider'    => is_string( $provider ) ? $provider : '',
				'model'       => is_string( $model ) ? $model : '',
			);
		}

		return $this->success(
			array(
				'assistants'        => $assistants,
				'total'             => count( $assistants ),
				'default_assistant' => ! empty( $assistants ) ? $assistants[0]['id'] : 0,
				'rest'              => array(
					'namespace' => self::REST_NAMESPACE,
					'base'      => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
				),
			)
		);
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
			'name'            => 'NV oOS MCP Server',
			'version'         => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			'protocolVersion' => '2026-07-28',
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
					'note'     => 'MCP 2026-07-28 Streamable HTTP - GET for discovery (JSON), POST for JSON-RPC 2.0',
				),
				'sse'             => array(
					'endpoint' => rest_url( self::REST_NAMESPACE . '/sse' ),
					'methods'  => array( 'GET' ),
					'default'  => false,
					'note'     => 'Optional SSE streaming - use /sse endpoint or /mcp?stream=true',
				),
				'stdio'           => array(
					'command' => 'wp mcp-ai stdio',
					'args'    => array( '--path=/path/to/wordpress', '--assistant-id=<id>' ),
					'default' => false,
					'note'    => 'STDIO transport for local agent integration (Claude Desktop, etc.)',
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
				'discovery'     => 'GET /mcp (default - returns this discovery JSON for LM Studio, etc.)',
				'jsonrpc'       => 'POST /mcp (JSON-RPC 2.0 protocol for tool execution)',
				'sse_dedicated' => 'GET /sse (dedicated SSE endpoint)',
				'sse_opt_in'    => 'GET /mcp?stream=true (opt-in SSE on /mcp endpoint)',
				'stdio'         => 'wp mcp-ai stdio (local STDIO transport for Claude Desktop)',
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
