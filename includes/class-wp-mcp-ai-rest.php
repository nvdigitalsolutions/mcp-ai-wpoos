<?php
/**
 * REST API controller for WP oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-mcp-methods.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-llm-sanitizer-interface.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-controller-base.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-chat-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-mcp-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-tools-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-authenticator.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-handler.php';

if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
	/**
	 * Registers the plugin's REST API endpoints.
	 */
	class WP_MCP_AI_REST {
		use WP_MCP_AI_REST_MCP_Methods;

		const REST_NAMESPACE              = 'mcp-ai/v1';
		const MEMORY_MAX_DOCUMENT_CHARS   = 4000;
		const MEMORY_CHUNK_CHARS          = 1200;
		const MEMORY_MAX_TOTAL_CHARS      = 12000;
		const MEMORY_MAX_FILE_BYTES       = 5242880; // 5MB default memory file size limit.
		const MEMORY_MAX_DOCUMENT_BYTES   = 262144; // ~256KB, enough headroom for markup around 4K characters of text.
		const MEMORY_MAX_TOTAL_BYTES      = 1048576; // ~1MB aggregate streaming budget across attachments.
		const CHAT_MAX_REQUEST_TOKENS     = 480000;  // Fallback ceiling when no model-specific limit is available.
		const CHAT_APPROX_CHARS_PER_TOKEN = 4;     // Rough heuristic used when trimming oversized chats.
		const TPM_SAFETY_MARGIN           = 0.8;   // Use 80% of TPM limit as target when truncating messages.
		const TPM_FALLBACK_TOKENS         = 100000; // Fallback token target if no TPM limit configured.
		const STREAMING_CHUNK_SIZE        = 50;    // Characters per chunk for simulated streaming.
		const STREAMING_CHUNK_DELAY_US    = 10000; // Microseconds delay between streaming chunks (10ms).

		/**
		 * SSE job monitoring configuration constants.
		 *
		 * @since 1.0.0
		 */
		const SSE_JOB_MAX_POLLS           = 120;   // Maximum number of status polls (120 * 3s = 6 minutes).
		const SSE_JOB_POLL_INTERVAL       = 3;     // Seconds between status polls.
		const SSE_JOB_HEARTBEAT_INTERVAL  = 5;     // Send heartbeat every N polls (5 * 3s = 15 seconds).

		/**
		 * Tool slug used for document + prompt submissions.
		 *
		 * Requests that include attachments are temporarily granted access to this
		 * tool so the OpenAI client can forward the files without requiring admins
		 * to manually toggle the capability for every assistant.
		 */
		const DOCUMENT_PROMPT_TOOL_SLUG = 'submit_document_prompt';

		/**
		 * Tool registry instance.
		 *
		 * @var WP_MCP_AI_Tool_Registry
		 */
		protected $registry;

		/**
		 * Language model router.
		 *
		 * @var WP_MCP_AI_Language_Model_Router
		 */
		protected $client;

		/**
		 * Authentication handler.
		 *
		 * @var WP_MCP_AI_REST_Authenticator
		 */
		protected $authenticator;

		/**
		 * Request validator and sanitizer.
		 *
		 * @var WP_MCP_AI_REST_Validator
		 */
		protected $validator;

		/**
		 * Server-Sent Events handler.
		 *
		 * @var WP_MCP_AI_SSE_Handler
		 */
		protected $sse_handler;

		/**
		 * Chat service.
		 *
		 * @var WP_MCP_AI_Chat_Service
		 */
		protected $chat_service;

		/**
		 * Assistant service.
		 *
		 * @var WP_MCP_AI_Assistant_Service
		 */
		protected $assistant_service;

		/**
		 * Tool service.
		 *
		 * @var WP_MCP_AI_Tool_Service
		 */
		protected $tool_service;

		/**
		 * File service.
		 *
		 * @var WP_MCP_AI_File_Service
		 */
		protected $file_service;

		/**
		 * Transcript repository.
		 *
		 * @var WP_MCP_AI_Transcript_Repository
		 */
		protected $transcript_repository;

		/**
		 * OpenAI client (lazy-loaded).
		 *
		 * @var WP_MCP_AI_OpenAI_Client
		 */
		protected $openai_client;

		/**
		 * Cron Status Service (lazy-loaded).
		 *
		 * @var WP_MCP_AI_Cron_Status_Service
		 */
		protected $cron_status_service;

		/**
		 * Tracks authentication details for the current request.
		 *
		 * @var array
		 */
		protected $auth_context = array();

		/**
		 * Request-scoped cache for validated assistants to reduce duplicate lookups.
		 *
		 * @var array
		 */
		protected $assistant_cache = array();

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Tool_Registry         $registry      Tool registry instance.
		 * @param WP_MCP_AI_Language_Model_Router $client        Language model router.
		 * @param WP_MCP_AI_REST_Authenticator    $authenticator REST authenticator (optional, for DI).
		 * @param WP_MCP_AI_REST_Validator        $validator     REST validator (optional, for DI).
		 * @param WP_MCP_AI_SSE_Handler           $sse_handler   SSE handler (optional, for DI).
		 */
		public function __construct( WP_MCP_AI_Tool_Registry $registry, WP_MCP_AI_Language_Model_Router $client, $authenticator = null, $validator = null, $sse_handler = null ) {
			$this->registry = $registry;
			$this->client   = $client;

			// Use dependency injection or fall back to creating instances (backward compatibility).
			$this->authenticator = $authenticator ?? new WP_MCP_AI_REST_Authenticator();
			$this->validator     = $validator ?? new WP_MCP_AI_REST_Validator();
			$this->sse_handler   = $sse_handler ?? new WP_MCP_AI_SSE_Handler();
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
			add_action( 'rest_api_init', array( $this, 'clean_output_buffer' ), 1 );

			// Register Token Manager REST endpoints.
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-token-manager.php';
			add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Token_Manager', 'register_routes' ) );

			// Register Cost Manager REST endpoints.
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-cost-manager.php';
			add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Cost_Manager', 'register_routes' ) );

			// Register Analytics Manager REST endpoints.
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-analytics-manager.php';
			add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Analytics_Manager', 'register_routes' ) );

			add_filter( 'rest_request_after_callbacks', array( $this, 'format_actionable_error' ), 10, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'augment_error_actions' ), 10, 3 );
			add_filter( 'rest_pre_serve_request', array( $this, 'ensure_clean_json_output' ), 10, 4 );
		}

		/**
		 * Clean all output buffers.
		 *
		 * Helper method to reduce code duplication.
		 * Includes safety measures to prevent infinite loops.
		 */
		private function clean_all_output_buffers() {
			$max_iterations = 100; // Safety limit.
			$iterations     = 0;

			while ( ob_get_level() > 0 && $iterations < $max_iterations ) {
				if ( ! ob_end_clean() ) {
					break; // If ob_end_clean fails, stop trying.
				}
				++$iterations;
			}
		}

		/**
		 * Clean output buffer before REST API processing.
		 *
		 * Prevents PHP errors/warnings from contaminating JSON responses.
		 */
		public function clean_output_buffer() {
			// Check if we're handling a REST request for our namespace.
			// Use rest_get_url_prefix() to handle subdirectory installations.
			$rest_prefix = rest_get_url_prefix();
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			// Parse and validate URI safely.
			$parsed_uri = wp_parse_url( $request_uri, PHP_URL_PATH );
			if ( ! $parsed_uri || false === strpos( $parsed_uri, '/' . $rest_prefix . '/' . self::REST_NAMESPACE ) ) {
				return;
			}

			// Clean any existing output and start fresh.
			$this->clean_all_output_buffers();
			ob_start();
		}

		/**
		 * Ensure clean JSON output before serving REST response.
		 *
		 * @param bool             $served  Whether the request has already been served.
		 * @param WP_HTTP_Response $result  Result to send to the client.
		 * @param WP_REST_Request  $request Request used to generate the response.
		 * @param WP_REST_Server   $server  Server instance.
		 * @return bool
		 */
		public function ensure_clean_json_output( $served, $result, $request, $server ) {
			// Only process our endpoints.
			$route = $request->get_route();
			if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
				return $served;
			}

			// Clean any stray output before serving.
			$this->clean_all_output_buffers();

			return $served;
		}

		/**
		 * Ensure permission errors expose actionable guidance for MCP routes.
		 *
		 * @param mixed           $response Result from the endpoint callbacks.
		 * @param array           $handler  Route handler configuration.
		 * @param WP_REST_Request $request  Current REST request.
		 * @return mixed
		 */
		public function format_actionable_error( $response, $handler, $request ) {
			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! $request instanceof WP_REST_Request ) {
				return $response;
			}

			$route = $request->get_route();
			if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
				return $response;
			}

			$data = $response->get_error_data();
			if ( ! is_array( $data ) || empty( $data['actions'] ) ) {
				return $response;
			}

			$status = isset( $data['status'] ) ? (int) $data['status'] : 500;

			$payload = array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'actions' => $data['actions'],
				'data'    => $data,
			);

			return new WP_REST_Response( $payload, $status );
		}

		/**
		 * Ensure actionable guidance is surfaced at the top-level of REST error responses.
		 *
		 * @param WP_REST_Response $response Response object.
		 * @param WP_REST_Server   $server   REST server instance.
		 * @param WP_REST_Request  $request  Original request object.
		 * @return WP_REST_Response
		 */
		public function augment_error_actions( $response, $server, $request ) {
			if ( ! $response instanceof WP_REST_Response ) {
				return $response;
			}

			if ( ! $request instanceof WP_REST_Request ) {
				return $response;
			}

			$route = $request->get_route();
			if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE ) ) {
				return $response;
			}

			$data = $response->get_data();
			if ( ! is_array( $data ) ) {
				return $response;
			}

			if ( isset( $data['actions'] ) ) {
				return $response;
			}

			if ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data']['actions'] ) ) {
				$data['actions'] = $data['data']['actions'];
				$response->set_data( $data );
			}

			return $response;
		}

		/**
		 * Reset the stored authentication context for the current request.
		 */
		protected function reset_auth_context() {
			$this->authenticator->reset_auth_context();
			$this->auth_context = $this->authenticator->get_auth_context();
		}

		/**
		 * Persist information about token-based authentication.
		 *
		 * @param string $type    Authentication method identifier.
		 * @param array  $context Additional context information.
		 */
		protected function mark_token_authenticated( $type, $context = array() ) {
			$this->authenticator->mark_token_authenticated( $type, $context );
			$this->auth_context = $this->authenticator->get_auth_context();
		}

		/**
		 * Store the resolved WordPress user ID for the request.
		 *
		 * @param int $user_id WordPress user identifier.
		 */
		protected function set_authenticated_user_id( $user_id ) {
			$this->authenticator->set_authenticated_user_id( $user_id );
			$this->auth_context = $this->authenticator->get_auth_context();
		}

		/**
		 * Retrieve the authentication context for the current request.
		 *
		 * @return array
		 */
		protected function get_auth_context() {
			$this->auth_context = $this->authenticator->get_auth_context();
			return $this->auth_context;
		}

		/**
		 * Register REST API routes.
		 */
		public function register_routes() {
			// Delegate chat routes to Chat Controller (Phase 3.2).
			$chat_controller = new WP_MCP_AI_REST_Chat_Controller( $this, $this->authenticator, $this->validator );
			$chat_controller->register_routes();

			// Delegate MCP protocol routes to MCP Controller (Phase 3.3).
			$mcp_controller = new WP_MCP_AI_REST_MCP_Controller( $this, $this->authenticator, $this->validator );
			$mcp_controller->register_routes();

			// Delegate tools and admin routes to Tools Controller (Phase 3.4).
			$tools_controller = new WP_MCP_AI_REST_Tools_Controller( $this, $this->authenticator, $this->validator );
			$tools_controller->register_routes();

			// Note: /assistants route now handled by MCP Controller (Phase 3.3).

			// Note: /chat route now handled by Chat Controller (Phase 3.2).

			// Note: /chat-client route now handled by Chat Controller (Phase 3.2).

			// Note: /chat-transcripts routes now handled by Chat Controller (Phase 3.2).

			// Note: /tools route now handled by Tools Controller (Phase 3.4).

			// Note: /files/{file_id}/download route now handled by Tools Controller (Phase 3.4).

			// Note: /cron-status route now handled by Tools Controller (Phase 3.4).

			// Note: /mcp route now handled by MCP Controller (Phase 3.3).
		}

		/**
		 * Permission callback for chat transcript endpoints.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return bool|WP_Error True if authorized, WP_Error otherwise.
		 */
		public function chat_transcripts_permissions_check( WP_REST_Request $request ) {
			$user_id      = absint( $request->get_param( 'user_id' ) );
			$current_user = get_current_user_id();

			// Check for guest token authentication.
			$guest_token = $this->extract_guest_token( $request );
			if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				$assistant_id    = absint( $request->get_param( 'assistant_id' ) );
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id );

				if ( $guest_assistant ) {
					// Guest users (not logged in) can access their own transcripts (user_id = 0).
					// Set user_id to 0 if not explicitly provided, matching how chat transcripts are saved for guests.
					if ( ! $user_id ) {
						$user_id = 0;
						$request->set_param( 'user_id', $user_id );
					}

					// Allow guest users to access transcripts with user_id = 0.
					if ( 0 === $user_id ) {
						return true;
					}
				}
			}

			if ( ! $user_id && $current_user ) {
				$user_id = $current_user;
				$request->set_param( 'user_id', $user_id );
			}

			// Verify nonce for logged-in users.
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( $current_user ) {
				if ( empty( $nonce ) ) {
					return new WP_Error(
						'wp_mcp_ai_missing_nonce',
						__( 'Authentication nonce is required. Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ).', 'wp-mcp-ai' ),
						array( 'status' => 401 )
					);
				}

				if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return new WP_Error(
						'rest_invalid_nonce',
						__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
						array( 'status' => 403 )
					);
				}
			}

			if ( $user_id && $current_user && $user_id === $current_user ) {
				if ( ! is_user_logged_in() ) {
					return new WP_Error(
						'wp_mcp_ai_forbidden',
						__( 'You do not have permission to view chat transcripts.', 'wp-mcp-ai' ),
						array( 'status' => 403 )
					);
				}

				return true;
			}

			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view chat transcripts.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Handle chat transcript lookup requests.
		 *
		 * @param WP_REST_Request $request Request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_transcripts( WP_REST_Request $request ) {
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
					__( 'A valid user is required to query chat transcripts. Please log in to view your chat history.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$session_key  = $this->normalise_transcript_session_key( $request->get_param( 'session_key' ) );
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
				$session = $this->get_transcript_session( $user_id, $session_key, $assistant_id );

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

					if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
						return rest_ensure_response(
							array(
								'session' => null,
								'message' => $session->get_error_message(),
							)
						);
					}

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

			$sessions = $this->get_transcript_sessions( $user_id, $per_page, $page, $assistant_id );

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
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_transcript_save( WP_REST_Request $request ) {
			$this->hydrate_request_body_params( $request );

			$assistant_id = absint( $request->get_param( 'assistant_id' ) );
			$session_key  = $this->validator->sanitize_session_key_param( $request->get_param( 'session_key' ) );
			$messages     = $request->get_param( 'messages' );

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
			$assistant_post = $this->validate_assistant_access( $assistant_id );
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
			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				WP_MCP_AI_Chat_Transcript_Recorder::record(
					$assistant_id,
					$clean_messages,
					array( 'model' => $model ),
					$response,
					$request,
					$user_id,
					$context
				);
			}

			return rest_ensure_response(
				array(
					'success'     => true,
					'session_key' => $session_key,
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
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_transcript_get( WP_REST_Request $request ) {
			$session_key  = $this->normalise_transcript_session_key( $request->get_param( 'session_key' ) );
			$assistant_id = absint( $request->get_param( 'assistant_id' ) );
			$user_id      = absint( $request->get_param( 'user_id' ) );

			if ( '' === $session_key ) {
				return new WP_Error(
					'wp_mcp_ai_transcripts_invalid_session',
					__( 'A valid session key is required to retrieve a transcript.', 'wp-mcp-ai' ),
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
					__( 'A valid user is required to retrieve chat transcripts. Please log in to view your chat history.', 'wp-mcp-ai' ),
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

			$session = $this->get_transcript_session( $user_id, $session_key, $assistant_id );

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

				if ( 'wp_mcp_ai_transcripts_unavailable' === $session->get_error_code() ) {
					return rest_ensure_response(
						array(
							'session' => null,
							'message' => $session->get_error_message(),
						)
					);
				}

				return $session;
			}

			return rest_ensure_response( array( 'session' => $session ) );
		}

		/**
		 * Handle deletion of a chat transcript session.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_transcript_delete( WP_REST_Request $request ) {
			$session_key = $this->normalise_transcript_session_key( $request->get_param( 'session_key' ) );

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

			$repository = $this->get_transcript_repository();
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
				return new WP_Error(
					'wp_mcp_ai_transcripts_delete_failed',
					__( 'Failed to delete the transcript.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'deleted' => $deleted,
					'message' => __( 'Transcript deleted successfully.', 'wp-mcp-ai' ),
				)
			);
		}

		/**
		 * Handle cron status request
		 *
		 * Returns lightweight status information about cron jobs.
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error Response object or error.
		 */
		public function handle_cron_status_request( WP_REST_Request $request ) {
			// Load the cron status service.
			if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
			}

			$service = $this->get_cron_status_service();

			// Get authenticated user ID from auth context (supports bearer tokens, nonces, mesh keys).
			$auth_context = $this->get_auth_context();
			$user_id      = isset( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : get_current_user_id();

			$limit = $request->get_param( 'limit' );
			if ( ! $limit ) {
				$limit = 10;
			}

			$assistant_id = $request->get_param( 'assistant_id' );
			if ( $assistant_id ) {
				$assistant_id = absint( $assistant_id );
			}

			// Get status summary and counts with optional assistant filter.
			$jobs   = $service->get_status_summary( $user_id, $limit, $assistant_id );
			$counts = $service->get_status_counts( $user_id, $assistant_id );

			$response = array(
				'jobs'   => $jobs,
				'counts' => $counts,
			);

			// Include assistant_id in response if filtered.
			if ( $assistant_id ) {
				$response['assistant_id'] = $assistant_id;
			}

			// Check if SSE streaming was requested.
			if ( $this->sse_handler && $this->sse_handler->request_wants_event_stream( $request ) ) {
				// Return cron status as SSE snapshot (one-shot response).
				// For continuous job monitoring, use /cron-status/{job_id}?stream=true instead.
				return $this->sse_handler->stream_event_stream_payload( $response, 'cron_status' );
			}

			return rest_ensure_response( $response );
		}

		/**
		 * Handle GET /cron-status/{job_id} request.
		 *
		 * Returns detailed information about a specific cron job.
		 * Supports SSE streaming for real-time updates when requested.
		 * Follows SOC by delegating to cron status service for data retrieval.
		 *
		 * @param WP_REST_Request $request REST request object.
		 * @return WP_REST_Response|WP_Error Response object.
		 */
		public function handle_cron_job_details_request( WP_REST_Request $request ) {
			// Load the cron status service.
			if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
			}

			$service = $this->get_cron_status_service();

			// Get authenticated user ID from auth context (supports bearer tokens, nonces, mesh keys).
			$auth_context = $this->get_auth_context();
			$user_id      = isset( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : get_current_user_id();

			// Get job ID from URL parameter.
			$job_id = $request->get_param( 'job_id' );

			// Get job details from service (includes permission check).
			$job_details = $service->get_job_details( $job_id, $user_id );

			if ( is_wp_error( $job_details ) ) {
				return $job_details;
			}

			// Check if SSE streaming was requested.
			if ( $this->sse_handler && $this->sse_handler->request_wants_event_stream( $request ) ) {
				// Stream job status updates via SSE until completion or timeout.
				return $this->stream_job_status_updates( $job_details, $job_id, $service, $user_id );
			}

			return rest_ensure_response( $job_details );
		}

		/**
		 * Stream job status updates via SSE.
		 *
		 * Keeps the SSE connection open and periodically polls for job status updates,
		 * sending events to the client as the job progresses from pending → polling → completed.
		 *
		 * @param array                          $initial_details Initial job details.
		 * @param string                         $job_id          Job identifier.
		 * @param WP_MCP_AI_Cron_Status_Service $service         Cron status service instance.
		 * @param int                            $user_id         User ID for permission checks.
		 * @return WP_REST_Response Response with SSE streaming configured.
		 */
		protected function stream_job_status_updates( $initial_details, $job_id, $service, $user_id ) {
			// Send SSE headers and initialize streaming.
			$this->sse_handler->send_sse_headers();

			// Send initial status.
			$this->sse_handler->send_sse_event( 'cron_job_status', $initial_details );

			// Check if job is already in a terminal state.
			$status = isset( $initial_details['status'] ) ? $initial_details['status'] : 'unknown';
			if ( in_array( $status, array( 'completed', 'failed' ), true ) ) {
				// Job is already done, send completion marker and exit.
				$this->sse_handler->send_sse_done();
				exit;
			}

			// Poll for updates until job completes or times out.
			$max_polls     = self::SSE_JOB_MAX_POLLS;
			$poll_interval = self::SSE_JOB_POLL_INTERVAL;
			$poll_count    = 0;
			$last_status   = $status;

			// Extend PHP execution time limit for long-running SSE connection.
			// The polling loop can run up to 6 minutes (120 polls × 3 seconds).
			// Default WordPress/PHP timeout is often 30 seconds, which would kill the connection.
			// Calculate required time: (max_polls * poll_interval) + buffer for processing.
			$required_time = ( $max_polls * $poll_interval ) + 60; // 6 minutes + 1 minute buffer = 420 seconds.

			// Set timeout if function exists. Some hosting environments disable set_time_limit
			// for security reasons (safe mode, disable_functions in php.ini).
			// Silencing errors because set_time_limit may trigger:
			// - Warning when disabled in php.ini (disable_functions)
			// - Warning when safe mode is enabled
			// - Warning when running as Apache module with certain configurations
			// These warnings are expected and can be safely ignored as we're providing
			// a best-effort timeout extension for SSE streaming.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $required_time ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			while ( $poll_count < $max_polls ) {
				// Wait before next poll.
				sleep( $poll_interval );
				++$poll_count;

				// Trigger WordPress cron to ensure async jobs continue processing.
				// WordPress cron only runs on page loads by default. When a client
				// is waiting on an SSE connection, no new page loads occur, so cron
				// jobs (including veo video polling) may not run. Calling spawn_cron()
				// ensures any scheduled cron events execute, allowing the job to progress.
				// We call this periodically (every heartbeat interval) to balance
				// responsiveness with avoiding excessive cron triggers.
				// Note: spawn_cron() is non-blocking and returns quickly; failures
				// are silently ignored to prevent disrupting the SSE polling loop.
				if ( 0 === $poll_count % self::SSE_JOB_HEARTBEAT_INTERVAL && function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}

				// Get updated job details.
				$updated_details = $service->get_job_details( $job_id, $user_id );

				// Handle errors (permissions changed, job deleted, etc.).
				if ( is_wp_error( $updated_details ) ) {
					$this->sse_handler->send_sse_event(
						'cron_job_status',
						array(
							'job_id' => $job_id,
							'status' => 'failed',
							'error'  => $updated_details->get_error_message(),
						)
					);
					$this->sse_handler->send_sse_done();
					exit;
				}

				$current_status = isset( $updated_details['status'] ) ? $updated_details['status'] : 'unknown';

				// Send update if status changed or if this is a periodic heartbeat.
				if ( $current_status !== $last_status || 0 === $poll_count % self::SSE_JOB_HEARTBEAT_INTERVAL ) {
					$this->sse_handler->send_sse_event( 'cron_job_status', $updated_details );
					$last_status = $current_status;
				}

				// Check if job reached terminal state.
				if ( in_array( $current_status, array( 'completed', 'failed' ), true ) ) {
					// Job finished - send final update and close.
					$this->sse_handler->send_sse_done();
					exit;
				}
			}

			// Timeout reached - send timeout event.
			$this->sse_handler->send_sse_event(
				'cron_job_status',
				array(
					'job_id' => $job_id,
					'status' => 'timeout',
					'error'  => __( 'Job status polling timed out. Job may still be running.', 'wp-mcp-ai' ),
				)
			);
			$this->sse_handler->send_sse_done();
			exit;
		}

		/**
		 * Provide a directory of assistants the authenticated client can access.
		 *
		 * Credential-scoped requests are limited to the issuing assistant while
		 * traditional authentication modes return every published assistant the
		 * caller can read.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_assistants_index( WP_REST_Request $request ) {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$default_assistant = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
			$auth_context      = $this->get_auth_context();

			$scoped_assistant = $this->apply_token_assistant_scope( 0 );
			if ( is_wp_error( $scoped_assistant ) ) {
				return $scoped_assistant;
			}

			$posts = array();

			if ( $scoped_assistant ) {
				$assistant_post = $this->validate_assistant_access( $scoped_assistant );

				if ( is_wp_error( $assistant_post ) ) {
					return $assistant_post;
				}

				$posts = array( $assistant_post );
			} else {
				$query_args = array(
					'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				);

				$search = $request->get_param( 'search' );
				if ( is_string( $search ) && '' !== $search ) {
					$query_args['s'] = sanitize_text_field( $search );
				}

				$include = $request->get_param( 'include' );
				if ( ! empty( $include ) ) {
					$include_ids = array();

					if ( is_string( $include ) ) {
						$include = explode( ',', $include );
					}

					foreach ( (array) $include as $candidate ) {
						$candidate = absint( $candidate );

						if ( $candidate ) {
							$include_ids[] = $candidate;
						}
					}

					if ( ! empty( $include_ids ) ) {
						$query_args['post__in'] = $include_ids;
						$query_args['orderby']  = 'post__in';
					}
				}

				/**
				 * Allow developers to adjust the assistant directory query.
				 *
				 * @since 1.0.0
				 *
				 * @param array           $query_args   WP_Query arguments.
				 * @param WP_REST_Request $request      Current REST request.
				 * @param array           $auth_context Authentication context for the caller.
				 */
				$query_args = apply_filters( 'wp_mcp_ai_rest_assistant_query_args', $query_args, $request, $auth_context );

				$query = new WP_Query( $query_args );
				$posts = $query->posts;

				if ( ! is_array( $posts ) ) {
					$posts = array();
				}

				$filtered = array();
				foreach ( $posts as $post ) {
					if ( ! $post instanceof WP_Post ) {
						$post = get_post( $post );
					}

					if ( ! $post instanceof WP_Post ) {
						continue;
					}

					$accessible = $this->validate_assistant_access( $post->ID );

					if ( $accessible instanceof WP_Post ) {
						$filtered[] = $accessible;
					}
				}

				$posts = $filtered;
			}

			$assistants = array();

			foreach ( $posts as $assistant_post ) {
				if ( ! $assistant_post instanceof WP_Post ) {
					continue;
				}

				$summary      = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );
				$assistants[] = $summary;
			}

			$assistants = array_values( $assistants );

			$directory_default = $scoped_assistant ? $scoped_assistant : $default_assistant;
			if ( ! $directory_default && ! empty( $assistants ) ) {
				$first_assistant = reset( $assistants );
				if ( is_array( $first_assistant ) && isset( $first_assistant['id'] ) ) {
					$directory_default = absint( $first_assistant['id'] );
				}
			}

			$response_data = array(
				'assistants'        => $assistants,
				'default_assistant' => $directory_default,
				'rest'              => array(
					'namespace'     => self::REST_NAMESPACE,
					'base'          => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( self::REST_NAMESPACE ) ) ),
					'chat'          => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( self::REST_NAMESPACE . '/chat' ) ) ),
					'tools'         => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( self::REST_NAMESPACE . '/tools' ) ) ),
					'file_download' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( self::REST_NAMESPACE . '/files' ) ) ),
					'sse'           => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( self::REST_NAMESPACE . '/sse' ) ) ),
					'mcp'           => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( self::REST_NAMESPACE . '/mcp' ) ) ),
				),
			);

			$capabilities = $this->build_assistant_directory_capabilities( $response_data );
			if ( ! empty( $capabilities ) ) {
				$response_data['capabilities'] = $capabilities;
			}

			$response_data['implementation'] = array(
				'name'    => 'WP oOS',
				'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			);

			if ( ! empty( $auth_context['token_authenticated'] ) ) {
				$token_scope = array(
					'type' => $auth_context['token_type'],
				);

				if ( 'local_token' === $auth_context['token_type'] && $scoped_assistant ) {
					$token_scope['assistant_id'] = $scoped_assistant;
				}

				$response_data['token_scope'] = $token_scope;
			}

			/**
			 * Filter the assistant directory response payload before it is returned.
			 *
			 * @since 1.0.0
			 *
			 * @param array           $response_data Response payload.
			 * @param WP_REST_Request $request       Current REST request.
			 * @param array           $auth_context  Authentication context for the caller.
			 */
			$response_data = apply_filters( 'wp_mcp_ai_rest_assistant_index', $response_data, $request, $auth_context );

			if ( $this->request_wants_event_stream( $request ) ) {
				return $this->stream_event_stream_payload( $response_data, 'directory' );
			}

			return new WP_REST_Response( $response_data, 200 );
		}

		/**
		 * Provide an explicit SSE endpoint for MCP clients that expect `/sse` handshakes.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_sse_handshake( WP_REST_Request $request ) {
			$request->set_param( 'stream', true );

			return $this->handle_assistants_index( $request );
		}

		/**
		 * Convert an assistant post into a safe directory summary.
		 *
		 * @param WP_Post         $assistant_post   Assistant post object.
		 * @param int             $default_assistant Default assistant identifier.
		 * @param array           $settings          Plugin settings array.
		 * @param WP_REST_Request $request           Current REST request.
		 * @return array
		 */
		protected function summarize_assistant_for_directory( WP_Post $assistant_post, $default_assistant, array $settings, WP_REST_Request $request ) {
			$assistant_id = absint( $assistant_post->ID );
			$config       = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

			$provider = isset( $config['provider'] ) ? sanitize_key( $config['provider'] ) : '';
			if ( '' === $provider ) {
				$provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
			}

			$model = isset( $config['model'] ) ? (string) $config['model'] : '';
			if ( '' === $model ) {
				if ( 'gemini' === $provider ) {
					$model = isset( $settings['default_gemini_model'] ) ? (string) $settings['default_gemini_model'] : '';
				} else {
					$model = isset( $settings['default_model'] ) ? (string) $settings['default_model'] : '';
				}
			}

			$temperature = isset( $config['temperature'] ) ? $config['temperature'] : null;
			if ( null !== $temperature ) {
				$temperature = floatval( $temperature );
			}

			$tools = array();
			if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
				foreach ( $config['tools'] as $tool_slug ) {
					$tool_slug = sanitize_key( $tool_slug );
					if ( '' !== $tool_slug ) {
						$tools[] = $tool_slug;
					}
				}

				$tools = array_values( array_unique( $tools ) );
			}

			$memory_files = 0;
			if ( isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) {
				$memory_files = count( array_filter( array_map( 'absint', $config['memory_files'] ) ) );
			}

			$summary = array(
				'id'                  => $assistant_id,
				'title'               => get_the_title( $assistant_post ),
				'slug'                => $assistant_post->post_name,
				'status'              => $assistant_post->post_status,
				'is_default'          => ( absint( $default_assistant ) === $assistant_id ),
				'provider'            => $provider,
				'model'               => $model,
				'temperature'         => ( null === $temperature ? null : $temperature ),
				'tools'               => $tools,
				'tool_count'          => count( $tools ),
				'memory_file_count'   => $memory_files,
				'has_vector_store'    => ( isset( $config['vector_store_id'] ) && '' !== $config['vector_store_id'] ),
				'has_external_action' => ( ! empty( $config['external_action_identifier'] ) ),
				'description'         => $this->get_assistant_directory_description( $assistant_post ),
				'updated_at'          => get_post_modified_time( 'c', true, $assistant_post ),
				'permalink'           => get_permalink( $assistant_post ),
			);

			/**
			 * Filter the assistant summary returned by the directory endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param array           $summary        Assistant summary array.
			 * @param WP_Post         $assistant_post Assistant post object.
			 * @param array           $config         Assistant configuration array.
			 * @param array           $settings       Plugin settings array.
			 * @param WP_REST_Request $request        Current REST request.
			 */
			return apply_filters( 'wp_mcp_ai_rest_assistant_summary', $summary, $assistant_post, $config, $settings, $request );
		}

		/**
		 * Build the capability metadata exposed alongside the assistant directory.
		 *
		 * @param array $response_data Current response payload.
		 * @return array
		 */
		protected function build_assistant_directory_capabilities( array $response_data ) {
			$capabilities = array();

			$capabilities['tools'] = array(
				'listChanged' => false,
			);

			$rest_links = array();
			if ( isset( $response_data['rest'] ) && is_array( $response_data['rest'] ) ) {
				$rest_links = $response_data['rest'];
			}

			$has_sse_route           = isset( $rest_links['sse'] ) && '' !== $rest_links['sse'];
			$has_file_download_route = isset( $rest_links['file_download'] ) && '' !== $rest_links['file_download'];

			if ( $has_sse_route || $has_file_download_route ) {
				$capabilities['resources'] = array(
					'subscribe'   => $has_sse_route,
					'listChanged' => false,
				);
			}

			/**
			 * Filter the capability metadata returned with the assistant directory response.
			 *
			 * @since 1.0.0
			 *
			 * @param array $capabilities  Capability metadata.
			 * @param array $response_data Current response payload.
			 */
			$capabilities = apply_filters( 'wp_mcp_ai_rest_assistant_capabilities', $capabilities, $response_data );

			return is_array( $capabilities ) ? $capabilities : array();
		}

		/**
		 * Generate a trimmed description for an assistant directory entry.
		 *
		 * @param WP_Post $assistant_post Assistant post object.
		 * @return string
		 */
		protected function get_assistant_directory_description( WP_Post $assistant_post ) {
			$excerpt = get_post_field( 'post_excerpt', $assistant_post->ID );

			if ( '' === $excerpt ) {
				$content = get_post_field( 'post_content', $assistant_post->ID );
				$excerpt = wp_trim_words( wp_strip_all_tags( (string) $content ), 30, '&hellip;' );
			}

			$excerpt = wp_strip_all_tags( (string) $excerpt );

			return $excerpt;
		}

		/**
		 * Permission callback for file download requests, ensuring query string nonces are honoured.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return true|WP_Error
		 */
		public function download_file_permissions_check( WP_REST_Request $request ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( empty( $nonce ) ) {
				$nonce_param = $request->get_param( '_wpnonce' );

				if ( is_string( $nonce_param ) && '' !== $nonce_param ) {
					$request->set_header( 'X-WP-Nonce', $nonce_param );
				}
			}

			return $this->permissions_check( $request );
		}

		/**
		 * Check permissions for REST requests, validating the nonce and capability.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public function permissions_check( WP_REST_Request $request ) {
			$this->reset_auth_context();

			$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );

			// Use the effective capability (per-assistant or global).
			$capability = function_exists( 'wp_mcp_ai_get_effective_chat_capability' )
				? wp_mcp_ai_get_effective_chat_capability( $assistant_id, 'rest' )
				: wp_mcp_ai_get_required_chat_capability( $assistant_id, 'rest' );

			$guest_token = $this->extract_guest_token( $request );

			if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id );

				if ( $guest_assistant ) {
					if ( ! $assistant_id ) {
						$assistant_id = $guest_assistant;
						$request->set_param( 'assistant_id', $assistant_id );
					}

					$capability = 'public';
				}
			}

			if ( is_string( $capability ) ) {
				$capability = sanitize_key( $capability );
			}

			$requires_authenticated_user = ! empty( $capability ) && 'public' !== $capability;

			// Check for mesh API key authentication.
			$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
			if ( ! empty( $mesh_key ) ) {
				$mesh_validated = $this->validate_mesh_key( $mesh_key );

				if ( true === $mesh_validated ) {
					$this->mark_token_authenticated( 'mesh', array( 'mesh_authenticated' => true ) );
					// Check rate limiting for mesh authenticated requests.
					$rate_limit_check = $this->check_rate_limit( 0 ); // Use 0 for mesh requests.
					if ( is_wp_error( $rate_limit_check ) ) {
						return $rate_limit_check;
					}
					return true;
				} elseif ( is_wp_error( $mesh_validated ) ) {
					return $mesh_validated;
				}
			}

			$bearer = $request->get_header( 'Authorization' );
			if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
				$token = trim( $matches[1] );
				$local = $this->validate_local_token( $token, $request );

				if ( true === $local ) {
					// Check rate limiting for local token authenticated requests.
					$user_id          = get_current_user_id();
					$rate_limit_check = $this->check_rate_limit( $user_id );
					if ( is_wp_error( $rate_limit_check ) ) {
						return $rate_limit_check;
					}
					return true;
				} elseif ( $local instanceof WP_Error ) {
					return $local;
				}

				$validated = $this->validate_bearer_token( $token, $request );

				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				// Check rate limiting for bearer token authenticated requests.
				$user_id          = get_current_user_id();
				$rate_limit_check = $this->check_rate_limit( $user_id );
				if ( is_wp_error( $rate_limit_check ) ) {
					return $rate_limit_check;
				}
				return true;
			}

			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $requires_authenticated_user ) {
				if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					$this->set_authenticated_user_id( get_current_user_id() );
				}

				// Check rate limiting for public/guest requests.
				$user_id          = get_current_user_id(); // Will be 0 for guests.
				$rate_limit_check = $this->check_rate_limit( $user_id );
				if ( is_wp_error( $rate_limit_check ) ) {
					return $rate_limit_check;
				}
				return true;
			}

			if ( empty( $nonce ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_credentials',
					__( 'Authentication is required. Provide an Auth0 bearer token or a WordPress REST nonce.', 'wp-mcp-ai' ),
					array(
						'status'  => 401,
						'actions' => array(
							'supply_bearer_token' => __( 'Include an Auth0-issued access token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
							'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			if ( $capability && ! current_user_can( $capability ) ) {
				return $this->insufficient_permissions_error( $capability );
			}

			$this->set_authenticated_user_id( get_current_user_id() );

			// Check rate limiting if enabled.
			$user_id          = get_current_user_id();
			$rate_limit_check = $this->check_rate_limit( $user_id );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}

			return true;
		}

		/**
		 * Permission check for listing assistants via REST API.
		 *
		 * Checks both standard permissions AND the rest_enable_assistant_list setting.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return bool|WP_Error
		 */
		public function permissions_check_assistant_list( WP_REST_Request $request ) {
			// First check standard permissions.
			$base_check = $this->permissions_check( $request );

			if ( is_wp_error( $base_check ) || ! $base_check ) {
				return $base_check;
			}

			// Then check if REST assistant listing is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['rest_enable_assistant_list'] ) ) {
				return new WP_Error(
					'rest_assistant_list_disabled',
					__( 'Listing assistants via REST API is currently disabled. Enable it in Settings → WP oOS → Authentication → REST API Capabilities.', 'wp-mcp-ai' ),
					array(
						'status' => 403,
					)
				);
			}

			return true;
		}

		/**
		 * Permission check for creating assistants via REST API.
		 *
		 * Checks both standard permissions AND the rest_enable_assistant_create setting.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return bool|WP_Error
		 */
		public function permissions_check_assistant_create( WP_REST_Request $request ) {
			// First check standard permissions.
			$base_check = $this->permissions_check( $request );

			if ( is_wp_error( $base_check ) || ! $base_check ) {
				return $base_check;
			}

			// Then check if REST assistant creation is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['rest_enable_assistant_create'] ) ) {
				return new WP_Error(
					'rest_assistant_create_disabled',
					__( 'Creating assistants via REST API is currently disabled. Enable it in Settings → WP oOS → Authentication.', 'wp-mcp-ai' ),
					array(
						'status' => 403,
					)
				);
			}

			return true;
		}

		/**
		 * Permission check for deleting assistants via REST API.
		 *
		 * Checks both standard permissions AND the rest_enable_assistant_delete setting.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return bool|WP_Error
		 */
		public function permissions_check_assistant_delete( WP_REST_Request $request ) {
			// First check standard permissions.
			$base_check = $this->permissions_check( $request );

			if ( is_wp_error( $base_check ) || ! $base_check ) {
				return $base_check;
			}

			// Then check if REST assistant deletion is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['rest_enable_assistant_delete'] ) ) {
				return new WP_Error(
					'rest_assistant_delete_disabled',
					__( 'Deleting assistants via REST API is currently disabled. Enable it in Settings → WP oOS → Authentication.', 'wp-mcp-ai' ),
					array(
						'status' => 403,
					)
				);
			}

			// Verify the user has permission to delete posts.
			$assistant_id = $request->get_param( 'id' );
			if ( ! current_user_can( 'delete_post', $assistant_id ) ) {
				return new WP_Error(
					'rest_cannot_delete',
					__( 'Sorry, you are not allowed to delete this assistant.', 'wp-mcp-ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			return true;
		}

		/**
		 * Handle assistant deletion via REST API.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_assistant_delete( WP_REST_Request $request ) {
			$assistant_id = $request->get_param( 'id' );

			// Validate the assistant exists and is the correct post type.
			$assistant_post = get_post( $assistant_id );

			if ( ! $assistant_post || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant_post->post_type ) {
				return new WP_Error(
					'rest_assistant_invalid_id',
					__( 'Invalid assistant ID.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}

			// Attempt to delete the assistant.
			// Using wp_delete_post() instead of wp_trash_post() for permanent deletion.
			// The force_delete parameter (2nd arg) ensures permanent deletion.
			$deleted = wp_delete_post( $assistant_id, true );

			if ( ! $deleted || is_wp_error( $deleted ) ) {
				return new WP_Error(
					'rest_cannot_delete',
					__( 'The assistant cannot be deleted.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			/**
			 * Fires after an assistant is deleted via REST API.
			 *
			 * @since 1.0.0
			 *
			 * @param WP_Post         $assistant_post Deleted assistant post object.
			 * @param WP_REST_Request $request        Request object.
			 */
			do_action( 'wp_mcp_ai_rest_assistant_deleted', $assistant_post, $request );

			$response = new WP_REST_Response();
			$response->set_data(
				array(
					'deleted'  => true,
					'previous' => array(
						'id'    => $assistant_post->ID,
						'title' => $assistant_post->post_title,
					),
				)
			);

			return $response;
		}

		/**
		 * Handle assistant creation via REST API.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_assistant_create( WP_REST_Request $request ) {
			// Extract and validate parameters from request body.
			$title         = $request->get_param( 'title' );
			$description   = $request->get_param( 'description' );
			$provider      = $request->get_param( 'provider' );
			$model         = $request->get_param( 'model' );
			$temperature   = $request->get_param( 'temperature' );
			$system_prompt = $request->get_param( 'system_prompt' );
			$tools         = $request->get_param( 'tools' );
			$status        = $request->get_param( 'status' );

			// Title is required.
			if ( empty( $title ) ) {
				return new WP_Error(
					'rest_missing_title',
					__( 'Title is required to create an assistant.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			// Sanitize title.
			$title = sanitize_text_field( $title );

			// Prepare post data.
			$post_data = array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish', // Default status.
			);

			// Add description if provided.
			if ( ! empty( $description ) ) {
				$post_data['post_content'] = wp_kses_post( $description );
			}

			// Validate and set status if provided.
			if ( ! empty( $status ) ) {
				$allowed_statuses = array( 'publish', 'draft', 'private' );
				$status           = sanitize_key( $status );
				if ( in_array( $status, $allowed_statuses, true ) ) {
					$post_data['post_status'] = $status;
				}
			}

			// Create the assistant post.
			$assistant_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $assistant_id ) ) {
				return new WP_Error(
					'rest_cannot_create',
					__( 'Could not create the assistant.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			// Save metadata.
			if ( ! empty( $provider ) ) {
				$provider = sanitize_key( $provider );
				update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, $provider );
			}

			if ( ! empty( $model ) ) {
				$model = sanitize_text_field( $model );
				update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, $model );
			}

			if ( isset( $temperature ) && is_numeric( $temperature ) ) {
				$temperature = floatval( $temperature );
				// Clamp temperature between 0 and 2 (OpenAI/Gemini range).
				$temperature = max( 0.0, min( 2.0, $temperature ) );
				update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, $temperature );
			}

			if ( ! empty( $system_prompt ) ) {
				$system_prompt = wp_kses_post( $system_prompt );
				update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, $system_prompt );
			}

			if ( ! empty( $tools ) && is_array( $tools ) ) {
				// Validate that tools are valid slugs.
				$valid_tools = array();
				foreach ( $tools as $tool_slug ) {
					$tool_slug = sanitize_key( $tool_slug );
					if ( ! empty( $tool_slug ) ) {
						// Optionally verify tool exists in registry.
						if ( $this->registry && $this->registry->get_tool( $tool_slug ) ) {
							$valid_tools[] = $tool_slug;
						} else {
							// Include the tool anyway - it might be available later.
							$valid_tools[] = $tool_slug;
						}
					}
				}
				update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array_unique( $valid_tools ) );
			}

			/**
			 * Fires after an assistant is created via REST API.
			 *
			 * @since 1.0.0
			 *
			 * @param int             $assistant_id Created assistant post ID.
			 * @param WP_REST_Request $request      Request object.
			 */
			do_action( 'wp_mcp_ai_rest_assistant_created', $assistant_id, $request );

			// Get the created post.
			$assistant_post = get_post( $assistant_id );

			// Build response using the same format as the directory endpoint.
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$default_assistant = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;

			$response_data = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );

			$response = new WP_REST_Response( $response_data, 201 );
			$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/assistants/' . $assistant_id ) );

			return $response;
		}

		/**
		 * Permission check for MCP endpoint - requires bearer token or mesh API key only.
		 *
		 * Enforces bearer-only authentication for remote MCP access.
		 * WordPress nonce authentication is NOT permitted for the /mcp endpoint.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return bool|WP_Error
		 */
		public function permissions_check_mcp( WP_REST_Request $request ) {
			$this->reset_auth_context();

			// Check for mesh API key authentication.
			$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
			if ( ! empty( $mesh_key ) ) {
				$mesh_validated = $this->validate_mesh_key( $mesh_key );

				if ( true === $mesh_validated ) {
					$this->mark_token_authenticated( 'mesh', array( 'mesh_authenticated' => true ) );
					return true;
				} elseif ( is_wp_error( $mesh_validated ) ) {
					return $mesh_validated;
				}
			}

			// Check for bearer token authentication.
			$bearer = $request->get_header( 'Authorization' );
			if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
				$token = trim( $matches[1] );

				// Validate local credential token.
				$local = $this->validate_local_token( $token, $request );
				if ( true === $local ) {
					return true;
				} elseif ( $local instanceof WP_Error ) {
					return $local;
				}

				// Validate Auth0 or other bearer token.
				$validated = $this->validate_bearer_token( $token, $request );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				return true;
			}

			// Allow WordPress nonce authentication ONLY for internal admin diagnostic testing.
			// This enables the diagnostic page to test MCP endpoint connectivity without requiring
			// bearer tokens for internal REST API calls made via rest_do_request().
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				// Verify this is an internal request (not from external source).
				// Internal requests via rest_do_request() won't have HTTP_ORIGIN or HTTP_REFERER headers.
				$is_internal = empty( $_SERVER['HTTP_ORIGIN'] ) ||
					( isset( $_SERVER['HTTP_ORIGIN'] ) && wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST ) );

				if ( $is_internal ) {
					$this->mark_token_authenticated( 'nonce_admin', array( 'admin_user' => get_current_user_id() ) );
					return true;
				}
			}

			// MCP endpoint requires bearer token or mesh key - nonce is NOT accepted for remote access.
			return new WP_Error(
				'wp_mcp_ai_mcp_bearer_required',
				__( 'The MCP endpoint requires bearer token authentication. WordPress nonce authentication is not permitted for remote MCP access.', 'wp-mcp-ai' ),
				array(
					'status'  => 401,
					'actions' => array(
						'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
						'supply_mesh_key'     => __( 'Alternatively, use the X-WP-MCP-AI-Mesh-Key header for mesh network access.', 'wp-mcp-ai' ),
						'issue_credential'    => __( 'To obtain a bearer token, issue an assistant credential via the WordPress admin.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		/**
		 * Permission check for cron-status endpoint.
		 *
		 * Only requires authentication - any logged-in user can see their own cron jobs.
		 * Admins can see all cron jobs.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return bool|WP_Error
		 */
		public function permissions_check_cron_status( WP_REST_Request $request ) {
			$this->reset_auth_context();

			// Check for mesh API key authentication.
			$mesh_key = $request->get_header( 'X-WP-MCP-AI-Mesh-Key' );
			if ( ! empty( $mesh_key ) ) {
				$mesh_validated = $this->validate_mesh_key( $mesh_key );

				if ( true === $mesh_validated ) {
					$this->mark_token_authenticated( 'mesh', array( 'mesh_authenticated' => true ) );
					return true;
				} elseif ( is_wp_error( $mesh_validated ) ) {
					return $mesh_validated;
				}
			}

			// Check for bearer token authentication.
			$bearer = $request->get_header( 'Authorization' );
			if ( ! empty( $bearer ) && preg_match( '/^Bearer\s+(.*)$/i', $bearer, $matches ) ) {
				$token = trim( $matches[1] );
				$local = $this->validate_local_token( $token, $request );

				if ( true === $local ) {
					return true;
				} elseif ( $local instanceof WP_Error ) {
					return $local;
				}

				$validated = $this->validate_bearer_token( $token, $request );

				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				return true;
			}

			// Check for guest token authentication.
			$guest_token = $this->extract_guest_token( $request );
			if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, 0 );

				if ( $guest_assistant ) {
					// Guest users can view their own cron jobs (user_id = 0).
					$this->set_authenticated_user_id( 0 );
					return true;
				}
			}

			// Check for WordPress nonce authentication.
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( empty( $nonce ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_credentials',
					__( 'Authentication is required to view cron status.', 'wp-mcp-ai' ),
					array(
						'status'  => 401,
						'actions' => array(
							'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'wp-mcp-ai' ),
							'supply_guest_token'  => __( 'Include a guest token using the X-WP-MCP-AI-Guest header for public chat surfaces.', 'wp-mcp-ai' ),
							'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'wp-mcp-ai' ),
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			// Any authenticated user can view their own cron jobs.
			// The service layer will filter jobs by user ID.
			// A valid nonce proves the user is authenticated.
			$this->set_authenticated_user_id( get_current_user_id() );

			return true;
		}

		/**
		 * Build a consistent error response when the authenticated user lacks access.
		 *
		 * @param string $capability Required capability name.
		 * @return WP_Error
		 */
		protected function insufficient_permissions_error( $capability = 'edit_posts' ) {
			return $this->authenticator->insufficient_permissions_error( $capability );
		}

		/**
		 * Attempt to validate a plugin-issued credential token.
		 *
		 * @param string          $token   Raw token string.
		 * @param WP_REST_Request $request Current REST request.
		 * @return true|WP_Error|null True when valid, WP_Error when rejected, null when the token should be treated as a JWT.
		 */
		protected function validate_local_token( $token, WP_REST_Request $request ) {
			$assistant_hint = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
			return $this->authenticator->validate_local_token( $token, $request, $assistant_hint );
		}

		/**
		 * Validate a mesh network API key.
		 *
		 * @param string $key The mesh API key to validate.
		 * @return true|WP_Error
		 */
		protected function validate_mesh_key( $key ) {
			return $this->authenticator->validate_mesh_key( $key );
		}

		/**
		 * Validate an Auth0 bearer token.
		 *
		 * @param string          $token   Raw bearer token string.
		 * @param WP_REST_Request $request Current REST request.
		 * @return true|WP_Error
		 */
		protected function validate_bearer_token( $token, WP_REST_Request $request ) {
			return $this->authenticator->validate_bearer_token( $token, $request );
		}

		/**
		 * Check if rate limiting is enabled and enforce limits.
		 *
		 * @param int $user_id User ID making the request (0 for guests).
		 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
		 */
		protected function check_rate_limit( $user_id ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Check if rate limiting is enabled.
			if ( empty( $settings['enable_rate_limiting'] ) ) {
				return true;
			}

			// Get rate limit configuration.
			$max_requests = isset( $settings['rate_limit_requests'] ) ? absint( $settings['rate_limit_requests'] ) : 100;
			$time_window  = isset( $settings['rate_limit_window'] ) ? absint( $settings['rate_limit_window'] ) : 3600;

			// Create a unique key for this user.
			$transient_key = 'wp_mcp_ai_rate_limit_' . $user_id;
			$current_count = get_transient( $transient_key );

			if ( false === $current_count ) {
				// First request in this time window, start counting.
				set_transient( $transient_key, 1, $time_window );
				return true;
			}

			if ( $current_count >= $max_requests ) {
				// Rate limit exceeded.
				WP_MCP_AI_Logger::log_event(
					'rate_limit_exceeded',
					sprintf(
						'User %d exceeded rate limit of %d requests per %d seconds.',
						$user_id,
						$max_requests,
						$time_window
					),
					array(
						'user_id'      => $user_id,
						'max_requests' => $max_requests,
						'time_window'  => $time_window,
						'ip_address'   => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
					)
				);

				return new WP_Error(
					'wp_mcp_ai_rate_limit_exceeded',
					sprintf(
						/* translators: 1: Maximum requests allowed, 2: Time window in seconds */
						__( 'Rate limit exceeded. Maximum %1$d requests allowed per %2$d seconds.', 'wp-mcp-ai' ),
						$max_requests,
						$time_window
					),
					array(
						'status'        => 429,
						'retry_after'   => $time_window,
						'max_requests'  => $max_requests,
						'time_window'   => $time_window,
						'current_count' => $current_count,
					)
				);
			}

			// Increment the counter.
			set_transient( $transient_key, $current_count + 1, $time_window );
			return true;
		}

		/**
		 * Hydrate request body parameters for GET requests.
		 *
		 * @param WP_REST_Request $request Current REST request.
		 */
		public function hydrate_request_body_params( WP_REST_Request $request ) {
			if ( 'GET' !== $request->get_method() ) {
				return;
			}

			if ( $request->get_param( 'messages' ) ) {
				return;
			}

			$raw_body = $request->get_body();

			if ( '' === $raw_body ) {
				return;
			}

			$decoded = json_decode( $raw_body, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return;
			}

			$copyable_keys = array(
				'assistant_id',
				'messages',
				'attachments',
				'options',
				'session_key',
				'probe',
			);

			foreach ( $copyable_keys as $key ) {
				if ( array_key_exists( $key, $decoded ) ) {
					$request->set_param( $key, $decoded[ $key ] );
				}
			}

			if ( isset( $decoded['options'] ) && is_array( $decoded['options'] ) ) {
				$options = $request->get_param( 'options' );

				if ( ! is_array( $options ) ) {
					$options = array();
				}

				$request->set_param( 'options', array_merge( $options, $decoded['options'] ) );
			}
		}

		/**
		 * Handle chat completion requests, normalising attachments and auto-enabling
		 * the document prompt tool whenever uploads are detected.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_request( WP_REST_Request $request ) {
			$this->hydrate_request_body_params( $request );

			// Check if this is a profession test request.
			$raw_assistant_id = $request->get_param( 'assistant_id' );
			$profession_id    = $this->extract_profession_id( $raw_assistant_id );

			$assistant_id = $this->resolve_assistant_id( $raw_assistant_id );
			$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
			if ( is_wp_error( $scoped_id ) ) {
				return $scoped_id;
			}

			$assistant_id = $scoped_id;

			if ( ! $assistant_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$assistant_post = $this->validate_assistant_access( $assistant_id );
			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}

			$sanitized_messages = $this->validator->sanitize_messages( $request->get_param( 'messages' ) );
			if ( is_wp_error( $sanitized_messages ) ) {
				return $sanitized_messages;
			}

			$messages    = $sanitized_messages['messages'];
			$attachments = $sanitized_messages['attachments'];

			if ( empty( $messages ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_messages', __( 'Messages must be provided as an array of role/content pairs.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

			// If testing a profession, merge profession configuration.
			if ( $profession_id ) {
				$assistant_config = $this->load_profession_configuration( $profession_id, $assistant_config );
			}

			$options = $this->validator->sanitize_options( $request->get_param( 'options' ), $assistant_config );

			$limit_context = $this->build_chat_limit_context( $assistant_id, $options );
			$enforced      = $this->enforce_chat_request_limits( $messages, $attachments, $limit_context );

			if ( is_wp_error( $enforced ) ) {
				return $enforced;
			}

			$messages    = $enforced['messages'];
			$attachments = $enforced['attachments'];

			$transcript_context = array(
				'save_transcript' => $this->should_save_transcript( $request ),
				'session_key'     => $this->validator->sanitize_session_key_param( $request->get_param( 'session_key' ) ),
			);

			if ( ! empty( $attachments ) ) {
				$assistant_config = $this->ensure_tool_in_config( $assistant_config, self::DOCUMENT_PROMPT_TOOL_SLUG );
			}

			$tools = $this->build_tools_payload( $assistant_config );
			if ( is_wp_error( $tools ) ) {
				return $tools;
			}

			$options['tools'] = $tools;

			if ( ! empty( $options['memory_files'] ) ) {
				$memory_documents = $this->prepare_memory_documents( $options['memory_files'] );

				if ( is_wp_error( $memory_documents ) ) {
					return $memory_documents;
				}

				if ( ! empty( $memory_documents ) ) {
					$options['memory_documents'] = $memory_documents;
					$options['memory_files']     = wp_list_pluck( $memory_documents, 'id' );
				} else {
					$options['memory_files'] = array();
				}
			}

			if ( ! empty( $attachments ) ) {
				$options['attachments'] = $attachments;
			}

			$probe_mode = ! empty( $options['probe'] );
			if ( $probe_mode ) {
				unset( $options['probe'] );

				$response_data = array(
					'assistant_id' => $assistant_id,
					'probe'        => array(
						'status'     => 'ok',
						'checked_at' => gmdate( 'c' ),
					),
					'message'      => __( 'Chat probe acknowledged.', 'wp-mcp-ai' ),
				);

				return rest_ensure_response( $response_data );
			}

			$user_id = get_current_user_id();

			/**
			 * Fires before a chat request is sent to the language model.
			 *
			 * @param int              $assistant_id Assistant identifier.
			 * @param array            $messages     Chat messages.
			 * @param array            $options      Prepared options.
			 * @param WP_REST_Request  $request      REST request instance.
			 */
			do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request );

			$options = apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, $request );

			// Check if streaming is requested for agentic loop support.
			$wants_streaming = $this->request_wants_event_stream( $request );

			// Agentic loop: automatically execute tools server-side when LLM requests them.
			// Default limit prevents infinite loops. /chat-client endpoint applies higher limit via filter.
			// Temporarily reduced to 1 to prevent loops on tool errors while quality mapping is being fixed.
			$max_iterations = 1;
			$max_iterations = (int) apply_filters( 'wp_mcp_ai_max_agentic_iterations', $max_iterations, $assistant_config );
			$max_iterations = max( 1, min( 50, $max_iterations ) ); // Safety bounds: 1-50.
			$iteration      = 0;

			// Track original tool results for frontend display.
			$tool_result_messages = array();

			// If streaming is requested, use streaming-enabled agentic loop.
			if ( $wants_streaming ) {
				return $this->handle_chat_request_with_streaming(
					$assistant_id,
					$messages,
					$options,
					$assistant_config,
					$transcript_context,
					$request,
					$user_id,
					$max_iterations
				);
			}

			$transcript_context['request_started_at']    = microtime( true );
			$response                                    = $this->client->create_chat_completion( $messages, $options );
			$transcript_context['response_completed_at'] = microtime( true );

			if ( ! is_wp_error( $response ) ) {
				$response = $this->maybe_convert_failed_chat_response( $response );
			}

			if ( is_wp_error( $response ) ) {
				$context = array(
					'assistant_id' => $assistant_id,
					'user_id'      => $user_id,
					'error_code'   => $response->get_error_code(),
					'error'        => $response->get_error_message(),
				);

				$error_data = $response->get_error_data();
				if ( is_array( $error_data ) && isset( $error_data['provider_error_code'] ) ) {
					$context['provider_error_code'] = $error_data['provider_error_code'];
				}

				$context = array_merge( $context, $this->extract_chat_error_log_context( $response ) );

				$log_message = $this->build_chat_error_log_message( $response );

				WP_MCP_AI_Logger::log_error( $log_message, $context );
				return $response;
			}

			// Agentic loop: execute tools until LLM stops requesting them.
			while ( $iteration < $max_iterations && ! is_wp_error( $response ) ) {
				$tool_calls = $this->extract_tool_calls_from_response( $response );

				if ( empty( $tool_calls ) ) {
					break; // No more tools to execute, final response ready.
				}

				WP_MCP_AI_Logger::log_event(
					'agentic_tool_execution',
					'Executing tools automatically in chat',
					array(
						'iteration'    => $iteration,
						'tool_count'   => count( $tool_calls ),
						'assistant_id' => $assistant_id,
					)
				);

				// Add the assistant message with tool_calls to the conversation.
				// This is required by OpenAI's API: an assistant message with tool_calls
				// must be followed by tool response messages.
				$assistant_message = $this->extract_assistant_message_from_response( $response );
				if ( $assistant_message ) {
					$messages[] = $assistant_message;
				}

				// Execute each tool and collect results.
				foreach ( $tool_calls as $tool_call ) {
					$tool_result = $this->execute_tool_call_internal( $tool_call, $assistant_id, $assistant_config, $user_id, $request, $iteration, $max_iterations );

					// Extract tool call metadata for message construction.
					$tool_call_id = isset( $tool_call['id'] ) ? $tool_call['id'] : '';
					$tool_name    = isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : '';

					// Get the tool instance for interface-based sanitization.
					$tool_instance   = null;
					$allowed_tools   = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
					$tool_candidates = $this->generate_tool_slug_candidates( $tool_name );
					$tool_slug       = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );
					if ( $tool_slug && in_array( $tool_slug, $allowed_tools, true ) ) {
						$tool_instance = $this->registry->get_tool( $tool_slug );
					}

					// Create a full tool message with structured result for frontend.
					// Use the tool's sanitize_for_llm method if available to strip base64 content.
					$full_tool_message = array(
						'role'    => 'tool',
						'content' => $this->validator->sanitize_tool_result_for_display( $tool_result, $tool_name, $tool_instance ),
					);

					if ( '' !== $tool_call_id ) {
						$full_tool_message['tool_call_id'] = $tool_call_id;
					}

					if ( '' !== $tool_name ) {
						$full_tool_message['name'] = $tool_name;
					}

					// Store sanitized tool result for frontend.
					$tool_result_messages[] = $full_tool_message;

					// Create a sanitized version for the LLM (strip large content fields).
					$sanitized_result = $this->validator->sanitize_tool_result_for_llm( $tool_result, $tool_name, $assistant_config, $tool_instance );

					$tool_message = array(
						'role'    => 'tool',
						'content' => is_string( $sanitized_result ) ? $sanitized_result : wp_json_encode( $sanitized_result ),
					);

					if ( '' !== $tool_call_id ) {
						$tool_message['tool_call_id'] = $tool_call_id;
					}

					if ( '' !== $tool_name ) {
						$tool_message['name'] = $tool_name;
					}

					$messages[] = $tool_message;
				}

				// Validate token budget before next iteration to prevent TPM limit errors.
				$model             = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';
				$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;
				$tpm_validation    = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

				if ( is_wp_error( $tpm_validation ) ) {
					// Check if we should switch to a higher-capacity model.
					$settings            = WP_MCP_AI_Admin_Settings::get_settings();
					$fallback_model      = isset( $settings['high_token_fallback_model'] ) ? $settings['high_token_fallback_model'] : 'gemini-2.5-flash';
					$auto_switch_enabled = isset( $settings['enable_high_token_model_switch'] ) ? (bool) $settings['enable_high_token_model_switch'] : true;
					$switched_model      = false;

					if ( $auto_switch_enabled && $fallback_model !== $model ) {
						// Try the fallback model.
						$fallback_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $fallback_model, $max_output_tokens );

						if ( ! is_wp_error( $fallback_validation ) ) {
							// Fallback model can handle the request.
							$original_model   = $model;
							$options['model'] = $fallback_model;
							$model            = $fallback_model;
							$switched_model   = true;

							WP_MCP_AI_Logger::log_event(
								'agentic_model_switched',
								'Switched to higher-capacity model due to token limits',
								array(
									'iteration'      => $iteration,
									'original_model' => $original_model,
									'new_model'      => $fallback_model,
									'assistant_id'   => $assistant_id,
								)
							);
						}
					}

					if ( ! $switched_model ) {
						// Attempt to truncate messages to fit within the limit.
						$tpm_limit     = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $model );
						$target_tokens = $tpm_limit ? (int) ( $tpm_limit * self::TPM_SAFETY_MARGIN ) : self::TPM_FALLBACK_TOKENS;
						$messages      = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, $model, $target_tokens );

						// Re-validate after truncation.
						$tpm_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

						if ( is_wp_error( $tpm_validation ) ) {
							// Still too large after truncation, return error with context.
							WP_MCP_AI_Logger::log_error(
								'Agentic tool execution loop failed: Messages exceed TPM limit even after truncation',
								array(
									'assistant_id' => $assistant_id,
									'user_id'      => $user_id,
									'iteration'    => $iteration,
									'error_code'   => $tpm_validation->get_error_code(),
									'error'        => $tpm_validation->get_error_message(),
									'model'        => $model,
								)
							);
							return $tpm_validation;
						}

						WP_MCP_AI_Logger::log_event(
							'agentic_messages_truncated',
							'Messages truncated to fit within TPM limits during agentic loop',
							array(
								'iteration'     => $iteration,
								'model'         => $model,
								'target_tokens' => $target_tokens,
								'assistant_id'  => $assistant_id,
							)
						);
					}
				}

				// Call LLM again with tool results.
				$response = $this->client->create_chat_completion( $messages, $options );

				if ( ! is_wp_error( $response ) ) {
					$response = $this->maybe_convert_failed_chat_response( $response );
				}

				if ( is_wp_error( $response ) ) {
					WP_MCP_AI_Logger::log_error(
						'Agentic tool execution loop failed',
						array(
							'assistant_id' => $assistant_id,
							'user_id'      => $user_id,
							'iteration'    => $iteration,
							'error_code'   => $response->get_error_code(),
							'error'        => $response->get_error_message(),
						)
					);
					return $response;
				}

				++$iteration;
			}

			if ( $iteration >= $max_iterations ) {
				WP_MCP_AI_Logger::log_event(
					'agentic_loop_limit',
					'Reached maximum tool execution iterations',
					array(
						'assistant_id' => $assistant_id,
						'iterations'   => $iteration,
					)
				);
			}

			// Update response completion timestamp after agentic loop.
			$transcript_context['response_completed_at'] = microtime( true );

			WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

			$recorded_session_key = null;
			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
					$assistant_id,
					$messages,
					$options,
					$response,
					$request,
					$user_id,
					$transcript_context
				);
			}

			WP_MCP_AI_Usage_Tracker::record_chat_usage(
				$user_id,
				$assistant_id,
				$options,
				$response
			);

			/**
			 * Fires after a chat response has been received from the language model.
			 *
			 * @param int              $assistant_id Assistant identifier.
			 * @param array            $response     Raw response array.
			 * @param WP_REST_Request  $request      REST request instance.
			 */
			do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

			// Extract cost information for Phase 7 Week 5-6 Enhanced Token Tracking.
			$cost_data = $this->calculate_response_cost( $response, $options, $assistant_id, $user_id, 'chat response' );

			$payload = array(
				'assistant_id' => $assistant_id,
				'data'         => $response,
			);

			// Include cost data if available (Phase 7 Week 5-6).
			if ( $cost_data ) {
				$payload['cost'] = $cost_data;

				/**
				 * Fires when cost data is calculated and added to chat response.
				 *
				 * Allows integration with caching layers, transients, AJAX handlers,
				 * or third-party analytics systems.
				 *
				 * @since 1.1.0
				 *
				 * @param array           $cost_data    Cost calculation data.
				 * @param int             $assistant_id Assistant identifier.
				 * @param int             $user_id      User identifier.
				 * @param array           $response     Full AI response with usage data.
				 * @param WP_REST_Request $request      REST request instance.
				 */
				do_action( 'wp_mcp_ai_cost_calculated', $cost_data, $assistant_id, $user_id, $response, $request );
			}

			// Include the session key in the response so the client can save it
			if ( $recorded_session_key ) {
				$payload['sessionKey'] = $recorded_session_key;
			}

			// Include tool result messages in the response for frontend display.
			if ( ! empty( $tool_result_messages ) ) {
				$payload['tool_results'] = $tool_result_messages;
			}

			if ( $this->request_wants_event_stream( $request ) ) {
				return $this->stream_event_stream_payload( $payload, 'message' );
			}

				return rest_ensure_response( $payload );
		}

		/**
		 * Handle chat request from browser-based UI clients.
		 *
		 * This endpoint is specifically designed for browser chat interfaces
		 * and applies relaxed iteration limits compared to the MCP protocol endpoint.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_client_request( WP_REST_Request $request ) {
			// Set higher max_iterations for browser chat UI (allows more complex multi-tool workflows).
			add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_chat_client_max_iterations' ), 10, 2 );

			// Delegate to the standard chat handler.
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
		 * @return int
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
		 * Handle chat request with SSE streaming support for agentic loop.
		 *
		 * Streams tool execution status and results in real-time during the agentic loop.
		 *
		 * Note: This method uses exit after streaming is complete, which is necessary
		 * for SSE to work properly. The exit ensures no additional output is sent
		 * after the [DONE] marker.
		 *
		 * Note: While 8 parameters exceeds typical guidelines, this maintains consistency
		 * with how the non-streaming handler is invoked and keeps all context together.
		 * Grouping into objects would add unnecessary complexity for internal use.
		 *
		 * @param int             $assistant_id        Assistant post ID.
		 * @param array           $messages            Chat messages array.
		 * @param array           $options             Chat options.
		 * @param array           $assistant_config    Assistant configuration.
		 * @param array           $transcript_context  Transcript recording context.
		 * @param WP_REST_Request $request             REST request instance.
		 * @param int             $user_id             Current user ID.
		 * @param int             $max_iterations      Maximum agentic loop iterations.
		 * @return WP_REST_Response|WP_Error
		 */
		protected function handle_chat_request_with_streaming( $assistant_id, $messages, $options, $assistant_config, $transcript_context, $request, $user_id, $max_iterations ) {
			// Set up SSE headers.
			$this->send_sse_headers();

			// Track request start time for timing indicators.
			$request_start_timestamp = time();

			// Send initial status.
			$this->send_sse_event(
				'status',
				array(
					'type'         => 'thinking',
					'message'      => __( 'Processing your request…', 'wp-mcp-ai' ),
					'assistant_id' => $assistant_id,
					'timestamp'    => $request_start_timestamp,
				)
			);

			$iteration            = 0;
			$tool_result_messages = array();

			// Send status update to indicate AI is generating response.
			$this->send_sse_event(
				'status',
				array(
					'type'    => 'generating',
					'message' => __( 'Generating response…', 'wp-mcp-ai' ),
				)
			);

			$transcript_context['request_started_at']    = microtime( true );
			$response                                    = $this->client->create_chat_completion( $messages, $options );
			$transcript_context['response_completed_at'] = microtime( true );

			if ( ! is_wp_error( $response ) ) {
				$response = $this->maybe_convert_failed_chat_response( $response );
			}

			if ( is_wp_error( $response ) ) {
				$this->send_sse_event(
					'error',
					array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					)
				);
				$this->send_sse_done();
				exit;
			}

			// Agentic loop with streaming updates.
			while ( $iteration < $max_iterations && ! is_wp_error( $response ) ) {
				$tool_calls = $this->extract_tool_calls_from_response( $response );

				if ( empty( $tool_calls ) ) {
					break; // No more tools to execute.
				}

				// Stream tool execution status.
				$this->send_sse_event(
					'tool_execution',
					array(
						'type'       => 'start',
						'iteration'  => $iteration,
						'tool_count' => count( $tool_calls ),
						'tools'      => array_map(
							function ( $tool_call ) {
								return isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : 'unknown';
							},
							$tool_calls
						),
						'timestamp'  => time(),
					)
				);

				// Add assistant message with tool_calls to conversation.
				$assistant_message = $this->extract_assistant_message_from_response( $response );
				if ( $assistant_message ) {
					$messages[] = $assistant_message;
				}

				// Execute each tool and stream results.
				foreach ( $tool_calls as $tool_call ) {
					$tool_name    = isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : '';
					$tool_call_id = isset( $tool_call['id'] ) ? $tool_call['id'] : '';

					// Stream tool start event.
					$this->send_sse_event(
						'tool_execution',
						array(
							'type'      => 'tool_start',
							'tool_name' => $tool_name,
							'tool_id'   => $tool_call_id,
							'timestamp' => time(),
						)
					);

					$tool_result = $this->execute_tool_call_internal( $tool_call, $assistant_id, $assistant_config, $user_id, $request, $iteration, $max_iterations );

					// Get the tool instance for interface-based sanitization.
					$tool_instance   = null;
					$allowed_tools   = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
					$tool_candidates = $this->generate_tool_slug_candidates( $tool_name );
					$tool_slug       = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );
					if ( $tool_slug && in_array( $tool_slug, $allowed_tools, true ) ) {
						$tool_instance = $this->registry->get_tool( $tool_slug );
					}

					// Sanitize the tool result for display (strips base64 content if tool implements sanitization).
					$display_result = $this->validator->sanitize_tool_result_for_display( $tool_result, $tool_name, $tool_instance );

					// Stream tool result event.
					$this->send_sse_event(
						'tool_execution',
						array(
							'type'      => 'tool_result',
							'tool_name' => $tool_name,
							'tool_id'   => $tool_call_id,
							'result'    => $display_result,
						)
					);

					// Create full tool message for frontend.
					// JSON-encode the content to match the non-streaming path format.
					// This ensures consistent handling in the JavaScript SSE processor.
					if ( is_string( $display_result ) ) {
						$result_content = $display_result;
					} else {
						// Use JSON_UNESCAPED_SLASHES to prevent escaping URLs in image results.
						$result_content = wp_json_encode( $display_result, JSON_UNESCAPED_SLASHES );
						// Handle JSON encoding failure gracefully.
						if ( false === $result_content ) {
							// Log the error with context for debugging.
							WP_MCP_AI_Logger::log_error(
								'SSE Streaming: Failed to JSON-encode tool result',
								array(
									'tool_name'   => $tool_name,
									'result_type' => gettype( $display_result ),
									'json_error'  => json_last_error_msg(),
								)
							);
							// Use hardcoded JSON string as ultimate fallback.
							$result_content = '{"error":"Tool result encoding failed"}';
						}
					}

					$full_tool_message = array(
						'role'    => 'tool',
						'content' => $result_content,
					);

					if ( '' !== $tool_call_id ) {
						$full_tool_message['tool_call_id'] = $tool_call_id;
					}

					if ( '' !== $tool_name ) {
						$full_tool_message['name'] = $tool_name;
					}

					$tool_result_messages[] = $full_tool_message;

					// Create sanitized version for LLM.
					$sanitized_result = $this->validator->sanitize_tool_result_for_llm( $tool_result, $tool_name, $assistant_config, $tool_instance );

					$tool_message = array(
						'role'    => 'tool',
						'content' => is_string( $sanitized_result ) ? $sanitized_result : wp_json_encode( $sanitized_result ),
					);

					if ( '' !== $tool_call_id ) {
						$tool_message['tool_call_id'] = $tool_call_id;
					}

					if ( '' !== $tool_name ) {
						$tool_message['name'] = $tool_name;
					}

					$messages[] = $tool_message;
				}

				// Stream thinking status immediately after tool execution completes.
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'thinking',
						'message' => __( 'Analyzing tool results…', 'wp-mcp-ai' ),
					)
				);

				// Validate token budget before next iteration.
				$model             = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';
				$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;
				$tpm_validation    = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

				if ( is_wp_error( $tpm_validation ) ) {
					// Handle model switching or truncation (same logic as non-streaming).
					$settings            = WP_MCP_AI_Admin_Settings::get_settings();
					$fallback_model      = isset( $settings['high_token_fallback_model'] ) ? $settings['high_token_fallback_model'] : 'gemini-2.5-flash';
					$auto_switch_enabled = isset( $settings['enable_high_token_model_switch'] ) ? (bool) $settings['enable_high_token_model_switch'] : true;
					$switched_model      = false;

					if ( $auto_switch_enabled && $fallback_model !== $model ) {
						$fallback_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $fallback_model, $max_output_tokens );

						if ( ! is_wp_error( $fallback_validation ) ) {
							$options['model'] = $fallback_model;
							$model            = $fallback_model;
							$switched_model   = true;

							$this->send_sse_event(
								'status',
								array(
									'type'    => 'model_switched',
									'message' => sprintf(
										/* translators: %s: New model name */
										__( 'Switched to %s for higher token capacity.', 'wp-mcp-ai' ),
										$fallback_model
									),
								)
							);
						}
					}

					if ( ! $switched_model ) {
						$tpm_limit     = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $model );
						$target_tokens = $tpm_limit ? (int) ( $tpm_limit * self::TPM_SAFETY_MARGIN ) : self::TPM_FALLBACK_TOKENS;
						$messages      = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, $model, $target_tokens );

						$tpm_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

						if ( is_wp_error( $tpm_validation ) ) {
							$this->send_sse_event(
								'error',
								array(
									'code'    => $tpm_validation->get_error_code(),
									'message' => $tpm_validation->get_error_message(),
								)
							);
							$this->send_sse_done();
							exit;
						}

						$this->send_sse_event(
							'status',
							array(
								'type'    => 'messages_truncated',
								'message' => __( 'Reduced context to fit token limits.', 'wp-mcp-ai' ),
							)
						);
					}
				}

				// Send status update to indicate AI is generating response after tool execution.
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'generating',
						'message' => __( 'Generating response…', 'wp-mcp-ai' ),
					)
				);

				// Call LLM again with tool results.
				$response = $this->client->create_chat_completion( $messages, $options );

				if ( ! is_wp_error( $response ) ) {
					$response = $this->maybe_convert_failed_chat_response( $response );
				}

				if ( is_wp_error( $response ) ) {
					$this->send_sse_event(
						'error',
						array(
							'code'    => $response->get_error_code(),
							'message' => $response->get_error_message(),
						)
					);
					$this->send_sse_done();
					exit;
				}

				++$iteration;
			}

			if ( $iteration >= $max_iterations ) {
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'max_iterations',
						'message' => __( 'Reached maximum tool execution iterations.', 'wp-mcp-ai' ),
					)
				);
			}

			// Update response completion timestamp after agentic loop.
			$transcript_context['response_completed_at'] = microtime( true );

			// Log and record transcript.
			WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

			$recorded_session_key = null;
			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
					$assistant_id,
					$messages,
					$options,
					$response,
					$request,
					$user_id,
					$transcript_context
				);
			}

			WP_MCP_AI_Usage_Tracker::record_chat_usage(
				$user_id,
				$assistant_id,
				$options,
				$response
			);

			do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

			// Extract cost information for Phase 7 Week 5-6 Enhanced Token Tracking (SSE streaming path).
			$cost_data = $this->calculate_response_cost( $response, $options, $assistant_id, $user_id, 'streaming chat response' );

			// Extract thinking/reasoning text from the response if present.
			// Supports multiple providers:
			// - Gemini 2.0 Flash Thinking mode: message['thinking']
			// - OpenAI reasoning models (future): message['reasoning_content'] or message['reasoning']
			$thinking_text = '';
			$thinking_provider_format = 'gemini'; // Default to Gemini format

			// Validate response structure before accessing nested keys
			if ( ! empty( $response['choices'] ) && is_array( $response['choices'] ) && isset( $response['choices'][0]['message'] ) ) {
				$message = $response['choices'][0]['message'];

				// Check for Gemini thinking text
				if ( ! empty( $message['thinking'] ) ) {
					$thinking_text = $message['thinking'];
					$thinking_provider_format = 'gemini';
				}
				// Check for OpenAI reasoning_content (future-ready)
				elseif ( ! empty( $message['reasoning_content'] ) ) {
					$thinking_text = $message['reasoning_content'];
					$thinking_provider_format = 'openai';
				}
				// Check for OpenAI reasoning (alternative field)
				elseif ( ! empty( $message['reasoning'] ) ) {
					$thinking_text = $message['reasoning'];
					$thinking_provider_format = 'openai';
				}
			}

			// Send thinking text in chunks BEFORE sending main content (if present).
			// This allows the client to display thinking text in the status section.
			if ( is_string( $thinking_text ) && '' !== $thinking_text ) {
				// Format thinking chunks based on provider for optimal client compatibility.
				if ( 'openai' === $thinking_provider_format ) {
					// Use OpenAI format for reasoning fields.
					$thinking_formatter = function( $chunk ) {
						return array(
							'choices' => array(
								array(
									'delta' => array(
										'reasoning_content' => $chunk,
									),
								),
							),
						);
					};
				} else {
					// Use Gemini format for thinking field.
					$thinking_formatter = function( $chunk ) {
						return array(
							'candidates' => array(
								array(
									'content' => array(
										'parts' => array(
											array(
												'thought' => $chunk,
											),
										),
									),
								),
							),
						);
					};
				}

				$this->stream_text_chunks( $thinking_text, $thinking_formatter, 'thinking', $assistant_id );
			}

			// Simulate streaming by sending text content in chunks before final response.
			// Extract text content from the response to send progressively.
			$text_content = '';
			if ( ! empty( $response['choices'][0]['message']['content'] ) ) {
				// Normalize content - handles both string and array of segments.
				$text_content = $this->normalise_message_content( $response['choices'][0]['message']['content'] );
			} elseif ( isset( $response['content'] ) ) {
				$text_content = $this->normalise_message_content( $response['content'] );
			}

			// FALLBACK: If LLM returned no content but we have tool results, extract text from them.
			// This happens when the LLM determines tool results are sufficient without adding commentary.
			// Common with image generation tools where the tool result contains descriptive text.
			if ( ( '' === $text_content || ! is_string( $text_content ) ) && ! empty( $tool_result_messages ) ) {
				$text_content = $this->extract_text_from_tool_results( $tool_result_messages );
				
				if ( '' !== $text_content ) {
					// Update response to include the extracted text so it appears in the message payload.
					if ( ! isset( $response['choices'][0]['message'] ) ) {
						$response['choices'][0]['message'] = array();
					}
					$response['choices'][0]['message']['content'] = $text_content;
					
					WP_MCP_AI_Logger::log_event(
						'debug',
						'SSE Streaming: Extracted text from tool results',
						array(
							'extracted_length' => strlen( $text_content ),
							'tool_count'       => count( $tool_result_messages ),
							'assistant_id'     => $assistant_id,
						)
					);
				}
			}

			// Send text content in chunks to simulate streaming (for better UX).
			if ( is_string( $text_content ) && '' !== $text_content ) {
				// Format content chunks in OpenAI-compatible format.
				$content_formatter = function( $chunk ) {
					return array(
						'choices' => array(
							array(
								'delta' => array(
									'content' => $chunk,
								),
							),
						),
					);
				};

				$this->stream_text_chunks( $text_content, $content_formatter, 'text', $assistant_id );
			} else {
				// Log when no chunks are sent (helps diagnose streaming issues).
				WP_MCP_AI_Logger::log_event(
					'debug',
					'SSE Streaming: No text chunks to send',
					array(
						'has_text_content' => ! empty( $text_content ),
						'is_string'        => is_string( $text_content ),
						'response_keys'    => array_keys( $response ),
						'tool_result_count' => count( $tool_result_messages ),
						'assistant_id'     => $assistant_id,
					)
				);
			}

			// Stream final response with complete data.
			$payload = array(
				'assistant_id' => $assistant_id,
				'data'         => $response,
			);

			// Include cost data if available (Phase 7 Week 5-6).
			if ( $cost_data ) {
				$payload['cost'] = $cost_data;

				/**
				 * Fires when cost data is calculated and added to streaming chat response.
				 *
				 * Allows integration with caching layers, transients, AJAX handlers,
				 * or third-party analytics systems.
				 *
				 * @since 1.1.0
				 *
				 * @param array           $cost_data    Cost calculation data.
				 * @param int             $assistant_id Assistant identifier.
				 * @param int             $user_id      User identifier.
				 * @param array           $response     Full AI response with usage data.
				 * @param WP_REST_Request $request      REST request instance.
				 */
				do_action( 'wp_mcp_ai_cost_calculated', $cost_data, $assistant_id, $user_id, $response, $request );
			}

			// Include the session key in the response so the client can save it
			if ( $recorded_session_key ) {
				$payload['sessionKey'] = $recorded_session_key;
			}

			if ( ! empty( $tool_result_messages ) ) {
				$payload['tool_results'] = $tool_result_messages;
			}

			$this->send_sse_event( 'message', $payload );
			$this->send_sse_done();

			exit;
		}

		/**
		 * Send SSE headers for streaming response.
		 *
		 * Delegates to SSE handler.
		 *
		 * @since 1.0.0
		 */
		protected function send_sse_headers() {
			$this->sse_handler->send_sse_headers();
		}

		/**
		 * Send an SSE event.
		 *
		 * Delegates to SSE handler.
		 *
		 * @param string $event Event name.
		 * @param array  $data  Event data.
		 */
		protected function send_sse_event( $event, $data ) {
			$this->sse_handler->send_sse_event( $event, $data );
		}

		/**
		 * Send SSE done marker.
		 *
		 * Delegates to SSE handler.
		 */
		protected function send_sse_done() {
			$this->sse_handler->send_sse_done();
		}

		/**
		 * Determine whether the current request prefers an event stream response.
		 *
		 * Delegates to SSE handler.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return bool
		 */
		protected function request_wants_event_stream( WP_REST_Request $request ) {
			return $this->sse_handler->request_wants_event_stream( $request );
		}

		/**
		 * Stream the provided payload as an event stream response.
		 *
		 * Delegates to SSE handler.
		 *
		 * @param array  $payload Response payload to emit.
		 * @param string $event   Event name used for the SSE frame.
		 * @return WP_REST_Response
		 */
		protected function stream_event_stream_payload( array $payload, $event = 'message' ) {
			return $this->sse_handler->stream_event_stream_payload( $payload, $event );
		}

		/**
		 * Build a Server-Sent Events chunk for the provided data.
		 *
		 * Delegates to SSE handler.
		 *
		 * @param string $event  Event name.
		 * @param string $data   Event data payload.
		 * @param string $id     Optional event ID for client-side reconnection tracking.
		 * @return string
		 */
		protected function build_event_stream_chunk( $event, $data, $id = '' ) {
			return $this->sse_handler->build_event_stream_chunk( $event, $data, $id );
		}

		/**
		 * Convert failed chat responses into WP_Error instances so they surface in the UI.
		 *
		 * @param mixed $response Raw response from the language model router.
		 * @return mixed
		 */
		protected function maybe_convert_failed_chat_response( $response ) {
			if ( ! is_array( $response ) ) {
				return $response;
			}

			$status = $this->extract_chat_response_status( $response );

			if ( ! in_array( $status, array( 'failed', 'cancelled', 'expired' ), true ) ) {
				return $response;
			}

			$message = $this->extract_failed_chat_error_message( $response );

			$data = array(
				'status'          => 502,
				'response_status' => $status,
				'response'        => $response,
				'message'         => $message,
			);

			if ( isset( $response['last_error'] ) && is_array( $response['last_error'] ) ) {
				$data['last_error'] = $response['last_error'];

				if ( isset( $response['last_error']['code'] ) && is_string( $response['last_error']['code'] ) ) {
					$data['provider_error_code'] = sanitize_key( $response['last_error']['code'] );
				}
			}

			if ( isset( $response['id'] ) && is_string( $response['id'] ) ) {
				$data['response_id'] = sanitize_text_field( $response['id'] );
			}

			return new WP_Error( 'wp_mcp_ai_chat_failed', $message, $data );
		}

		/**
		 * Extract the status from a chat response payload.
		 *
		 * @param array $response Chat response payload.
		 * @return string
		 */
		protected function extract_chat_response_status( array $response ) {
			if ( isset( $response['status'] ) && is_string( $response['status'] ) ) {
				return sanitize_key( $response['status'] );
			}

			if ( isset( $response['response'] ) && is_array( $response['response'] ) ) {
				return $this->extract_chat_response_status( $response['response'] );
			}

			return '';
		}

		/**
		 * Extract a human readable error message from a failed chat response.
		 *
		 * @param array $response Chat response payload.
		 * @return string
		 */
		protected function extract_failed_chat_error_message( array $response ) {
			$candidates = array();

			if ( isset( $response['last_error'] ) && is_array( $response['last_error'] ) ) {
				if ( isset( $response['last_error']['message'] ) && is_string( $response['last_error']['message'] ) ) {
					$candidates[] = $response['last_error']['message'];
				}
			}

			if ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
				if ( isset( $response['error']['message'] ) && is_string( $response['error']['message'] ) ) {
					$candidates[] = $response['error']['message'];
				}
			}

			if ( isset( $response['incomplete_details'] ) && is_array( $response['incomplete_details'] ) ) {
				if ( isset( $response['incomplete_details']['message'] ) && is_string( $response['incomplete_details']['message'] ) ) {
					$candidates[] = $response['incomplete_details']['message'];
				} elseif ( isset( $response['incomplete_details']['reason'] ) && is_string( $response['incomplete_details']['reason'] ) ) {
					$candidates[] = $response['incomplete_details']['reason'];
				}
			}

			if ( empty( $candidates ) && isset( $response['response'] ) && is_array( $response['response'] ) ) {
				$nested_message = $this->extract_failed_chat_error_message( $response['response'] );
				if ( $nested_message ) {
					$candidates[] = $nested_message;
				}
			}

			foreach ( $candidates as $candidate ) {
				if ( ! is_string( $candidate ) ) {
					continue;
				}

				$sanitized = trim( wp_strip_all_tags( $candidate ) );
				if ( '' !== $sanitized ) {
					return $sanitized;
				}
			}

			return __( 'The assistant response failed to generate.', 'wp-mcp-ai' );
		}

		/**
		 * Build a descriptive log message for chat failures.
		 *
		 * @param WP_Error $error Chat failure error object.
		 * @return string
		 */
		protected function build_chat_error_log_message( $error ) {
			if ( ! $error instanceof WP_Error ) {
				return 'Chat request failed.';
			}

			$data = $error->get_error_data();
			if ( ! is_array( $data ) ) {
				return 'Chat request failed.';
			}

			$status = isset( $data['status'] ) ? (int) $data['status'] : 0;

			if ( 429 !== $status ) {
				return 'Chat request failed.';
			}

			$details     = $this->parse_openai_rate_limit_details( $data );
			$description = 'OpenAI rate limits';

			if ( ! empty( $details['type'] ) ) {
				$label = str_replace( '_', ' ', $details['type'] );
				$label = trim( preg_replace( '/\s+/', ' ', $label ) );

				if ( false !== strpos( $label, 'token' ) ) {
					$description = 'token limits';
				} elseif ( false !== strpos( $label, 'request' ) ) {
					$description = 'request limits';
				} elseif ( '' !== $label ) {
					$description = $label . ' limits';
				}
			}

			if ( '' === $description ) {
				$description = 'OpenAI rate limits';
			}

			if ( ! empty( $details['unit'] ) ) {
				$description .= ' (' . $details['unit'] . ')';
			}

			return sprintf(
				'Chat request failed due to %s being exceeded; OpenAI rate-limit response %d.',
				$description,
				$status
			);
		}

		/**
		 * Extract additional context for chat failure log entries.
		 *
		 * @param WP_Error $error Chat failure error object.
		 * @return array
		 */
		protected function extract_chat_error_log_context( $error ) {
			if ( ! $error instanceof WP_Error ) {
				return array();
			}

			$data = $error->get_error_data();
			if ( ! is_array( $data ) ) {
				return array();
			}

			$context = array();

			if ( isset( $data['status'] ) && '' !== $data['status'] ) {
				$context['http_status'] = (int) $data['status'];
			}

			if ( isset( $data['response_status'] ) && '' !== $data['response_status'] ) {
				$context['response_status'] = sanitize_key( $data['response_status'] );
			}

			if ( isset( $data['response_id'] ) && '' !== $data['response_id'] ) {
				$context['response_id'] = sanitize_text_field( $data['response_id'] );
			}

			$status = isset( $context['http_status'] ) ? (int) $context['http_status'] : ( isset( $data['status'] ) ? (int) $data['status'] : 0 );

			if ( 429 === $status ) {
				$details = $this->parse_openai_rate_limit_details( $data );

				if ( ! empty( $details['unit'] ) ) {
					$context['rate_limit_unit'] = $details['unit'];
				}

				if ( ! empty( $details['type'] ) ) {
					$context['rate_limit_type'] = $details['type'];
				}

				if ( ! empty( $details['scope'] ) ) {
					$context['rate_limit_scope'] = $details['scope'];
				}

				if ( null !== $details['limit'] ) {
					$context['rate_limit_limit'] = $details['limit'];
				}

				if ( null !== $details['remaining'] ) {
					$context['rate_limit_remaining'] = $details['remaining'];
				}

				if ( null !== $details['reset_seconds'] ) {
					$context['rate_limit_reset_seconds'] = $details['reset_seconds'];
				}
			}

			return $context;
		}

		/**
		 * Parse rate limit details from an OpenAI error payload.
		 *
		 * @param array $error_data Error data array attached to the WP_Error instance.
		 * @return array
		 */
		protected function parse_openai_rate_limit_details( array $error_data ) {
			$details = array(
				'unit'          => '',
				'type'          => '',
				'scope'         => '',
				'limit'         => null,
				'remaining'     => null,
				'reset_seconds' => null,
			);

			$error_payload = array();

			if ( isset( $error_data['body'] ) && is_array( $error_data['body'] ) && isset( $error_data['body']['error'] ) && is_array( $error_data['body']['error'] ) ) {
				$error_payload = $error_data['body']['error'];
			} elseif ( isset( $error_data['error'] ) && is_array( $error_data['error'] ) ) {
				$error_payload = $error_data['error'];
			}

			$detail_sections = array();

			if ( ! empty( $error_payload ) ) {
				$detail_sections[] = $error_payload;

				if ( isset( $error_payload['detail'] ) && is_array( $error_payload['detail'] ) ) {
					$detail_sections[] = $error_payload['detail'];
				}
			}

			foreach ( $detail_sections as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}

				if ( '' === $details['unit'] ) {
					if ( isset( $section['rate_limit_unit'] ) && is_string( $section['rate_limit_unit'] ) ) {
						$candidate = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $section['rate_limit_unit'] ) );
						if ( '' !== $candidate ) {
							$details['unit'] = $candidate;
						}
					} elseif ( isset( $section['unit'] ) && is_string( $section['unit'] ) ) {
						$candidate = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $section['unit'] ) );
						if ( '' !== $candidate ) {
							$details['unit'] = $candidate;
						}
					}
				}

				if ( '' === $details['type'] ) {
					$candidates = array();

					if ( isset( $section['limit_type'] ) ) {
						$candidates[] = $section['limit_type'];
					}

					if ( isset( $section['type'] ) ) {
						$candidates[] = $section['type'];
					}

					foreach ( $candidates as $candidate ) {
						if ( ! is_string( $candidate ) || '' === $candidate ) {
							continue;
						}

						$normalised = strtolower( preg_replace( '/[^a-z0-9_]/', '', $candidate ) );

						if ( '' !== $normalised ) {
							$details['type'] = $normalised;
							break;
						}
					}
				}

				if ( '' === $details['scope'] && isset( $section['scope'] ) && is_string( $section['scope'] ) ) {
					$scope = sanitize_key( $section['scope'] );
					if ( '' !== $scope ) {
						$details['scope'] = $scope;
					}
				}

				if ( null === $details['limit'] && isset( $section['limit'] ) ) {
					$limit = $this->normalise_rate_limit_number( $section['limit'] );
					if ( null !== $limit ) {
						$details['limit'] = $limit;
					}
				}

				if ( null === $details['remaining'] && isset( $section['remaining'] ) ) {
					$remaining = $this->normalise_rate_limit_number( $section['remaining'] );
					if ( null !== $remaining ) {
						$details['remaining'] = $remaining;
					}
				}

				if ( null === $details['reset_seconds'] && isset( $section['reset_seconds'] ) ) {
					$reset = $this->normalise_rate_limit_number( $section['reset_seconds'] );
					if ( null !== $reset ) {
						$details['reset_seconds'] = $reset;
					}
				}

				if ( null === $details['reset_seconds'] && isset( $section['retry_after'] ) ) {
					$reset = $this->normalise_rate_limit_number( $section['retry_after'] );
					if ( null !== $reset ) {
						$details['reset_seconds'] = $reset;
					}
				}
			}

			return $details;
		}

		/**
		 * Normalise numeric rate limit values.
		 *
		 * @param mixed $value Rate limit field value.
		 * @return int|float|null
		 */
		protected function normalise_rate_limit_number( $value ) {
			if ( is_int( $value ) || is_float( $value ) ) {
				return $value;
			}

			if ( is_numeric( $value ) ) {
				return 0 + $value;
			}

			if ( is_string( $value ) ) {
				$trimmed = trim( $value );
				if ( is_numeric( $trimmed ) ) {
					return 0 + $trimmed;
				}
			}

			return null;
		}

		/**
		 * Handle GET requests to list available tools for an assistant.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_tools_list( WP_REST_Request $request ) {
			$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
			$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

			if ( is_wp_error( $scoped_id ) ) {
				return $scoped_id;
			}

			$assistant_id = $scoped_id;

			if ( ! $assistant_id ) {
				// Return all tools if no assistant specified.
				$tools = $this->registry->get_tools();
			} else {
				// Get tools allowed for this assistant.
				$assistant_post = $this->validate_assistant_access( $assistant_id );

				if ( is_wp_error( $assistant_post ) ) {
					return $assistant_post;
				}

				$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
				$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

				$tools = array();
				foreach ( $allowed_tools as $tool_slug ) {
					$tool = $this->registry->get_tool( $tool_slug );
					if ( $tool ) {
						$tools[] = $tool;
					}
				}
			}

			// Convert tools to a simple array format.
			$tools_list = array();
			foreach ( $tools as $tool ) {
				try {
					$schema = $tool->get_parameters_schema();

					// Validate that the schema is a valid array.
					if ( ! is_array( $schema ) ) {
						WP_MCP_AI_Logger::log_event(
							'error',
							'Tool returned invalid schema',
							array(
								'tool_slug'   => $tool->get_slug(),
								'schema_type' => gettype( $schema ),
							)
						);
						continue;
					}

					$tools_list[] = array(
						'name'        => $tool->get_slug(),
						'description' => $tool->get_description(),
						'inputSchema' => $schema,
					);
				} catch ( Exception $e ) {
					// Log the error and skip this tool.
					WP_MCP_AI_Logger::log_event(
						'error',
						'Tool schema generation failed',
						array(
							'tool_slug' => $tool->get_slug(),
							'error'     => $e->getMessage(),
							'trace'     => $e->getTraceAsString(),
						)
					);
					continue;
				} catch ( Error $e ) {
					// Catch PHP 7+ errors as well.
					WP_MCP_AI_Logger::log_event(
						'error',
						'Tool schema generation failed with PHP Error',
						array(
							'tool_slug' => $tool->get_slug(),
							'error'     => $e->getMessage(),
							'trace'     => $e->getTraceAsString(),
						)
					);
					continue;
				}
			}

			return rest_ensure_response(
				array(
					'tools' => $tools_list,
				)
			);
		}

		/**
		 * Handle requests to execute a specific tool, temporarily granting access to
		 * the document prompt helper when the payload includes attachments.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_tool_request( WP_REST_Request $request ) {
			$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
			$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
			if ( is_wp_error( $scoped_id ) ) {
				return $scoped_id;
			}

			$assistant_id = $scoped_id;

			if ( ! $assistant_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$assistant_post = $this->validate_assistant_access( $assistant_id );
			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}

			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			$raw_tool         = $request->get_param( 'tool' );
			$arguments        = $request->get_param( 'arguments' );
			$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			$tool_candidates = $this->generate_tool_slug_candidates( $raw_tool );

			if ( $this->candidates_include_slug( $tool_candidates, self::DOCUMENT_PROMPT_TOOL_SLUG ) && ! in_array( self::DOCUMENT_PROMPT_TOOL_SLUG, $allowed_tools, true ) ) {
				if ( $this->tool_arguments_include_document_payload( $arguments ) ) {
					$assistant_config = $this->ensure_tool_in_config( $assistant_config, self::DOCUMENT_PROMPT_TOOL_SLUG );
					$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
				}
			}

			$tool_slug = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );

			if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
				return new WP_Error( 'wp_mcp_ai_tool_forbidden', __( 'This assistant is not allowed to execute the requested tool.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
			}

			$tool = $this->registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				return new WP_Error( 'wp_mcp_ai_tool_missing', __( 'The requested tool is not registered.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
			}

			$auth_context = $this->get_auth_context();
			$user_id      = isset( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : 0;

			$context = array(
				'user_id'          => $user_id,
				'assistant_id'     => $assistant_id,
				'request'          => $request,
				'assistant_config' => $assistant_config,
			);

			if ( ! empty( $auth_context['token_authenticated'] ) ) {
				$context['token_authenticated'] = true;
				$context['token_type']          = $auth_context['token_type'];

				if ( ! empty( $auth_context['token_context'] ) ) {
					$context['token_context'] = $auth_context['token_context'];
				}
			}

			if ( empty( $context['user_id'] ) && empty( $auth_context['token_authenticated'] ) ) {
				return new WP_Error( 'wp_mcp_ai_anonymous_user', __( 'You must be logged in to execute tools.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
			}

			/**
			 * Fires immediately before executing a registered tool.
			 *
			 * @param string           $tool_slug Tool identifier.
			 * @param array            $arguments Arguments passed in the request.
			 * @param array            $context   Execution context including user_id and assistant_id.
			 */
			$prepared_arguments = is_array( $arguments ) ? $arguments : array();

			if ( 'run_openai_external_action' === $tool_slug ) {
				if ( empty( $prepared_arguments['action_type'] ) && ! empty( $assistant_config['external_action_type'] ) ) {
					$prepared_arguments['action_type'] = $assistant_config['external_action_type'];
				}

				if ( empty( $prepared_arguments['identifier'] ) && ! empty( $assistant_config['external_action_identifier'] ) ) {
					$prepared_arguments['identifier'] = $assistant_config['external_action_identifier'];
				}
			}

			// Orchestration Layer: Wrap in try-catch to handle budget enforcement.
			try {
				do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $prepared_arguments, $context );

				$result = $tool->execute( $prepared_arguments, $context );

				if ( is_wp_error( $result ) ) {
					WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );
					return $result;
				}

				$result = apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $prepared_arguments, $context );

				// Orchestration Layer: Adjust result to fit within budget constraints.
				if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
					$result = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget( $result, $tool_slug, $context );
				}

				WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );

				/**
				 * Fires after a registered tool has completed execution.
				 *
				 * @param string           $tool_slug Tool identifier.
				 * @param array            $arguments Arguments passed in the request.
				 * @param array            $context   Execution context including user_id and assistant_id.
				 * @param mixed            $result    Tool result after filters have been applied.
				 */
				do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $prepared_arguments, $context, $result );

			} catch ( Exception $e ) {
				// Orchestration Layer: Budget constraint violation.
				WP_MCP_AI_Logger::log_error(
					'Tool execution blocked by orchestration layer',
					array(
						'tool_slug' => $tool_slug,
						'error'     => $e->getMessage(),
						'context'   => $context,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_budget_exceeded',
					$e->getMessage(),
					array( 'status' => 429 )
				);
			}

			return rest_ensure_response(
				array(
					'assistant_id' => $assistant_id,
					'tool'         => $tool_slug,
					'result'       => $result,
				)
			);
		}

		/**
		 * Build a list of potential tool slugs based on the supplied identifier.
		 *
		 * @param mixed $tool_name Raw tool identifier from the REST request.
		 * @return array
		 */
		protected function generate_tool_slug_candidates( $tool_name ) {
			if ( ! is_string( $tool_name ) ) {
				$tool_name = '';
			}

			$tool_name = trim( $tool_name );

			if ( '' === $tool_name ) {
				return array();
			}

			$candidates = array();

			$primary = sanitize_key( $tool_name );
			if ( '' !== $primary ) {
				$candidates[] = $primary;
			}

			$variants = array(
				str_replace( array( '-', ' ' ), '_', $tool_name ),
			);

			$camel_split = preg_replace( '/(?<=\p{Ll})(\p{Lu})/u', '_$1', $tool_name );

			if ( is_string( $camel_split ) && '' !== $camel_split ) {
				$lower_camel = strtolower( $camel_split );
				$variants[]  = $lower_camel;
				$variants[]  = str_replace( array( '-', ' ' ), '_', $lower_camel );
			}

			foreach ( $variants as $variant ) {
				if ( ! is_string( $variant ) ) {
					continue;
				}

				$variant = trim( $variant );

				if ( '' === $variant ) {
					continue;
				}

				$sanitized = sanitize_key( $variant );
				if ( '' !== $sanitized ) {
					$candidates[] = $sanitized;
				}
			}

			$candidates = array_values( array_unique( $candidates ) );

			return $candidates;
		}

		/**
		 * Determine whether the supplied candidates refer to a specific tool slug.
		 *
		 * @param array  $candidates Candidate tool slugs.
		 * @param string $slug       Target slug to match.
		 * @return bool
		 */
		protected function candidates_include_slug( array $candidates, $slug ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug ) {
				return false;
			}

			if ( in_array( $slug, $candidates, true ) ) {
				return true;
			}

			$normalised_slug = preg_replace( '/[_-]/', '', $slug );

			if ( '' === $normalised_slug ) {
				return false;
			}

			foreach ( $candidates as $candidate ) {
				if ( preg_replace( '/[_-]/', '', $candidate ) === $normalised_slug ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Resolve the requested tool slug by comparing candidates against the assistant's allow-list.
		 *
		 * @param array $candidates     Candidate tool slugs derived from the REST payload.
		 * @param array $allowed_tools  Assistant tool allow-list.
		 * @return string
		 */
		protected function resolve_tool_slug_from_candidates( array $candidates, array $allowed_tools ) {
			if ( empty( $candidates ) ) {
				return '';
			}

			$allowed_lookup = array();
			foreach ( $allowed_tools as $slug ) {
				$sanitized = sanitize_key( $slug );

				if ( '' === $sanitized ) {
					continue;
				}

				$allowed_lookup[ $sanitized ] = $sanitized;
			}

			foreach ( $candidates as $candidate ) {
				if ( isset( $allowed_lookup[ $candidate ] ) ) {
					return $allowed_lookup[ $candidate ];
				}
			}

			if ( ! empty( $allowed_lookup ) ) {
				$normalised_candidates = array();
				foreach ( $candidates as $candidate ) {
					$normalised_candidates[] = preg_replace( '/[_-]/', '', $candidate );
				}

				$normalised_candidates = array_values( array_filter( array_unique( $normalised_candidates ) ) );

				if ( ! empty( $normalised_candidates ) ) {
					foreach ( $allowed_lookup as $slug ) {
						$normalised_slug = preg_replace( '/[_-]/', '', $slug );

						if ( in_array( $normalised_slug, $normalised_candidates, true ) ) {
							return $slug;
						}
					}
				}
			}

			return $candidates[0];
		}

		/**
		 * Proxy OpenAI file downloads through WordPress so attachments can be saved locally.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_file_download( WP_REST_Request $request ) {
			$assistant_id = $this->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
			$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

			if ( is_wp_error( $scoped_id ) ) {
				return $scoped_id;
			}

			if ( $scoped_id ) {
				$assistant_id = $scoped_id;
			}

			$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

			if ( '' === $file_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$local_attachment = $this->resolve_local_attachment_for_openai_file( $file_id );

			if ( is_wp_error( $local_attachment ) ) {
				return $local_attachment;
			}

			if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
			}

			$client = $this->get_openai_client();
			$result = $client->download_file( $file_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$body = isset( $result['body'] ) ? (string) $result['body'] : '';

			if ( '' === $body ) {
				return new WP_Error( 'wp_mcp_ai_file_download_empty', __( 'The downloaded OpenAI file was empty.', 'wp-mcp-ai' ) );
			}

			$content_type = isset( $result['content_type'] ) && '' !== $result['content_type'] ? $result['content_type'] : 'application/octet-stream';

			if ( 'application/octet-stream' === $content_type && ! empty( $local_attachment['metadata']['mime_type'] ) ) {
				if ( function_exists( 'sanitize_mime_type' ) ) {
					$content_type = sanitize_mime_type( $local_attachment['metadata']['mime_type'] );
				} else {
					$content_type = sanitize_text_field( $local_attachment['metadata']['mime_type'] );
				}
			}

			$requested_name = $request->get_param( 'download_name' );
			$download_name  = '';

			if ( is_string( $requested_name ) && '' !== $requested_name ) {
				$download_name = sanitize_file_name( $requested_name );
			}

			$filename = '';

			if ( isset( $result['filename'] ) && '' !== $result['filename'] ) {
				$filename = sanitize_file_name( $result['filename'] );
			} elseif ( ! empty( $local_attachment['metadata']['filename'] ) ) {
				$filename = sanitize_file_name( $local_attachment['metadata']['filename'] );
			}

			if ( '' === $filename && '' !== $download_name ) {
				$filename = $download_name;
			}

			if ( '' === $filename ) {
				$fallback_name = sanitize_file_name( 'openai-file-' . $file_id );
				$filename      = '' !== $fallback_name ? $fallback_name : 'openai-file';
			}

			$disposition = $request->get_param( 'disposition' );
			$disposition = is_string( $disposition ) ? strtolower( $disposition ) : '';

			if ( ! in_array( $disposition, array( 'inline', 'attachment' ), true ) ) {
				$disposition = 'attachment';
			}

			$content_length = strlen( $body );

			$headers = array(
				'Content-Type'           => $content_type,
				'Content-Length'         => (string) $content_length,
				'Content-Disposition'    => sprintf( '%s; filename="%s"', $disposition, $filename ),
				'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
				'Pragma'                 => 'no-cache',
				'X-Content-Type-Options' => 'nosniff',
				'X-Robots-Tag'           => 'noindex',
			);

			$headers = apply_filters( 'wp_mcp_ai_file_download_headers', $headers, $file_id, $result, $request );

			add_filter(
				'rest_pre_serve_request',
				function ( $served, $response, $request_obj, $server ) use ( $headers, $body ) {
					if ( $served ) {
						return $served;
					}

					foreach ( $headers as $key => $value ) {
						if ( '' === $key || null === $value ) {
							continue;
						}

						$server->send_header( $key, $value );
					}

					echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

					return true;
				},
				10,
				4
			);

			return new WP_REST_Response( null, 200 );
		}

		/**
		 * Locate the local attachment associated with an OpenAI file identifier and ensure it is accessible.
		 *
		 * @param string $file_id OpenAI file identifier.
		 * @return array|WP_Error Array containing the attachment ID and metadata, or WP_Error on failure.
		 */
		protected function resolve_local_attachment_for_openai_file( $file_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
			}

			$file_id = sanitize_text_field( (string) $file_id );

			if ( '' === $file_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			global $wpdb;

			$meta_key = WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY;
			$like     = '%' . $wpdb->esc_like( $file_id ) . '%';

			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
					$meta_key,
					$like
				)
			);

			if ( empty( $post_ids ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_download_not_found',
					__( 'The requested file could not be located or is no longer available.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}

			$post_ids        = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
			$unauthorised_id = 0;

			foreach ( $post_ids as $attachment_id ) {
				if ( ! $attachment_id ) {
					continue;
				}

				$attachment = get_post( $attachment_id );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					continue;
				}

				$raw_meta = get_post_meta( $attachment_id, $meta_key, true );

				if ( is_string( $raw_meta ) && '' !== $raw_meta ) {
					$maybe_unserialized = maybe_unserialize( $raw_meta );

					if ( is_array( $maybe_unserialized ) ) {
						$metadata = $maybe_unserialized;
					} else {
						$metadata = array( 'file_id' => (string) $raw_meta );
					}
				} elseif ( is_array( $raw_meta ) ) {
					$metadata = $raw_meta;
				} else {
					$metadata = array();
				}

				$meta_file_id = '';
				if ( isset( $metadata['file_id'] ) ) {
					$meta_file_id = sanitize_text_field( (string) $metadata['file_id'] );
				}

				if ( $file_id !== $meta_file_id ) {
					continue;
				}

				if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
					$unauthorised_id = $attachment_id;
					continue;
				}

				if ( ! is_array( $metadata ) ) {
					$metadata = array();
				}

				$metadata['file_id'] = $meta_file_id;

				return array(
					'attachment_id' => $attachment_id,
					'metadata'      => $metadata,
				);
			}

			if ( $unauthorised_id ) {
				return new WP_Error(
					'wp_mcp_ai_file_download_forbidden',
					__( 'You do not have permission to download this file.', 'wp-mcp-ai' ),
					array(
						'status'        => 403,
						'attachment_id' => $unauthorised_id,
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_file_download_not_found',
				__( 'The requested file could not be located or is no longer available.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * Retrieve the assistant ID to use for a request.
		 *
		 * @param mixed $assistant_id Assistant ID from the request.
		 * @return int
		 */
		protected function resolve_assistant_id( $assistant_id ) {
			// Check if this is a profession test request with format "profession_123".
			if ( is_string( $assistant_id ) && 0 === strpos( $assistant_id, 'profession_' ) ) {
				// Extract the profession ID and use default assistant (profession data will be merged separately).
				$settings = WP_MCP_AI_Admin_Settings::get_settings();
				$default  = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
				return $default;
			}

			$assistant_id = absint( $assistant_id );
			if ( $assistant_id ) {
				return $assistant_id;
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$default  = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;

			return $default;
		}

		/**
		 * Extract profession ID from assistant_id parameter if it has profession_ prefix.
		 *
		 * @param mixed $assistant_id Assistant ID parameter from request.
		 * @return int|false Profession ID or false if not a profession test request.
		 */
		protected function extract_profession_id( $assistant_id ) {
			if ( ! is_string( $assistant_id ) || 0 !== strpos( $assistant_id, 'profession_' ) ) {
				return false;
			}

			$profession_id = absint( str_replace( 'profession_', '', $assistant_id ) );
			if ( ! $profession_id ) {
				return false;
			}

			// Verify it's actually a profession post.
			$profession_post = get_post( $profession_id );
			if ( ! $profession_post || 'mcp_ai_profession' !== $profession_post->post_type ) {
				return false;
			}

			return $profession_id;
		}

		/**
		 * Load profession configuration and merge with assistant configuration.
		 *
		 * @param int   $profession_id     Profession post ID.
		 * @param array $assistant_config  Base assistant configuration.
		 * @return array Merged configuration with profession data.
		 */
		protected function load_profession_configuration( $profession_id, $assistant_config ) {
			$profession_id = absint( $profession_id );
			if ( ! $profession_id ) {
				return $assistant_config;
			}

			// Get profession meta data.
			$role_description     = get_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', true );
			$knowledge_base       = get_post_meta( $profession_id, '_wp_mcp_ai_profession_knowledge_base', true );
			$default_tools        = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_tools', true );
			$memory_files         = get_post_meta( $profession_id, '_wp_mcp_ai_profession_memory_files', true );
			$default_provider_val = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', true );
			$default_model_val    = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', true );
			$default_temp_val     = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_temperature', true );

			// Build system prompt from profession data.
			$system_prompt = '';
			if ( ! empty( $role_description ) ) {
				$system_prompt = $role_description;
			}

			if ( ! empty( $knowledge_base ) ) {
				$system_prompt .= "\n\n" . __( 'Knowledge Base:', 'wp-mcp-ai' ) . "\n" . $knowledge_base;
			}

			// Merge profession configuration with assistant configuration.
			// Profession data takes priority over assistant defaults for testing.
			if ( ! empty( $system_prompt ) ) {
				$assistant_config['system_prompt'] = $system_prompt;
			}

			if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
				$assistant_config['tools'] = $default_tools;
			}

			if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
				$assistant_config['memory_files'] = $memory_files;
			}

			if ( ! empty( $default_provider_val ) ) {
				$assistant_config['provider'] = $default_provider_val;
			}

			if ( ! empty( $default_model_val ) ) {
				$assistant_config['model'] = $default_model_val;
			}

			if ( ! empty( $default_temp_val ) && is_numeric( $default_temp_val ) ) {
				$assistant_config['temperature'] = floatval( $default_temp_val );
			}

			return $assistant_config;
		}

		/**
		 * Ensure the active assistant aligns with the authenticated token scope.
		 *
		 * @param int $assistant_id Assistant identifier resolved from the request.
		 * @return int|WP_Error Scoped assistant identifier or error when the token cannot access the requested assistant.
		 */
		protected function apply_token_assistant_scope( $assistant_id ) {
			$assistant_id = absint( $assistant_id );
			$auth_context = $this->get_auth_context();

			if ( empty( $auth_context['token_authenticated'] ) || 'local_token' !== $auth_context['token_type'] ) {
				return $assistant_id;
			}

			$token_assistant = 0;

			if ( isset( $auth_context['assistant_id'] ) ) {
				$token_assistant = absint( $auth_context['assistant_id'] );
			}

			if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
				$token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
			}

			if ( ! $token_assistant ) {
				return $assistant_id;
			}

			if ( ! $assistant_id ) {
				return $token_assistant;
			}

			if ( $assistant_id !== $token_assistant ) {
				return new WP_Error(
					'wp_mcp_ai_assistant_scope_mismatch',
					__( 'The provided credential cannot access the requested assistant.', 'wp-mcp-ai' ),
					array(
						'status'  => 403,
						'actions' => array(
							'use_scoped_assistant' => __( 'Retry the request without overriding the assistant or request a credential for the desired assistant.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			return $token_assistant;
		}

		/**
		 * Ensure the current user can access the requested assistant post.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return WP_Post|WP_Error
		 */
		public function validate_assistant_access( $assistant_id ) {
			$assistant_id = absint( $assistant_id );

			// Check cache for repeated validations within the same request (optimization can be disabled with WP_MCP_AI_DISABLE_CACHE).
			if ( ! defined( 'WP_MCP_AI_DISABLE_CACHE' ) || ! WP_MCP_AI_DISABLE_CACHE ) {
				$cache_key = 'assistant_' . $assistant_id;
				if ( isset( $this->assistant_cache[ $cache_key ] ) ) {
					return $this->assistant_cache[ $cache_key ];
				}
			}
			$assistant_post = $assistant_id ? get_post( $assistant_id ) : null;

			if ( ! $assistant_post || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant_post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_assistant_forbidden',
					__( 'You do not have access to this assistant.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			$token_bypasses_visibility = false;

			$auth_context = $this->get_auth_context();
			if ( ! empty( $auth_context['token_authenticated'] ) && 'local_token' === $auth_context['token_type'] ) {
				$token_assistant = isset( $auth_context['assistant_id'] ) ? absint( $auth_context['assistant_id'] ) : 0;

				if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
					$token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
				}

				if ( $token_assistant && $token_assistant === $assistant_id ) {
					$token_bypasses_visibility = true;
				}
			}

			if ( 'publish' !== $assistant_post->post_status && ! $this->user_can_access_post( $assistant_id ) && ! $token_bypasses_visibility ) {
				return new WP_Error(
					'wp_mcp_ai_assistant_forbidden',
					__( 'You do not have access to this assistant.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			// Cache the successful result.
			if ( ! defined( 'WP_MCP_AI_DISABLE_CACHE' ) || ! WP_MCP_AI_DISABLE_CACHE ) {
				$this->assistant_cache[ $cache_key ] = $assistant_post;
			}
			return $assistant_post;
		}

		/**
		 * Check if the authenticated user (from auth context or session) can read a post.
		 *
		 * This method honors the user_id from token authentication context to prevent
		 * privilege escalation where a bearer token might inadvertently use privileges
		 * from an active admin session instead of the mapped token user.
		 *
		 * @param int $post_id Post identifier to check access for.
		 * @return bool Whether the user has read access to the post.
		 */
		protected function user_can_access_post( $post_id ) {
			$auth_context = $this->get_auth_context();

			// If token-authenticated, use the auth context user_id to avoid privilege escalation.
			if ( ! empty( $auth_context['token_authenticated'] ) && isset( $auth_context['user_id'] ) ) {
				$check_user_id = absint( $auth_context['user_id'] );
				return user_can( $check_user_id, 'read_post', $post_id );
			}

			// Fall back to checking the current session user.
			return current_user_can( 'read_post', $post_id );
		}

		/**
		 * Build the context array used when enforcing chat request limits.
		 *
		 * @param int   $assistant_id Assistant identifier.
		 * @param array $options      Prepared chat options.
		 * @return array
		 */
		protected function build_chat_limit_context( $assistant_id, array $options ) {
			return array(
				'assistant_id' => absint( $assistant_id ),
				'provider'     => isset( $options['provider'] ) ? sanitize_key( $options['provider'] ) : '',
				'model'        => isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : '',
			);
		}

		/**
		 * Ensure tool messages are paired with the immediately preceding tool call.
		 *
		 * Messages with the `tool` role must reference the ID of a tool call emitted by
		 * the previous assistant message. When that metadata is missing or does not
		 * match the pending tool calls the OpenAI API rejects the request. This helper
		 * discards any orphaned tool messages before the payload is dispatched.
		 *
		 * @param array $messages Sanitized chat messages.
		 * @return array
		 */
		protected function filter_tool_messages_without_matching_calls( array $messages ) {
			if ( empty( $messages ) ) {
				return $messages;
			}

			$filtered               = array();
			$pending_calls          = array();
			$saw_prompt_message     = false;
			$saw_assistant_response = false;
			$blocked_tool_messages  = false;

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

				if ( in_array( $role, array( 'system', 'user' ), true ) ) {
					$saw_prompt_message     = true;
					$saw_assistant_response = false;
					$blocked_tool_messages  = false;
					$pending_calls          = array();
					$filtered[]             = $message;
					$previous_role          = $role;
					continue;
				}

				if ( 'assistant' === $role ) {
					$pending_calls = array();

					$has_tool_calls = false;

					if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
						foreach ( $message['tool_calls'] as $tool_call ) {
							if ( ! is_array( $tool_call ) ) {
								continue;
							}

							$call_id = isset( $tool_call['id'] ) ? (string) $tool_call['id'] : '';

							if ( '' !== $call_id ) {
								$pending_calls[ $call_id ] = true;
								$has_tool_calls            = true;
							}
						}
					}

					if ( $has_tool_calls && ! $saw_prompt_message ) {
						WP_MCP_AI_Logger::log_event(
							'dropped_assistant_tool_calls',
							'Dropping assistant message with tool calls without a preceding prompt.',
							array(
								'reason'          => 'missing_prompt_message',
								'tool_call_count' => isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ? count( $message['tool_calls'] ) : 0,
							)
						);

						$pending_calls          = array();
						$saw_assistant_response = false;
						$blocked_tool_messages  = true;
						continue;
					}

					$blocked_tool_messages  = false;
					$saw_assistant_response = true;
					$filtered[]             = $message;
					continue;
				}

				if ( 'tool' === $role ) {
					$tool_call_id = isset( $message['tool_call_id'] ) ? (string) $message['tool_call_id'] : '';

					if ( '' === $tool_call_id ) {
						WP_MCP_AI_Logger::log_event(
							'dropped_tool_message',
							'Dropping tool message without matching tool call.',
							array(
								'tool_call_id' => $tool_call_id,
								'reason'       => 'missing_tool_call_id',
							)
						);

						$previous_role = $role;
						continue;
					}

					if ( $blocked_tool_messages ) {
						WP_MCP_AI_Logger::log_event(
							'dropped_tool_message',
							'Dropping tool message without matching tool call.',
							array(
								'tool_call_id' => $tool_call_id,
								'reason'       => 'blocked_by_missing_prompt',
							)
						);

						continue;
					}

					if ( empty( $pending_calls ) && $saw_assistant_response ) {
						WP_MCP_AI_Logger::log_event(
							'dropped_tool_message',
							'Dropping tool message without matching tool call.',
							array(
								'tool_call_id' => $tool_call_id,
								'reason'       => 'no_pending_tool_calls',
							)
						);

						continue;
					}

					if ( ! empty( $pending_calls ) && ! isset( $pending_calls[ $tool_call_id ] ) ) {
						WP_MCP_AI_Logger::log_event(
							'dropped_tool_message',
							'Dropping tool message without matching tool call.',
							array(
								'tool_call_id' => $tool_call_id,
								'reason'       => 'tool_call_not_found',
							)
						);

						$previous_role = $role;
						continue;
					}

					if ( isset( $pending_calls[ $tool_call_id ] ) ) {
						unset( $pending_calls[ $tool_call_id ] );
					}
					$filtered[] = $message;
					continue;
				}

				$pending_calls = array();
				$filtered[]    = $message;
				$previous_role = $role;
			}

			return $filtered;
		}

		/**
		 * Ensure chat requests stay within approximate token limits before dispatching to the model.
		 *
		 * @param array $messages    Sanitized chat messages.
		 * @param array $attachments Attachment payloads associated with the request.
		 * @param array $context     Contextual information about the request (assistant, provider, model).
		 * @return array|WP_Error
		 */
		protected function enforce_chat_request_limits( array $messages, array $attachments, array $context = array() ) {
			$messages    = array_values( $messages );
			$attachments = array_values( $attachments );
			$context     = is_array( $context ) ? $context : array();

			$context['assistant_id'] = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
			$context['provider']     = isset( $context['provider'] ) ? sanitize_key( $context['provider'] ) : '';
			$context['model']        = isset( $context['model'] ) ? sanitize_text_field( $context['model'] ) : '';

			// Pre-flight token count validation using resource manager budget.
			$resource_mgr     = WP_MCP_AI_Resource_Manager::instance();
			$max_input_tokens = $resource_mgr->get_max_input_tokens();

			// Estimate current token count.
			$estimated_tokens = 0;
			foreach ( $messages as $message ) {
				if ( isset( $message['content'] ) ) {
					if ( is_string( $message['content'] ) ) {
						$estimated_tokens += WP_MCP_AI_Text_Chunker::estimate_tokens( $message['content'] );
					} elseif ( is_array( $message['content'] ) ) {
						foreach ( $message['content'] as $segment ) {
							if ( is_array( $segment ) && isset( $segment['text'] ) ) {
								$estimated_tokens += WP_MCP_AI_Text_Chunker::estimate_tokens( $segment['text'] );
							} elseif ( is_string( $segment ) ) {
								$estimated_tokens += WP_MCP_AI_Text_Chunker::estimate_tokens( $segment );
							}
						}
					}
				}
			}

			// Check if request exceeds budget.
			if ( $estimated_tokens > $max_input_tokens ) {
				WP_MCP_AI_Logger::log_event(
					'chat_request_token_budget_exceeded',
					'Request exceeded maximum input token budget.',
					array(
						'estimated_tokens' => $estimated_tokens,
						'max_input_tokens' => $max_input_tokens,
						'message_count'    => count( $messages ),
					)
				);

				// Attempt to trim to budget using text chunker.
				$messages = $this->trim_messages_to_token_budget( $messages, $max_input_tokens );

				// Re-estimate after trimming.
				$estimated_tokens_after = 0;
				foreach ( $messages as $message ) {
					if ( isset( $message['content'] ) ) {
						if ( is_string( $message['content'] ) ) {
							$estimated_tokens_after += WP_MCP_AI_Text_Chunker::estimate_tokens( $message['content'] );
						}
					}
				}

				// If still over budget, return error.
				if ( $estimated_tokens_after > $max_input_tokens ) {
					return new WP_Error(
						'wp_mcp_ai_token_budget_exceeded',
						sprintf(
							/* translators: 1: Token count, 2: Maximum allowed tokens */
							__( 'Request token count (%1$d) exceeds maximum allowed (%2$d) even after trimming. Please reduce message length.', 'wp-mcp-ai' ),
							$estimated_tokens_after,
							$max_input_tokens
						),
						array(
							'status'           => 413,
							'estimated_tokens' => $estimated_tokens,
							'max_input_tokens' => $max_input_tokens,
							'trimmed_tokens'   => $estimated_tokens_after,
						)
					);
				}

				WP_MCP_AI_Logger::log_event(
					'chat_request_trimmed_to_budget',
					'Messages trimmed to fit token budget.',
					array(
						'original_tokens' => $estimated_tokens,
						'trimmed_tokens'  => $estimated_tokens_after,
						'max_tokens'      => $max_input_tokens,
					)
				);
			}

			// Apply message count limit before token-based trimming.
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$max_message_count = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 8;
			$max_message_count = (int) apply_filters( 'wp_mcp_ai_max_history_messages', $max_message_count, $context );

			if ( $max_message_count > 0 && count( $messages ) > $max_message_count ) {
				// Separate system messages from other messages.
				$system_messages = array();
				$other_messages  = array();

				foreach ( $messages as $message ) {
					$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';
					if ( 'system' === $role ) {
						$system_messages[] = $message;
					} else {
						$other_messages[] = $message;
					}
				}

				// Ensure we have room for at least 2 non-system messages.
				// If system messages exceed the limit, keep only the most recent ones.
				$min_history_slots = 2;
				if ( count( $system_messages ) > ( $max_message_count - $min_history_slots ) ) {
					$max_system_messages = max( 1, $max_message_count - $min_history_slots );
					$system_messages     = array_slice( $system_messages, -$max_system_messages );

					WP_MCP_AI_Logger::log_event(
						'chat_request_system_messages_trimmed',
						'System messages exceeded limit and were trimmed.',
						array(
							'max_message_count'    => $max_message_count,
							'system_message_count' => count( $system_messages ),
							'min_history_slots'    => $min_history_slots,
						)
					);
				}

				// Keep the most recent N non-system messages.
				$available_for_history = max( $min_history_slots, $max_message_count - count( $system_messages ) );
				$other_messages        = array_slice( $other_messages, -$available_for_history );

				// Recombine: system messages first, then history.
				$messages = array_merge( $system_messages, $other_messages );

				WP_MCP_AI_Logger::log_event(
					'chat_request_history_trimmed',
					'Chat request trimmed to maximum history message count.',
					array(
						'max_message_count' => $max_message_count,
						'final_count'       => count( $messages ),
					)
				);
			}

			$limit_tokens = $this->determine_chat_request_token_limit( $context );
			$limit_tokens = (int) apply_filters( 'wp_mcp_ai_chat_request_token_limit', $limit_tokens, $messages, $attachments, $context );

			if ( $limit_tokens <= 0 ) {
				return array(
					'messages'    => $messages,
					'attachments' => $attachments,
				);
			}

			$chars_per_token = (int) apply_filters( 'wp_mcp_ai_chat_request_chars_per_token', self::CHAT_APPROX_CHARS_PER_TOKEN, $messages, $attachments );

			if ( $chars_per_token <= 0 ) {
				$chars_per_token = self::CHAT_APPROX_CHARS_PER_TOKEN;
			}

			$max_chars = (int) $limit_tokens * $chars_per_token;

			if ( $max_chars <= 0 ) {
				return array(
					'messages'    => $messages,
					'attachments' => $attachments,
				);
			}

			$message_lengths = array();
			$total_chars     = 0;

			foreach ( $messages as $index => $message ) {
				$length                    = $this->calculate_message_character_length( $message );
				$message_lengths[ $index ] = $length;
				$total_chars              += $length;
			}

			if ( $total_chars <= $max_chars ) {
				return array(
					'messages'    => $messages,
					'attachments' => $attachments,
				);
			}

			$original_total_chars   = $total_chars;
			$original_message_count = count( $messages );
			$trimmed                = false;

			$removal_order  = array();
			$system_indexes = array();

			foreach ( array_keys( $messages ) as $index ) {
				$role = isset( $messages[ $index ]['role'] ) ? sanitize_key( $messages[ $index ]['role'] ) : '';

				if ( 'system' === $role ) {
					$system_indexes[] = $index;
				} else {
					$removal_order[] = $index;
				}
			}

			$removal_order = array_merge( $removal_order, $system_indexes );

			foreach ( $removal_order as $index ) {
				if ( $total_chars <= $max_chars ) {
					break;
				}

				if ( ! isset( $messages[ $index ] ) ) {
					continue;
				}

				$length                    = isset( $message_lengths[ $index ] ) ? (int) $message_lengths[ $index ] : 0;
				$remaining_without_message = $total_chars - $length;

				if ( $remaining_without_message >= $max_chars || $length <= 0 ) {
					unset( $messages[ $index ], $message_lengths[ $index ] );
					$total_chars = max( 0, $remaining_without_message );
					$trimmed     = true;
					continue;
				}

				$available_for_message = max( 0, $max_chars - $remaining_without_message );
				$updated_message       = $this->truncate_message_to_length( $messages[ $index ], $available_for_message );

				if ( empty( $updated_message ) ) {
					unset( $messages[ $index ], $message_lengths[ $index ] );
					$total_chars = max( 0, $remaining_without_message );
					$trimmed     = true;
					continue;
				}

				$new_length                = $this->calculate_message_character_length( $updated_message );
				$messages[ $index ]        = $updated_message;
				$message_lengths[ $index ] = $new_length;
				$total_chars               = $remaining_without_message + $new_length;
				$trimmed                   = true;
			}

			if ( $total_chars > $max_chars ) {
				foreach ( array_keys( $messages ) as $index ) {
					if ( $total_chars <= $max_chars ) {
						break;
					}

					if ( ! isset( $messages[ $index ] ) ) {
						continue;
					}

					$length = isset( $message_lengths[ $index ] ) ? (int) $message_lengths[ $index ] : 0;
					unset( $messages[ $index ], $message_lengths[ $index ] );
					$total_chars = max( 0, $total_chars - $length );
					$trimmed     = true;
				}
			}

			$messages = array_values( $messages );

			if ( empty( $messages ) ) {
				return new WP_Error(
					'wp_mcp_ai_request_too_large',
					__( 'The chat request exceeds the maximum allowed size.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'actions' => array(
							'reduce_request_size' => __( 'Reduce the length of the conversation before retrying.', 'wp-mcp-ai' ),
						),
					)
				);
			}

			$trimmed_total_chars = 0;

			foreach ( $messages as $message ) {
				$trimmed_total_chars += $this->calculate_message_character_length( $message );
			}

			$filtered_attachments = $this->filter_attachments_for_messages( $attachments, $messages );

			if ( $trimmed ) {
				WP_MCP_AI_Logger::log_event(
					'chat_request_trimmed',
					'Chat request trimmed to satisfy token limits.',
					array(
						'original_total_chars'   => $original_total_chars,
						'trimmed_total_chars'    => $trimmed_total_chars,
						'max_chars'              => $max_chars,
						'original_message_count' => $original_message_count,
						'trimmed_message_count'  => count( $messages ),
					)
				);
			}

			return array(
				'messages'    => $messages,
				'attachments' => $filtered_attachments,
			);
		}

		/**
		 * Determine the maximum token budget for a chat request.
		 *
		 * @param array $context Contextual information about the request.
		 * @return int
		 */
		protected function determine_chat_request_token_limit( array $context ) {
			$limit = self::CHAT_MAX_REQUEST_TOKENS;

			$provider_limits = array();

			if ( 'openai' === $context['provider'] ) {
				$provider_limits = array(
					'gpt-5-nano' => 150000,
				);
			}

			/**
			 * Filter the provider-specific token ceilings used when trimming chat requests.
			 *
			 * @param array $provider_limits Associative array of model identifiers mapped to token limits.
			 * @param array $context         Contextual information about the request (assistant, provider, model).
			 */
			$provider_limits = apply_filters( 'wp_mcp_ai_provider_chat_token_limits', $provider_limits, $context );

			if ( ! is_array( $provider_limits ) ) {
				$provider_limits = array();
			}

			$matched_limit = null;

			if ( ! empty( $context['model'] ) ) {
				$normalized_model = strtolower( $context['model'] );

				foreach ( $provider_limits as $candidate_model => $candidate_limit ) {
					$candidate_model = is_string( $candidate_model ) ? strtolower( $candidate_model ) : '';
					$candidate_limit = (int) $candidate_limit;

					if ( '' === $candidate_model || $candidate_limit <= 0 ) {
						continue;
					}

					if ( $normalized_model === $candidate_model ) {
						$matched_limit = $candidate_limit;
						break;
					}
				}
			}

			if ( null === $matched_limit ) {
				foreach ( array( 'default', '*' ) as $fallback_key ) {
					if ( isset( $provider_limits[ $fallback_key ] ) ) {
						$fallback_limit = (int) $provider_limits[ $fallback_key ];
						if ( $fallback_limit > 0 ) {
							$matched_limit = $fallback_limit;
							break;
						}
					}
				}
			}

			if ( null !== $matched_limit && $matched_limit > 0 ) {
				$limit = min( $limit, $matched_limit );
			}

			return (int) $limit;
		}

		/**
		 * Estimate the number of characters contributed by a chat message.
		 *
		 * @param array $message Chat message payload.
		 * @return int
		 */
		protected function calculate_message_character_length( array $message ) {
			if ( empty( $message['content'] ) ) {
				return 0;
			}

			$content = $message['content'];

			if ( is_string( $content ) ) {
				return $this->mb_strlen( $content );
			}

			if ( ! is_array( $content ) ) {
				return 0;
			}

			$length = 0;

			foreach ( $content as $segment ) {
				if ( is_string( $segment ) ) {
					$length += $this->mb_strlen( $segment );
					continue;
				}

				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

				switch ( $type ) {
					case 'text':
					case 'input_text':
						if ( isset( $segment['text'] ) ) {
							$length += $this->mb_strlen( (string) $segment['text'] );
						}
						break;
					case 'input_image':
						if ( isset( $segment['caption'] ) ) {
							$length += $this->mb_strlen( (string) $segment['caption'] );
						}

						if ( isset( $segment['detail'] ) ) {
							$length += $this->mb_strlen( (string) $segment['detail'] );
						}
						break;
					case 'input_file':
						if ( isset( $segment['display_name'] ) ) {
							$length += $this->mb_strlen( (string) $segment['display_name'] );
						}
						break;
				}
			}

			return $length;
		}

		/**
		 * Truncate a message's text segments so they fit within the supplied character budget.
		 *
		 * @param array $message   Chat message payload.
		 * @param int   $max_chars Maximum characters to retain.
		 * @return array
		 */
		protected function truncate_message_to_length( array $message, $max_chars ) {
			$max_chars = (int) $max_chars;

			if ( $max_chars <= 0 ) {
				return array();
			}

			if ( ! isset( $message['content'] ) ) {
				return array();
			}

			$current_length = $this->calculate_message_character_length( $message );

			if ( $current_length <= $max_chars ) {
				return $message;
			}

			$content = $message['content'];

			if ( ! is_array( $content ) ) {
				$content = array();
			}

			$note        = '[' . __( 'Truncated', 'wp-mcp-ai' ) . '] ';
			$note_length = $this->mb_strlen( $note );

			if ( $note_length >= $max_chars ) {
				$note        = '';
				$note_length = 0;
			}

			$available = max( 0, $max_chars - $note_length );
			$kept      = array();
			$remaining = $available;
			$truncated = false;

			for ( $index = count( $content ) - 1; $index >= 0; $index-- ) {
				$segment = $content[ $index ];

				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

				if ( in_array( $type, array( 'text', 'input_text' ), true ) ) {
					$text   = isset( $segment['text'] ) ? (string) $segment['text'] : '';
					$length = $this->mb_strlen( $text );

					if ( $length <= 0 ) {
						array_unshift( $kept, $segment );
						continue;
					}

					if ( $remaining <= 0 ) {
						$truncated = true;
						continue;
					}

					if ( $length <= $remaining ) {
						$remaining -= $length;
						array_unshift( $kept, $segment );
					} else {
						$offset                   = max( 0, $length - $remaining );
						$trimmed_text             = $this->mb_substr( $text, $offset, $remaining );
						$trimmed_text             = ltrim( $trimmed_text );
						$modified_segment         = $segment;
						$modified_segment['text'] = $trimmed_text;

						array_unshift( $kept, $modified_segment );
						$remaining = 0;
						$truncated = true;
					}

					continue;
				}

				array_unshift( $kept, $segment );
			}

			if ( empty( $kept ) ) {
				return array();
			}

			if ( $note_length > 0 && $truncated ) {
				$note_added = false;

				foreach ( $kept as &$segment ) {
					if ( ! is_array( $segment ) ) {
						continue;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

					if ( in_array( $type, array( 'text', 'input_text' ), true ) ) {
						$segment['text'] = $note . ltrim( (string) $segment['text'] );
						$note_added      = true;
						break;
					}
				}

				unset( $segment );

				if ( ! $note_added ) {
					array_unshift(
						$kept,
						array(
							'type' => 'text',
							'text' => $note,
						)
					);
				}
			}

			$message['content'] = array_values( $kept );

			return $message;
		}

		/**
		 * Trim messages to fit within a token budget.
		 *
		 * @param array $messages     Array of messages to trim.
		 * @param int   $max_tokens   Maximum token budget.
		 * @return array Trimmed messages.
		 */
		protected function trim_messages_to_token_budget( array $messages, $max_tokens ) {
			$max_tokens = max( 100, absint( $max_tokens ) );

			// Separate system messages from other messages.
			$system_messages = array();
			$other_messages  = array();

			foreach ( $messages as $message ) {
				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';
				if ( 'system' === $role ) {
					$system_messages[] = $message;
				} else {
					$other_messages[] = $message;
				}
			}

			// Trim each message content using the text chunker.
			$trimmed_messages = array();

			foreach ( $system_messages as $message ) {
				if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
					$message['content'] = WP_MCP_AI_Text_Chunker::trim_to_token_budget(
						$message['content'],
						(int) ( $max_tokens * 0.2 ) // Reserve 20% for system messages.
					);
				}
				$trimmed_messages[] = $message;
			}

			foreach ( $other_messages as $message ) {
				if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
					$message['content'] = WP_MCP_AI_Text_Chunker::trim_to_token_budget(
						$message['content'],
						(int) ( $max_tokens * 0.8 / max( 1, count( $other_messages ) ) )
					);
				}
				$trimmed_messages[] = $message;
			}

			return $trimmed_messages;
		}

		/**
		 * Remove attachments that are no longer referenced by the trimmed message payload.
		 *
		 * @param array $attachments Attachment payloads from the request.
		 * @param array $messages    Trimmed chat messages.
		 * @return array
		 */
		protected function filter_attachments_for_messages( array $attachments, array $messages ) {
			if ( empty( $attachments ) ) {
				return array();
			}

			$referenced_ids = $this->collect_message_attachment_ids( $messages );

			if ( empty( $referenced_ids ) ) {
				return array();
			}

			$referenced_lookup = array_flip( $referenced_ids );
			$filtered          = array();

			foreach ( $attachments as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}

				$file_id = '';

				if ( isset( $attachment['file_id'] ) && '' !== $attachment['file_id'] ) {
					$file_id = (string) $attachment['file_id'];
				} elseif ( isset( $attachment['id'] ) && '' !== $attachment['id'] ) {
					$file_id = (string) $attachment['id'];
				}

				if ( '' === $file_id ) {
					continue;
				}

				if ( isset( $referenced_lookup[ $file_id ] ) ) {
					$filtered[] = $attachment;
				}
			}

			return array_values( $filtered );
		}

		/**
		 * Collect attachment identifiers referenced in a set of messages.
		 *
		 * @param array $messages Chat messages.
		 * @return array
		 */
		protected function collect_message_attachment_ids( array $messages ) {
			$file_ids = array();

			foreach ( $messages as $message ) {
				if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
					continue;
				}

				foreach ( $message['content'] as $segment ) {
					if ( ! is_array( $segment ) ) {
						continue;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

					if ( 'input_image' === $type ) {
						$file_id = '';

						if ( isset( $segment['file_id'] ) ) {
							$file_id = (string) $segment['file_id'];
						} elseif ( isset( $segment['image_file'] ) && is_array( $segment['image_file'] ) ) {
							$file_id = isset( $segment['image_file']['file_id'] ) ? (string) $segment['image_file']['file_id'] : '';
						} elseif ( isset( $segment['image']['file_id'] ) ) {
							$file_id = (string) $segment['image']['file_id'];
						}

						if ( '' !== $file_id ) {
							$file_ids[] = $file_id;
						}

						continue;
					}

					if ( 'input_file' === $type ) {
						$file_id = isset( $segment['file_id'] ) ? (string) $segment['file_id'] : '';

						if ( '' !== $file_id ) {
							$file_ids[] = $file_id;
						}
					}
				}
			}

			return array_values( array_unique( $file_ids ) );
		}

		/**
		 * Ensure the supplied assistant configuration allows a specific tool.
		 *
		 * @param array  $assistant_config Assistant configuration array.
		 * @param string $tool_slug        Tool identifier to allow.
		 * @return array
		 */
		protected function ensure_tool_in_config( array $assistant_config, $tool_slug ) {
			if ( ! isset( $assistant_config['tools'] ) || ! is_array( $assistant_config['tools'] ) ) {
				$assistant_config['tools'] = array();
			}

			$tool_slug = sanitize_key( $tool_slug );

			if ( '' === $tool_slug ) {
				return $assistant_config;
			}

			if ( ! in_array( $tool_slug, $assistant_config['tools'], true ) ) {
				$assistant_config['tools'][] = $tool_slug;
			}

			$assistant_config['tools'] = array_values(
				array_filter(
					array_unique(
						array_map( 'sanitize_key', $assistant_config['tools'] )
					)
				)
			);

			return $assistant_config;
		}

		/**
		 * Determine whether a tool request payload references document attachments.
		 *
		 * Recognises the attachment_id, attachment_ids, file_id, file_ids, and
		 * attachments keys so the REST layer mirrors the tool schema.
		 *
		 * @param mixed $arguments Tool invocation arguments.
		 * @return bool
		 */
		protected function tool_arguments_include_document_payload( $arguments ) {
			if ( empty( $arguments ) || ! is_array( $arguments ) ) {
				return false;
			}

			if ( ! empty( $arguments['attachment_id'] ) || ! empty( $arguments['file_id'] ) ) {
				return true;
			}

			if ( ! empty( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ) {
				foreach ( $arguments['attachment_ids'] as $value ) {
					if ( ! empty( $value ) ) {
						return true;
					}
				}
			}

			if ( ! empty( $arguments['file_ids'] ) && is_array( $arguments['file_ids'] ) ) {
				foreach ( $arguments['file_ids'] as $value ) {
					if ( ! empty( $value ) ) {
						return true;
					}
				}
			}

			if ( ! empty( $arguments['attachments'] ) && is_array( $arguments['attachments'] ) ) {
				foreach ( $arguments['attachments'] as $entry ) {
					if ( $entry instanceof \Traversable ) {
						$entry = iterator_to_array( $entry );
					}

					if ( is_object( $entry ) ) {
						$entry = (array) $entry;
					}

					if ( ! is_array( $entry ) ) {
						continue;
					}

					if ( ! empty( $entry['attachment_id'] ) || ! empty( $entry['file_id'] ) || ! empty( $entry['id'] ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Determine whether transcripts should be saved for the current request.
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return bool
		 */
		protected function should_save_transcript( WP_REST_Request $request ) {
			$value = $request->get_param( 'save_transcript' );

			if ( null === $value ) {
				return true;
			}

			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) ) {
				return wp_validate_boolean( $value );
			}

			if ( is_numeric( $value ) ) {
				return (bool) (int) $value;
			}

			return ! empty( $value );
		}

		/**
		 * Build the tool payload to send to OpenAI.
		 *
		 * @param array $assistant_config Assistant configuration array.
		 * @return array|WP_Error
		 */
		protected function build_tools_payload( array $assistant_config ) {
			$allowed_tool_slugs = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			if ( empty( $allowed_tool_slugs ) ) {
				return array();
			}

			$chat_provider = isset( $assistant_config['provider'] ) ? sanitize_key( $assistant_config['provider'] ) : 'openai';

			$tools_payload = array();
			foreach ( $allowed_tool_slugs as $slug ) {
				$tool = $this->registry->get_tool( $slug );
				if ( ! $tool ) {
					WP_MCP_AI_Admin_Settings::log( 'Assistant references missing tool.', array( 'tool' => $slug ) );
					continue;
				}

				try {
					$schema = $tool->get_parameters_schema();

					// Validate that the schema is a valid array.
					if ( ! is_array( $schema ) ) {
						WP_MCP_AI_Logger::log_event(
							'error',
							'Tool returned invalid schema in build_tools_payload',
							array(
								'tool_slug'   => $slug,
								'schema_type' => gettype( $schema ),
							)
						);
						continue;
					}

					$description = $tool->get_description();

					// Add provider-specific fallback text for tools that require a different provider.
					$description = $this->maybe_add_provider_fallback_text( $tool, $description, $chat_provider );

					$tools_payload[] = array(
						'type'     => 'function',
						'function' => array(
							'name'        => $tool->get_slug(),
							'description' => $description,
							'parameters'  => $schema,
						),
					);
				} catch ( Exception $e ) {
					// Log the error and skip this tool.
					WP_MCP_AI_Logger::log_event(
						'error',
						'Tool schema generation failed in build_tools_payload',
						array(
							'tool_slug' => $slug,
							'error'     => $e->getMessage(),
							'trace'     => $e->getTraceAsString(),
						)
					);
					continue;
				} catch ( Error $e ) {
					// Catch PHP 7+ errors as well.
					WP_MCP_AI_Logger::log_event(
						'error',
						'Tool schema generation failed in build_tools_payload with PHP Error',
						array(
							'tool_slug' => $slug,
							'error'     => $e->getMessage(),
							'trace'     => $e->getTraceAsString(),
						)
					);
					continue;
				}
			}

			return $tools_payload;
		}

		/**
		 * Add provider-specific fallback text to tool descriptions when the tool requires a different provider.
		 *
		 * When a tool requires a specific provider (e.g., Gemini for image generation) but the chat
		 * is using a different provider (e.g., OpenAI), this method appends informative fallback text
		 * to help the LLM understand the tool's requirements and limitations.
		 *
		 * @param WP_MCP_AI_Tool_Interface $tool          The tool instance.
		 * @param string                   $description   The original tool description.
		 * @param string                   $chat_provider The current chat provider (e.g., 'openai', 'gemini').
		 * @return string The description with optional fallback text appended.
		 */
		protected function maybe_add_provider_fallback_text( $tool, $description, $chat_provider ) {
			// Only process tools that implement the rules interface.
			if ( ! $tool instanceof WP_MCP_AI_Tool_Rules_Interface ) {
				return $description;
			}

			$rules = $tool->get_tool_rules();

			// Check if the tool has provider requirements.
			if ( empty( $rules['model_requirements']['providers'] ) || ! is_array( $rules['model_requirements']['providers'] ) ) {
				return $description;
			}

			$required_providers = $rules['model_requirements']['providers'];

			// If the current chat provider is in the list of required providers, no fallback needed.
			if ( in_array( $chat_provider, $required_providers, true ) ) {
				return $description;
			}

			// Build fallback text based on the required providers.
			$fallback_parts = array();

			// Check for Gemini-specific tools when using OpenAI.
			if ( 'openai' === $chat_provider && in_array( 'gemini', $required_providers, true ) ) {
				$fallback_parts[] = sprintf(
					/* translators: %s: required provider name (e.g., "Gemini") */
					__( 'Note: This tool uses the %s API for image processing. A valid Gemini API key must be configured in the plugin settings.', 'wp-mcp-ai' ),
					'Gemini'
				);
			}

			// Check for OpenAI-specific tools when using Gemini.
			if ( 'gemini' === $chat_provider && in_array( 'openai', $required_providers, true ) ) {
				$fallback_parts[] = sprintf(
					/* translators: %s: required provider name (e.g., "OpenAI") */
					__( 'Note: This tool uses the %s API. A valid OpenAI API key must be configured in the plugin settings.', 'wp-mcp-ai' ),
					'OpenAI'
				);
			}

			// If no specific fallback text was generated, create a generic one.
			if ( empty( $fallback_parts ) && ! empty( $required_providers ) ) {
				$provider_list = implode( ', ', array_map( 'ucfirst', $required_providers ) );
				$fallback_parts[] = sprintf(
					/* translators: %s: comma-separated list of required providers */
					__( 'Note: This tool requires one of the following providers: %s.', 'wp-mcp-ai' ),
					$provider_list
				);
			}

			// Append fallback text to the description.
			if ( ! empty( $fallback_parts ) ) {
				$description = trim( $description ) . ' ' . implode( ' ', $fallback_parts );
			}

			return $description;
		}

		/**
		 * Prepare memory documents for inclusion with a chat request.
		 *
		 * @param array $file_ids Attachment identifiers.
		 * @return array|WP_Error
		 */
		protected function prepare_memory_documents( array $file_ids ) {
			if ( empty( $file_ids ) ) {
				return array();
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';

			global $wp_filesystem;

			if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
				WP_Filesystem();
			}

			$documents             = array();
			$total_chars           = 0;
			$total_bytes           = 0;
			$forbidden_file_ids    = array();
			$encountered_permitted = false;

			$max_total_bytes = (int) apply_filters( 'wp_mcp_ai_memory_max_total_bytes', self::MEMORY_MAX_TOTAL_BYTES, $file_ids );
			if ( $max_total_bytes <= 0 ) {
				$max_total_bytes = 0;
			}

			$max_document_bytes = (int) apply_filters( 'wp_mcp_ai_memory_max_document_bytes', self::MEMORY_MAX_DOCUMENT_BYTES, $file_ids );
			if ( $max_document_bytes <= 0 ) {
				$max_document_bytes = 0;
			}

			foreach ( $file_ids as $file_id ) {
				$file_id = absint( $file_id );
				if ( ! $file_id ) {
					continue;
				}

				$attachment = get_post( $file_id );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					continue;
				}

				if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $file_id ) ) {
					$forbidden_file_ids[] = $file_id;
					continue;
				}

				$encountered_permitted = true;

				$file_path = get_attached_file( $file_id );
				if ( ! $file_path ) {
					continue;
				}

				if ( ! file_exists( $file_path ) ) {
					return new WP_Error(
						'wp_mcp_ai_memory_file_missing',
						__( 'A memory file could not be located.', 'wp-mcp-ai' ),
						array(
							'status'  => 404,
							'file_id' => $file_id,
						)
					);
				}

				$file_size = filesize( $file_path );

				if ( false === $file_size ) {
					return new WP_Error(
						'wp_mcp_ai_memory_file_size_unknown',
						__( 'Could not determine the size of a memory file.', 'wp-mcp-ai' ),
						array(
							'status'  => 400,
							'file_id' => $file_id,
						)
					);
				}

				$max_bytes = (int) apply_filters( 'wp_mcp_ai_memory_max_file_bytes', self::MEMORY_MAX_FILE_BYTES, $file_id );

				if ( $file_size > $max_bytes ) {
					/* translators: 1: maximum allowed size in bytes, 2: detected file size in bytes. */
					$message = sprintf(
					/* translators: 1: maximum file size in bytes, 2: actual file size in bytes */
						__( 'Memory files must be smaller than %1$s bytes. The requested file is %2$s bytes.', 'wp-mcp-ai' ),
						number_format_i18n( $max_bytes ),
						number_format_i18n( $file_size )
					);

					return new WP_Error(
						'wp_mcp_ai_memory_file_too_large',
						$message,
						array(
							'status'    => 400,
							'file_id'   => $file_id,
							'max_bytes' => $max_bytes,
							'file_size' => (int) $file_size,
						)
					);
				}

				$mime_type       = get_post_mime_type( $file_id );
				$remaining_chars = self::MEMORY_MAX_TOTAL_CHARS - $total_chars;
				if ( $remaining_chars <= 0 ) {
					break;
				}

				$remaining_bytes = $max_total_bytes > 0 ? $max_total_bytes - $total_bytes : PHP_INT_MAX;
				if ( $remaining_bytes <= 0 ) {
					break;
				}

				$document_char_budget = min( self::MEMORY_MAX_DOCUMENT_CHARS, $remaining_chars );
				if ( $document_char_budget <= 0 ) {
					break;
				}

				$document_byte_budget = $max_document_bytes > 0 ? min( $max_document_bytes, $remaining_bytes ) : $remaining_bytes;
				if ( $document_byte_budget <= 0 ) {
					break;
				}

				$bytes_consumed = 0;
				$raw_text       = $this->extract_memory_text( $file_path, $mime_type, $document_char_budget, $document_byte_budget, $bytes_consumed );

				if ( is_wp_error( $raw_text ) ) {
					return $raw_text;
				}

				if ( '' === $raw_text ) {
					continue;
				}

				$normalized = $this->normalize_memory_text( $raw_text, $mime_type );
				if ( '' === $normalized ) {
					continue;
				}

				$chunk_data = $this->chunk_memory_text( $normalized, $total_chars );

				if ( empty( $chunk_data['chunks'] ) ) {
					continue;
				}

				$total_chars  = $chunk_data['total_chars'];
				$total_bytes += max( 0, (int) $bytes_consumed );

				$documents[] = array(
					'id'        => $file_id,
					'title'     => get_the_title( $attachment ),
					'mime_type' => $mime_type,
					'chunks'    => $chunk_data['chunks'],
					'truncated' => $chunk_data['truncated'],
				);

				if ( $total_chars >= self::MEMORY_MAX_TOTAL_CHARS ) {
					break;
				}
			}

			if ( empty( $documents ) && ! $encountered_permitted && ! empty( $forbidden_file_ids ) ) {
				return new WP_Error(
					'wp_mcp_ai_memory_files_forbidden',
					__( 'You do not have permission to use the requested memory files.', 'wp-mcp-ai' ),
					array(
						'status'        => 403,
						'forbidden_ids' => array_values( array_unique( $forbidden_file_ids ) ),
					)
				);
			}

			return $documents;
		}

		/**
		 * Extract text content from an attachment.
		 *
		 * @param string $file_path File system path.
		 * @param string $mime_type MIME type.
		 * @param int    $char_budget Maximum characters to extract (0 for unlimited).
		 * @param int    $byte_budget Maximum bytes to read (0 for unlimited).
		 * @param int    $bytes_consumed Reference variable for bytes consumed during extraction.
		 * @return string|WP_Error
		 */
		protected function extract_memory_text( $file_path, $mime_type, $char_budget = 0, $byte_budget = 0, &$bytes_consumed = 0 ) {
			$char_budget    = (int) $char_budget;
			$byte_budget    = (int) $byte_budget;
			$bytes_consumed = 0;

			if ( 'application/pdf' === $mime_type ) {
				if ( function_exists( 'wp_read_pdf' ) ) {
					$pdf_content = wp_read_pdf( $file_path );

					if ( is_array( $pdf_content ) && isset( $pdf_content['text'] ) ) {
						$text = (string) $pdf_content['text'];
					}

					if ( ! isset( $text ) && is_string( $pdf_content ) ) {
						$text = $pdf_content;
					}

					if ( isset( $text ) ) {
						if ( $byte_budget > 0 && strlen( $text ) > $byte_budget ) {
							if ( function_exists( 'mb_strcut' ) ) {
								$text = mb_strcut( $text, 0, $byte_budget, 'UTF-8' );
							} else {
								$text = substr( $text, 0, $byte_budget );
							}
						}

						if ( $char_budget > 0 && $this->mb_strlen( $text ) > $char_budget ) {
							$text = $this->mb_substr( $text, 0, $char_budget );
						}

						$bytes_consumed = strlen( $text );

						return $text;
					}
				}

				return '';
			}

			$docx_mimes = array(
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
				'application/vnd.ms-word.document.macroEnabled.12',
				'application/vnd.ms-word.template.macroEnabled.12',
			);

			if ( in_array( $mime_type, $docx_mimes, true ) ) {
				return $this->extract_docx_text( $file_path, $char_budget, $byte_budget, $bytes_consumed );
			}

			$textual_mimes = array(
				'text/',
				'application/json',
				'application/javascript',
				'application/xml',
				'application/rss+xml',
				'application/xhtml+xml',
			);

			$is_textual = 0 === strpos( $mime_type, 'text/' ) || in_array( $mime_type, $textual_mimes, true );

			if ( ! $is_textual ) {
				return '';
			}

			$contents = $this->read_file_contents( $file_path, $byte_budget, $bytes_consumed );

			if ( is_wp_error( $contents ) ) {
				return $contents;
			}

			$text = (string) $contents;

			if ( $byte_budget > 0 && strlen( $text ) > $byte_budget ) {
				if ( function_exists( 'mb_strcut' ) ) {
					$text = mb_strcut( $text, 0, $byte_budget, 'UTF-8' );
				} else {
					$text = substr( $text, 0, $byte_budget );
				}
			}

			if ( $char_budget > 0 && $this->mb_strlen( $text ) > $char_budget ) {
				$text = $this->mb_substr( $text, 0, $char_budget );
			}

			return $text;
		}

		/**
		 * Extract text from a DOCX-based file.
		 *
		 * @param string $file_path File system path.
		 * @param int    $char_budget Maximum characters to extract.
		 * @param int    $byte_budget Maximum bytes to read from the underlying XML stream.
		 * @param int    &$bytes_consumed Bytes consumed while reading the document.
		 * @return string
		 */
		protected function extract_docx_text( $file_path, $char_budget = 0, $byte_budget = 0, &$bytes_consumed = 0 ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return '';
			}

			$char_budget    = (int) $char_budget;
			$byte_budget    = (int) $byte_budget;
			$bytes_consumed = 0;

			$stream_path = 'zip://' . $file_path . '#word/document.xml';

			$reader = new XMLReader();
			if ( ! $reader->open( $stream_path, null, LIBXML_NONET ) ) {
				return '';
			}

			$paragraph_open = false;
			$text           = '';

			while ( $reader->read() ) {
				if ( $byte_budget > 0 && $bytes_consumed >= $byte_budget ) {
					break;
				}

				switch ( $reader->nodeType ) {
					case XMLReader::ELEMENT:
						switch ( $reader->name ) {
							case 'w:br':
							case 'w:cr':
								$text           .= "\n";
								$bytes_consumed += strlen( "\n" );
								break;
							case 'w:tab':
								$text           .= "\t";
								$bytes_consumed += strlen( "\t" );
								break;
							case 'w:p':
								$paragraph_open = true;
								break;
						}
						break;
					case XMLReader::END_ELEMENT:
						if ( 'w:p' === $reader->name && $paragraph_open ) {
							$paragraph_open  = false;
							$text           .= "\n";
							$bytes_consumed += strlen( "\n" );
						}
						break;
					case XMLReader::TEXT:
					case XMLReader::CDATA:
					case XMLReader::SIGNIFICANT_WHITESPACE:
					case XMLReader::WHITESPACE:
						$value = $reader->value;
						if ( '' === $value ) {
							break;
						}

						$value_bytes = strlen( $value );
						if ( $byte_budget > 0 && $bytes_consumed + $value_bytes > $byte_budget ) {
							$allowed     = max( 0, $byte_budget - $bytes_consumed );
							$value       = substr( $value, 0, $allowed );
							$value_bytes = strlen( $value );
						}

						$text           .= $value;
						$bytes_consumed += $value_bytes;
						break;
				}

				if ( $char_budget > 0 && $this->mb_strlen( $text ) >= $char_budget ) {
					break;
				}
			}

			$reader->close();

			if ( '' === $text ) {
				return '';
			}

			$text = trim( $text );

			if ( $char_budget > 0 && $this->mb_strlen( $text ) > $char_budget ) {
				$text = $this->mb_substr( $text, 0, $char_budget );
			}

			$bytes_consumed = max( $bytes_consumed, strlen( $text ) );

			return $text;
		}

		/**
		 * Read a file from disk using the WordPress filesystem when available.
		 *
		 * @param string $file_path File path.
		 * @param int    $byte_budget Maximum bytes to read.
		 * @param int    &$bytes_consumed Bytes consumed while reading the file.
		 * @return string
		 */
		protected function read_file_contents( $file_path, $byte_budget = 0, &$bytes_consumed = 0 ) {
			global $wp_filesystem;

			$byte_budget    = (int) $byte_budget;
			$bytes_consumed = 0;

			if ( $byte_budget <= 0 ) {
				$byte_budget = PHP_INT_MAX;
			}

			$chunk_size = (int) apply_filters( 'wp_mcp_ai_memory_read_chunk_bytes', 1024 * 1024, $file_path );
			if ( $chunk_size <= 0 ) {
				$chunk_size = 1024 * 1024;
			}

			if ( is_readable( $file_path ) ) {
				try {
					$file = new SplFileObject( $file_path, 'rb' );
				} catch ( RuntimeException $exception ) {
					return new WP_Error( 'wp_mcp_ai_memory_file_unreadable', __( 'Unable to read memory file contents.', 'wp-mcp-ai' ) );
				}

				$contents      = '';
				$bytes_allowed = $byte_budget;

				while ( ! $file->eof() && $bytes_allowed > 0 ) {
					$read_length = min( $chunk_size, $bytes_allowed );
					$chunk       = $file->fread( $read_length );

					if ( false === $chunk ) {
						return new WP_Error( 'wp_mcp_ai_memory_file_read_failed', __( 'Failed to read memory file contents.', 'wp-mcp-ai' ) );
					}

					$length = strlen( $chunk );

					if ( 0 === $length ) {
						break;
					}

					$contents       .= $chunk;
					$bytes_consumed += $length;
					$bytes_allowed  -= $length;
				}

				return $contents;
			}

			if ( $wp_filesystem instanceof WP_Filesystem_Base && $wp_filesystem->exists( $file_path ) ) {
				$contents = $wp_filesystem->get_contents( $file_path );
				if ( is_string( $contents ) ) {
					if ( $byte_budget < PHP_INT_MAX ) {
						$contents = substr( $contents, 0, $byte_budget );
					}

					$bytes_consumed = strlen( $contents );

					return $contents;
				}

				return new WP_Error( 'wp_mcp_ai_memory_file_read_failed', __( 'Failed to read memory file contents.', 'wp-mcp-ai' ) );
			}

			return new WP_Error( 'wp_mcp_ai_memory_file_unreadable', __( 'Unable to read memory file contents.', 'wp-mcp-ai' ) );
		}

		/**
		 * Normalise extracted text for downstream processing.
		 *
		 * @param string $text      Raw text.
		 * @param string $mime_type MIME type of the file.
		 * @return string
		 */
		protected function normalize_memory_text( $text, $mime_type ) {
			$text = (string) $text;

			if ( 'text/html' === $mime_type ) {
				$text = wp_strip_all_tags( $text );
			}

			$text = preg_replace( "/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/", ' ', $text );
			$text = preg_replace( "/\r\n|\r/", "\n", $text );
			$text = preg_replace( "/[ \t]+/", ' ', $text );
			$text = preg_replace( "/\n{3,}/", "\n\n", $text );

			return trim( $text );
		}

		/**
		 * Chunk and truncate text to the configured limits.
		 *
		 * @param string $text          Normalized text.
		 * @param int    $current_total Characters already accounted for in this request.
		 * @return array
		 */
		protected function chunk_memory_text( $text, &$current_total ) {
			$available_total = max( 0, self::MEMORY_MAX_TOTAL_CHARS - $current_total );

			if ( $available_total <= 0 ) {
				return array(
					'chunks'      => array(),
					'total_chars' => $current_total,
					'truncated'   => true,
				);
			}

			$length = $this->mb_strlen( $text );
			$limit  = min( $available_total, min( $length, self::MEMORY_MAX_DOCUMENT_CHARS ) );

			// If the document is significantly larger than limit, summarize instead of truncate.
			if ( $length > $limit * 2 && $limit > 500 ) {
				WP_MCP_AI_Logger::log_event(
					'memory_document_summarization',
					'Summarizing large memory document to fit budget.',
					array(
						'original_length' => $length,
						'target_length'   => $limit,
					)
				);

				$text   = WP_MCP_AI_Document_Summarizer::summarize_if_needed(
					$text,
					array(
						'force_summarize' => true,
						'target_chars'    => $limit,
					)
				);
				$length = $this->mb_strlen( $text );
				$limit  = min( $available_total, $length );
			}

			$chunks = array();

			for ( $offset = 0; $offset < $limit; $offset += self::MEMORY_CHUNK_CHARS ) {
				$remaining = $limit - $offset;
				$take      = min( self::MEMORY_CHUNK_CHARS, $remaining );
				$chunk     = trim( $this->mb_substr( $text, $offset, $take ) );

				if ( '' !== $chunk ) {
					$chunks[] = $chunk;
				}
			}

			$truncated = $limit < $this->mb_strlen( $text );

			if ( $truncated && ! empty( $chunks ) ) {
				$chunks[ count( $chunks ) - 1 ] .= "\n\n[" . __( 'Truncated', 'wp-mcp-ai' ) . ']';
			}

			$current_total += $limit;

			return array(
				'chunks'      => array_values( $chunks ),
				'total_chars' => $current_total,
				'truncated'   => $truncated,
			);
		}

		/**
		 * Extract a guest token from the incoming request headers or parameters.
		 *
		 * @param WP_REST_Request $request Request instance.
		 * @return string Guest token if supplied, otherwise empty string.
		 */
		protected function extract_guest_token( WP_REST_Request $request ) {
			return $this->authenticator->extract_guest_token( $request );
		}

		/**
		 * Retrieve chat transcript session summaries for a user.
		 *
		 * @param int $user_id      User identifier.
		 * @param int $per_page     Number of sessions to return.
		 * @param int $page         Results page.
		 * @param int $assistant_id Optional assistant ID to filter by.
		 * @return array|WP_Error
		 */
		public function get_transcript_sessions( $user_id, $per_page, $page, $assistant_id = 0 ) {
			$repository = $this->get_transcript_repository();
			$result     = $repository->get_sessions( $user_id, $per_page, $page, $assistant_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Format the raw database rows into session summaries.
			$sessions = array();
			foreach ( $result['items'] as $row ) {
				$sessions[] = $this->format_transcript_session_summary( $row, $user_id );
			}

			return array(
				'items' => $sessions,
				'total' => $result['total'],
			);
		}

		/**
		 * Retrieve the full transcript for a specific session.
		 *
		 * @param int    $user_id      User identifier.
		 * @param string $session_key  Session key string.
		 * @param int    $assistant_id Optional assistant ID to filter by.
		 * @return array|WP_Error
		 */
		public function get_transcript_session( $user_id, $session_key, $assistant_id = 0 ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'get_transcript_session called',
				array(
					'raw_session_key' => $session_key,
					'raw_user_id'     => $user_id,
				)
			);

			$session_key = $this->normalise_transcript_session_key( $session_key );

			WP_MCP_AI_Logger::log_event(
				'debug',
				'session_key after normalization',
				array(
					'normalised_session_key' => $session_key,
				)
			);

			if ( '' === $session_key ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'get_transcript_session: session_key is empty after normalization'
				);

				return new WP_Error(
					'wp_mcp_ai_transcript_missing',
					__( 'The requested chat transcript could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 404 )
				);
			}

			// Use the repository to retrieve raw database rows with fallback logic.
			$repository = $this->get_transcript_repository();
			$rows       = $repository->get_session( $user_id, $session_key, $assistant_id );

			// Handle errors from repository.
			if ( is_wp_error( $rows ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'get_transcript_session: repository returned error',
					array(
						'error_code'    => $rows->get_error_code(),
						'error_message' => $rows->get_error_message(),
						'user_id'       => $user_id,
						'session_key'   => $session_key,
						'assistant_id'  => $assistant_id,
					)
				);

				return $rows;
			}

			WP_MCP_AI_Logger::log_event(
				'debug',
				'get_transcript_session: retrieved rows from repository',
				array(
					'row_count'    => count( $rows ),
					'user_id'      => $user_id,
					'session_key'  => $session_key,
					'assistant_id' => $assistant_id,
				)
			);

			$assistant_id    = 0;
			$assistant_model = '';
			$messages        = array();
			$turn_count      = 0;
			$started_at      = '';
			$updated_at      = '';

			foreach ( $rows as $row ) {
				if ( ! $assistant_id && ! empty( $row['assistant_id'] ) ) {
					$assistant_id = (int) $row['assistant_id'];
				}

				if ( '' === $assistant_model && ! empty( $row['assistant_model'] ) ) {
					$assistant_model = sanitize_text_field( $row['assistant_model'] );
				}

				if ( '' === $started_at ) {
					$started_at = $this->format_transcript_timestamp( $row['request_started_at'], $row['cct_created'] );
				}

				$updated_at = $this->format_transcript_timestamp( $row['response_completed_at'], $row['cct_created'] );

				WP_MCP_AI_Logger::log_event(
					'debug',
					'get_transcript_session: processing row',
					array(
						'has_request_payload'   => ! empty( $row['request_payload'] ) ? 'yes' : 'no',
						'has_response_payload'  => ! empty( $row['response_payload'] ) ? 'yes' : 'no',
						'current_message_count' => count( $messages ),
					)
				);

				$request_messages       = $this->extract_request_messages( $row );
				$response_messages      = $this->extract_response_messages( $row );
				$pending_tool_responses = array();

				WP_MCP_AI_Logger::log_event(
					'debug',
					'get_transcript_session: extracted messages from row',
					array(
						'request_messages_count'  => count( $request_messages ),
						'response_messages_count' => count( $response_messages ),
					)
				);

				if ( ! empty( $request_messages ) ) {
					$existing_count = count( $messages );

					$this->append_new_messages( $messages, $request_messages, $row['request_started_at'], $row['cct_created'] );

					$pending_tool_responses = $this->extract_appended_tool_responses( $messages, $existing_count );

					WP_MCP_AI_Logger::log_event(
						'debug',
						'get_transcript_session: appended request messages',
						array(
							'new_message_count'            => count( $messages ) - $existing_count,
							'pending_tool_responses_count' => count( $pending_tool_responses ),
						)
					);
				}

				$before_response = count( $messages );
				$this->append_new_messages( $messages, $response_messages, $row['response_completed_at'], $row['cct_created'] );

				WP_MCP_AI_Logger::log_event(
					'debug',
					'get_transcript_session: appended response messages',
					array(
						'new_message_count' => count( $messages ) - $before_response,
					)
				);

				if ( ! empty( $pending_tool_responses ) ) {
					$before_tool = count( $messages );
					$this->append_new_messages( $messages, $pending_tool_responses, $row['response_completed_at'], $row['cct_created'] );

					WP_MCP_AI_Logger::log_event(
						'debug',
						'get_transcript_session: appended pending tool responses',
						array(
							'new_message_count' => count( $messages ) - $before_tool,
						)
					);
				}

				if ( ! empty( $response_messages ) ) {
					$turn_count += count( $response_messages );
				}
			}

			WP_MCP_AI_Logger::log_event(
				'debug',
				'get_transcript_session: final reconstruction complete',
				array(
					'total_messages' => count( $messages ),
					'turn_count'     => $turn_count,
				)
			);

			if ( $turn_count <= 0 ) {
				$turn_count = count( $messages );
			}

			$assistant_title = '';

			if ( $assistant_id ) {
				$assistant_title = get_the_title( $assistant_id );

				if ( ! is_string( $assistant_title ) ) {
					$assistant_title = '';
				} else {
					$assistant_title = wp_strip_all_tags( $assistant_title );
				}
			}

			return array(
				'session_key'     => $session_key,
				'assistant_id'    => $assistant_id,
				'assistant_title' => $assistant_title,
				'assistant_model' => $assistant_model,
				'started_at'      => $started_at,
				'updated_at'      => $updated_at,
				'turn_count'      => $turn_count,
				'messages'        => $messages,
			);
		}

		/**
		 * Determine the name of the transcript database table.
		 *
		 * @return string
		 */
		protected function get_transcript_table_name() {
			global $wpdb;

			if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
				return '';
			}

			$slug = WP_MCP_AI_JetEngine_CCT::get_slug();

			if ( '' === $slug ) {
				return '';
			}

			return $wpdb->prefix . 'jet_cct_' . $slug;
		}

		/**
		 * Confirm whether the transcript table exists in the database.
		 *
		 * @return bool
		 */
		protected function transcript_table_exists() {
			global $wpdb;

			$table = $this->get_transcript_table_name();

			if ( '' === $table ) {
				return false;
			}

			$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			return $result === $table;
		}

		/**
		 * Normalise a raw session key into a safe identifier.
		 *
		 * @param mixed $value Raw session key value.
		 * @return string
		 */
		public function normalise_transcript_session_key( $value ) {
			if ( ! is_scalar( $value ) ) {
				return '';
			}

			$value = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value );

			if ( ! is_string( $value ) || '' === $value ) {
				return '';
			}

			$max = 96;

			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				$max = (int) WP_MCP_AI_Chat_Transcript_Recorder::MAX_SESSION_KEY_LENGTH;
			}

			return substr( $value, 0, $max );
		}

		/**
		 * Get transcript repository instance
		 *
		 * @return WP_MCP_AI_Transcript_Repository Transcript repository instance.
		 */
		public function get_transcript_repository() {
			if ( null === $this->transcript_repository ) {
				$this->transcript_repository = wp_mcp_ai_get_transcript_repository();
			}
			return $this->transcript_repository;
		}

		/**
		 * Get OpenAI client instance (lazy-loaded from container).
		 *
		 * @return WP_MCP_AI_OpenAI_Client OpenAI client instance.
		 */
		protected function get_openai_client() {
			if ( null === $this->openai_client ) {
				$container           = wp_mcp_ai_container();
				$this->openai_client = $container->get( 'client.openai' );
			}
			return $this->openai_client;
		}

		/**
		 * Get Cron Status Service instance (lazy-loaded from container).
		 *
		 * @return WP_MCP_AI_Cron_Status_Service Cron Status Service instance.
		 */
		protected function get_cron_status_service() {
			if ( null === $this->cron_status_service ) {
				$container                 = wp_mcp_ai_container();
				$this->cron_status_service = $container->get( 'service.cron_status' );
			}
			return $this->cron_status_service;
		}

		/**
		 * Format a timestamp string for API responses.
		 *
		 * @param string $primary  Primary timestamp string.
		 * @param string $fallback Fallback timestamp string.
		 * @return string
		 */
		protected function format_transcript_timestamp( $primary, $fallback = '' ) {
			// Try primary value first (can be integer timestamp or string).
			$timestamp = false;

			if ( is_numeric( $primary ) && $primary > 0 ) {
				$timestamp = (int) $primary;
			} elseif ( is_string( $primary ) && '' !== $primary ) {
				$timestamp = strtotime( $primary );
			}

			// Try fallback if primary didn't work.
			if ( false === $timestamp && is_string( $fallback ) && '' !== $fallback ) {
				$timestamp = strtotime( $fallback );
			}

			if ( false === $timestamp || $timestamp <= 0 ) {
				return '';
			}

			return gmdate( 'c', $timestamp );
		}

		/**
		 * Prepare a single session summary entry for REST responses.
		 *
		 * @param array $row     Database row.
		 * @param int   $user_id User identifier.
		 * @return array
		 */
		protected function format_transcript_session_summary( array $row, $user_id ) {
			$session_key     = isset( $row['session_key'] ) ? $this->normalise_transcript_session_key( $row['session_key'] ) : '';
			$assistant_id    = isset( $row['assistant_id'] ) ? (int) $row['assistant_id'] : 0;
			$assistant_model = isset( $row['assistant_model'] ) ? sanitize_text_field( $row['assistant_model'] ) : '';
			$assistant_title = '';

			if ( $assistant_id ) {
				$assistant_title = get_the_title( $assistant_id );

				if ( ! is_string( $assistant_title ) ) {
					$assistant_title = '';
				} else {
					$assistant_title = wp_strip_all_tags( $assistant_title );
				}
			}

			$preview = '';

			if ( '' !== $session_key ) {
				$preview = $this->get_session_preview_text( $session_key, $user_id );
			}

			return array(
				'session_key'     => $session_key,
				'assistant_id'    => $assistant_id,
				'assistant_title' => $assistant_title,
				'assistant_model' => $assistant_model,
				'started_at'      => $this->format_transcript_timestamp( isset( $row['started_at'] ) ? $row['started_at'] : '', isset( $row['first_created'] ) ? $row['first_created'] : '' ),
				'completed_at'    => $this->format_transcript_timestamp( isset( $row['completed_at'] ) ? $row['completed_at'] : '', isset( $row['last_created'] ) ? $row['last_created'] : '' ),
				'updated_at'      => $this->format_transcript_timestamp( isset( $row['last_created'] ) ? $row['last_created'] : '', isset( $row['completed_at'] ) ? $row['completed_at'] : '' ),
				'turn_count'      => isset( $row['turn_count'] ) ? (int) $row['turn_count'] : 0,
				'preview'         => $preview,
			);
		}

		/**
		 * Extract a preview snippet from the earliest turn in the session.
		 *
		 * @param string $session_key Session key string.
		 * @param int    $user_id     User identifier.
		 * @return string
		 */
		protected function get_session_preview_text( $session_key, $user_id ) {
			global $wpdb;

			if ( '' === $session_key ) {
				return '';
			}

			$table = $this->get_transcript_table_name();

			$query = $wpdb->prepare(
				"SELECT request_payload
             FROM {$table}
             WHERE session_key = %s AND cct_author_id = %d
             ORDER BY cct_created ASC
             LIMIT 1",
				$session_key,
				absint( $user_id )
			);

			$row = $wpdb->get_row( $query, ARRAY_A );

			if ( empty( $row['request_payload'] ) ) {
				return '';
			}

			$payload = json_decode( $row['request_payload'], true );

			if ( ! is_array( $payload ) || empty( $payload['messages'] ) || ! is_array( $payload['messages'] ) ) {
				return '';
			}

			foreach ( $payload['messages'] as $message ) {
				if ( isset( $message['role'] ) && 'user' === $message['role'] ) {
					$text = $this->prepare_message_text( $message );

					if ( '' !== $text ) {
						return $text;
					}
				}
			}

			return '';
		}

		/**
		 * Normalise arbitrary text extracted from transcript payloads.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		protected function clean_transcript_text( $text ) {
			if ( ! is_string( $text ) ) {
				return '';
			}

			$text = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $text );
			$text = wp_specialchars_decode( $text, ENT_QUOTES );
			$text = wp_strip_all_tags( $text );
			$text = preg_replace( '/\r\n|\r/', "\n", $text );
			$text = preg_replace( '/\n{3,}/', "\n\n", $text );

			if ( ! is_string( $text ) ) {
				return '';
			}

			return trim( $text );
		}

		/**
		 * Convert structured message content into readable text.
		 *
		 * @param mixed $content Raw content value.
		 * @return string
		 */
		protected function normalise_message_content( $content ) {
			if ( is_string( $content ) ) {
				return $this->clean_transcript_text( $content );
			}

			if ( is_array( $content ) ) {
				$parts = $this->collect_message_content_fragments( $content );

				if ( ! empty( $parts ) ) {
					return $this->clean_transcript_text( implode( "\n\n", $parts ) );
				}
			}

			if ( is_scalar( $content ) ) {
				return $this->clean_transcript_text( (string) $content );
			}

			return '';
		}

		/**
		 * Extract text content from tool result messages.
		 *
		 * When an LLM returns an empty response after tool execution in the agentic loop,
		 * this method extracts descriptive text from the tool results themselves.
		 * This ensures the chat UI always has content to display.
		 *
		 * @since 1.0.0
		 * @param array $tool_result_messages Array of tool result messages from agentic loop.
		 * @return string Extracted text content, or empty string if none found.
		 */
		protected function extract_text_from_tool_results( $tool_result_messages ) {
			if ( empty( $tool_result_messages ) || ! is_array( $tool_result_messages ) ) {
				return '';
			}

			$text_parts = array();

			foreach ( $tool_result_messages as $tool_message ) {
				if ( ! isset( $tool_message['content'] ) || '' === $tool_message['content'] ) {
					continue;
				}

				$content = $tool_message['content'];

				// Tool result content can be a JSON string or already an array.
				if ( is_string( $content ) ) {
					$decoded = json_decode( $content, true );
					if ( is_array( $decoded ) ) {
						$content = $decoded;
					}
				}

				// Extract text field from tool result.
				if ( is_array( $content ) && isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$text = trim( $content['text'] );
					if ( '' !== $text ) {
						$text_parts[] = $text;
					}
				} elseif ( is_string( $content ) ) {
					// If content is just a string, use it directly.
					$text = trim( $content );
					if ( '' !== $text ) {
						$text_parts[] = $text;
					}
				}
			}

			if ( empty( $text_parts ) ) {
				return '';
			}

			// Join multiple tool results with double newlines.
			return implode( "\n\n", $text_parts );
		}

		/**
		 * Recursively extract readable fragments from structured message content.
		 *
		 * @param mixed $value Arbitrary content value.
		 * @return array
		 */
		protected function collect_message_content_fragments( $value ) {
			$fragments = array();

			if ( is_string( $value ) ) {
				$fragments[] = $value;

				return $fragments;
			}

			if ( is_scalar( $value ) ) {
				$fragments[] = (string) $value;

				return $fragments;
			}

			if ( ! is_array( $value ) ) {
				return $fragments;
			}

			if ( $this->is_sequential_array( $value ) ) {
				foreach ( $value as $child ) {
					$fragments = array_merge( $fragments, $this->collect_message_content_fragments( $child ) );
				}

				return $fragments;
			}

			$keys_to_extract = array(
				'text',
				'content',
				'value',
				'output_text',
				'input_text',
				'result',
				'output',
				'message',
				'summary',
				'description',
				'details',
				'body',
				'response',
				'caption',
				'notes',
				'note',
				'answer',
			);

			$keys_to_skip = array(
				'type',
				'role',
				'id',
				'tool_call_id',
				'tool',
				'name',
				'slug',
				'index',
				'finish_reason',
				'object',
				'model',
				'provider',
				'status',
				'status_code',
				'code',
				'created',
				'created_at',
				'usage',
				'metadata',
				'headers',
				'function',
				'arguments',
				'tool_calls',
				'tools',
				'assistant_id',
				'assistant_model',
				'session_key',
				'latency_ms',
				'request_started_at',
				'response_completed_at',
				'cct_created',
				'mime_type',
				'file_id',
				'download_url',
				'downloadurl',
				'url',
				'permalink',
				'href',
				'image',
				'image_url',
				'imagefile',
				'image_file',
				'height',
				'width',
				'size',
				'bytes',
				'quality',
				'format',
				'duration_formatted',
				'prompt',
				'system_prompt',
				'temperature',
				'top_p',
				'top_k',
				'max_output_tokens',
				'stop',
				'stop_sequences',
				'frequency_penalty',
				'presence_penalty',
				'seed',
				'n',
				'attachments',
				'attachment',
				'attachment_id',
				'file_ids',
				'display_name',
				'options',
				'config',
				'settings',
				'params',
				'parameters',
				'context',
				'actions',
				'action',
				'request',
			);

			foreach ( $value as $key => $child ) {
				$normalised_key = is_string( $key ) ? sanitize_key( $key ) : '';

				if ( in_array( $normalised_key, $keys_to_skip, true ) ) {
					continue;
				}

				if ( in_array( $normalised_key, $keys_to_extract, true ) ) {
					$fragments = array_merge(
						$fragments,
						$this->collect_message_content_fragments( $child )
					);
					continue;
				}

				if ( is_array( $child ) ) {
					$fragments = array_merge(
						$fragments,
						$this->collect_message_content_fragments( $child )
					);
					continue;
				}

				if ( is_string( $child ) || is_numeric( $child ) ) {
					$text = $this->clean_transcript_text( (string) $child );

					if ( '' !== $text ) {
						$fragments[] = $text;
					}
				}
			}

			return $fragments;
		}

		/**
		 * Determine whether an array is sequentially indexed.
		 *
		 * @param array $array Array to inspect.
		 * @return bool
		 */
		protected function is_sequential_array( $array ) {
			if ( ! is_array( $array ) ) {
				return false;
			}

			if ( array() === $array ) {
				return true;
			}

			return array_keys( $array ) === range( 0, count( $array ) - 1 );
		}

		/**
		 * Build a readable string for a message payload.
		 *
		 * @param array $message Message payload array.
		 * @return string
		 */
		protected function prepare_message_text( $message ) {
			if ( ! is_array( $message ) ) {
				return '';
			}

			if ( isset( $message['content'] ) ) {
				$text = $this->normalise_message_content( $message['content'] );

				if ( '' !== $text ) {
					return $text;
				}
			}

			if ( isset( $message['text'] ) ) {
				$text = $this->normalise_message_content( $message['text'] );

				if ( '' !== $text ) {
					return $text;
				}
			}

			if ( isset( $message['value'] ) ) {
				$text = $this->normalise_message_content( $message['value'] );

				if ( '' !== $text ) {
					return $text;
				}
			}

			return '';
		}

		/**
		 * Check if a message contains image content (image_url or image_file).
		 *
		 * @param array $message Message array to check.
		 * @return bool True if the message contains image content, false otherwise.
		 */
		protected function message_has_image_content( $message ) {
			if ( ! is_array( $message ) || ! isset( $message['content'] ) ) {
				return false;
			}

			$content = $message['content'];

			// Content must be an array to contain image segments
			if ( ! is_array( $content ) ) {
				return false;
			}

			// Check if content is a sequential array of segments
			if ( ! $this->is_sequential_array( $content ) ) {
				return false;
			}

			// Look for image_url or image_file type segments
			foreach ( $content as $segment ) {
				if ( ! is_array( $segment ) || ! isset( $segment['type'] ) ) {
					continue;
				}

				$type = sanitize_key( $segment['type'] );
				if ( 'image_url' === $type || 'image_file' === $type ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Extract request messages from a transcript row.
		 *
		 * @param array $row Database row.
		 * @return array
		 */
		protected function extract_request_messages( array $row ) {
			if ( empty( $row['request_payload'] ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'extract_request_messages: empty request_payload',
					array( 'row_keys' => array_keys( $row ) )
				);
				return array();
			}

			$payload = json_decode( $row['request_payload'], true );

			if ( ! is_array( $payload ) || empty( $payload['messages'] ) || ! is_array( $payload['messages'] ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'extract_request_messages: invalid payload structure',
					array(
						'is_array'          => is_array( $payload ),
						'has_messages'      => isset( $payload['messages'] ) ? 'yes' : 'no',
						'is_messages_array' => isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? 'yes' : 'no',
					)
				);
				return array();
			}

			$messages = array();

			foreach ( $payload['messages'] as $index => $message ) {
				if ( ! is_array( $message ) ) {
					WP_MCP_AI_Logger::log_event(
						'debug',
						'extract_request_messages: skipping non-array message',
						array(
							'index' => $index,
							'type'  => gettype( $message ),
						)
					);
					continue;
				}

				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

				if ( '' === $role ) {
					WP_MCP_AI_Logger::log_event(
						'debug',
						'extract_request_messages: skipping message with empty role',
						array(
							'index'        => $index,
							'message_keys' => array_keys( $message ),
						)
					);
					continue;
				}

				$content = $this->prepare_message_text( $message );

				// Check if message has image content (even if text content is empty)
				$has_image_content = $this->message_has_image_content( $message );

				// Skip messages with empty content, except:
				// - tool role messages (required for tool responses)
				// - system role messages (can be empty for context)
				// - assistant role messages with tool_calls (required for agentic flow)
				// - messages with image content (required to preserve images in chat)
				$has_tool_calls = 'assistant' === $role && isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) && ! empty( $message['tool_calls'] );

				if ( '' === $content && 'tool' !== $role && 'system' !== $role && ! $has_tool_calls && ! $has_image_content ) {
					WP_MCP_AI_Logger::log_event(
						'debug',
						'extract_request_messages: skipping message with empty content',
						array(
							'index' => $index,
							'role'  => $role,
						)
					);
					continue;
				}

				$message_entry = array(
					'role'    => $role,
					'content' => $content,
				);

				// If message has image content, preserve the original content structure
				// instead of the extracted text (which would be empty for image-only messages)
				if ( $has_image_content && isset( $message['content'] ) ) {
					$message_entry['content'] = $message['content'];
				}

				// Preserve tool_call_id for tool messages (required by OpenAI for proper request validation).
				if ( 'tool' === $role && isset( $message['tool_call_id'] ) && '' !== $message['tool_call_id'] ) {
					$message_entry['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
				}

				// Preserve name for tool messages (optional but helpful for debugging).
				if ( 'tool' === $role && isset( $message['name'] ) && '' !== $message['name'] ) {
					$message_entry['name'] = sanitize_text_field( $message['name'] );
				}

				// Preserve tool_calls for assistant messages (required when assistant makes tool calls).
				if ( $has_tool_calls ) {
					$message_entry['tool_calls'] = $message['tool_calls'];
				}

				$messages[] = $message_entry;
			}

			WP_MCP_AI_Logger::log_event(
				'debug',
				'extract_request_messages: extracted messages',
				array( 'count' => count( $messages ) )
			);

			return $messages;
		}

		/**
		 * Extract assistant response messages from a transcript row.
		 *
		 * @param array $row Database row.
		 * @return array
		 */
		protected function extract_response_messages( array $row ) {
			if ( empty( $row['response_payload'] ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'extract_response_messages: empty response_payload',
					array( 'row_keys' => array_keys( $row ) )
				);
				return array();
			}

			$payload = json_decode( $row['response_payload'], true );

			if ( ! is_array( $payload ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'extract_response_messages: payload is not an array',
					array( 'type' => gettype( $payload ) )
				);
				return array();
			}

			$messages = array();

			if ( isset( $payload['choices'] ) && is_array( $payload['choices'] ) ) {
				foreach ( $payload['choices'] as $choice_index => $choice ) {
					if ( empty( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
						WP_MCP_AI_Logger::log_event(
							'debug',
							'extract_response_messages: skipping choice with invalid message',
							array(
								'choice_index' => $choice_index,
								'has_message'  => isset( $choice['message'] ) ? 'yes' : 'no',
								'is_array'     => isset( $choice['message'] ) && is_array( $choice['message'] ) ? 'yes' : 'no',
							)
						);
						continue;
					}

					$role    = isset( $choice['message']['role'] ) ? sanitize_key( $choice['message']['role'] ) : 'assistant';
					$content = $this->prepare_message_text( $choice['message'] );

					// Always include assistant messages, even with empty content, if they have tool_calls or image content.
					$has_tool_calls    = ! empty( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] );
					$has_image_content = $this->message_has_image_content( $choice['message'] );

					if ( '' !== $content || 'tool' === $role || $has_tool_calls || $has_image_content ) {
						$message_entry = array(
							'role'    => $role,
							'content' => $content,
						);

						// If message has image content, preserve the original content structure
						// instead of the extracted text (which would be empty for image-only messages)
						if ( $has_image_content && isset( $choice['message']['content'] ) ) {
							$message_entry['content'] = $choice['message']['content'];
						}

						// Preserve tool_calls in the assistant message for proper OpenAI schema compliance.
						// Tool calls should remain in the assistant message, not be converted to tool role messages.
						// Only actual tool responses (with tool_call_id) should be tool role messages.
						if ( $has_tool_calls ) {
							$message_entry['tool_calls'] = $choice['message']['tool_calls'];
						}

						$messages[] = $message_entry;

						WP_MCP_AI_Logger::log_event(
							'debug',
							'extract_response_messages: added message',
							array(
								'choice_index'      => $choice_index,
								'role'              => $role,
								'content_length'    => strlen( $content ),
								'has_tool_calls'    => $has_tool_calls ? 'yes' : 'no',
								'has_image_content' => $has_image_content ? 'yes' : 'no',
							)
						);
					} else {
						WP_MCP_AI_Logger::log_event(
							'debug',
							'extract_response_messages: skipping message with empty content',
							array(
								'choice_index' => $choice_index,
								'role'         => $role,
							)
						);
					}
				}
			} else {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'extract_response_messages: payload has no choices array',
					array( 'payload_keys' => array_keys( $payload ) )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'debug',
				'extract_response_messages: extracted messages',
				array( 'count' => count( $messages ) )
			);

			return $messages;
		}

		/**
		 * Generate a readable message describing a tool call.
		 *
		 * @param array $tool_call Tool call payload.
		 * @return string
		 */
		protected function format_tool_call_message( $tool_call ) {
			if ( empty( $tool_call ) || ! is_array( $tool_call ) ) {
				return '';
			}

			$name = '';

			if ( isset( $tool_call['function'] ) && is_array( $tool_call['function'] ) ) {
				if ( isset( $tool_call['function']['name'] ) ) {
					$name = sanitize_text_field( $tool_call['function']['name'] );
				}
			} elseif ( isset( $tool_call['name'] ) ) {
				$name = sanitize_text_field( $tool_call['name'] );
			}

			$arguments = '';

			if ( isset( $tool_call['function']['arguments'] ) && is_string( $tool_call['function']['arguments'] ) ) {
				$arguments = $this->clean_transcript_text( $tool_call['function']['arguments'] );
			} elseif ( isset( $tool_call['arguments'] ) && is_string( $tool_call['arguments'] ) ) {
				$arguments = $this->clean_transcript_text( $tool_call['arguments'] );
			}

			$parts = array();

			if ( '' !== $name ) {
				/* translators: %s: tool name */
				$parts[] = sprintf( __( 'Tool call: %s', 'wp-mcp-ai' ), $name );
			}

			if ( '' !== $arguments ) {
				$parts[] = $arguments;
			}

			if ( empty( $parts ) ) {
				return '';
			}

			return $this->clean_transcript_text( implode( "\n", $parts ) );
		}

		/**
		 * Append new messages to the conversation, avoiding duplicates.
		 *
		 * @param array  $conversation      Current conversation array (passed by reference).
		 * @param array  $new_messages      New messages to append.
		 * @param string $primary_timestamp Primary timestamp.
		 * @param string $fallback_timestamp Fallback timestamp.
		 */
		protected function append_new_messages( array &$conversation, array $new_messages, $primary_timestamp, $fallback_timestamp ) {
			if ( empty( $new_messages ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'append_new_messages: no new messages to append'
				);
				return;
			}

			$timestamp      = $this->format_transcript_timestamp( $primary_timestamp, $fallback_timestamp );
			$existing_count = count( $conversation );
			$new_count      = count( $new_messages );
			$position       = 0;

			WP_MCP_AI_Logger::log_event(
				'debug',
				'append_new_messages: starting append process',
				array(
					'existing_count' => $existing_count,
					'new_count'      => $new_count,
					'timestamp'      => $timestamp,
				)
			);

			while ( $position < $existing_count && $position < $new_count ) {
				if ( ! $this->messages_match( $conversation[ $position ], $new_messages[ $position ] ) ) {
					WP_MCP_AI_Logger::log_event(
						'debug',
						'append_new_messages: found first non-matching message at position',
						array( 'position' => $position )
					);
					break;
				}

				++$position;
			}

			$added_count = 0;
			for ( $index = $position; $index < $new_count; $index++ ) {
				$message              = $new_messages[ $index ];
				$message['timestamp'] = $timestamp;
				$conversation[]       = $message;
				++$added_count;
			}

			WP_MCP_AI_Logger::log_event(
				'debug',
				'append_new_messages: completed append',
				array(
					'skipped_duplicates' => $position,
					'added_count'        => $added_count,
					'final_count'        => count( $conversation ),
				)
			);
		}

		/**
		 * Capture any tool responses appended during the current request pass.
		 *
		 * Tool results are initially captured from the chat request payload which
		 * causes them to appear before the matching assistant tool call in the
		 * reconstructed conversation. Moving those responses after the tool call
		 * keeps the transcript in the same order that users observed in the chat
		 * interface.
		 *
		 * @param array $conversation  Current conversation (passed by reference).
		 * @param int   $start_index   Index where new messages were appended.
		 * @return array
		 */
		protected function extract_appended_tool_responses( array &$conversation, $start_index ) {
			$total_messages = count( $conversation );

			if ( $start_index >= $total_messages ) {
				return array();
			}

			$appended_messages = array_slice( $conversation, $start_index );
			$conversation      = array_slice( $conversation, 0, $start_index );
			$tool_responses    = array();

			foreach ( $appended_messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role    = isset( $message['role'] ) ? (string) $message['role'] : '';
				$content = isset( $message['content'] ) ? (string) $message['content'] : '';

				if ( 'tool' === $role && '' !== $content ) {
					if ( isset( $message['timestamp'] ) ) {
						unset( $message['timestamp'] );
					}

					$tool_responses[] = $message;
					continue;
				}

				$conversation[] = $message;
			}

			return $tool_responses;
		}

		/**
		 * Compare two message structures.
		 *
		 * @param array $existing Existing message.
		 * @param array $candidate Candidate message.
		 * @return bool
		 */
		protected function messages_match( $existing, $candidate ) {
			if ( ! is_array( $existing ) || ! is_array( $candidate ) ) {
				return false;
			}

			$existing_role  = isset( $existing['role'] ) ? (string) $existing['role'] : '';
			$candidate_role = isset( $candidate['role'] ) ? (string) $candidate['role'] : '';
			$existing_text  = isset( $existing['content'] ) ? (string) $existing['content'] : '';
			$candidate_text = isset( $candidate['content'] ) ? (string) $candidate['content'] : '';

			return $existing_role === $candidate_role && $existing_text === $candidate_text;
		}

		/**
		 * Multibyte-safe string length helper.
		 *
		 * @param string $string String to measure.
		 * @return int
		 */
		protected function mb_strlen( $string ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $string ) : strlen( $string );
		}

		/**
		 * Multibyte-safe substring helper.
		 *
		 * @param string $string Input string.
		 * @param int    $start  Start position.
		 * @param int    $length Length of substring.
		 * @return string
		 */
		protected function mb_substr( $string, $start, $length ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $string, $start, $length ) : substr( $string, $start, $length );
		}

		/**
		 * Stream text in chunks via SSE with configurable format.
		 *
		 * Follows Separation of Concerns by extracting common chunking logic
		 * into a reusable method. Supports both thinking text and content streaming.
		 *
		 * @param string   $text         Text to stream.
		 * @param callable $formatter    Callback to format each chunk for SSE event.
		 * @param string   $log_type     Type label for logging (e.g., 'thinking', 'text').
		 * @param int      $assistant_id Assistant ID for logging.
		 */
		protected function stream_text_chunks( $text, $formatter, $log_type, $assistant_id ) {
			if ( ! is_string( $text ) || '' === $text ) {
				return;
			}

			$chunk_size = self::STREAMING_CHUNK_SIZE;
			$text_len   = $this->mb_strlen( $text );

			// Log streaming start for debugging.
			WP_MCP_AI_Logger::log_event(
				'debug',
				sprintf( 'SSE Streaming: Starting to send %s chunks', $log_type ),
				array(
					'text_length'  => $text_len,
					'chunk_size'   => $chunk_size,
					'num_chunks'   => ceil( $text_len / $chunk_size ),
					'assistant_id' => $assistant_id,
					'type'         => $log_type,
				)
			);

			// Check once if usleep is available before the loop.
			$can_sleep = function_exists( 'usleep' );

			// Send chunks with delay to simulate realistic streaming.
			for ( $i = 0; $i < $text_len; $i += $chunk_size ) {
				$chunk = $this->mb_substr( $text, $i, $chunk_size );

				// Use formatter callback to create the SSE event payload.
				$payload = call_user_func( $formatter, $chunk );
				$this->send_sse_event( 'message', $payload );

				// Small delay between chunks to simulate realistic streaming.
				if ( $can_sleep ) {
					usleep( self::STREAMING_CHUNK_DELAY_US );
				}
			}
		}

		/**
		 * Extract tool calls from an LLM response.
		 *
		 * @param array $response LLM response array.
		 * @return array Array of tool call objects.
		 */
		protected function extract_tool_calls_from_response( $response ) {
			if ( ! is_array( $response ) ) {
				return array();
			}

			// Check for tool_calls in the response.
			if ( isset( $response['tool_calls'] ) && is_array( $response['tool_calls'] ) ) {
				return $response['tool_calls'];
			}

			// Check for tool_calls in choices array (OpenAI format).
			if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
				foreach ( $response['choices'] as $choice ) {
					if ( isset( $choice['message']['tool_calls'] ) && is_array( $choice['message']['tool_calls'] ) ) {
						return $choice['message']['tool_calls'];
					}
				}
			}

			return array();
		}

		/**
		 * Extract the assistant message from an LLM response.
		 *
		 * When the LLM response contains tool_calls, we need to add the assistant message
		 * to the conversation before adding tool response messages, as required by OpenAI's API.
		 *
		 * @param array $response LLM response array.
		 * @return array|null Assistant message array or null if not found.
		 */
		protected function extract_assistant_message_from_response( $response ) {
			if ( ! is_array( $response ) ) {
				return null;
			}

			// Check for message in choices array (OpenAI/Gemini format).
			if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
				foreach ( $response['choices'] as $choice ) {
					if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
						return $choice['message'];
					}
				}
			}

			// Check for direct message format (some providers).
			if ( isset( $response['role'] ) && 'assistant' === $response['role'] ) {
				return $response;
			}

			return null;
		}

		/**
		 * Execute a single tool call internally during the agentic loop.
		 *
		 * @param array           $tool_call        Tool call object from LLM.
		 * @param int             $assistant_id     Assistant ID.
		 * @param array           $assistant_config Assistant configuration.
		 * @param int             $user_id          User ID.
		 * @param WP_REST_Request $request          Original REST request.
		 * @param int             $iteration        Current iteration number (default 0).
		 * @param int             $max_iterations   Maximum iterations (default 5).
		 * @return mixed Tool execution result.
		 */
		protected function execute_tool_call_internal( $tool_call, $assistant_id, $assistant_config, $user_id, $request, $iteration = 0, $max_iterations = 5 ) {
			if ( ! isset( $tool_call['function']['name'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_tool_call', __( 'Tool call missing function name.', 'wp-mcp-ai' ) );
			}

			$tool_name = $tool_call['function']['name'];
			$arguments = array();

			if ( isset( $tool_call['function']['arguments'] ) ) {
				if ( is_string( $tool_call['function']['arguments'] ) ) {
					$arguments_string = trim( $tool_call['function']['arguments'] );

					// Empty string is valid - means no arguments.
					if ( '' === $arguments_string ) {
						$arguments = array();
					} else {
						$decoded = json_decode( $arguments_string, true );
						if ( JSON_ERROR_NONE === json_last_error() ) {
							if ( is_array( $decoded ) ) {
								$arguments = $decoded;
							} else {
								// JSON decoded successfully but result is not an array.
								WP_MCP_AI_Logger::log_error(
									'Tool call arguments JSON decoded to non-array',
									array(
										'tool_name'    => $tool_name,
										'arguments'    => $arguments_string,
										'decoded_type' => gettype( $decoded ),
										'assistant_id' => $assistant_id,
										'agentic_loop' => true,
									)
								);
								/* translators: %s: tool name */
								return new WP_Error( 'wp_mcp_ai_invalid_tool_arguments', sprintf( __( 'Tool "%s" has invalid arguments: expected JSON object.', 'wp-mcp-ai' ), $tool_name ) );
							}
						} else {
							// JSON decode failed.
							$json_error = json_last_error_msg();
							WP_MCP_AI_Logger::log_error(
								'Tool call arguments JSON decode failed',
								array(
									'tool_name'    => $tool_name,
									'arguments'    => $arguments_string,
									'json_error'   => $json_error,
									'assistant_id' => $assistant_id,
									'agentic_loop' => true,
								)
							);
							/* translators: 1: tool name, 2: JSON error message */
							return new WP_Error( 'wp_mcp_ai_invalid_tool_arguments_json', sprintf( __( 'Tool "%1$s" has invalid JSON arguments: %2$s', 'wp-mcp-ai' ), $tool_name, $json_error ) );
						}
					}
				} elseif ( is_array( $tool_call['function']['arguments'] ) ) {
					$arguments = $tool_call['function']['arguments'];
				}
			}

			$allowed_tools   = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
			$tool_candidates = $this->generate_tool_slug_candidates( $tool_name );

			// Check for document prompt tool special case.
			if ( $this->candidates_include_slug( $tool_candidates, self::DOCUMENT_PROMPT_TOOL_SLUG ) && ! in_array( self::DOCUMENT_PROMPT_TOOL_SLUG, $allowed_tools, true ) ) {
				if ( $this->tool_arguments_include_document_payload( $arguments ) ) {
					$assistant_config = $this->ensure_tool_in_config( $assistant_config, self::DOCUMENT_PROMPT_TOOL_SLUG );
					$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
				}
			}

			$tool_slug = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );

			if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
				/* translators: %s: tool name */
				return new WP_Error( 'wp_mcp_ai_tool_forbidden', sprintf( __( 'Tool "%s" is not allowed for this assistant.', 'wp-mcp-ai' ), $tool_name ), array( 'status' => 403 ) );
			}

			$tool = $this->registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				/* translators: %s: tool name */
				return new WP_Error( 'wp_mcp_ai_tool_missing', sprintf( __( 'Tool "%s" is not registered.', 'wp-mcp-ai' ), $tool_name ), array( 'status' => 404 ) );
			}

			$context = array(
				'user_id'               => $user_id,
				'assistant_id'          => $assistant_id,
				'request'               => $request,
				'assistant_config'      => $assistant_config,
				'agentic_loop'          => true,
				'iteration'             => $iteration,
				'max_iterations'        => $max_iterations,
				'endpoint'              => $request->get_route(),
				'allow_sensitive_tools' => $request->get_param( 'allow_sensitive_tools' ) === true,
			);

			// Special handling for run_openai_external_action tool.
			if ( 'run_openai_external_action' === $tool_slug ) {
				if ( empty( $arguments['action_type'] ) && ! empty( $assistant_config['external_action_type'] ) ) {
					$arguments['action_type'] = $assistant_config['external_action_type'];
				}

				if ( empty( $arguments['identifier'] ) && ! empty( $assistant_config['external_action_identifier'] ) ) {
					$arguments['identifier'] = $assistant_config['external_action_identifier'];
				}
			}

			// Filter arguments to only include parameters defined in the tool's schema.
			// This prevents "Invalid parameter(s)" errors when AI providers include extra
			// parameters like 'messages' that aren't in the tool's schema.
			$arguments = $this->filter_tool_arguments_by_schema( $tool, $arguments );

			// Orchestration Layer: Check if tool should execute asynchronously.
			// Get async orchestrator to determine execution strategy.
			$orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
			$should_async = $orchestrator->should_execute_async( $tool, $arguments, $context );

			// CRITICAL: Force synchronous execution in agentic loop for most tools.
			// Async tools must complete before the loop continues to ensure the LLM
			// receives actual results, not pending status. Without this, the agentic
			// loop would continue with pending tool results, and the final LLM response
			// would not include the actual tool output (e.g., generated image links).
			// 
			// EXCEPTION: Some tools (like video generation) take so long (60-120s) that
			// they MUST run async to avoid HTTP timeouts, even in agentic loops.
			// These tools are marked with 'background-only' capability flag.
			$must_run_async = false;
			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$capability_flags = $tool->get_capability_flags();
				if ( is_array( $capability_flags ) && in_array( 'background-only', $capability_flags, true ) ) {
					$must_run_async = true;
				}
			}
			
			if ( $should_async && ! empty( $context['agentic_loop'] ) && ! $must_run_async ) {
				$should_async = false;
				WP_MCP_AI_Logger::log_event(
					'async_tool_forced_sync',
					sprintf( 'Forced synchronous execution of %s in agentic loop', $tool_slug ),
					array(
						'tool_slug' => $tool_slug,
						'iteration' => $iteration,
						'reason'    => 'agentic_loop_requires_complete_results',
					)
				);
			} elseif ( $must_run_async && ! empty( $context['agentic_loop'] ) ) {
				WP_MCP_AI_Logger::log_event(
					'async_tool_required_in_agentic_loop',
					sprintf( 'Tool %s must run async even in agentic loop (background-only)', $tool_slug ),
					array(
						'tool_slug' => $tool_slug,
						'iteration' => $iteration,
						'reason'    => 'tool_requires_background_execution',
					)
				);
			}

			// CRITICAL: Prevent infinite loops in agentic workflows.
			// If we're already in an iteration and this is an async tool, check if we should defer.
			// Async tools queued in agentic loops should be marked to prevent re-queuing.
			if ( $should_async && $iteration > 0 ) {
				// Check if this tool was already queued in a previous iteration.
				$tool_call_signature = md5( wp_json_encode( array( $tool_slug, $arguments ) ) );
				$previous_calls      = isset( $context['tool_call_history'] ) ? $context['tool_call_history'] : array();

				if ( in_array( $tool_call_signature, $previous_calls, true ) ) {
					// Tool already called with same arguments - don't queue again.
					$should_async = false;
					WP_MCP_AI_Logger::log_event(
						'async_tool_loop_prevented',
						sprintf( 'Prevented async re-queuing of %s in iteration %d', $tool_slug, $iteration ),
						array(
							'tool_slug' => $tool_slug,
							'iteration' => $iteration,
							'signature' => $tool_call_signature,
						)
					);
				}
			}

			if ( $should_async ) {
				// Queue tool for async execution via cron.
				$executor = wp_mcp_ai_get_async_tool_executor();
				$job_id   = $executor->queue_tool( $tool_slug, $arguments, $context );

				if ( is_wp_error( $job_id ) ) {
					// Failed to queue - execute synchronously as fallback.
					WP_MCP_AI_Logger::log_error(
						sprintf( 'Failed to queue async tool %s, executing synchronously', $tool_slug ),
						array(
							'tool_slug' => $tool_slug,
							'error'     => $job_id->get_error_message(),
						)
					);
				} else {
					// Successfully queued - return pending status.
					WP_MCP_AI_Logger::log_event(
						'async_tool_queued',
						sprintf( 'Tool %s queued for async execution', $tool_slug ),
						array(
							'tool_slug' => $tool_slug,
							'job_id'    => $job_id,
						)
					);

					// Return a structured response indicating the tool is processing asynchronously.
					// The LLM should understand this and inform the user about the job ID.
					// Include the job_id prominently in the message so the LLM knows to tell the user.
					return array(
						'status'    => 'pending',
						'job_id'    => $job_id,
						'message'   => sprintf(
							/* translators: 1: tool name, 2: job ID */
							__( 'Tool "%1$s" is processing in the background (Job ID: %2$s). The results will be available shortly and will appear here automatically when ready.', 'wp-mcp-ai' ),
							$tool_name,
							$job_id
						),
						'async'     => true,
						'tool_slug' => $tool_slug,
					);
				}
			}

			// Execute tool synchronously (either not async-capable or async queueing failed).
			// Orchestration Layer: Wrap in try-catch to handle budget enforcement and timeouts.
			try {
				// Set execution time limit for synchronous tool execution in agentic loop
				// to prevent PHP timeout. Default WordPress limit is 30s, we allow up to 60s
				// for tools that might take longer (like image generation).
				if ( ! empty( $context['agentic_loop'] ) ) {
					$original_time_limit = ini_get( 'max_execution_time' );
					$tool_timeout        = apply_filters( 'wp_mcp_ai_agentic_tool_timeout', 60, $tool_slug );
					
					// Only set if we can (some hosting environments don't allow this)
					if ( function_exists( 'set_time_limit' ) && 0 !== (int) $original_time_limit ) {
						@set_time_limit( $tool_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				
				do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context );

				$result = $tool->execute( $arguments, $context );

				if ( is_wp_error( $result ) ) {
					WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $arguments, $result, $context );
					
					// In agentic loop, if sync execution failed and tool supports async,
					// provide helpful error message instead of returning WP_Error object
					// which would break the conversation flow.
					if ( ! empty( $context['agentic_loop'] ) ) {
						return sprintf(
							/* translators: 1: tool name, 2: error message */
							__( 'Tool "%1$s" execution failed: %2$s', 'wp-mcp-ai' ),
							$tool_name,
							$result->get_error_message()
						);
					}
					
					return $result->get_error_message();
				}

				$result = apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $arguments, $context );

				// Orchestration Layer: Adjust result to fit within budget constraints.
				if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
					$result = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget( $result, $tool_slug, $context );
				}

				WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $arguments, $result, $context );

				do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );

				return $result;

			} catch ( Exception $e ) {
				// Orchestration Layer: Budget constraint violation or execution timeout.
				WP_MCP_AI_Logger::log_error(
					'Tool execution blocked by orchestration layer or failed',
					array(
						'tool_slug'    => $tool_slug,
						'error'        => $e->getMessage(),
						'context'      => $context,
						'agentic_loop' => ! empty( $context['agentic_loop'] ),
					)
				);

				// In agentic loop, provide a graceful error message that the LLM can understand
				// and potentially work around, rather than breaking the conversation flow.
				if ( ! empty( $context['agentic_loop'] ) ) {
					return sprintf(
						/* translators: 1: tool name, 2: error message */
						__( 'Tool "%1$s" execution error: %2$s. The tool could not complete successfully.', 'wp-mcp-ai' ),
						$tool_name,
						$e->getMessage()
					);
				}

				return $e->getMessage();
			}
		}

		/**
		 * Filter tool arguments to only include parameters defined in the tool's schema.
		 *
		 * When a tool's schema has 'additionalProperties' => false, this method removes
		 * any extra parameters that aren't defined in the schema. This prevents errors
		 * like "Invalid parameter(s): messages" when AI providers include extra parameters.
		 *
		 * @param WP_MCP_AI_Tool_Interface $tool      The tool instance.
		 * @param array                    $arguments The tool arguments to filter.
		 * @return array Filtered arguments.
		 */
		protected function filter_tool_arguments_by_schema( $tool, array $arguments ) {
			// Get the tool's parameter schema.
			$schema = $tool->get_parameters_schema();

			// If no schema or schema doesn't restrict additional properties, return arguments as-is.
			if ( ! is_array( $schema ) || ! isset( $schema['additionalProperties'] ) || false !== $schema['additionalProperties'] ) {
				return $arguments;
			}

			// Get the allowed properties from the schema.
			// Handle both array properties and stdClass objects (used for empty schemas like {}).
			$allowed_properties = array();
			if ( isset( $schema['properties'] ) ) {
				$properties = $schema['properties'];
				if ( is_array( $properties ) ) {
					$allowed_properties = array_keys( $properties );
				} elseif ( $properties instanceof stdClass ) {
					// stdClass is used for empty properties ({}) in JSON schemas.
					// Convert to array to get property keys (will be empty for new stdClass()).
					$allowed_properties = array_keys( get_object_vars( $properties ) );
				} else {
					// Unexpected type for properties - skip filtering to avoid dropping valid arguments.
					// This is a defensive fallback for malformed schemas.
					WP_MCP_AI_Logger::log_event(
						'tool_schema_warning',
						'Unexpected properties type in tool schema, skipping argument filtering',
						array(
							'tool_slug'       => $tool->get_slug(),
							'properties_type' => gettype( $properties ),
						)
					);
					return $arguments;
				}
			}

			// Filter arguments to only include allowed properties.
			$filtered_arguments = array();
			foreach ( $arguments as $key => $value ) {
				if ( in_array( $key, $allowed_properties, true ) ) {
					$filtered_arguments[ $key ] = $value;
				} else {
					// Log that we're dropping an extra parameter.
					WP_MCP_AI_Logger::log_event(
						'tool_argument_filtered',
						'Dropped extra parameter not in tool schema',
						array(
							'tool_slug' => $tool->get_slug(),
							'parameter' => $key,
							'allowed'   => $allowed_properties,
						)
					);
				}
			}

			return $filtered_arguments;
		}

		/**
		 * Generic sanitization for tools that don't implement custom rules.
		 *
		 * @param mixed $result Tool execution result.
		 * @return mixed Sanitized result.
		 */
		protected function generic_sanitize_for_llm( $result ) {
			if ( ! is_array( $result ) ) {
				return $result;
			}

			$sanitized = $result;

			// Strip duplicate raw API responses.
			unset( $sanitized['raw'] );

			// Strip verbose metadata.
			if ( isset( $sanitized['metadata'] ) && is_array( $sanitized['metadata'] ) ) {
				$sanitized['metadata'] = $this->sanitize_metadata_for_llm( $sanitized['metadata'] );
				if ( empty( $sanitized['metadata'] ) ) {
					unset( $sanitized['metadata'] );
				}
			}

			// Strip base64 content from image generation tools.
			if ( isset( $sanitized['content'] ) && is_array( $sanitized['content'] ) ) {
				$sanitized['content'] = $this->sanitize_content_for_llm( $sanitized['content'] );
				if ( empty( $sanitized['content'] ) ) {
					unset( $sanitized['content'] );
				}
			}

			// Recursively sanitize nested result arrays.
			foreach ( array( 'results', 'items', 'pages' ) as $key ) {
				if ( isset( $sanitized[ $key ] ) && is_array( $sanitized[ $key ] ) ) {
					$sanitized[ $key ] = array_map(
						function ( $item ) {
							return is_array( $item ) ? $this->generic_sanitize_for_llm( $item ) : $item;
						},
						$sanitized[ $key ]
					);
				}
			}

			return $sanitized;
		}

		/**
		 * Handle OPTIONS request for MCP endpoint (CORS preflight).
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return WP_REST_Response
		 */
		public function handle_mcp_options( WP_REST_Request $request ) {
			/**
			 * Filter the Access-Control-Allow-Origin header value for OPTIONS requests.
			 *
			 * @see 'wp_mcp_ai_cors_allow_origin' filter in MCP methods trait.
			 */
			$allow_origin = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

			$response = new WP_REST_Response( null, 204 );
			$response->header( 'Access-Control-Allow-Origin', $allow_origin );
			$response->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
			$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest' );
			$response->header( 'Access-Control-Max-Age', '3600' );
			return $response;
		}

		/**
		 * Get the SELECT fields for transcript session queries.
		 *
		 * @return string SQL SELECT fields.
		 */
		private function get_transcript_select_fields() {
			return "request_payload,
                    response_payload,
                    metadata,
                    request_started_at,
                    response_completed_at,
                    cct_created,
                    assistant_id,
                    assistant_model,
                    latency_ms";
		}

		/**
		 * Calculate cost data from AI response usage information.
		 *
		 * Extracts usage information from the AI response and calculates the cost
		 * using the Cost Calculator. Returns cost data array if cost can be calculated,
		 * null otherwise.
		 *
		 * @since 1.1.0
		 *
		 * @param array|WP_Error $response     AI response containing usage data.
		 * @param array          $options      Request options containing provider and model.
		 * @param int            $assistant_id Assistant identifier for logging.
		 * @param int            $user_id      User identifier for logging.
		 * @param string         $context      Context string for logging (e.g., 'chat response' or 'streaming chat response').
		 * @return array|null Cost data array with cost_usd, provider, model, is_estimated keys, or null if cost cannot be calculated.
		 */
		protected function calculate_response_cost( $response, $options, $assistant_id, $user_id, $context = 'chat response' ) {
			if ( is_wp_error( $response ) || ! isset( $response['usage'] ) || ! class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
				return null;
			}

			$provider_key = isset( $options['provider'] ) ? $options['provider'] : 'openai';
			$model_name   = isset( $options['model'] ) ? $options['model'] : '';

			$prompt_tokens     = isset( $response['usage']['prompt_tokens'] ) ? absint( $response['usage']['prompt_tokens'] ) : 0;
			$completion_tokens = isset( $response['usage']['completion_tokens'] ) ? absint( $response['usage']['completion_tokens'] ) : 0;

			if ( $prompt_tokens <= 0 && $completion_tokens <= 0 ) {
				return null;
			}

			$calculated_cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
				$provider_key,
				$model_name,
				$prompt_tokens,
				$completion_tokens
			);

			if ( $calculated_cost <= 0 ) {
				return null;
			}

			$cost_data = array(
				'cost_usd'     => $calculated_cost,
				'provider'     => $provider_key,
				'model'        => $model_name,
				'is_estimated' => false, // We have actual provider/model from the request.
			);

			// Log cost calculation when logging is enabled (integrates with logging layer).
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'cost_calculation',
					'Real-time cost calculated for ' . $context,
					array(
						'assistant_id'      => $assistant_id,
						'user_id'           => $user_id,
						'provider'          => $provider_key,
						'model'             => $model_name,
						'prompt_tokens'     => $prompt_tokens,
						'completion_tokens' => $completion_tokens,
						'total_tokens'      => $prompt_tokens + $completion_tokens,
						'cost_usd'          => $calculated_cost,
						'is_estimated'      => false,
					)
				);
			}

			return $cost_data;
		}
	}
}
