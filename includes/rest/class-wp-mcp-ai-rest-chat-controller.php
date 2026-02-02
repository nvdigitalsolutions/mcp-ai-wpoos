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
	 * Allowed response metadata fields for manual transcript saves.
	 *
	 * These fields can be included in the response_metadata parameter when
	 * manually saving a conversation. Only whitelisted fields are accepted
	 * to prevent injection of arbitrary data.
	 *
	 * @var array
	 */
	const ALLOWED_RESPONSE_METADATA_FIELDS = array(
		'usage',
		'provider',
		'id',
		'object',
		'created',
		'service_tier',
		'system_fingerprint',
	);

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

		// Hook into SSE job status events to stream them to chat clients.
		add_action( 'wp_mcp_ai_emit_sse_event', array( $this, 'handle_sse_job_event' ), 10, 2 );
	}

	/**
	 * Get the main REST controller with fallback to global scope.
	 *
	 * This method provides a defensive fallback for scenarios where the main controller
	 * reference may be lost due to caching, opcache, or other environmental issues.
	 *
	 * @return WP_MCP_AI_REST|null Main REST controller or null if unavailable.
	 */
	private function get_main_controller() {
		// Return the stored reference if available.
		if ( null !== $this->main_controller ) {
			return $this->main_controller;
		}

		// Fallback: Try to get from global scope.
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) && $GLOBALS['wp_mcp_ai_rest_controller'] instanceof WP_MCP_AI_REST ) {
			// Cache the reference for future use.
			$this->main_controller = $GLOBALS['wp_mcp_ai_rest_controller'];

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Chat Controller: Retrieved main_controller from global scope',
					array(
						'context' => 'get_main_controller_fallback',
					)
				);
			}

			return $this->main_controller;
		}

		return null;
	}

	/**
	 * Handle SSE job event and stream to chat clients.
	 *
	 * This method is called when a job status update occurs and we're in an SSE context.
	 * It streams the event to connected chat clients.
	 *
	 * @param string $event_name Event name (e.g., 'cron_job_status_update', 'crawl4ai_job_status_update').
	 * @param array  $event_data Event data to stream.
	 */
	public function handle_sse_job_event( $event_name, $event_data ) {
		// Only emit if we have an SSE handler available.
		if ( ! class_exists( 'WP_MCP_AI_SSE_Handler' ) ) {
			return;
		}

		// Get SSE handler instance.
		$main_controller = $this->get_main_controller();
		if ( null === $main_controller || ! method_exists( $main_controller, 'get_sse_handler' ) ) {
			return;
		}

		$sse_handler = $main_controller->get_sse_handler();
		if ( ! $sse_handler || ! method_exists( $sse_handler, 'send_sse_event' ) ) {
			return;
		}

		// Stream the event.
		$sse_handler->send_sse_event( $event_name, $event_data );
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
							'description'       => __( 'ID of the assistant to use for SSE handshake.', 'mcp-ai-wpoos' ),
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
							'description'       => __( 'ID of the assistant to use for SSE handshake.', 'mcp-ai-wpoos' ),
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
						'assistant_id'      => array(
							'description' => __( 'ID of the assistant for this chat transcript. Can be an integer assistant ID or a string like "unified_team_123" or "team_123_member_456".', 'mcp-ai-wpoos' ),
							'type'        => array( 'integer', 'string' ),
							'required'    => true,
						),
						'session_key'       => array(
							'description'       => __( 'Session key for this conversation.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'messages'          => array(
							'description'       => __( 'Array of conversation messages.', 'mcp-ai-wpoos' ),
							'type'              => 'array',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_messages_array_wrapper' ),
						),
						'response_metadata' => array(
							'description' => __( 'Optional response metadata to preserve (usage data, provider info, etc.). If provided, this will be merged into the response payload and metadata fields.', 'mcp-ai-wpoos' ),
							'type'        => 'object',
							'required'    => false,
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
							'description'       => __( 'Session key for the transcript.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_session_key_wrapper' ),
						),
						'user_id'      => array(
							'description'       => __( 'User ID to filter transcripts by. Defaults to current user.', 'mcp-ai-wpoos' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'assistant_id' => array(
							'description'       => __( 'Assistant ID to filter transcripts by. Can be an integer or string like "unified_team_123" or "team_123_member_456".', 'mcp-ai-wpoos' ),
							'type'              => array( 'integer', 'string' ),
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_chat_transcript_delete' ),
					'permission_callback' => array( $this, 'chat_transcripts_permissions_check' ),
					'args'                => array(
						'session_key' => array(
							'description'       => __( 'Session key for the transcript.', 'mcp-ai-wpoos' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_session_key_wrapper' ),
						),
					),
				),
			)
		);

		// /track-embedded-usage - Track usage from embedded LLM (client-side).
		register_rest_route(
			self::REST_NAMESPACE,
			'/track-embedded-usage',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'permissions_check' ),
				'callback'            => array( $this, 'handle_track_embedded_usage' ),
				'args'                => array(
					'assistant_id'  => array(
						'description'       => __( 'ID of the assistant that generated the response.', 'mcp-ai-wpoos' ),
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'model'         => array(
						'description'       => __( 'Model identifier used for generation.', 'mcp-ai-wpoos' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'usage'         => array(
						'description' => __( 'Usage statistics object.', 'mcp-ai-wpoos' ),
						'type'        => 'object',
						'required'    => true,
						'properties'  => array(
							'prompt_tokens'     => array(
								'type' => 'integer',
							),
							'completion_tokens' => array(
								'type' => 'integer',
							),
							'total_tokens'      => array(
								'type' => 'integer',
							),
						),
					),
					'finish_reason' => array(
						'description'       => __( 'Why generation stopped.', 'mcp-ai-wpoos' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
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
			'assistant_id'        => array(
				'description'       => __( 'ID of the assistant to use for this chat. Can be an integer assistant ID or a string like "profession_123" for profession testing. Defaults to the site default assistant.', 'mcp-ai-wpoos' ),
				'type'              => array( 'integer', 'string' ),
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'messages'            => array(
				'description'       => __( 'Array of message objects with role and content.', 'mcp-ai-wpoos' ),
				'type'              => 'array',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_messages_array_wrapper' ),
			),
			'attachments'         => array(
				'description'       => __( 'Optional array of file attachments to include with the request.', 'mcp-ai-wpoos' ),
				'type'              => 'array',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_attachments_array_wrapper' ),
			),
			'options'             => array(
				'description' => __( 'Optional request options to override assistant defaults.', 'mcp-ai-wpoos' ),
				'type'        => 'object',
				'required'    => false,
				'properties'  => array(
					'provider'        => array(
						'type' => 'string',
					),
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
						'description' => __( 'Response format configuration (e.g., for JSON mode).', 'mcp-ai-wpoos' ),
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
			'professional_prompt' => array(
				'description'       => __( 'Optional professional role prompt to prepend to the system prompt. Used when a professional is dynamically selected via professional selector.', 'mcp-ai-wpoos' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Get or initialize the validator instance.
	 *
	 * This method ensures the validator is available at runtime.
	 * The validator should always be initialized via the parent constructor,
	 * but this provides a defensive fallback for edge cases where the
	 * validator might not be set (e.g., during unit tests or if the
	 * constructor chain is broken).
	 *
	 * @return WP_MCP_AI_REST_Validator The validator instance.
	 */
	private function get_validator() {
		// Return existing validator if available.
		if ( $this->validator ) {
			return $this->validator;
		}

		// Try to get from container.
		$container = wp_mcp_ai_container();
		if ( $container ) {
			$this->validator = $container->get( 'rest.validator' );
		}

		// Final fallback: create new instance.
		if ( ! $this->validator ) {
			$this->validator = new WP_MCP_AI_REST_Validator();
		}

		return $this->validator;
	}

	/**
	 * Wrapper for messages array validation.
	 *
	 * This wrapper ensures validation happens through a consistent method
	 * that can handle edge cases where the validator might not be initialized.
	 *
	 * @param mixed           $value   The value to validate.
	 * @param WP_REST_Request $request The REST request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_messages_array_wrapper( $value, $request, $param ) {
		return $this->get_validator()->validate_messages_array( $value, $request, $param );
	}

	/**
	 * Wrapper for attachments array validation.
	 *
	 * This wrapper ensures validation happens through a consistent method
	 * that can handle edge cases where the validator might not be initialized.
	 *
	 * @param mixed           $value   The value to validate.
	 * @param WP_REST_Request $request The REST request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_attachments_array_wrapper( $value, $request, $param ) {
		return $this->get_validator()->validate_attachments_array( $value, $request, $param );
	}

	/**
	 * Wrapper for session key sanitization.
	 *
	 * This wrapper ensures sanitization happens through a consistent method
	 * that can handle edge cases where the validator might not be initialized.
	 *
	 * @param string $value The session key to sanitize.
	 * @return string Sanitized session key.
	 */
	public function sanitize_session_key_wrapper( $value ) {
		return $this->get_validator()->sanitize_session_key_param( $value );
	}

	/**
	 * Permission check for chat transcripts endpoints.
	 *
	 * Supports multiple authentication methods:
	 * - WordPress nonce (for same-origin requests)
	 * - Bearer token (for API access)
	 * - Guest token (for public chat surfaces)
	 *
	 * Falls back to base class authentication if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	public function chat_transcripts_permissions_check( WP_REST_Request $request ) {
		$main_controller = $this->get_main_controller();

		// Try main controller first for full functionality.
		if ( null !== $main_controller && method_exists( $main_controller, 'chat_transcripts_permissions_check' ) ) {
			return $main_controller->chat_transcripts_permissions_check( $request );
		}

		// Fallback: Use base class authentication.
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Permission check for chat endpoints.
	 *
	 * Supports multiple authentication methods:
	 * - WordPress nonce (for same-origin requests)
	 * - Bearer token (for API access)
	 * - Guest token (for public chat surfaces)
	 *
	 * Falls back to base class authentication if main controller is unavailable.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$main_controller = $this->get_main_controller();

		// Try main controller first for full functionality.
		if ( null !== $main_controller && method_exists( $main_controller, 'permissions_check' ) ) {
			return $main_controller->permissions_check( $request );
		}

		// Fallback: Use base class authentication.
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Handle /chat request (MCP remote clients).
	 *
	 * Delegates to main REST controller for AI chat functionality.
	 * Returns 503 Service Unavailable when main controller is not available,
	 * as chat requires AI model integration that cannot be self-contained.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_request( WP_REST_Request $request ) {
		$main_controller = $this->get_main_controller();

		// Delegate to main controller if available.
		if ( null !== $main_controller && method_exists( $main_controller, 'handle_chat_request' ) ) {
			return $main_controller->handle_chat_request( $request );
		}

		// Self-contained fallback: Chat requires AI model integration.
		// Return 503 to indicate service is temporarily unavailable.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'Chat Controller: main_controller is null',
				array(
					'route'   => $request->get_route(),
					'method'  => $request->get_method(),
					'context' => 'handle_chat_request',
				)
			);
		}

		return $this->error(
			'wp_mcp_ai_chat_unavailable',
			__( 'Chat service is not available. Please ensure the plugin is properly configured.', 'mcp-ai-wpoos' ),
			503
		);
	}

	/**
	 * Handle /chat-client request (browser clients).
	 *
	 * This endpoint is specifically designed for browser chat interfaces
	 * and applies relaxed iteration limits (15) compared to the MCP protocol endpoint (1).
	 *
	 * For Cloudflare Workers AI, defaults to tool_choice="auto" to allow the model to
	 * intelligently decide when tools are appropriate based on user intent. The system
	 * prompt and conversation context guide appropriate tool usage.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_client_request( WP_REST_Request $request ) {
		// Defensive check: If main_controller is not available, return error immediately
		// without setting up filters to avoid secondary errors during filter execution.
		if ( null === $this->main_controller ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'error',
					'Chat Controller: main_controller is null in handle_chat_client_request',
					array(
						'route'   => $request->get_route(),
						'method'  => $request->get_method(),
						'context' => 'handle_chat_client_request',
					)
				);
			}

			return $this->error(
				'wp_mcp_ai_chat_unavailable',
				__( 'Chat service is not available. Please ensure the plugin is properly configured.', 'mcp-ai-wpoos' ),
				503
			);
		}

		// Set higher max_iterations for browser chat UI (allows more complex multi-tool workflows).
		add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_chat_client_max_iterations' ), 10, 2 );

		// Set default tool_choice for chat-client to prevent auto-triggering tools.
		add_filter( 'wp_mcp_ai_chat_options', array( $this, 'set_chat_client_tool_choice_default' ), 5, 3 );

		// Delegate to the chat handler (which delegates to main controller for now).
		$response = $this->handle_chat_request( $request );

		// Remove filters to avoid affecting other requests.
		remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_chat_client_max_iterations' ), 10 );
		remove_filter( 'wp_mcp_ai_chat_options', array( $this, 'set_chat_client_tool_choice_default' ), 5 );

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

		// If admin setting was applied by custom filters applicator (priority 5),.

		// it will be in $default_max. Only use chat client default if $default_max.

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
	 * Set default tool_choice for chat-client requests.
	 *
	 * For Cloudflare Workers AI, defaults to "auto" to allow the model to intelligently
	 * decide when to use tools based on the user's request. The model will only trigger
	 * tools when appropriate based on the conversation context.
	 *
	 * User can still override by passing tool_choice in request options.
	 *
	 * @param array $options          Chat options.
	 * @param array $assistant_config Assistant configuration.
	 * @param array $request_params   Request parameters.
	 * @return array Modified options.
	 */
	public function set_chat_client_tool_choice_default( $options, $assistant_config, $request_params ) {
		// Defensive check: Ensure $options is an array.
		if ( ! is_array( $options ) ) {
			return $options;
		}

		// Only apply default if:
		// 1. Provider is Cloudflare
		// 2. tool_choice is not already set by user
		// 3. tools are present.
		$provider = isset( $assistant_config['provider'] ) ? $assistant_config['provider'] : '';

		if ( 'cloudflare' === $provider && ! isset( $options['tool_choice'] ) && ! empty( $options['tools'] ) ) {
			// Default to "auto" for chat-client to let model decide when tools are needed.
			$options['tool_choice'] = 'auto';

			// Only log if logger class is available.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'chat_client_tool_choice_default',
					'Set default tool_choice="auto" for Cloudflare chat-client',
					array(
						'assistant_id' => isset( $assistant_config['ID'] ) ? $assistant_config['ID'] : null,
						'tool_count'   => count( $options['tools'] ),
					)
				);
			}
		}

		return $options;
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
		$main_controller = $this->get_main_controller();

		// Defensive check for main controller.
		if ( ! $main_controller ) {
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
				__( 'Internal server error. Please try again later.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$user_id = absint( $request->get_param( 'user_id' ) );

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
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
				__( 'A valid user is required to query chat transcripts. Please log in to view your chat history.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$session_key      = $main_controller->normalise_transcript_session_key( $request->get_param( 'session_key' ) );
		$assistant_id_raw = $request->get_param( 'assistant_id' );

		// Handle both integer and string assistant IDs (for unified teams and team members).
		if ( is_string( $assistant_id_raw ) && ! empty( $assistant_id_raw ) ) {
			$assistant_id = sanitize_text_field( $assistant_id_raw );
		} else {
			$assistant_id = absint( $assistant_id_raw );
		}

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
			$session = $main_controller->get_transcript_session( $user_id, $session_key, $assistant_id );

			if ( is_wp_error( $session ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'handle_chat_transcripts: Error retrieving session',
					array(
						'error_code'    => $session->get_error_code(),
						'error_message' => $session->get_error_message(),
						'session_key'   => $session_key,
						'user_id'       => $user_id,
					)
				);

				// Handle gracefully for unavailable transcript storage (JetEngine not active).

				if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
					return rest_ensure_response(
						array(
							'session' => null,
							'message' => $session->get_error_message(),
						)
					);
				}

				// Return error directly for missing transcripts (will be 404).

				return $session;
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

		$sessions = $main_controller->get_transcript_sessions( $user_id, $per_page, $page, $assistant_id );

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
		$main_controller = $this->get_main_controller();

		// Defensive check for main controller.
		if ( ! $main_controller ) {
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
				__( 'Internal server error. Please try again later.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$main_controller->hydrate_request_body_params( $request );

		// Get assistant_id as raw value first to check for virtual team IDs.
		$assistant_id_raw = $request->get_param( 'assistant_id' );
		$session_key      = $this->get_validator()->sanitize_session_key_param( $request->get_param( 'session_key' ) );
		$messages         = $request->get_param( 'messages' );

		// Check if this is a virtual team assistant ID.
		// These are constructed by the Test Team interface and don't correspond to real assistant posts.
		// Format: unified_team_{digits} or team_{digits}_member_{digits}.
		$is_virtual_team_assistant = is_string( $assistant_id_raw ) &&
			preg_match( '/^(unified_team_\d+|team_\d+_member_\d+)$/', $assistant_id_raw );

		// Sanitize assistant_id based on type.
		if ( $is_virtual_team_assistant ) {
			// Keep as string for virtual team IDs.
			$assistant_id = sanitize_text_field( $assistant_id_raw );
		} else {
			// Convert to integer for real assistant post IDs.
			$assistant_id = absint( $assistant_id_raw );
		}

		// Validate assistant_id is provided.
		if ( ! $assistant_id || ( is_string( $assistant_id ) && '' === trim( $assistant_id ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_assistant',
				__( 'Assistant ID is required to save a transcript.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_session',
				__( 'Session key is required to save a transcript.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $messages ) || ! is_array( $messages ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_messages',
				__( 'Messages array is required to save a transcript.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Validate assistant access for real assistant IDs only.
		if ( ! $is_virtual_team_assistant ) {
			// For real assistant IDs, validate that the post exists and user has access.
			$assistant_post = $main_controller->validate_assistant_access( $assistant_id );
			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}
		}

		// Sanitize messages.
		$sanitized_messages = $this->get_validator()->sanitize_messages( $messages );
		if ( is_wp_error( $sanitized_messages ) ) {
			return $sanitized_messages;
		}

		$clean_messages = $sanitized_messages['messages'];

		if ( empty( $clean_messages ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_invalid_messages',
				__( 'No valid messages to save.', 'mcp-ai-wpoos' ),
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
		$provider         = isset( $assistant_config['provider'] ) ? sanitize_key( $assistant_config['provider'] ) : '';

		// Get optional response metadata (usage data, provider info, etc.) if provided.
		$response_metadata = $request->get_param( 'response_metadata' );
		if ( ! is_array( $response_metadata ) ) {
			$response_metadata = array();
		}

		// Build a response payload from the conversation messages.
		// When manually saving a conversation, we need to construct a response that includes.

		// the assistant messages in the expected OpenAI format so they can be properly extracted.

		// when the transcript is loaded later.
		$response = $this->build_response_from_messages( $clean_messages, $model, $response_metadata );

		// Add provider to response if available and not already set.
		if ( '' !== $provider && ! isset( $response['provider'] ) ) {
			$response['provider'] = $provider;
		}

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
			// Get diagnostic information for troubleshooting.
			$diagnostic_info = $this->get_transcript_save_diagnostic_info();

			WP_MCP_AI_Logger::log_event(
				'warning',
				'handle_chat_transcript_save: Transcript not saved to database - persistence unavailable',
				array_merge(
					array(
						'session_key'   => $session_key,
						'assistant_id'  => $assistant_id,
						'user_id'       => $user_id,
						'message_count' => count( $clean_messages ),
						'reason'        => 'Recorder returned null',
						'impact'        => 'Transcript stored in browser only (24h)',
					),
					$diagnostic_info
				)
			);

			// Return success with warning instead of error.
			// Transcripts are still stored in localStorage (24h) so chat functionality works.
			// Just inform the user that permanent storage is unavailable.
			$warning_message = $this->build_transcript_save_warning_message( $diagnostic_info );

			return rest_ensure_response(
				array(
					'success'             => true,
					'session_key'         => $session_key,
					'saved_to_database'   => false,
					'saved_to_browser'    => true,
					'warning'             => $warning_message,
					'message'             => __( 'Transcript saved to browser only. Permanent storage unavailable.', 'mcp-ai-wpoos' ),
					'persistence_details' => $diagnostic_info,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'info',
			'handle_chat_transcript_save: Transcript saved successfully',
			array(
				'session_key'   => $session_key,
				'assistant_id'  => $assistant_id,
				'user_id'       => $user_id,
				'message_count' => count( $clean_messages ),
			)
		);

		return rest_ensure_response(
			array(
				'success'     => true,
				'session_key' => $recorded_session_key,
				'message'     => __( 'Transcript saved successfully.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * Handle retrieval of a specific chat transcript session by session key.
	 *
	 * This endpoint provides RESTful access to a specific transcript using the
	 * session key in the URL path (e.g., /chat-transcripts/{session_key}).
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_get( WP_REST_Request $request ) {
		$main_controller = $this->get_main_controller();

		// Defensive check for main controller.
		if ( ! $main_controller ) {
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
				__( 'Internal server error. Please try again later.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$session_key  = $main_controller->normalise_transcript_session_key( $request->get_param( 'session_key' ) );
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );
		$user_id      = absint( $request->get_param( 'user_id' ) );

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_invalid_session',
				__( 'A valid session key is required to retrieve a transcript.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'handle_chat_transcript_get: No user ID available',
				array(
					'requested_user_id' => $request->get_param( 'user_id' ),
					'current_user_id'   => get_current_user_id(),
					'is_user_logged_in' => is_user_logged_in(),
					'session_key'       => $session_key,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_user',
				__( 'A valid user is required to retrieve chat transcripts. Please log in to view your chat history.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'debug',
			'handle_chat_transcript_get: Request parameters',
			array(
				'session_key'  => $session_key,
				'user_id'      => $user_id,
				'assistant_id' => $assistant_id,
			)
		);

		$session = $main_controller->get_transcript_session( $user_id, $session_key, $assistant_id );

		if ( is_wp_error( $session ) ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'handle_chat_transcript_get: Error retrieving session',
				array(
					'error_code'    => $session->get_error_code(),
					'error_message' => $session->get_error_message(),
					'session_key'   => $session_key,
					'user_id'       => $user_id,
				)
			);

			// Handle gracefully for unavailable transcript storage (JetEngine not active).

			if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
				return rest_ensure_response(
					array(
						'session' => null,
						'message' => $session->get_error_message(),
					)
				);
			}

			// Return error directly for missing transcripts (will be 404).

			return $session;
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
		$main_controller = $this->get_main_controller();

		// Defensive check for main controller.
		if ( ! $main_controller ) {
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
				__( 'Internal server error. Please try again later.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$session_key = $main_controller->normalise_transcript_session_key( $request->get_param( 'session_key' ) );

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_invalid_session',
				__( 'A valid session key is required to delete a transcript.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_missing_user',
				__( 'You must be logged in to delete a transcript.', 'mcp-ai-wpoos' ),
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

		$repository = $main_controller->get_transcript_repository();
		$table      = $repository->get_table_name();

		if ( '' === $table ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'Chat transcripts are not configured or available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		if ( ! $repository->table_exists() ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'The transcript storage table does not exist.', 'mcp-ai-wpoos' ),
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
				__( 'Failed to delete the transcript.', 'mcp-ai-wpoos' ),
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
				'message' => __( 'Transcript deleted successfully.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * Build a response payload from conversation messages for transcript storage.
	 *
	 * When manually saving a conversation (not from a live chat response), we need to
	 * construct a response payload that matches the expected OpenAI response format.
	 * This ensures that when the transcript is loaded later, the messages can be
	 * properly extracted from the response_payload field.
	 *
	 * The response payload will include all assistant messages from the conversation
	 * in the 'choices' array, formatted according to the OpenAI API response schema.
	 *
	 * Optionally accepts response metadata (usage data, provider info, etc.) that
	 * will be merged into the response payload to preserve this information.
	 *
	 * @param array  $messages         Clean sanitized messages array.
	 * @param string $model            Model identifier.
	 * @param array  $response_metadata Optional response metadata (usage, provider, etc.).
	 * @return array Response payload with choices containing assistant messages.
	 */
	private function build_response_from_messages( array $messages, $model, array $response_metadata = array() ) {
		$choices = array();
		$index   = 0;

		// Extract all assistant messages and add them to choices array.
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}

			// Only include assistant messages in the response payload.
			// User, system, and tool messages are stored in request_payload.
			if ( 'assistant' === $message['role'] ) {
				$choice = array(
					'index'         => $index++,
					'message'       => array(
						'role'    => 'assistant',
						'content' => isset( $message['content'] ) ? $message['content'] : null,
					),
					'finish_reason' => 'stop',
				);

				// Preserve tool_calls if present in the assistant message.
				if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					$choice['message']['tool_calls'] = $message['tool_calls'];
				}

				$choices[] = $choice;
			}
		}

		// Build the base response payload in OpenAI format.
		$response = array(
			'model'   => $model,
			'choices' => $choices,
		);

		// Merge in optional response metadata if provided.
		// This allows preserving usage data, provider info, response IDs, etc.
		if ( ! empty( $response_metadata ) && is_array( $response_metadata ) ) {
			foreach ( self::ALLOWED_RESPONSE_METADATA_FIELDS as $field ) {
				if ( isset( $response_metadata[ $field ] ) ) {
					// Sanitize based on field type.
					switch ( $field ) {
						case 'usage':
							// Validate and sanitize usage data structure.
							$usage = $this->sanitize_usage_data( $response_metadata[ $field ] );
							if ( ! empty( $usage ) ) {
								$response[ $field ] = $usage;
							}
							break;

						case 'provider':
							$response[ $field ] = sanitize_key( $response_metadata[ $field ] );
							break;

						case 'id':
						case 'object':
						case 'service_tier':
						case 'system_fingerprint':
							$response[ $field ] = sanitize_text_field( $response_metadata[ $field ] );
							break;

						case 'created':
							$response[ $field ] = absint( $response_metadata[ $field ] );
							break;
					}
				}
			}
		}

		return $response;
	}

	/**
	 * Sanitize and validate usage data structure.
	 *
	 * Validates that usage data contains the expected fields with appropriate types.
	 * Returns a sanitized usage array or empty array if invalid.
	 *
	 * @param mixed $usage_data Raw usage data from request.
	 * @return array Sanitized usage data or empty array if invalid.
	 */
	private function sanitize_usage_data( $usage_data ) {
		if ( ! is_array( $usage_data ) ) {
			return array();
		}

		$sanitized = array();

		// Sanitize top-level token counts.
		$token_fields = array( 'prompt_tokens', 'completion_tokens', 'total_tokens' );
		foreach ( $token_fields as $field ) {
			if ( isset( $usage_data[ $field ] ) ) {
				$value = absint( $usage_data[ $field ] );
				if ( $value >= 0 ) {
					$sanitized[ $field ] = $value;
				}
			}
		}

		// Sanitize prompt_tokens_details if present.
		if ( isset( $usage_data['prompt_tokens_details'] ) && is_array( $usage_data['prompt_tokens_details'] ) ) {
			$prompt_details = array();
			$detail_fields  = array( 'cached_tokens', 'audio_tokens' );

			foreach ( $detail_fields as $field ) {
				if ( isset( $usage_data['prompt_tokens_details'][ $field ] ) ) {
					$prompt_details[ $field ] = absint( $usage_data['prompt_tokens_details'][ $field ] );
				}
			}

			if ( ! empty( $prompt_details ) ) {
				$sanitized['prompt_tokens_details'] = $prompt_details;
			}
		}

		// Sanitize completion_tokens_details if present.
		if ( isset( $usage_data['completion_tokens_details'] ) && is_array( $usage_data['completion_tokens_details'] ) ) {
			$completion_details = array();
			$detail_fields      = array(
				'reasoning_tokens',
				'audio_tokens',
				'accepted_prediction_tokens',
				'rejected_prediction_tokens',
			);

			foreach ( $detail_fields as $field ) {
				if ( isset( $usage_data['completion_tokens_details'][ $field ] ) ) {
					$completion_details[ $field ] = absint( $usage_data['completion_tokens_details'][ $field ] );
				}
			}

			if ( ! empty( $completion_details ) ) {
				$sanitized['completion_tokens_details'] = $completion_details;
			}
		}

		return $sanitized;
	}

	/**
	 * Get diagnostic information about transcript save failures.
	 *
	 * @return array Diagnostic information.
	 */
	private function get_transcript_save_diagnostic_info() {
		$info = array();

		// Check JetEngine availability.
		$info['jetengine_active'] = function_exists( 'jet_engine' );

		if ( function_exists( 'jet_engine' ) ) {
			$engine = jet_engine();
			if ( is_object( $engine ) && property_exists( $engine, 'modules' ) && is_object( $engine->modules ) ) {
				if ( method_exists( $engine->modules, 'is_module_active' ) ) {
					$info['cct_module_active']         = $engine->modules->is_module_active( 'custom-content-types' );
					$info['data_stores_module_active'] = $engine->modules->is_module_active( 'data-stores' );
				}
			}
		}

		// Check CCT class availability.
		$info['jetengine_cct_class_exists'] = class_exists( 'WP_MCP_AI_JetEngine_CCT' );

		// Check transcript repository and table.
		$main_controller = $this->get_main_controller();
		if ( $main_controller && method_exists( $main_controller, 'get_transcript_repository' ) ) {
			$repository = $main_controller->get_transcript_repository();
			if ( $repository ) {
				$info['table_name']   = $repository->get_table_name();
				$info['table_exists'] = $repository->table_exists();
			}
		}

		return $info;
	}

	/**
	 * Build a helpful error message based on diagnostic information.
	 *
	 * @deprecated 1.6.0 Use build_transcript_save_warning_message() instead. Will be removed in 2.0.0.
	 * @param array $diagnostic_info Diagnostic information from get_transcript_save_diagnostic_info().
	 * @return string Error message with guidance.
	 */
	private function build_transcript_save_error_message( array $diagnostic_info ) {
		return $this->build_transcript_save_warning_message( $diagnostic_info );
	}

	/**
	 * Build a helpful warning message about transcript persistence unavailability.
	 *
	 * @param array $diagnostic_info Diagnostic information from get_transcript_save_diagnostic_info().
	 * @return string Warning message with guidance.
	 */
	private function build_transcript_save_warning_message( array $diagnostic_info ) {
		// Check if base version mode is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return __( 'Transcript persistence requires the full version with JetEngine integration. Currently running in base version mode.', 'mcp-ai-wpoos' );
		}

		// Add specific guidance based on diagnostics.
		if ( empty( $diagnostic_info['jetengine_active'] ) ) {
			return sprintf(
				/* translators: %s: Link to JetEngine plugin */
				__( 'Permanent transcript storage requires JetEngine plugin. Install and activate %s to enable database persistence.', 'mcp-ai-wpoos' ),
				'JetEngine (https://crocoblock.com/plugins/jetengine/)'
			);
		}

		if ( ! empty( $diagnostic_info['jetengine_active'] ) && empty( $diagnostic_info['cct_module_active'] ) ) {
			return __( 'JetEngine Custom Content Types module is not active. Enable it in JetEngine → Settings → Modules to enable transcript storage.', 'mcp-ai-wpoos' );
		}

		if ( ! empty( $diagnostic_info['jetengine_active'] ) && empty( $diagnostic_info['data_stores_module_active'] ) ) {
			return __( 'JetEngine Data Stores module is not active. Enable it in JetEngine → Settings → Modules to enable transcript storage.', 'mcp-ai-wpoos' );
		}

		if ( empty( $diagnostic_info['table_exists'] ) && ! empty( $diagnostic_info['table_name'] ) ) {
			return sprintf(
				/* translators: %s: Database table name */
				__( 'Transcript database table (%s) does not exist. Try deactivating and reactivating the plugin to recreate it.', 'mcp-ai-wpoos' ),
				esc_html( $diagnostic_info['table_name'] )
			);
		}

		return __( 'Transcript persistence is unavailable. Transcripts will be stored in browser only (24 hours). Check the error logs for more details.', 'mcp-ai-wpoos' );
	}

	/**
	 * Handle /track-embedded-usage request.
	 *
	 * Tracks usage from embedded LLM (client-side WebLLM) for cost monitoring
	 * and orchestration dashboard visibility.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_track_embedded_usage( WP_REST_Request $request ) {
		$assistant_id  = $request->get_param( 'assistant_id' );
		$model         = $request->get_param( 'model' );
		$usage         = $request->get_param( 'usage' );
		$finish_reason = $request->get_param( 'finish_reason' );

		// Get current user.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $this->error(
				'wp_mcp_ai_no_user',
				__( 'User must be authenticated to track usage.', 'mcp-ai-wpoos' ),
				401
			);
		}

		// Validate usage data.
		if ( empty( $usage ) || ! is_array( $usage ) ) {
			return $this->error(
				'wp_mcp_ai_invalid_usage',
				__( 'Invalid usage data provided.', 'mcp-ai-wpoos' ),
				400
			);
		}

		// Prepare response object for usage tracker.
		// Format matches what server-side chat responses provide.
		$response = array(
			'usage'    => $usage,
			'provider' => 'embedded',
			'model'    => $model,
		);

		// Prepare options for usage tracker.
		$options = array(
			'provider' => 'embedded',
			'model'    => $model,
		);

		// Record usage via standard usage tracker.
		// This integrates with existing cost estimation and reporting.
		if ( class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			WP_MCP_AI_Usage_Tracker::record_chat_usage(
				$user_id,
				$assistant_id,
				$options,
				$response
			);
		}

		// Optional: Log to JetEngine CCT for detailed usage history.
		// This provides queryable usage logs with timestamps.
		/**
		 * Fires after embedded LLM usage is tracked.
		 *
		 * Extensions can use this to log detailed usage history to JetEngine CCT
		 * or other storage systems.
		 *
		 * @param int    $user_id       User who generated the completion.
		 * @param int    $assistant_id  Assistant used.
		 * @param string $model         Model identifier.
		 * @param array  $usage         Usage statistics.
		 * @param string $finish_reason Why generation stopped.
		 */
		do_action( 'wp_mcp_ai_embedded_usage_tracked', $user_id, $assistant_id, $model, $usage, $finish_reason );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Usage tracked successfully.', 'mcp-ai-wpoos' ),
			)
		);
	}
}
