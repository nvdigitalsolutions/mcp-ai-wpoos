<?php
/**
 * Thread REST Controller — Registers CRUD endpoints for conversation threads.
 *
 * Endpoints:
 *   GET    /mcp-ai/v1/threads                           — List threads
 *   POST   /mcp-ai/v1/threads                           — Create thread
 *   DELETE /mcp-ai/v1/threads/(?P<id>\d+)               — Archive thread
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/restore       — Restore thread
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/summarize     — Summarize thread
 *   GET    /mcp-ai/v1/threads/(?P<id>\d+)/messages      — List messages
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/messages      — Send message (SSE)
 *   GET    /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints   — List checkpoints
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints   — Create checkpoint
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/restore — Restore checkpoint
 *   GET    /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/diff     — Get checkpoint diff
 *
 * @package WP_MCP_AI
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent fatal error if WP_REST_Controller is not available yet.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

/**
 * Class WP_MCP_AI_REST_Threads_Controller
 *
 * @since 1.7.0
 */
class WP_MCP_AI_REST_Threads_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Thread Manager instance.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Thread_Manager|null
	 */
	private $thread_manager;

	/**
	 * Main REST controller reference (for AI processing and SSE streaming).
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_REST|null
	 */
	private $main_controller;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_MCP_AI_REST|null $main_controller Main REST controller for AI processing.
	 */
	public function __construct( $main_controller = null ) {
		if ( class_exists( 'WP_MCP_AI_Thread_Manager' ) ) {
			$this->thread_manager = new WP_MCP_AI_Thread_Manager();
		}
		$this->main_controller = $main_controller;
	}

	/**
	 * Register REST API routes for threads.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_routes() {
		// Ensure thread manager tables exist.
		if ( $this->thread_manager ) {
			$this->maybe_create_tables();
		}

		// GET /threads — List threads.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_threads' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'default'           => 'active',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'active', 'archived', 'all' ),
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads — Create thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_thread' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'assistant_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'model'        => array(
						'type'    => 'object',
						'default' => array(),
					),
					'profile'      => array(
						'type'              => 'string',
						'default'           => 'write',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'scope'        => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		// DELETE /threads/(?P<id>\d+) — Archive thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'archive_thread' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/restore — Restore thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_thread' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/messages — Send a message and stream the AI response.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_post_message' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'               => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'content'          => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context_mentions' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'type'  => array( 'type' => 'string' ),
								'id'    => array( 'type' => array( 'string', 'integer' ) ),
								'title' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/summarize — Summarize (compact) thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/summarize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'summarize_thread' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /threads/(?P<id>\d+)/messages — List messages.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_messages' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /threads/(?P<id>\d+)/checkpoints — List checkpoints.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkpoints' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/checkpoints — Create checkpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checkpoint' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'           => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'label'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'affected_ids' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type' => 'integer',
						),
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/restore — Restore checkpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_checkpoint' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'cid' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/diff — Get checkpoint diff.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/diff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkpoint_diff' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'cid' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	// ──────────────────────────────────────────────
	// Permission callbacks
	// ──────────────────────────────────────────────

	/**
	 * Check if user has permission to access threads.
	 *
	 * @since 1.7.0
	 *
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access threads.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access threads.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if user has permission to access a specific thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_thread_permission( $request ) {
		$base_check = $this->check_permission();
		if ( is_wp_error( $base_check ) ) {
			return $base_check;
		}

		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	// ──────────────────────────────────────────────
	// Endpoint callbacks
	// ──────────────────────────────────────────────

	/**
	 * List threads.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_threads( $request ) {
		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$status   = $request->get_param( 'status' );
		$per_page = $request->get_param( 'per_page' );
		$user_id  = get_current_user_id();

		$result = $this->thread_manager->list_threads( $user_id, $status, 1, $per_page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create a new thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_thread( $request ) {
		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$user_id      = get_current_user_id();
		$assistant_id = $request->get_param( 'assistant_id' );
		$model        = $request->get_param( 'model' );
		$profile      = $request->get_param( 'profile' );
		$scope        = $request->get_param( 'scope' );

		// Ensure model and scope are arrays.
		if ( ! is_array( $model ) ) {
			$model = array();
		}
		if ( ! is_array( $scope ) ) {
			$scope = array();
		}

		$result = $this->thread_manager->create_thread( $user_id, $assistant_id, $model, $profile, $scope );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Archive a thread (soft delete).
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function archive_thread( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->archive_thread( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Restore an archived thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_thread( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->restore_thread( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Summarize (compact) a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function summarize_thread( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->summarize_thread( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get messages for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_messages( $request ) {
		$thread_id = $request->get_param( 'id' );
		$page      = $request->get_param( 'page' );
		$per_page  = $request->get_param( 'per_page' );

		$result = $this->thread_manager->get_messages( $thread_id, $page, $per_page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Post a message to a thread and stream the AI response via SSE.
	 *
	 * This is the core chat endpoint for the thread-based SPA.  It saves the
	 * user message, retrieves the thread context, builds a chat request, and
	 * delegates to the main REST controller for AI processing with SSE streaming.
	 *
	 * The assistant response is saved to the thread after the SSE stream
	 * completes (if the chat handler returns without exiting).
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Streams SSE events or returns error.
	 */
	public function handle_post_message( $request ) {
		$thread_id        = (int) $request->get_param( 'id' );
		$content          = (string) $request->get_param( 'content' );
		$context_mentions = $request->get_param( 'context_mentions' );

		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$thread = $this->thread_manager->get_thread( $thread_id );
		if ( ! $thread ) {
			return new WP_Error(
				'wp_mcp_ai_thread_not_found',
				__( 'Thread not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// 1. Save the user message to the thread.
		$user_msg_result = $this->thread_manager->add_message( $thread_id, 'user', $content );
		if ( is_wp_error( $user_msg_result ) ) {
			return $user_msg_result;
		}

		// 2. Get thread context (up to 50 recent messages).
		$thread_context = $this->thread_manager->get_thread_context( $thread_id, 50 );

		// 3. Build the messages array for the AI provider.
		$messages = array();
		foreach ( $thread_context as $msg ) {
			$messages[] = array(
				'role'    => $msg['role'],
				'content' => $msg['content'],
			);
		}

		// 4. Determine the assistant and model from the thread or fall back to defaults.
		$assistant_id   = (int) ( $thread['assistant_id'] ?? 0 );
		$model_name     = ! empty( $thread['model_name'] ) ? $thread['model_name'] : '';
		$model_provider = ! empty( $thread['model_provider'] ) ? $thread['model_provider'] : '';

		// Resolve assistant configuration.
		$assistant_config = array();
		if ( $assistant_id > 0 && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		}

		// If the thread has a specific model, override the assistant config.
		if ( '' !== $model_provider && '' !== $model_name ) {
			$assistant_config['provider'] = $model_provider;
			$assistant_config['model']    = $model_name;
		}

		// If we still have no assistant, use the site default.
		if ( $assistant_id < 1 ) {
			$settings         = get_option( 'wp_mcp_ai_settings', array() );
			$assistant_id     = ! empty( $settings['default_assistant_id'] ) ? absint( $settings['default_assistant_id'] ) : 0;
			$assistant_config = $assistant_id > 0 && class_exists( 'WP_MCP_AI_Assistant_CPT' )
				? WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id )
				: array();
		}

		// If there is still no assistant and no model configured, return an error.
		if ( $assistant_id < 1 && empty( $assistant_config['provider'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_assistant',
				__( 'No assistant is configured. Please select a model or set a default assistant in NV oOS settings.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// 5. Delegate to the main controller for AI processing.
		if ( ! $this->main_controller ) {
			return new WP_Error(
				'wp_mcp_ai_chat_unavailable',
				__( 'Chat service is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		// Build a synthetic REST request to pass through the main chat pipeline.
		$chat_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$chat_request->set_param( 'assistant_id', $assistant_id );
		$chat_request->set_param( 'messages', $messages );
		$chat_request->set_param( 'stream', true );
		if ( '' !== $model_name ) {
			$chat_request->set_param( 'model', $model_name );
		}
		if ( '' !== $model_provider ) {
			$chat_request->set_param( 'provider', $model_provider );
		}

		/*
		 * 6. Delegate to chat controller for SSE streaming.
		 *
		 * handle_chat_client_request → handle_chat_request →
		 * handle_chat_request_with_streaming which streams SSE and calls
		 * finish_sse() → exit().
		 *
		 * We hook wp_mcp_ai_after_chat_response (fires right before exit)
		 * to save the assistant message and create a checkpoint.
		 */
		$chat_controller = new WP_MCP_AI_REST_Chat_Controller(
			$this->main_controller
		);

		$saved_message_id    = 0;
		$saved_checkpoint_id = 0;
		$capture_thread_id   = $thread_id;
		$capture_tm          = $this->thread_manager;

		$capture_callback = function ( $assistant_id, $response, $request ) use ( $capture_thread_id, $capture_tm, &$saved_message_id, &$saved_checkpoint_id ) {
			unset( $request ); // Required by hook signature, not used here.

			// Extract the assistant message content from the response.
			$content = '';
			if ( is_array( $response ) && isset( $response['choices'][0]['message']['content'] ) ) {
				$content = $response['choices'][0]['message']['content'];
			} elseif ( is_string( $response ) ) {
				$content = $response;
			}

			if ( '' !== $content ) {
				$result = $capture_tm->add_message( $capture_thread_id, 'assistant', $content );
				if ( ! is_wp_error( $result ) && ! empty( $result['data']['message_id'] ) ) {
					$saved_message_id = $result['data']['message_id'];
					$cp_result        = $capture_tm->create_checkpoint( $capture_thread_id );
					if ( ! is_wp_error( $cp_result ) && ! empty( $cp_result['data']['checkpoint_id'] ) ) {
						$saved_checkpoint_id = $cp_result['data']['checkpoint_id'];
					}
				}
			}
		};

		add_action( 'wp_mcp_ai_after_chat_response', $capture_callback, 100, 3 );

		// Process the chat request.
		// In the streaming path this calls finish_sse() → exit().
		// In the non-streaming path it returns WP_REST_Response/WP_Error.
		$response = $chat_controller->handle_chat_client_request( $chat_request );

		remove_action( 'wp_mcp_ai_after_chat_response', $capture_callback, 100 );

		// Non-streaming fallback: if we get here, save the response if not already saved.
		if ( 0 === $saved_message_id && ! is_wp_error( $response ) ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['choices'][0]['message']['content'] ) ) {
				$this->thread_manager->add_message( $capture_thread_id, 'assistant', $data['choices'][0]['message']['content'] );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Get checkpoints for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkpoints( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->get_checkpoints( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create a checkpoint for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_checkpoint( $request ) {
		$thread_id    = $request->get_param( 'id' );
		$label        = $request->get_param( 'label' );
		$affected_ids = $request->get_param( 'affected_ids' );

		if ( ! is_array( $affected_ids ) ) {
			$affected_ids = array();
		}

		$result = $this->thread_manager->create_checkpoint( $thread_id, $label, $affected_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Restore a checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_checkpoint( $request ) {
		$thread_id     = $request->get_param( 'id' );
		$checkpoint_id = $request->get_param( 'cid' );

		$result = $this->thread_manager->restore_checkpoint( $thread_id, $checkpoint_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get diff data for a checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkpoint_diff( $request ) {
		$thread_id     = $request->get_param( 'id' );
		$checkpoint_id = $request->get_param( 'cid' );

		$result = $this->thread_manager->get_checkpoint_diff( $thread_id, $checkpoint_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Ensure the thread database tables exist.
	 *
	 * Uses a transient to avoid checking on every request.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function maybe_create_tables() {
		$transient_key = 'wp_mcp_ai_thread_tables_created';
		if ( get_transient( $transient_key ) ) {
			return;
		}

		WP_MCP_AI_Thread_Manager::create_tables();
		set_transient( $transient_key, true, DAY_IN_SECONDS );
	}
}
