<?php
/**
 * Chat Controller for REST API
 *
 * Handles chat-related endpoints including MCP chat, browser chat,
 * and transcript management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Controller Class
 *
 * Manages all chat-related REST API endpoints with support for:
 * - MCP remote clients (/chat) with 5 iteration limit
 * - Browser clients (/chat-client) with 15 iteration limit
 * - Chat transcript management (/chat-transcripts)
 * - SSE streaming for real-time responses
 */
class WP_MCP_AI_REST_Chat_Controller extends WP_MCP_AI_REST_Controller_Base {
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
	 * Register chat routes.
	 *
	 * Registers all chat-related REST API endpoints:
	 * - POST /chat: MCP remote client chat with 5 iteration limit
	 * - GET /chat: SSE handshake for streaming chat responses
	 * - POST /chat-client: Browser client chat with 15 iteration limit
	 * - GET /chat-client: SSE handshake for browser chat streaming
	 * - GET /chat-transcripts: List chat transcripts for authenticated user
	 * - POST /chat-transcripts: Save a chat transcript to persistent storage
	 * - GET /chat-transcripts/{session_key}: Retrieve specific transcript by session key
	 * - DELETE /chat-transcripts/{session_key}: Delete a specific transcript
	 *
	 * All endpoints support multiple authentication methods:
	 * - WordPress REST nonce (same-origin requests)
	 * - Assistant-issued bearer tokens (scoped to specific assistant)
	 * - Auth0 bearer tokens (enterprise integrations)
	 * - Guest tokens (public chat surfaces with allow_guests enabled)
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// /chat - MCP remote client chat.
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_request' ),
					'args'                => $this->get_chat_endpoint_args(),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant to use for SSE handshake.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);

		// /chat-client - Browser client chat.
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-client',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_client_request' ),
					'args'                => $this->get_chat_endpoint_args(),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_chat_client_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant to use for SSE handshake.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);

		// /chat-transcripts - List all transcripts.
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-transcripts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_chat_transcripts' ),
					'permission_callback' => array( $this, 'chat_transcripts_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_chat_transcript_save' ),
					'permission_callback' => array( $this, 'chat_transcripts_permissions_check' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant for this chat transcript.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'session_key'  => array(
							'description'       => __( 'Session key for this conversation.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'messages'     => array(
							'description'       => __( 'Array of conversation messages.', 'wp-mcp-ai' ),
							'type'              => 'array',
							'required'          => true,
							'validate_callback' => array( $this->validator, 'validate_messages_array' ),
							'items'             => $this->get_message_item_schema(),
						),
					),
				),
			)
		);

		// /chat-transcripts/{session_key} - Individual transcript operations.
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-transcripts/(?P<session_key>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_chat_transcript_get' ),
					'permission_callback' => array( $this, 'chat_transcripts_permissions_check' ),
					'args'                => array(
						'session_key'  => array(
							'description'       => __( 'Session key for the transcript.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this->validator, 'sanitize_session_key_param' ),
						),
						'user_id'      => array(
							'description'       => __( 'User ID to filter transcripts by. Defaults to current user.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'assistant_id' => array(
							'description'       => __( 'Assistant ID to filter transcripts by.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_chat_transcript_delete' ),
					'permission_callback' => array( $this, 'chat_transcripts_permissions_check' ),
					'args'                => array(
						'session_key' => array(
							'description'       => __( 'Session key for the transcript.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this->validator, 'sanitize_session_key_param' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Get chat endpoint arguments.
	 *
	 * @return array Endpoint arguments.
	 */
	private function get_chat_endpoint_args() {
		return array(
			'assistant_id' => array(
				'description'       => __( 'ID of the assistant to use for this chat. Defaults to the site default assistant.', 'wp-mcp-ai' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'messages'     => array(
				'description'       => __( 'Array of message objects with role and content.', 'wp-mcp-ai' ),
				'type'              => 'array',
				'required'          => true,
				'validate_callback' => array( $this->validator, 'validate_messages_array' ),
				'items'             => $this->get_message_item_schema(),
			),
			'attachments'  => array(
				'description'       => __( 'Optional array of file attachments to include with the request.', 'wp-mcp-ai' ),
				'type'              => 'array',
				'required'          => false,
				'validate_callback' => array( $this->validator, 'validate_attachments_array' ),
				'items'             => array(
					'type'       => 'object',
					'properties' => array(
						'file_id' => array(
							'type' => 'integer',
						),
						'url'     => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
			),
			'options'      => array(
				'description' => __( 'Optional request options to override assistant defaults.', 'wp-mcp-ai' ),
				'type'        => 'object',
				'required'    => false,
				'properties'  => array(
					'model'           => array(
						'type' => 'string',
					),
					'temperature'     => array(
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 2,
					),
					'stream'          => array(
						'type' => 'boolean',
					),
					'response_format' => array(
						'description' => __( 'Response format configuration (e.g., for JSON mode).', 'wp-mcp-ai' ),
						'type'        => 'object',
						'properties'  => array(
							'type'        => array(
								'type' => 'string',
								'enum' => array( 'text', 'json_object', 'json_schema' ),
							),
							'json_schema' => array(
								'type' => 'object',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get message item schema for REST API endpoints.
	 *
	 * Defines the complete OpenAI-compatible message schema including:
	 * - role (required): Message role (system, user, assistant, tool)
	 * - content: Message content (string, array of content parts, or null)
	 * - tool_calls: Array of tool calls for assistant messages
	 * - tool_call_id: Tool call identifier for tool messages
	 * - name: Optional name field for tool messages
	 *
	 * @return array Message item schema definition.
	 */
	private function get_message_item_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'role'         => array(
					'type' => 'string',
					'enum' => array( 'system', 'user', 'assistant', 'tool' ),
				),
				'content'      => array(
					'description' => __( 'Message content. Can be a string, array of content parts, or null for assistant messages with tool_calls.', 'wp-mcp-ai' ),
					'oneOf'       => array(
						array( 'type' => 'string' ),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
						array( 'type' => 'null' ),
					),
				),
				'tool_calls'   => array(
					'description' => __( 'Tool calls made by the assistant. Only valid for assistant role messages.', 'wp-mcp-ai' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array(
								'type'        => 'string',
								'description' => __( 'Unique identifier for the tool call.', 'wp-mcp-ai' ),
							),
							'type'     => array(
								'type' => 'string',
								'enum' => array( 'function' ),
							),
							'function' => array(
								'type'       => 'object',
								'properties' => array(
									'name'      => array(
										'type'        => 'string',
										'description' => __( 'The name of the function to call.', 'wp-mcp-ai' ),
									),
									'arguments' => array(
										'type'        => 'string',
										'description' => __( 'JSON string of arguments to pass to the function.', 'wp-mcp-ai' ),
									),
								),
							),
						),
					),
				),
				'tool_call_id' => array(
					'description' => __( 'Tool call identifier. Required for tool role messages to match with assistant tool_calls.', 'wp-mcp-ai' ),
					'type'        => 'string',
				),
				'name'         => array(
					'description' => __( 'Optional name field for tool messages.', 'wp-mcp-ai' ),
					'type'        => 'string',
				),
			),
		);
	}

	/**
	 * Permission check for chat transcripts endpoints.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	public function chat_transcripts_permissions_check( WP_REST_Request $request ) {
		// Delegate to main controller's permission check.
		if ( $this->main_controller ) {
			return $this->main_controller->chat_transcripts_permissions_check( $request );
		}
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Permission check for chat endpoints.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	public function permissions_check( WP_REST_Request $request ) {
		// Delegate to main controller's permission check.
		if ( $this->main_controller ) {
			return $this->main_controller->permissions_check( $request );
		}
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Handle /chat request (MCP remote clients).
	 *
	 * Delegates to main REST controller for now.
	 * Will be extracted in implementation phase.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_request( WP_REST_Request $request ) {
		// Delegate to main controller.
		if ( $this->main_controller ) {
			return $this->main_controller->handle_chat_request( $request );
		}

		// Log error if main controller is missing.
		WP_MCP_AI_Logger::log_event(
			'error',
			'Chat Controller: main_controller is null',
			array(
				'route'   => $request->get_route(),
				'method'  => $request->get_method(),
				'context' => 'handle_chat_request',
			)
		);

		return $this->error( 'not_implemented', __( 'Chat endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}

	/**
	 * Handle /chat-client request (browser clients).
	 *
	 * This endpoint is specifically designed for browser chat interfaces
	 * and applies relaxed iteration limits (15) compared to the MCP protocol endpoint (1).
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_client_request( WP_REST_Request $request ) {
		// Set higher max_iterations for browser chat UI (allows more complex multi-tool workflows).
		add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_chat_client_max_iterations' ), 10, 2 );

		// Delegate to the chat handler (which delegates to main controller for now).
		$response = $this->handle_chat_request( $request );

		// Remove filter to avoid affecting other requests.
		remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_chat_client_max_iterations' ), 10 );

		return $response;
	}

	/**
	 * Get maximum agentic loop iterations for chat client requests.
	 *
	 * Browser-based chat UI gets higher limits than MCP protocol clients.
	 *
	 * Priority order:
	 * 1. Per-assistant config (highest priority)
	 * 2. Admin setting (filter_max_agentic_iterations)
	 * 3. Chat client default (15 iterations)
	 *
	 * @param int   $default_max      Default max iterations (may include admin setting if applied).
	 * @param array $assistant_config Assistant configuration.
	 * @return int Maximum iterations allowed.
	 */
	public function get_chat_client_max_iterations( $default_max, $assistant_config = array() ) {
		// Allow per-assistant override.
		if ( ! empty( $assistant_config['max_agentic_iterations'] ) ) {
			return absint( $assistant_config['max_agentic_iterations'] );
		}

		// If admin setting was applied by custom filters applicator (priority 5),
		// it will be in $default_max. Only use chat client default if $default_max
		// is still the base default (5 for /chat endpoint).
		// This allows admin setting to override the chat client default.
		if ( $default_max > 5 ) {
			// Admin setting or another filter has already increased the limit.
			return $default_max;
		}

		// Chat client default: 15 iterations (vs 5 for MCP protocol).
		return 15;
	}

	/**
	 * Handle list transcripts request.
	 *
	 * Retrieves all chat transcripts for the current user.
	 * Supports pagination and filtering by session_key or assistant_id.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcripts( WP_REST_Request $request ) {
		// Defensive check for main controller.
		if ( ! $this->main_controller ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'Chat Controller: main_controller is null in handle_chat_transcripts',
				array(
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_internal_error',
				__( 'Internal server error. Please try again later.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// The permissions check has already validated and set the user_id on the request.
		// For CCT queries, this user_id parameter directly maps to the user_id field in the database.
		// We must preserve user_id = 0 for guest users and not override with get_current_user_id().
		// This allows admins to view other users' transcripts or guest transcripts.
		// Note: user_id is already sanitized by REST API via absint() sanitize_callback.
		$user_id = $request->get_param( 'user_id' );
		if ( null === $user_id ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'handle_chat_transcripts: No user ID available',
				array(
					'requested_user_id' => $request->get_param( 'user_id' ),
					'current_user_id'   => get_current_user_id(),
					'is_user_logged_in' => is_user_logged_in(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_user',
				__( 'A valid user is required to query chat transcripts. Please log in to view your chat history.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$session_key  = $this->main_controller->normalise_transcript_session_key( $request->get_param( 'session_key' ) );
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );

		WP_MCP_AI_Logger::log_event(
			'debug',
			'handle_chat_transcripts: Request parameters',
			array(
				'raw_session_key'        => $request->get_param( 'session_key' ),
				'normalized_session_key' => $session_key,
				'user_id'                => $user_id,
				'assistant_id'           => $assistant_id,
			)
		);

		if ( '' !== $session_key ) {
			// Retrieve session by session_key only (gets all messages regardless of user_id).
			// Pass 0 as user_id since it's no longer used in the query.
			$session = $this->main_controller->get_transcript_session( 0, $session_key, $assistant_id );

			if ( is_wp_error( $session ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'handle_chat_transcripts: Error retrieving session',
					array(
						'error_code'    => $session->get_error_code(),
						'error_message' => $session->get_error_message(),
						'session_key'   => $session_key,
					)
				);

				// Handle gracefully for unavailable transcript storage (JetEngine not active)
				if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
					return rest_ensure_response(
						array(
							'session' => null,
							'message' => $session->get_error_message(),
						)
					);
				}

				// Return error directly for missing transcripts (will be 404)
				return $session;
			}

			// Authorization check: Verify user can view this session.
			$auth_check = $this->verify_session_access( $session, $user_id );
			if ( is_wp_error( $auth_check ) ) {
				WP_MCP_AI_Logger::log_event(
					'warning',
					'handle_chat_transcripts: Unauthorized access attempt',
					array(
						'session_key' => $session_key,
						'user_id'     => $user_id,
					)
				);

				return $auth_check;
			}

			return rest_ensure_response( array( 'session' => $session ) );
		}

		$per_page = (int) $request->get_param( 'per_page' );

		if ( $per_page <= 0 ) {
			$per_page = 20;
		}

		$per_page = min( 100, max( 1, $per_page ) );

		$page = (int) $request->get_param( 'page' );

		if ( $page <= 0 ) {
			$page = 1;
		}

		$sessions = $this->main_controller->get_transcript_sessions( $user_id, $per_page, $page, $assistant_id );

		if ( is_wp_error( $sessions ) ) {
			if ( 'wp_mcp_ai_transcripts_unavailable' === $sessions->get_error_code() ) {
				return rest_ensure_response(
					array(
						'sessions' => array(),
						'total'    => 0,
						'per_page' => $per_page,
						'page'     => $page,
						'message'  => $sessions->get_error_message(),
					)
				);
			}

			return $sessions;
		}

		return rest_ensure_response(
			array(
				'sessions' => isset( $sessions['items'] ) ? $sessions['items'] : array(),
				'total'    => isset( $sessions['total'] ) ? (int) $sessions['total'] : 0,
				'per_page' => $per_page,
				'page'     => $page,
			)
		);
	}

	/**
	 * Save a chat transcript explicitly without requiring a chat response.
	 *
	 * This endpoint allows the frontend to persist a conversation to CCT
	 * before clearing it (e.g., when starting a new chat or switching conversations).
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_save( WP_REST_Request $request ) {
		// Defensive check for main controller.
		if ( ! $this->main_controller ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'Chat Controller: main_controller is null in handle_chat_transcript_save',
				array(
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_internal_error',
				__( 'Internal server error. Please try again later.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$this->main_controller->hydrate_request_body_params( $request );

		$assistant_id = absint( $request->get_param( 'assistant_id' ) );
		$session_key  = $this->validator->sanitize_session_key_param( $request->get_param( 'session_key' ) );
		$messages     = $request->get_param( 'messages' );

		// Debug logging: Log incoming save request.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			$user_id     = get_current_user_id();
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'N/A';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[WP oOS Debug] POST /chat-transcripts: session_key=%s assistant_id=%d user_id=%d message_count=%d url=%s',
					$session_key,
					$assistant_id,
					$user_id,
					is_array( $messages ) ? count( $messages ) : 0,
					$request_uri
				)
			);
		}

		if ( ! $assistant_id ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_assistant',
				__( 'Assistant ID is required to save a transcript.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_session',
				__( 'Session key is required to save a transcript.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $messages ) || ! is_array( $messages ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_messages',
				__( 'Messages array is required to save a transcript.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Validate assistant access.
		$assistant_post = $this->main_controller->validate_assistant_access( $assistant_id );
		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		// Sanitize messages.
		$sanitized_messages = $this->validator->sanitize_messages( $messages );
		if ( is_wp_error( $sanitized_messages ) ) {
			return $sanitized_messages;
		}

		$clean_messages = $sanitized_messages['messages'];

		if ( empty( $clean_messages ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_invalid_messages',
				__( 'No valid messages to save.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get user ID.
		$user_id = get_current_user_id();

		// Guest users (authenticated via guest token) can save transcripts with user_id = 0.
		// The permission check already validated the guest token if present.

		WP_MCP_AI_Logger::log_event(
			'debug',
			'handle_chat_transcript_save: Saving transcript',
			array(
				'session_key'   => $session_key,
				'assistant_id'  => $assistant_id,
				'user_id'       => $user_id,
				'message_count' => count( $clean_messages ),
				'source'        => 'chat_client',
			)
		);

		// Get assistant configuration for metadata.
		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$model            = isset( $assistant_config['model'] ) ? sanitize_text_field( $assistant_config['model'] ) : 'unknown-model';

		// Build a minimal response payload for the recorder.
		// Since this is just saving a conversation without a new response,
		// we create a synthetic response payload.
		$response = array(
			'model'   => $model,
			'choices' => array(),
		);

		// Build context for the transcript recorder.
		$context = array(
			'session_key'           => $session_key,
			'save_transcript'       => true,
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// Use the transcript recorder to save.
		$recorded_session_key = null;
		if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
				$assistant_id,
				$clean_messages,
				array( 'model' => $model ),
				$response,
				$request,
				$user_id,
				$context
			);
		}

		// Check if recording failed.
		if ( null === $recorded_session_key ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'handle_chat_transcript_save: Failed to save transcript',
				array(
					'session_key'   => $session_key,
					'assistant_id'  => $assistant_id,
					'user_id'       => $user_id,
					'message_count' => count( $clean_messages ),
					'reason'        => 'Recorder returned null',
				)
			);

			// Debug logging: Log save failure.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP oOS Debug] POST /chat-transcripts FAILED: session_key=%s assistant_id=%d user_id=%d saved=0 response=500',
						$session_key,
						$assistant_id,
						get_current_user_id()
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_transcript_save_failed',
				__( 'Failed to save transcript. Please ensure JetEngine Custom Content Types is active and properly configured.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'info',
			'handle_chat_transcript_save: Transcript saved successfully',
			array(
				'session_key'          => $session_key,
				'recorded_session_key' => $recorded_session_key,
				'keys_match'           => $session_key === $recorded_session_key,
				'assistant_id'         => $assistant_id,
				'user_id'              => $user_id,
				'message_count'        => count( $clean_messages ),
			)
		);

		// Debug logging: Log successful save.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[WP oOS Debug] POST /chat-transcripts SUCCESS: session_key=%s assistant_id=%d user_id=%d saved=1 response=200',
					$recorded_session_key,
					$assistant_id,
					get_current_user_id()
				)
			);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'session_key' => $recorded_session_key,
				'message'     => __( 'Transcript saved successfully.', 'wp-mcp-ai' ),
			)
		);
	}

	/**
	 * Handle retrieval of a specific chat transcript session by session key.
	 *
	 * This endpoint provides RESTful access to a specific transcript using the
	 * session key in the URL path (e.g., /chat-transcripts/{session_key}).
	 *
	 * Retrieves ALL messages in the session regardless of user_id, then validates
	 * authorization based on session ownership.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_get( WP_REST_Request $request ) {
		// Defensive check for main controller.
		if ( ! $this->main_controller ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'Chat Controller: main_controller is null in handle_chat_transcript_get',
				array(
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_internal_error',
				__( 'Internal server error. Please try again later.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$session_key  = $this->main_controller->normalise_transcript_session_key( $request->get_param( 'session_key' ) );
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );

		// Debug logging: Log incoming GET request.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			$user_id     = get_current_user_id();
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'N/A';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[WP oOS Debug] GET /chat-transcripts/{session_key}: session_key=%s assistant_id=%d user_id=%d url=%s',
					$session_key,
					$assistant_id,
					$user_id,
					$request_uri
				)
			);
		}

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_invalid_session',
				__( 'A valid session key is required to retrieve a transcript.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get requesting user from permission check.
		$requesting_user_id = $request->get_param( 'user_id' );
		$current_user_id    = get_current_user_id();

		WP_MCP_AI_Logger::log_event(
			'debug',
			'handle_chat_transcript_get: Request parameters',
			array(
				'session_key'        => $session_key,
				'requesting_user_id' => $requesting_user_id,
				'current_user_id'    => $current_user_id,
				'assistant_id'       => $assistant_id,
			)
		);

		// Retrieve the session - this now gets ALL messages by session_key.
		// Pass 0 as user_id since it's no longer used in the query.
		$session = $this->main_controller->get_transcript_session( 0, $session_key, $assistant_id );

		if ( is_wp_error( $session ) ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'handle_chat_transcript_get: Error retrieving session',
				array(
					'error_code'    => $session->get_error_code(),
					'error_message' => $session->get_error_message(),
					'session_key'   => $session_key,
				)
			);

			// Debug logging: Log session not found.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
				$response_code = 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ? 200 : 404;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP oOS Debug] GET /chat-transcripts/{session_key} ERROR: session_key=%s found_session=0 found_messages=0 response=%d error_code=%s',
						$session_key,
						$response_code,
						$session->get_error_code()
					)
				);
			}

			// Handle gracefully for unavailable transcript storage (JetEngine not active)
			if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
				return rest_ensure_response(
					array(
						'session' => null,
						'message' => $session->get_error_message(),
					)
				);
			}

			// Return error directly for missing transcripts (will be 404)
			return $session;
		}

		// Authorization check: Verify user can view this session.
		$auth_check = $this->verify_session_access( $session, $requesting_user_id );
		if ( is_wp_error( $auth_check ) ) {
			WP_MCP_AI_Logger::log_event(
				'warning',
				'handle_chat_transcript_get: Unauthorized access attempt',
				array(
					'session_key'        => $session_key,
					'requesting_user_id' => $requesting_user_id,
				)
			);

			// Debug logging: Log unauthorized access.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[WP oOS Debug] GET /chat-transcripts/{session_key} UNAUTHORIZED: session_key=%s found_session=1 found_messages=%d response=403',
						$session_key,
						isset( $session['messages'] ) ? count( $session['messages'] ) : 0
					)
				);
			}

			return $auth_check;
		}

		// Debug logging: Log successful retrieval.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			$message_count = isset( $session['messages'] ) ? count( $session['messages'] ) : 0;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[WP oOS Debug] GET /chat-transcripts/{session_key} SUCCESS: session_key=%s found_session=1 found_messages=%d response=200',
					$session_key,
					$message_count
				)
			);
		}

		return rest_ensure_response( array( 'session' => $session ) );
	}

	/**
	 * Handle deletion of a chat transcript session.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_delete( WP_REST_Request $request ) {
		// Defensive check for main controller.
		if ( ! $this->main_controller ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'Chat Controller: main_controller is null in handle_chat_transcript_delete',
				array(
					'route'  => $request->get_route(),
					'method' => $request->get_method(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_internal_error',
				__( 'Internal server error. Please try again later.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$session_key = $this->main_controller->normalise_transcript_session_key( $request->get_param( 'session_key' ) );

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_invalid_session',
				__( 'A valid session key is required to delete a transcript.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_user',
				__( 'You must be logged in to delete a transcript.', 'wp-mcp-ai' ),
				array( 'status' => 401 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'debug',
			'handle_chat_transcript_delete: Deleting transcript',
			array(
				'session_key' => $session_key,
				'user_id'     => $user_id,
				'source'      => 'chat_client',
			)
		);

		$repository = $this->main_controller->get_transcript_repository();
		$table      = $repository->get_table_name();

		if ( '' === $table ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'Chat transcripts are not configured or available.', 'wp-mcp-ai' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $repository->table_exists() ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'The transcript storage table does not exist.', 'wp-mcp-ai' ),
				array( 'status' => 503 )
			);
		}

		// Delete all transcript entries for this session and user.
		$deleted = $repository->delete_transcript( $session_key, $user_id );

		if ( false === $deleted ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'handle_chat_transcript_delete: Failed to delete transcript',
				array(
					'session_key' => $session_key,
					'user_id'     => $user_id,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_transcripts_delete_failed',
				__( 'Failed to delete the transcript.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'info',
			'handle_chat_transcript_delete: Transcript deleted successfully',
			array(
				'session_key'  => $session_key,
				'user_id'      => $user_id,
				'deleted_rows' => $deleted,
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'deleted' => $deleted,
				'message' => __( 'Transcript deleted successfully.', 'wp-mcp-ai' ),
			)
		);
	}

	/**
	 * Verify if a user is authorized to view a transcript session.
	 *
	 * This method enforces authorization by checking if:
	 * - The user is an admin (can view any session), OR
	 * - The user owns at least one message in the session
	 *
	 * This follows SOC by separating authorization logic from data retrieval.
	 *
	 * @param array $session           The session data (array of messages).
	 * @param int   $requesting_user_id The user requesting access.
	 * @return true|WP_Error True if authorized, WP_Error otherwise.
	 */
	protected function verify_session_access( $session, $requesting_user_id ) {
		// Admins can view any session.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Non-admin users can only view sessions that contain at least one message they own.
		// Extract unique user_ids from the session messages.
		$session_user_ids = array();
		if ( is_array( $session ) ) {
			foreach ( $session as $message ) {
				if ( isset( $message['user_id'] ) ) {
					$session_user_ids[] = absint( $message['user_id'] );
				}
			}
		}
		$session_user_ids = array_unique( $session_user_ids );

		// Check if the requesting user owns any message in this session.
		if ( ! in_array( $requesting_user_id, $session_user_ids, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_forbidden',
				__( 'You do not have permission to view this transcript.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
