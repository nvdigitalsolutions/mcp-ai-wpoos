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
						'transcript'  => array(
							'description' => __( 'Chat transcript to save.', 'wp-mcp-ai' ),
							'type'        => 'object',
							'required'    => true,
						),
						'session_key' => array(
							'description' => __( 'Optional session key.', 'wp-mcp-ai' ),
							'type'        => 'string',
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
						'session_key' => array(
							'description'       => __( 'Session key for the transcript.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this->validator, 'sanitize_session_key_param' ),
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
				'items'             => array(
					'type'       => 'object',
					'properties' => array(
						'role'    => array(
							'type' => 'string',
							'enum' => array( 'system', 'user', 'assistant', 'tool' ),
						),
						'content' => array(
							'description' => __( 'Message content. Can be a string or array of content parts.', 'wp-mcp-ai' ),
							'oneOf'       => array(
								array( 'type' => 'string' ),
								array(
									'type'  => 'array',
									'items' => array(
										'type' => 'object',
									),
								),
							),
						),
					),
				),
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
		return $this->error( 'not_implemented', __( 'Chat endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}

	/**
	 * Handle /chat-client request (browser clients).
	 *
	 * Delegates to main REST controller for now.
	 * Will be extracted in implementation phase.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_client_request( WP_REST_Request $request ) {
		// Delegate to main controller.
		if ( $this->main_controller ) {
			return $this->main_controller->handle_chat_client_request( $request );
		}
		return $this->error( 'not_implemented', __( 'Chat client endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}

	/**
	 * Handle list transcripts request.
	 *
	 * Delegates to main REST controller for now.
	 * Will be extracted in implementation phase.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcripts( WP_REST_Request $request ) {
		// Delegate to main controller.
		if ( $this->main_controller ) {
			return $this->main_controller->handle_chat_transcripts( $request );
		}
		return $this->error( 'not_implemented', __( 'Chat transcripts endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}

	/**
	 * Handle save transcript request.
	 *
	 * Delegates to main REST controller for now.
	 * Will be extracted in implementation phase.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_save( WP_REST_Request $request ) {
		// Delegate to main controller.
		if ( $this->main_controller ) {
			return $this->main_controller->handle_chat_transcript_save( $request );
		}
		return $this->error( 'not_implemented', __( 'Chat transcript save endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}

	/**
	 * Handle get individual transcript request.
	 *
	 * Delegates to main REST controller for now.
	 * Will be extracted in implementation phase.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_get( WP_REST_Request $request ) {
		// Delegate to main controller.
		if ( $this->main_controller ) {
			return $this->main_controller->handle_chat_transcript_get( $request );
		}
		return $this->error( 'not_implemented', __( 'Chat transcript get endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}

	/**
	 * Handle delete transcript request.
	 *
	 * Delegates to main REST controller for now.
	 * Will be extracted in implementation phase.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_chat_transcript_delete( WP_REST_Request $request ) {
		// Delegate to main controller.
		if ( $this->main_controller ) {
			return $this->main_controller->handle_chat_transcript_delete( $request );
		}
		return $this->error( 'not_implemented', __( 'Chat transcript delete endpoint not yet fully extracted.', 'wp-mcp-ai' ), 501 );
	}
}
