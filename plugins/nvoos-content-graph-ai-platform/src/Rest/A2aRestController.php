<?php
/**
 * A2A REST Controller — receive routes (Wave E5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_REST_A2A_Controller`:
 * byte-identical `mcp-ai/v1/a2a` route surface (JSON-RPC 2.0 POST, agent
 * card GET, per-assistant card GET, webhook receiver POST), parameter
 * schemas, permission envelopes (`a2a_disabled`,
 * `a2a_version_not_supported`, the JSON-RPC error map), the
 * A2A-Version header gate, and the full JSON-RPC method routing
 * (`message/send|stream`, `tasks/get|list|cancel`,
 * `tasks/pushNotificationConfig/*`, `agent/authenticatedExtendedCard`).
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Static utility instead of the base's instance controller — the
 *    base constructor takes the main `WP_MCP_AI_REST` instance and a DI
 *    container; this port resolves those collaborators through
 *    protected static seams (`chat_processor()`, `sse_handler()`).
 *  - Standalone-only route registration via
 *    `Plugin::registerA2aRest()` — the base loader owns the same routes
 *    (boot-gated on `enable_a2a_server`) in monolith installs. The
 *    request-level `a2a_disabled` gate is byte-identical.
 *  - Authenticator + security-manager resolution per install mode: the
 *    base authenticator owns credential validation monolith; standalone
 *    (no authenticator) degrades `wp_mcp_ai_auth_unavailable` (503) on
 *    token branches — nonce auth works in both modes. The security
 *    manager's IP/HTTPS/role/capability gates run monolith-only
 *    (standalone security guarantees come from the AI addon's ported
 *    security stack).
 *  - Chat pipeline + SSE handler are monolith-only seams (the base
 *    resolves them from its DI container); standalone `message/send`
 *    degrades with `a2a_processing_error` and `message/stream` with a
 *    JSON-RPC error until the standalone chat flow lands with the
 *    E1/D-UI-6 waves. Task tracking, cards, push configs, and webhook
 *    receive are fully functional standalone.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Rest
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Rest;

/**
 * REST controller for A2A protocol endpoints.
 *
 * @since 2.1.0
 */
class A2aRestController {

	/**
	 * A2A protocol version.
	 */
	const A2A_VERSION = '1.0';

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * A2A JSON-RPC methods supported.
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
	 * Authenticator instance (lazy loaded).
	 *
	 * @var object|null
	 */
	protected static $authenticator = null;

	/**
	 * Initialize REST routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes for A2A protocol.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// JSON-RPC 2.0 endpoint (primary A2A protocol binding).
		register_rest_route(
			self::REST_NAMESPACE,
			'/a2a',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_jsonrpc_request' ),
					'permission_callback' => array( __CLASS__, 'permissions_check_a2a' ),
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
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_agent_card_request' ),
					'permission_callback' => array( __CLASS__, 'permissions_check_agent_card' ),
				),
			)
		);

		// Per-assistant Agent Card endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/a2a/agent-card/(?P<assistant_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_per_assistant_card' ),
				'permission_callback' => array( __CLASS__, 'permissions_check_per_assistant_card' ),
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
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'permissions_check_a2a' ),
				'args'                => array(
					'type' => array(
						'description'       => __( 'Webhook event type.', 'nvoos-content-graph-ai-platform' ),
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'data' => array(
						'description' => __( 'Webhook payload data.', 'nvoos-content-graph-ai-platform' ),
						'type'        => 'object',
						'required'    => false,
					),
				),
			)
		);
	}

	// ── Permissions ───────────────────────────────────────────────────────────

	/**
	 * Permission check for A2A endpoints.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error True if permitted, error otherwise.
	 */
	public static function permissions_check_a2a( \WP_REST_Request $request ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Check if A2A is enabled.
		if ( empty( $settings['enable_a2a_server'] ) ) {
			return new \WP_Error(
				'a2a_disabled',
				__( 'A2A protocol is not enabled on this server.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		// Validate A2A-Version header.
		$version = $request->get_header( 'A2A-Version' );
		if ( $version && version_compare( $version, self::A2A_VERSION, '>' ) ) {
			return new \WP_Error(
				'a2a_version_not_supported',
				sprintf(
					/* translators: %s: supported version */
					__( 'A2A version not supported. This server supports version %s.', 'nvoos-content-graph-ai-platform' ),
					self::A2A_VERSION
				),
				array( 'status' => 400 )
			);
		}

		// Authenticate the request.
		return self::permissions_check_authenticated( $request );
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
	 * @param \WP_REST_Request $request The request (unused; kept for signature compatibility).
	 * @return true|\WP_Error True when A2A is enabled, WP_Error 403 otherwise.
	 */
	public static function permissions_check_agent_card( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Variable is intentionally unused in the current implementation but reserved for future A2A protocol extensions.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_a2a_server'] ) ) {
			return new \WP_Error(
				'a2a_disabled',
				__( 'A2A protocol is not enabled on this server.', 'nvoos-content-graph-ai-platform' ),
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
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error True if permitted, error otherwise.
	 */
	public static function permissions_check_per_assistant_card( \WP_REST_Request $request ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( empty( $settings['enable_a2a_server'] ) ) {
			return new \WP_Error(
				'a2a_disabled',
				__( 'A2A protocol is not enabled on this server.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 403 )
			);
		}

		// Require A2A authentication (nonce, bearer, or mesh key).
		return self::permissions_check_authenticated( $request );
	}

	/**
	 * Permission callback for authenticated requests.
	 *
	 * Port of the base controller's `permissions_check_authenticated`:
	 * authenticator + security-manager gates resolve per install mode
	 * (see class docblock for the standalone degradation contract).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return true|\WP_Error True if authenticated, WP_Error otherwise.
	 */
	protected static function permissions_check_authenticated( \WP_REST_Request $request ) {
		// Step 1: IP and HTTPS requirements (monolith security manager only).
		$security_manager = self::get_security_manager();
		if ( null !== $security_manager ) {
			$security_check = $security_manager->check_ip_access( self::get_client_ip() );
			if ( is_wp_error( $security_check ) ) {
				return $security_check;
			}

			$https_check = $security_manager->check_https_requirement();
			if ( is_wp_error( $https_check ) ) {
				return $https_check;
			}
		}

		$authenticator = self::get_authenticator();

		if ( null === $authenticator ) {
			// Standalone installs have no authenticator port yet — WordPress
			// nonce auth still works (logged-in users), while token branches
			// degrade instead of accepting unvalidated credentials.
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce ) {
				$nonce = $request->get_param( '_wpnonce' );
			}
			if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) && get_current_user_id() > 0 ) {
				return true;
			}

			return new \WP_Error(
				'wp_mcp_ai_auth_unavailable',
				__( 'Authentication service is unavailable. Please try again later.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 503 )
			);
		}

		// Step 2: Authenticate the request.
		$auth_result = $authenticator->authenticate( $request );

		if ( is_wp_error( $auth_result ) ) {
			return $auth_result;
		}

		// Step 3: Role and capability requirements (monolith security manager only).
		if ( null !== $security_manager ) {
			$authenticated_user_id = isset( $auth_result['user_id'] ) ? absint( $auth_result['user_id'] ) : 0;

			if ( $authenticated_user_id > 0 ) {
				if ( ! $security_manager->check_role_access( $authenticated_user_id ) ) {
					return new \WP_Error(
						'insufficient_role',
						__( 'Access denied: Your user role does not have permission to access this resource.', 'nvoos-content-graph-ai-platform' ),
						array( 'status' => 403 )
					);
				}

				if ( ! $security_manager->check_capability_requirement( $authenticated_user_id ) ) {
					return new \WP_Error(
						'insufficient_capability',
						__( 'Access denied: You do not have sufficient capabilities to access this resource.', 'nvoos-content-graph-ai-platform' ),
						array( 'status' => 403 )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	private static function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Proxy/load balancer.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Get first IP if multiple (X-Forwarded-For can contain multiple IPs).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	// ── JSON-RPC request handling ─────────────────────────────────────────────

	/**
	 * Handle JSON-RPC 2.0 requests for A2A protocol.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The JSON-RPC response.
	 */
	public static function handle_jsonrpc_request( \WP_REST_Request $request ) {
		$method    = $request->get_param( 'method' );
		$params    = $request->get_param( 'params' );
		$rpc_id    = $request->get_param( 'id' );
		$is_params = is_array( $params ) ? $params : array();

		// Route to appropriate handler.
		switch ( $method ) {
			case 'message/send':
				$result = self::handle_message_send( $is_params, $request );
				break;

			case 'message/stream':
				return self::handle_message_stream( $is_params, $request );

			case 'tasks/get':
				$result = self::handle_tasks_get( $is_params );
				break;

			case 'tasks/list':
				$result = self::handle_tasks_list( $is_params );
				break;

			case 'tasks/cancel':
				$result = self::handle_tasks_cancel( $is_params );
				break;

			case 'tasks/pushNotificationConfig/create':
				$result = self::handle_push_config_create( $is_params );
				break;

			case 'tasks/pushNotificationConfig/get':
				$result = self::handle_push_config_get( $is_params );
				break;

			case 'tasks/pushNotificationConfig/list':
				$result = self::handle_push_config_list( $is_params );
				break;

			case 'tasks/pushNotificationConfig/delete':
				$result = self::handle_push_config_delete( $is_params );
				break;

			case 'agent/authenticatedExtendedCard':
				$result = self::handle_extended_card();
				break;

			default:
				$result = new \WP_Error(
					'a2a_method_not_found',
					sprintf(
						/* translators: %s: method name */
						__( 'Unknown A2A method: %s', 'nvoos-content-graph-ai-platform' ),
						$method
					),
					array( 'status' => 400 )
				);
				break;
		}

		return self::build_jsonrpc_response( $rpc_id, $result );
	}

	/**
	 * Handle message/send — the primary A2A operation.
	 *
	 * @param array             $params  The JSON-RPC params.
	 * @param \WP_REST_Request $request The REST request.
	 * @return array|\WP_Error Task or Message object, or error.
	 */
	protected static function handle_message_send( $params, \WP_REST_Request $request ) {
		$message = isset( $params['message'] ) ? $params['message'] : null;
		if ( ! $message || ! isset( $message['parts'] ) || ! is_array( $message['parts'] ) ) {
			return new \WP_Error(
				'a2a_invalid_params',
				__( 'Invalid message: parts array is required.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Fires when an A2A message is received.
		 *
		 * @param array             $message The A2A message.
		 * @param \WP_REST_Request $request The REST request.
		 */
		do_action( 'wp_mcp_ai_a2a_message_received', $message, $request );

		// Translate A2A message to NV oOS chat format.
		$translated = self::translator_class()::a2a_to_chat( $params );
		$context_id = $translated['context_id'];
		$task_id    = $translated['task_id'];

		// If continuing an existing task, validate it.
		if ( $task_id ) {
			$existing = self::task_manager_class()::get_task( $task_id );
			if ( ! $existing ) {
				return new \WP_Error(
					'a2a_task_not_found',
					__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 404 )
				);
			}

			if ( self::task_manager_class()::is_terminal_state( $existing['status']['state'] ) ) {
				return new \WP_Error(
					'a2a_unsupported_operation',
					__( 'Cannot send messages to a task in a terminal state.', 'nvoos-content-graph-ai-platform' ),
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
			$task = self::task_manager_class()::add_message( $task_id, $a2a_message );
			if ( is_wp_error( $task ) ) {
				return $task;
			}
			self::task_manager_class()::transition_state( $task_id, self::task_manager_class()::STATE_WORKING );
		} else {
			// Create new task.
			$task    = self::task_manager_class()::create_task( $a2a_message, $context_id );
			$task_id = $task['id'];
			self::task_manager_class()::transition_state( $task_id, self::task_manager_class()::STATE_WORKING );
		}

		// Determine which assistant to use.
		$assistant_id = static::resolve_assistant( $params );

		// Process through the chat pipeline.
		$chat_result = static::process_chat( $translated['messages'], $assistant_id );

		if ( is_wp_error( $chat_result ) ) {
			$error_message = self::translator_class()::chat_to_a2a_message(
				$chat_result->get_error_message(),
				$context_id
			);
			self::task_manager_class()::add_message( $task_id, $error_message );
			self::task_manager_class()::transition_state(
				$task_id,
				self::task_manager_class()::STATE_FAILED,
				$error_message
			);

			return self::task_manager_class()::get_task( $task_id );
		}

		// Convert chat response to A2A format.
		$response_text = is_array( $chat_result ) && isset( $chat_result['content'] ) ? $chat_result['content'] : (string) $chat_result;
		$agent_message = self::translator_class()::chat_to_a2a_message( $response_text, $context_id );

		// Add response to task history.
		self::task_manager_class()::add_message( $task_id, $agent_message );

		// If there are structured results, add them as artifacts.
		if ( is_array( $chat_result ) && isset( $chat_result['tool_results'] ) ) {
			foreach ( $chat_result['tool_results'] as $tool_slug => $tool_result ) {
				$artifact = self::translator_class()::tool_result_to_artifact( $tool_result, $tool_slug );
				self::task_manager_class()::add_artifact( $task_id, $artifact );
			}
		}

		// Mark task as completed.
		self::task_manager_class()::transition_state(
			$task_id,
			self::task_manager_class()::STATE_COMPLETED,
			$agent_message
		);

		// Fire push notifications if configured.
		self::fire_push_notifications( $task_id );

		return self::task_manager_class()::get_task( $task_id );
	}

	/**
	 * Handle message/stream — streaming A2A operation.
	 *
	 * @param array             $params  The JSON-RPC params.
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	protected static function handle_message_stream( $params, \WP_REST_Request $request ) {
		$message = isset( $params['message'] ) ? $params['message'] : null;
		if ( ! $message || ! isset( $message['parts'] ) || ! is_array( $message['parts'] ) ) {
			return self::build_jsonrpc_response(
				$request->get_param( 'id' ),
				new \WP_Error(
					'a2a_invalid_params',
					__( 'Invalid message: parts array is required.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 400 )
				)
			);
		}

		// The base resolves its SSE handler from the DI container — monolith
		// only. Standalone installs degrade honestly until the SSE flow ports.
		$sse_handler = static::sse_handler();
		if ( null === $sse_handler ) {
			return self::build_jsonrpc_response(
				$request->get_param( 'id' ),
				new \WP_Error(
					'a2a_processing_error',
					__( 'Streaming chat pipeline not available.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 500 )
				)
			);
		}

		// Translate and create task.
		$translated = self::translator_class()::a2a_to_chat( $params );
		$context_id = $translated['context_id'];

		$a2a_message = array(
			'kind'      => 'message',
			'messageId' => isset( $message['messageId'] ) ? sanitize_text_field( $message['messageId'] ) : wp_generate_uuid4(),
			'role'      => 'user',
			'parts'     => $message['parts'],
		);

		$task    = self::task_manager_class()::create_task( $a2a_message, $context_id );
		$task_id = $task['id'];

		// Send SSE headers.
		$sse_handler->send_sse_headers();

		// Emit initial task event.
		$sse_handler->send_sse_event( 'message', $task );

		// Transition to working.
		self::task_manager_class()::transition_state( $task_id, self::task_manager_class()::STATE_WORKING );
		$status_update = self::translator_class()::build_status_update(
			$task_id,
			$task['contextId'],
			self::task_manager_class()::STATE_WORKING
		);
		$sse_handler->send_sse_event( 'message', $status_update );

		// Process through chat pipeline.
		$assistant_id = static::resolve_assistant( $params );
		$chat_result  = static::process_chat( $translated['messages'], $assistant_id );

		if ( is_wp_error( $chat_result ) ) {
			self::task_manager_class()::transition_state( $task_id, self::task_manager_class()::STATE_FAILED );
			$fail_update = self::translator_class()::build_status_update(
				$task_id,
				$task['contextId'],
				self::task_manager_class()::STATE_FAILED,
				null,
				true
			);
			$sse_handler->send_sse_event( 'message', $fail_update );
		} else {
			$response_text = is_array( $chat_result ) && isset( $chat_result['content'] ) ? $chat_result['content'] : (string) $chat_result;
			$agent_message = self::translator_class()::chat_to_a2a_message( $response_text, $task['contextId'] );
			self::task_manager_class()::add_message( $task_id, $agent_message );

			// Build and emit artifact if there are structured results.
			if ( is_array( $chat_result ) && isset( $chat_result['tool_results'] ) ) {
				foreach ( $chat_result['tool_results'] as $tool_slug => $tool_result ) {
					$artifact = self::translator_class()::tool_result_to_artifact( $tool_result, $tool_slug );
					self::task_manager_class()::add_artifact( $task_id, $artifact );

					$artifact_update = self::translator_class()::build_artifact_update(
						$task_id,
						$task['contextId'],
						$artifact
					);
					$sse_handler->send_sse_event( 'message', $artifact_update );
				}
			}

			// Mark completed.
			self::task_manager_class()::transition_state( $task_id, self::task_manager_class()::STATE_COMPLETED, $agent_message );
			$complete_update = self::translator_class()::build_status_update(
				$task_id,
				$task['contextId'],
				self::task_manager_class()::STATE_COMPLETED,
				$agent_message,
				true
			);
			$sse_handler->send_sse_event( 'message', $complete_update );
		}

		$sse_handler->send_sse_done();
		$sse_handler->finish();

		// Return empty response since SSE was sent directly.
		return new \WP_REST_Response( null, 200 );
	}

	/**
	 * Handle tasks/get.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|\WP_Error The task or error.
	 */
	protected static function handle_tasks_get( $params ) {
		$task_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		if ( empty( $task_id ) ) {
			return new \WP_Error(
				'a2a_invalid_params',
				__( 'Task ID is required.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$task = self::task_manager_class()::get_task( $task_id );
		if ( ! $task ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
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
	protected static function handle_tasks_list( $params ) {
		return self::task_manager_class()::list_tasks(
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
	 * @return array|\WP_Error Updated task or error.
	 */
	protected static function handle_tasks_cancel( $params ) {
		$task_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		if ( empty( $task_id ) ) {
			return new \WP_Error(
				'a2a_invalid_params',
				__( 'Task ID is required.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$result = self::task_manager_class()::cancel_task( $task_id );

		if ( ! is_wp_error( $result ) ) {
			self::fire_push_notifications( $task_id );
		}

		return $result;
	}

	/**
	 * Handle push notification config create.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|\WP_Error The created config or error.
	 */
	protected static function handle_push_config_create( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new \WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$task_id = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		if ( empty( $task_id ) || ! self::task_manager_class()::get_task( $task_id ) ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		$config = isset( $params['pushNotificationConfig'] ) ? $params['pushNotificationConfig'] : array();
		$url    = isset( $config['url'] ) ? esc_url_raw( $config['url'] ) : '';

		if ( empty( $url ) ) {
			return new \WP_Error(
				'a2a_invalid_params',
				__( 'Webhook URL is required.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		return self::push_notifications_class()::create_config( $task_id, $config );
	}

	/**
	 * Handle push notification config get.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|\WP_Error The config or error.
	 */
	protected static function handle_push_config_get( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new \WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$task_id   = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		$config_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		return self::push_notifications_class()::get_config( $task_id, $config_id );
	}

	/**
	 * Handle push notification config list.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|\WP_Error The configs or error.
	 */
	protected static function handle_push_config_list( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new \WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$task_id = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		return self::push_notifications_class()::list_configs( $task_id );
	}

	/**
	 * Handle push notification config delete.
	 *
	 * @param array $params The JSON-RPC params.
	 * @return array|\WP_Error Success or error.
	 */
	protected static function handle_push_config_delete( $params ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['a2a_enable_push_notifications'] ) ) {
			return new \WP_Error(
				'a2a_push_not_supported',
				__( 'Push notifications are not supported by this agent.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		$task_id   = isset( $params['taskId'] ) ? sanitize_text_field( $params['taskId'] ) : '';
		$config_id = isset( $params['id'] ) ? sanitize_text_field( $params['id'] ) : '';

		return self::push_notifications_class()::delete_config( $task_id, $config_id );
	}

	/**
	 * Handle agent/authenticatedExtendedCard.
	 *
	 * @return array The extended Agent Card.
	 */
	protected static function handle_extended_card() {
		return self::agent_card_class()::build_site_card();
	}

	/**
	 * Handle GET request for Agent Card.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The Agent Card response.
	 */
	public static function handle_agent_card_request( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Kept for handler signature compatibility with the REST server (byte-identical to the base).
		$card = self::agent_card_class()::build_site_card();
		return new \WP_REST_Response( $card, 200 );
	}

	/**
	 * Handle per-assistant Agent Card request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The Agent Card response.
	 */
	public static function handle_per_assistant_card( \WP_REST_Request $request ) {
		$assistant_id = $request->get_param( 'assistant_id' );
		$card         = self::agent_card_class()::build_card_for_assistant( $assistant_id );

		if ( is_wp_error( $card ) ) {
			$error_data = $card->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? $error_data['status'] : 404;

			return new \WP_REST_Response(
				array(
					'error'   => $card->get_error_code(),
					'message' => $card->get_error_message(),
				),
				$status
			);
		}

		return new \WP_REST_Response( $card, 200 );
	}

	/**
	 * Handle inbound webhook for push notifications from remote agents.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response Response.
	 */
	public static function handle_webhook( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		$result = self::webhook_handler_class()::handle_inbound( $body );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return new \WP_REST_Response( array( 'status' => 'received' ), 200 );
	}

	/**
	 * Build a JSON-RPC 2.0 response.
	 *
	 * @param mixed            $id     The request ID.
	 * @param array|\WP_Error  $result The result or error.
	 * @return \WP_REST_Response The JSON-RPC response.
	 */
	protected static function build_jsonrpc_response( $id, $result ) {
		$response = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
		);

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = isset( $error_data['status'] ) ? $error_data['status'] : 400;

			$response['error'] = array(
				'code'    => self::map_error_code( $result->get_error_code() ),
				'message' => $result->get_error_message(),
				'data'    => array(
					'type' => $result->get_error_code(),
				),
			);

			return new \WP_REST_Response( $response, $status );
		}

		$response['result'] = $result;
		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Map A2A error codes to JSON-RPC error codes.
	 *
	 * @param string $error_code The A2A error code.
	 * @return int The JSON-RPC error code.
	 */
	protected static function map_error_code( $error_code ) {
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
	protected static function resolve_assistant( $params ) {
		// Check if an assistant is specified in metadata.
		if ( isset( $params['metadata']['assistant_id'] ) ) {
			return absint( $params['metadata']['assistant_id'] );
		}

		// Use the default exposed assistant.
		$exposed = self::agent_card_class()::get_exposed_assistants();
		return ! empty( $exposed ) ? $exposed[0] : 0;
	}

	/**
	 * Process a message through the NV oOS chat pipeline.
	 *
	 * @param array $messages     Array of NV oOS chat messages.
	 * @param int   $assistant_id The assistant to use.
	 * @return array|\WP_Error Chat result or error.
	 */
	protected static function process_chat( $messages, $assistant_id ) {
		if ( ! $assistant_id ) {
			return new \WP_Error(
				'a2a_no_assistant',
				__( 'No assistant configured for A2A processing.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		// Validate assistant exists.
		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new \WP_Error(
				'a2a_invalid_assistant',
				__( 'Invalid assistant configuration.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 500 )
			);
		}

		// Monolith: the base REST controller owns the chat pipeline.
		$rest = static::chat_processor();
		if ( null !== $rest && method_exists( $rest, 'process_chat_request' ) ) {
			return $rest->process_chat_request( $messages, $assistant_id );
		}

		// Monolith fallback: the DI container router.
		$router = static::router();
		if ( null !== $router && method_exists( $router, 'route' ) ) {
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

		// Standalone: no chat pipeline yet — honest degradation (documented).
		return new \WP_Error(
			'a2a_processing_error',
			__( 'Chat pipeline not available.', 'nvoos-content-graph-ai-platform' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Fire push notifications for a task if any are configured.
	 *
	 * @param string $task_id The task ID.
	 * @return void
	 */
	protected static function fire_push_notifications( $task_id ): void {
		$class = self::push_notifications_class();
		if ( null !== $class && method_exists( $class, 'fire_notifications' ) ) {
			$class::fire_notifications( $task_id );
		}
	}

	// ── Per-mode collaborator seams ───────────────────────────────────────────

	/**
	 * Get or create the authenticator instance.
	 *
	 * Standalone installs have no authenticator port yet — the
	 * `wp_mcp_ai_auth_unavailable` degradation branches handle that. The
	 * resolution goes through `authenticator_class()` (late static
	 * binding) so tests can force the absent branch deterministically.
	 *
	 * @return object|null The authenticator instance or null if unavailable.
	 */
	protected static function get_authenticator() {
		if ( null === self::$authenticator ) {
			$class = static::authenticator_class();
			if ( null !== $class ) {
				self::$authenticator = new $class();
			}
		}
		return self::$authenticator;
	}

	/**
	 * Resolve the request authenticator class.
	 *
	 * The base plugin's authenticator owns credential validation in
	 * monolith installs (and resolves through the monorepo autoloader in
	 * the standalone test matrix); a real standalone install without the
	 * monorepo resolves null and the token branches degrade.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function authenticator_class(): ?string {
		if ( class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
			return 'WP_MCP_AI_REST_Authenticator';
		}

		return null;
	}

	/**
	 * Resolve the security manager (monolith only).
	 *
	 * The base controller's IP/HTTPS/role/capability gates run through
	 * `WP_MCP_AI_Security_Manager`; standalone installs rely on the AI
	 * addon's ported security stack instead (documented).
	 *
	 * @return object|null Security manager instance or null when unavailable.
	 */
	protected static function get_security_manager() {
		$class = static::security_manager_class();
		return null !== $class ? new $class() : null;
	}

	/**
	 * Resolve the security manager class.
	 *
	 * Boot-gated: the class resolves through the monorepo autoloader in
	 * both test matrices, but its constructor depends on base-only
	 * globals — so it may only instantiate when the base plugin is
	 * actually booted.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function security_manager_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Security_Manager' ) ) {
			return 'WP_MCP_AI_Security_Manager';
		}

		return null;
	}

	/**
	 * Resolve the A2A message translator class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function translator_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_A2A_Message_Translator' ) ) {
			return 'WP_MCP_AI_A2A_Message_Translator';
		}

		if ( class_exists( 'NvoosContentGraphAiPlatform\A2A\MessageTranslator' ) ) {
			return 'NvoosContentGraphAiPlatform\A2A\MessageTranslator';
		}

		return null;
	}

	/**
	 * Resolve the A2A task manager class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function task_manager_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_A2A_Task_Manager' ) ) {
			return 'WP_MCP_AI_A2A_Task_Manager';
		}

		if ( class_exists( 'NvoosContentGraphAiPlatform\A2A\TaskManager' ) ) {
			return 'NvoosContentGraphAiPlatform\A2A\TaskManager';
		}

		return null;
	}

	/**
	 * Resolve the A2A push notifications class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function push_notifications_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_A2A_Push_Notifications' ) ) {
			return 'WP_MCP_AI_A2A_Push_Notifications';
		}

		if ( class_exists( 'NvoosContentGraphAiPlatform\A2A\PushNotifications' ) ) {
			return 'NvoosContentGraphAiPlatform\A2A\PushNotifications';
		}

		return null;
	}

	/**
	 * Resolve the A2A agent card class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function agent_card_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_A2A_Agent_Card' ) ) {
			return 'WP_MCP_AI_A2A_Agent_Card';
		}

		if ( class_exists( 'NvoosContentGraphAiPlatform\A2A\AgentCard' ) ) {
			return 'NvoosContentGraphAiPlatform\A2A\AgentCard';
		}

		return null;
	}

	/**
	 * Resolve the A2A webhook handler class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function webhook_handler_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_A2A_Webhook_Handler' ) ) {
			return 'WP_MCP_AI_A2A_Webhook_Handler';
		}

		if ( class_exists( 'NvoosContentGraphAiPlatform\A2A\WebhookHandler' ) ) {
			return 'NvoosContentGraphAiPlatform\A2A\WebhookHandler';
		}

		return null;
	}

	/**
	 * Resolve the main REST controller for chat processing (monolith).
	 *
	 * The base resolves this from its DI container (`rest` service);
	 * standalone installs have no container — returns null and the
	 * documented degradation envelope applies.
	 *
	 * @return object|null The chat processor or null when unavailable.
	 */
	protected static function chat_processor() {
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			$rest      = $container->get( 'rest' );
			if ( $rest && method_exists( $rest, 'process_chat_request' ) ) {
				return $rest;
			}
		}

		return null;
	}

	/**
	 * Resolve the provider router from the DI container (monolith).
	 *
	 * @return object|null The router or null when unavailable.
	 */
	protected static function router() {
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			$router    = $container->get( 'router' );
			if ( $router && method_exists( $router, 'route' ) ) {
				return $router;
			}
		}

		return null;
	}

	/**
	 * Resolve the SSE handler from the DI container (monolith).
	 *
	 * @return object|null The SSE handler or null when unavailable.
	 */
	protected static function sse_handler() {
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container   = wp_mcp_ai_container();
			$sse_handler = $container->get( 'rest.sse_handler' );
			if ( $sse_handler ) {
				return $sse_handler;
			}
		}

		return null;
	}
}
