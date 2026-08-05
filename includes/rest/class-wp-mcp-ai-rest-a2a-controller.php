<?php
/**
 * A2A REST API Controller.
 *
 * Implements the A2A protocol server operations including message/send,
 * message/stream, tasks management, and agent card endpoints via JSON-RPC 2.0.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @see       https://a2a-protocol.org/latest/specification/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for A2A protocol endpoints.
 */
class WP_MCP_AI_REST_A2A_Controller extends WP_MCP_AI_REST_Controller_Base {

	/**
	 * A2A protocol version.
	 *
	 * @var string
	 */
	const A2A_VERSION = '1.0';

	/**
	 * A2A JSON-RPC methods supported.
	 *
	 * @var array
	 */
	const SUPPORTED_METHODS = array(
		'message/send',
		'message/stream',
		'tasks/get',
		'tasks/list',
		'tasks/cancel',
		'tasks/pushNotificationConfig/create',
		'tasks/pushNotificationConfig/get',
		'tasks/pushNotificationConfig/list',
		'tasks/pushNotificationConfig/delete',
		'agent/authenticatedExtendedCard',
	);

	/**
	 * Main REST instance for chat pipeline access.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST                    $rest          Main REST controller instance.
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator Authentication handler.
	 * @param WP_MCP_AI_REST_Validator|null     $validator     Request validator.
	 */
	public function __construct( $rest, $authenticator = null, $validator = null ) {
		parent::__construct( $authenticator, $validator );
		$this->rest = $rest;
	}

	/**
	 * Register REST routes for A2A protocol.
	 */
	public function register_routes() {
		// JSON-RPC 2.0 endpoint (primary A2A protocol binding).
		register_rest_route(
			self::REST_NAMESPACE,
			'/a2a',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_jsonrpc_request' ),
					'permission_callback' => array( $this, 'permissions_check_a2a' ),
					'args'                => array(
						'jsonrpc' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( '2.0' ),
						),
						'id'      => array(
							'required' => false,
						),
						'method'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'params'  => array(
							'type'     => array( 'object', 'array' ),
							'required' => false,
							'default'  => array(),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_agent_card_request' ),
					'permission_callback' => array( $this, 'permissions_check_agent_card' ),
				),
			)
		);

		// Per-assistant Agent Card endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/a2a/agent-card/(?P<assistant_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_per_assistant_card' ),
				'permission_callback' => array( $this, 'permissions_check_per_assistant_card' ),
				'args'                => array(
					'assistant_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Webhook receiver for push notifications from remote A2A agents.
		register_rest_route(
			self::REST_NAMESPACE,
			'/a2a/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'permissions_check_a2a' ),
				'args'                => array(
					'type' => array(
						'description'       => __( 'Webhook event type.', 'mcp-ai-wpoos' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'data' => array(
						'description' => __( 'Webhook payload data.', 'mcp-ai-wpoos' ),
						'type'        => 'object',
						'required'    => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check for A2A endpoints.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True if permitted, error otherwise.
	 */
	public function permissions_check_a2a( $request ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Check if A2A is enabled.
		if ( empty( $settings['enable_a2a_server'] ) ) {
			return new WP_Error(
				'a2a_disabled',
				__( 'A2A protocol is not enabled on this server.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Validate A2A-Version header.
		$version = $request->get_header( 'A2A-Version' );
		if ( $version && version_compare( $version, self::A2A_VERSION, '>' ) ) {
			return new WP_Error(
				'a2a_version_not_supported',
				sprintf(
					/* translators: %s: supported version */
					__( 'A2A version not supported. This server supports version %s.', 'mcp-ai-wpoos' ),
					self::A2A_VERSION
				),
				array( 'status' => 400 )
			);
		}

		// Authenticate the request.
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Permission check for Agent Card discovery endpoints (GET /a2a/agent-card and
	 * GET /a2a/agent-card/{id}).
	 *
	 * Agent Cards are intentionally public — they are the machine-readable discovery
	 * document that remote A2A agents read before initiating a session (similar to
	 * RFC 8615 .well-known resources). However there is no reason to expose them
	 * when A2A is disabled on this installation.
	 *
	 * @param WP_REST_Request $request The request (unused; kept for signature compatibility).
	 * @return true|WP_Error True when A2A is enabled, WP_Error 403 otherwise.
	 */
	public function permissions_check_agent_card( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Variable is intentionally unused in the current implementation but reserved for future A2A protocol extensions.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_a2a_server'] ) ) {
			return new WP_Error(
				'a2a_disabled',
				__( 'A2A protocol is not enabled on this server.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission check for per-assistant Agent Card endpoint
	 * (GET /a2a/agent-card/{id}).
	 *
	 * Unlike the top-level agent card which is intentionally public,
	 * per-assistant cards expose assistant-specific metadata (model info,
	 * tool lists, configuration) that should be gated behind A2A
	 * authentication.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error True if permitted, error otherwise.
	 */
	public function permissions_check_per_assistant_card( $request ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_a2a_server'] ) ) {
			return new WP_Error(
				'a2a_disabled',
				__( 'A2A protocol is not enabled on this server.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Require A2A authentication (nonce, bearer, or mesh key).
		return $this->permissions_check_authenticated( $request );
	}

	/**
	 * Handle JSON-RPC 2.0 requests for A2A protocol.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The JSON-RPC response.
	 */
	public function handle_jsonrpc_request( $request ) {
		$method    = $request->get_param( 'method' );
		$params    = $request->get_param( 'params' );
		$rpc_id    = $request->get_param( 'id' );
		$is_params = is_array( $params ) ? $params : array();

		// Route to appropriate handler.
		switch ( $method ) {
			case 'message/send':
				$result = $this->handle_message_send( $is_params, $request );
				break;

			case 'message/stream':
				return $this->handle_message_stream( $is_params, $request );

			case 'tasks/get':
				$result = $this->handle_tasks_get( $is_params );
				break;

			case 'tasks/list':
				$result = $this->handle_tasks_list( $is_params );
				break;

			case 'tasks/cancel':
				$result = $this->handle_tasks_cancel( $is_params );
				break;

			case 'tasks/pushNotificationConfig/create':
				$result = $this->handle_push_config_create( $is_params );
				break;

			case 'tasks/pushNotificationConfig/get':
				$result = $this->handle_push_config_get( $is_params );
				break;

			case 'tasks/pushNotificationConfig/list':
				$result = $this->handle_push_config_list( $is_params );
				break;

			case 'tasks/pushNotificationConfig/delete':
				$result = $this->handle_push_config_delete( $is_params );
				break;

			case 'agent/authenticatedExtendedCard':
				$result = $this->handle_extended_card();
				break;

			default:
				$result = new WP_Error(
					'a2a_method_not_found',
					sprintf(
						/* translators: %s: method name */
						__( 'Unknown A2A method: %s', 'mcp-ai-wpoos' ),
						$method
					),
					array( 'status' => 400 )
				);
				break;
		}

		return $this->build_jsonrpc_response( $rpc_id, $result );
	}

	/**
	 * Handle message/send — the primary A2A operation.
	 *
	 * @param array           $params  The JSON-RPC params.
	 * @param WP_REST_Request $request The REST request.
	 * @return array|WP_Error Task or Message object, or error.
	 */
	protected function handle_message_send( $params, $request ) {
		$message = isset( $params['message'] ) ? $params['message'] : null;
		if ( ! $message || ! isset( $message['parts'] ) || ! is_array( $message['parts'] ) ) {
			return new WP_Error(
				'a2a_invalid_params',
				__( 'Invalid message: parts array is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Fires when an A2A message is received.
		 *
		 * @param array           $message The A2A message.
		 * @param WP_REST_Request $request The REST request.
		 */
		do_action( 'wp_mcp_ai_a2a_message_received', $message, $request );

		// Translate A2A message to NV oOS chat format.
		$translated = WP_MCP_AI_A2A_Message_Translator::a2a_to_chat( $params );
		$context_id = $translated['context_id'];
		$task_id    = $translated['task_id'];

		// If continuing an existing task, validate it.
		if ( $task_id ) {
			$existing = WP_MCP_AI_A2A_Task_Manager::get_task( $task_id );
			if ( ! $existing ) {
				return new WP_Error(
					'a2a_task_not_found',
					__( 'Task not found.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			if ( WP_MCP_AI_A2A_Task_Manager::is_terminal_state( $existing['status']['state'] ) ) {
				return new WP_Error(
					'a2a_unsupported_operation',
					__( 'Cannot send messages to a task in a terminal state.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$context_id = $existing['contextId'];
		}

		// Create A2A task to track this request.
		// Validate and sanitize messageId — enforce max length to prevent abuse.
		$raw_msg_id  = isset( $message['messageId'] ) ? sanitize_text_field( $message['messageId'] ) : '';
		$message_id  = ( ! empty( $raw_msg_id ) && strlen( $raw_msg_id ) <= 128 ) ? $raw_msg_id : wp_generate_uuid4();
		$a2a_message = array(
			'kind'      => 'message',
			'messageId' => $message_id,
			'role'      => 'user',
			'parts'     => $message['parts'],
		);

		if ( $task_id ) {
			// Continue existing task.
			$task = WP_MCP_AI_A2A_Task_Manager::add_message( $task_id, $a2a_message );
			if ( is_wp_error( $task ) ) {
				return $task;
			}
			WP_MCP_AI_A2A_Task_Manager::transition_state( $task_id, WP_MCP_AI_A2A_Task_Manager::STATE_WORKING );
		} else {
			// Create new task.
			$task    = WP_MCP_AI_A2A_Task_Manager::create_task( $a2a_message, $context_id );
			$task_id = $task['id'];
			WP_MCP_AI_A2A_Task_Manager::transition_state( $task_id, WP_MCP_AI_A2A_Task_Manager::STATE_WORKING );
		}

		// Determine which assistant to use.
		$assistant_id = $this->resolve_assistant( $params );

		// Process through the chat pipeline.
		$chat_result = $this->process_chat( $translated['messages'], $assistant_id );

		if ( is_wp_error( $chat_result ) ) {
			$error_message = WP_MCP_AI_A2A_Message_Translator::chat_to_a2a_message(
				$chat_result->get_error_message(),
				$context_id
			);
			WP_MCP_AI_A2A_Task_Manager::add_message( $task_id, $error_message );
			WP_MCP_AI_A2A_Task_Manager::transition_state(
				$task_id,
				WP_MCP_AI_A2A_Task_Manager::STATE_FAILED,
				$error_message
			);

			return WP_MCP_AI_A2A_Task_Manager::get_task( $task_id );
		}

		// Convert chat response to A2A format.
		$response_text = is_array( $chat_result ) && isset( $chat_result['content'] ) ? $chat_result['content'] : (string) $chat_result;
		$agent_message = WP_MCP_AI_A2A_Message_Translator::chat_to_a2a_message( $response_text, $context_id );

		// Add response to task history.
		WP_MCP_AI_A2A_Task_Manager::add_message( $task_id, $agent_message );

		// If there are structured results, add them as artifacts.
		if ( is_array( $chat_result ) && isset( $chat_result['tool_results'] ) ) {
			foreach ( $chat_result['tool_results'] as $tool_slug => $tool_result ) {
				$artifact = WP_MCP_AI_A2A_Message_Translator::tool_result_to_artifact( $tool_result, $tool_slug );
				WP_MCP_AI_A2A_Task_Manager::add_artifact( $task_id, $artifact );
			}
		}

		// Mark task as completed.
		WP_MCP_AI_A2A_Task_Manager::transition_state(
			$task_id,
			WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED,
			$agent_message
		);

		// Fire push notifications if configured.
		$this->fire_push_notifications( $task_id );

		return WP_MCP_AI_A2A_Task_Manager::get_task( $task_id );
	}

	/**
	 * Handle message/stream — streaming A2A operation.
	 *
	 * @param array           $params  The JSON-RPC params.
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response
	 */
	protected function handle_message_stream( $params, $request ) {
		$message = isset( $params['message'] ) ? $params['message'] : null;
		if ( ! $message || ! isset( $message['parts'] ) || ! is_array( $message['parts'] ) ) {
			return $this->build_jsonrpc_response(
				$request->get_param( 'id' ),
				new WP_Error(
					'a2a_invalid_params',
					__( 'Invalid message: parts array is required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				)
			);
		}

		// Translate and create task.
		$translated = WP_MCP_AI_A2A_Message_Translator::a2a_to_chat( $params );
		$context_id = $translated['context_id'];

		$a2a_message = array(
			'kind'      => 'message',
			'messageId' => isset( $message['messageId'] ) ? sanitize_text_field( $message['messageId'] ) : wp_generate_uuid4(),
			'role'      => 'user',
			'parts'     => $message['parts'],
		);

		$task    = WP_MCP_AI_A2A_Task_Manager::create_task( $a2a_message, $context_id );
		$task_id = $task['id'];

		// Get SSE handler.
		$container   = wp_mcp_ai_container();
		$sse_handler = $container->get( 'rest.sse_handler' );

		// Send SSE headers.
		$sse_handler->send_sse_headers();

		// Emit initial task event.
		$sse_handler->send_sse_event( 'message', $task );

		// Transition to working.
		WP_MCP_AI_A2A_Task_Manager::transition_state( $task_id, WP_MCP_AI_A2A_Task_Manager::STATE_WORKING );
		$status_update = WP_MCP_AI_A2A_Message_Translator::build_status_update(
			$task_id,
			$task['contextId'],
			WP_MCP_AI_A2A_Task_Manager::STATE_WORKING
		);
		$sse_handler->send_sse_event( 'message', $status_update );

		// Process through chat pipeline.
		$assistant_id = $this->resolve_assistant( $params );
		$chat_result  = $this->process_chat( $translated['messages'], $assistant_id );

		if ( is_wp_error( $chat_result ) ) {
			WP_MCP_AI_A2A_Task_Manager::transition_state( $task_id, WP_MCP_AI_A2A_Task_Manager::STATE_FAILED );
			$fail_update = WP_MCP_AI_A2A_Message_Translator::build_status_update(
				$task_id,
				$task['contextId'],
				WP_MCP_AI_A2A_Task_Manager::STATE_FAILED,
				null,
				true
			);
			$sse_handler->send_sse_event( 'message', $fail_update );
		} else {
			$response_text = is_array( $chat_result ) && isset( $chat_result['content'] ) ? $chat_result['content'] : (string) $chat_result;
			$agent_message = WP_MCP_AI_A2A_Message_Translator::chat_to_a2a_message( $response_text, $task['contextId'] );
			WP_MCP_AI_A2A_Task_Manager::add_message( $task_id, $agent_message );

			// Build and emit artifact if there are structured results.
			if ( is_array( $chat_result ) && isset( $chat_result['tool_results'] ) ) {
				foreach ( $chat_result['tool_results'] as $tool_slug => $tool_result ) {
					$artifact = WP_MCP_AI_A2A_Message_Translator::tool_result_to_artifact( $tool_result, $tool_slug );
					WP_MCP_AI_A2A_Task_Manager::add_artifact( $task_id, $artifact );

					$artifact_update = WP_MCP_AI_A2A_Message_Translator::build_artifact_update(
						$task_id,
						$task['contextId'],
						$artifact
					);
					$sse_handler->send_sse_event( 'message', $artifact_update );
				}
			}

			// Mark completed.
			WP_MCP_AI_A2A_Task_Manager::transition_state( $task_id, WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED, $agent_message );
			$complete_update = WP_MCP_AI_A2A_Message_Translator::build_status_update(
				$task_id,
				$task['contextId'],
				WP_MCP_AI_A2A_Task_Manager::STATE_COMPLETED,
				$agent_message,
				true
			);
			$sse_handler->send_sse_event( 'message', $complete_update );
		}

		$sse_handler->send_sse_done();
		$sse_handler->finish();

		// Return empty response since SSE was sent directly.
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Handle tasks/get.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|WP_Error The task or error.
	 */
	protected function handle_tasks_get( $params ) {
		$task_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		if ( empty( $task_id ) ) {
			return new WP_Error(
				'a2a_invalid_params',
				__( 'Task ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$task = WP_MCP_AI_A2A_Task_Manager::get_task( $task_id );
		if ( ! $task ) {
			return new WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// Apply historyLength if provided.
		if ( isset( $params['historyLength'] ) ) {
			$history_length = absint( $params['historyLength'] );
			if ( 0 === $history_length ) {
				unset( $task['history'] );
			} elseif ( isset( $task['history'] ) ) {
				$task['history'] = array_slice( $task['history'], -$history_length );
			}
		}

		return $task;
	}

	/**
	 * Handle tasks/list.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array Task list result.
	 */
	protected function handle_tasks_list( $params ) {
		return WP_MCP_AI_A2A_Task_Manager::list_tasks(
			array(
				'context_id'        => isset( $params['contextId'] ) ? sanitize_text_field( $params['contextId'] ) : '',
				'state'             => isset( $params['state'] ) ? sanitize_text_field( $params['state'] ) : '',
				'per_page'          => isset( $params['pageSize'] ) ? min( absint( $params['pageSize'] ), 100 ) : 20,
				'page_token'        => isset( $params['pageToken'] ) ? sanitize_text_field( $params['pageToken'] ) : '',
				'include_artifacts' => ! empty( $params['includeArtifacts'] ),
			)
		);
	}

	/**
	 * Handle tasks/cancel.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|WP_Error Updated task or error.
	 */
	protected function handle_tasks_cancel( $params ) {
		$task_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		if ( empty( $task_id ) ) {
			return new WP_Error(
				'a2a_invalid_params',
				__( 'Task ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$result = WP_MCP_AI_A2A_Task_Manager::cancel_task( $task_id );

		if ( ! is_wp_error( $result ) ) {
			$this->fire_push_notifications( $task_id );
		}

		return $result;
	}

	/**
	 * Handle push notification config create.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|WP_Error The created config or error.
	 */
	protected function handle_push_config_create( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$task_id = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		if ( empty( $task_id ) || ! WP_MCP_AI_A2A_Task_Manager::get_task( $task_id ) ) {
			return new WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$config = isset( $params['pushNotificationConfig'] ) ? $params['pushNotificationConfig'] : array();
		$url    = isset( $config['url'] ) ? esc_url_raw( $config['url'] ) : '';

		if ( empty( $url ) ) {
			return new WP_Error(
				'a2a_invalid_params',
				__( 'Webhook URL is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		return WP_MCP_AI_A2A_Push_Notifications::create_config( $task_id, $config );
	}

	/**
	 * Handle push notification config get.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|WP_Error The config or error.
	 */
	protected function handle_push_config_get( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$task_id   = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		$config_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		return WP_MCP_AI_A2A_Push_Notifications::get_config( $task_id, $config_id );
	}

	/**
	 * Handle push notification config list.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|WP_Error The configs or error.
	 */
	protected function handle_push_config_list( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$task_id = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		return WP_MCP_AI_A2A_Push_Notifications::list_configs( $task_id );
	}

	/**
	 * Handle push notification config delete.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|WP_Error Success or error.
	 */
	protected function handle_push_config_delete( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$task_id   = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		$config_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		return WP_MCP_AI_A2A_Push_Notifications::delete_config( $task_id, $config_id );
	}

	/**
	 * Handle agent/authenticatedExtendedCard.
	 *
	 * @return array The extended Agent Card.
	 */
	protected function handle_extended_card() {
		return WP_MCP_AI_A2A_Agent_Card::build_site_card();
	}

	/**
	 * Handle GET request for Agent Card.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The Agent Card response.
	 */
	public function handle_agent_card_request( $request ) {
		$card = WP_MCP_AI_A2A_Agent_Card::build_site_card();
		return new WP_REST_Response( $card, 200 );
	}

	/**
	 * Handle per-assistant Agent Card request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The Agent Card response.
	 */
	public function handle_per_assistant_card( $request ) {
		$assistant_id = $request->get_param( 'assistant_id' );
		$card         = WP_MCP_AI_A2A_Agent_Card::build_card_for_assistant( $assistant_id );

		if ( is_wp_error( $card ) ) {
			$error_data = $card->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? $error_data['status'] : 404;

			return new WP_REST_Response(
				array(
					'error'   => $card->get_error_code(),
					'message' => $card->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response( $card, 200 );
	}

	/**
	 * Handle inbound webhook for push notifications from remote agents.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response Response.
	 */
	public function handle_webhook( $request ) {
		$body = $request->get_json_params();

		$result = WP_MCP_AI_A2A_Webhook_Handler::handle_inbound( $body );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response( array( 'status' => 'received' ), 200 );
	}

	/**
	 * Build a JSON-RPC 2.0 response.
	 *
	 * @param mixed          $id     The request ID.
	 * @param array|WP_Error $result The result or error.
	 * @return WP_REST_Response The JSON-RPC response.
	 */
	protected function build_jsonrpc_response( $id, $result ) {
		$response = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
		);

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = isset( $error_data['status'] ) ? $error_data['status'] : 400;

			$response['error'] = array(
				'code'    => $this->map_error_code( $result->get_error_code() ),
				'message' => $result->get_error_message(),
				'data'    => array(
					'type' => $result->get_error_code(),
				),
			);

			return new WP_REST_Response( $response, $status );
		}

		$response['result'] = $result;
		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Map A2A error codes to JSON-RPC error codes.
	 *
	 * @param string $error_code The A2A error code.
	 * @return int The JSON-RPC error code.
	 */
	protected function map_error_code( $error_code ) {
		$map = array(
			'a2a_invalid_params'             => -32602,
			'a2a_method_not_found'           => -32601,
			'a2a_task_not_found'             => -32001,
			'a2a_task_not_cancelable'        => -32002,
			'a2a_push_not_supported'         => -32003,
			'a2a_unsupported_operation'      => -32004,
			'a2a_content_type_not_supported' => -32005,
			'a2a_version_not_supported'      => -32006,
			'a2a_disabled'                   => -32007,
			'a2a_invalid_assistant'          => -32008,
			'a2a_invalid_transition'         => -32009,
		);

		return isset( $map[ $error_code ] ) ? $map[ $error_code ] : -32603;
	}

	/**
	 * Resolve which assistant to use for the A2A request.
	 *
	 * @param array $params The request params.
	 * @return int The assistant post ID.
	 */
	protected function resolve_assistant( $params ) {
		// Check if an assistant is specified in metadata.
		if ( isset( $params['metadata']['assistant_id'] ) ) {
			return absint( $params['metadata']['assistant_id'] );
		}

		// Use the default exposed assistant.
		$exposed = WP_MCP_AI_A2A_Agent_Card::get_exposed_assistants();
		return ! empty( $exposed ) ? $exposed[0] : 0;
	}

	/**
	 * Process a message through the NV oOS chat pipeline.
	 *
	 * @param array $messages     Array of NV oOS chat messages.
	 * @param int   $assistant_id The assistant to use.
	 * @return array|WP_Error Chat result or error.
	 */
	protected function process_chat( $messages, $assistant_id ) {
		if ( ! $assistant_id ) {
			return new WP_Error(
				'a2a_no_assistant',
				__( 'No assistant configured for A2A processing.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Validate assistant exists.
		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new WP_Error(
				'a2a_invalid_assistant',
				__( 'Invalid assistant configuration.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Use the REST controller's chat processing.
		if ( method_exists( $this->rest, 'process_chat_request' ) ) {
			return $this->rest->process_chat_request( $messages, $assistant_id );
		}

		// Fallback: use the router directly.
		$container = wp_mcp_ai_container();
		$router    = $container->get( 'router' );

		if ( ! $router || ! method_exists( $router, 'route' ) ) {
			return new WP_Error(
				'a2a_processing_error',
				__( 'Chat pipeline not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$result = $router->route( $messages, $assistant_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Normalize result format.
		if ( is_string( $result ) ) {
			return array( 'content' => $result );
		}

		return $result;
	}

	/**
	 * Fire push notifications for a task if any are configured.
	 *
	 * @param string $task_id The task ID.
	 */
	protected function fire_push_notifications( $task_id ) {
		if ( class_exists( 'WP_MCP_AI_A2A_Push_Notifications' ) ) {
			WP_MCP_AI_A2A_Push_Notifications::fire_notifications( $task_id );
		}
	}
}
