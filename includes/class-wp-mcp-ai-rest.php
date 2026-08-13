<?php
/**
 * REST API controller for NV oOS.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-mcp-methods.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-async-metadata.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-controller-base.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-chat-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-mcp-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-tools-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-chat-memory-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-teams-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-transcript-mining-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-a2a-controller.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-authenticator.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-handler.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-session-store.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-thread-manager.php';
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-threads-controller.php';

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
		const SSE_JOB_MAX_POLLS          = 120;   // Maximum number of status polls (120 * 3s = 6 minutes).
		const SSE_JOB_POLL_INTERVAL      = 3;     // Seconds between status polls.
		const SSE_JOB_HEARTBEAT_INTERVAL = 5;     // Send heartbeat every N polls (5 * 3s = 15 seconds).

		/**
		 * Tool slug used for document + prompt submissions.
		 *
		 * Requests that include attachments are temporarily granted access to this
		 * tool so the OpenAI client can forward the files without requiring admins
		 * to manually toggle the capability for every assistant.
		 */
		const DOCUMENT_PROMPT_TOOL_SLUG = 'submit_document_prompt';

		/**
		 * Utility tools that are auto-enabled for all assistants.
		 *
		 * These tools provide essential chat client functionality (speech synthesis,
		 * audio transcription) and don't perform sensitive operations, so they should
		 * be automatically available without requiring explicit configuration.
		 *
		 * @since 1.0.0
		 */
		const AUTO_ENABLED_UTILITY_TOOLS = array(
			'generate_openai_speech',
			'transcribe_openai_audio',
		);

		/**
		 * Tool execution rate limiting window in seconds.
		 *
		 * @since 1.2.0
		 * @var int
		 */
		const TOOL_RATE_LIMIT_WINDOW = 60;

		/**
		 * Maximum tool executions per user/guest per rate limit window.
		 *
		 * @since 1.2.0
		 * @var int
		 */
		const TOOL_RATE_LIMIT_MAX = 60;

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
		 * Voice controller for realtime voice sessions (OpenAI Realtime / Gemini Live).
		 *
		 * @var WP_MCP_AI_REST_Voice_Controller
		 */
		protected $voice_controller;

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
			$token_manager_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-token-manager.php';
			if ( file_exists( $token_manager_file ) ) {
				require_once $token_manager_file;
				add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Token_Manager', 'register_routes' ) );
			}

			// Register Cost Manager REST endpoints.
			$cost_manager_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-cost-manager.php';
			if ( file_exists( $cost_manager_file ) ) {
				require_once $cost_manager_file;
				add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Cost_Manager', 'register_routes' ) );
			}

			// Register Analytics Manager REST endpoints.
			$analytics_manager_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-analytics-manager.php';
			if ( file_exists( $analytics_manager_file ) ) {
				require_once $analytics_manager_file;
				add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Analytics_Manager', 'register_routes' ) );
			}

			// Register Slash Command REST endpoints.
			$slash_command_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-slash-command-controller.php';
			if ( file_exists( $slash_command_file ) ) {
				require_once $slash_command_file;
				add_action(
					'rest_api_init',
					function () {
						$controller = new WP_MCP_AI_REST_Slash_Command_Controller();
						$controller->register_routes();
					}
				);
			}

			// Register ACP Transport REST endpoints.
			$acp_dispatcher_file = WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-jsonrpc-dispatcher.php';
			$acp_session_file    = WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-manager.php';
			$acp_bridge_file     = WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-bridge.php';
			$acp_server_file     = WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-server.php';
			$acp_transport_file  = WP_MCP_AI_PATH . 'includes/acp/transport/class-wp-mcp-ai-acp-transport-http.php';
			if ( file_exists( $acp_dispatcher_file ) && file_exists( $acp_session_file ) && file_exists( $acp_bridge_file ) && file_exists( $acp_server_file ) && file_exists( $acp_transport_file ) ) {
				require_once $acp_dispatcher_file;
				require_once $acp_session_file;
				require_once $acp_bridge_file;
				require_once $acp_server_file;
				require_once $acp_transport_file;

				add_action(
					'rest_api_init',
					function () {
						// Only mount the ACP server if enabled in settings.
						$settings = get_option( 'wp_mcp_ai_settings', array() );
						if ( empty( $settings['enable_acp_server'] ) ) {
							return;
						}

						$session_manager = new WP_MCP_AI_ACP_Session_Manager();
						$session_bridge  = new WP_MCP_AI_ACP_Session_Bridge();
						$dispatcher      = new WP_MCP_AI_ACP_JSONRPC_Dispatcher( $session_manager, $session_bridge );
						$controller      = new WP_MCP_AI_ACP_Transport_HTTP( $dispatcher );
						$controller->register_routes();
					}
				);
			}

			// Register Health Check REST endpoint (load balancer / monitoring).
			$health_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-health.php';
			if ( file_exists( $health_file ) ) {
				require_once $health_file;
				add_action( 'rest_api_init', array( 'WP_MCP_AI_REST_Health', 'register_routes' ) );
			}

			// Register Voice REST endpoints (realtime voice sessions).
			$voice_file     = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-voice-controller.php';
			$translate_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-realtime-translate-client.php';
			$whisper_file   = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-realtime-whisper-client.php';
			if ( file_exists( $voice_file ) && file_exists( $translate_file ) && file_exists( $whisper_file ) ) {
				require_once $voice_file;
				require_once $translate_file;
				require_once $whisper_file;
				$this->voice_controller = new WP_MCP_AI_REST_Voice_Controller();
				$this->voice_controller->register_provider( new WP_MCP_AI_OpenAI_Realtime_Client() );
				$this->voice_controller->register_provider( new WP_MCP_AI_OpenAI_Realtime_Translate_Client() );
				$this->voice_controller->register_provider( new WP_MCP_AI_OpenAI_Realtime_Whisper_Client() );
				$this->voice_controller->register_provider( new WP_MCP_AI_Gemini_Live_Client() );
			}

			add_filter( 'rest_request_after_callbacks', array( $this, 'format_actionable_error' ), 10, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'augment_error_actions' ), 10, 3 );
			add_filter( 'rest_pre_serve_request', array( $this, 'ensure_clean_json_output' ), 10, 4 );
		}

		/**
		 * Get singleton instance.
		 *
		 * Resolves dependencies from the container and stores the instance globally
		 * for backward compatibility with code that accesses it via the global.
		 *
		 * @return WP_MCP_AI_REST
		 */
		public static function get_instance() {
			if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) && $GLOBALS['wp_mcp_ai_rest_controller'] instanceof self ) {
				return $GLOBALS['wp_mcp_ai_rest_controller'];
			}

			if ( function_exists( 'wp_mcp_ai_container' ) ) {
				$container = wp_mcp_ai_container();
				if ( $container->has( 'rest_controller' ) ) {
					$instance                             = $container->get( 'rest_controller' );
					$GLOBALS['wp_mcp_ai_rest_controller'] = $instance;
					return $instance;
				}
			}

			// Fallback: construct with real dependencies when container unavailable.
			$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
			$client                               = new WP_MCP_AI_Language_Model_Router(
				new WP_MCP_AI_OpenAI_Client(),
				new WP_MCP_AI_Gemini_Client(),
				new WP_MCP_AI_Ollama_Client()
			);
			$instance                             = new self( $registry, $client );
			$GLOBALS['wp_mcp_ai_rest_controller'] = $instance;
			return $instance;
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
			$cleaned        = '';

			while ( ob_get_level() > 0 && $iterations < $max_iterations ) {
				$contents = ob_get_contents();
				if ( is_string( $contents ) && '' !== $contents ) {
					$cleaned .= $contents;
				}
				if ( ! ob_end_clean() ) {
					break; // If ob_end_clean fails, stop trying.
				}
				++$iterations;
			}

			// When WP_DEBUG is on, log cleaned output so developers can see
			// suppressed errors (PHP warnings, notices, etc.) that would
			// otherwise corrupt JSON responses.
			if ( '' !== $cleaned && defined( 'WP_DEBUG' ) && WP_DEBUG && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Output buffer contained content that was cleaned before serving REST response.',
					array( 'cleaned_output' => substr( $cleaned, 0, 2000 ) )
				);
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

			// Clean any existing output so REST responses are not contaminated.
			// The rest_pre_serve_request filter (ensure_clean_json_output) will
			// call clean_all_output_buffers() again right before serving to
			// discard anything that accumulated during request processing.
			$this->clean_all_output_buffers();
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
		public function ensure_clean_json_output( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
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

			// Strip internal-only keys before exposing to the client.
			$safe_data = array_intersect_key(
				$data,
				array_flip( array( 'status', 'actions', 'field', 'user_message', 'suggestions', 'retry_after' ) )
			);

			$payload = array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'actions' => $data['actions'],
				'data'    => $safe_data,
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

			// Delegate chat-client ⇄ memory bridge to Chat Memory Controller (Phase 1).
			$chat_memory_controller = new WP_MCP_AI_REST_Chat_Memory_Controller( $this->authenticator, $this->validator );
			$chat_memory_controller->register_routes();

			// Delegate teams routes to Teams Controller.
			$teams_controller = new WP_MCP_AI_REST_Teams_Controller();
			$teams_controller->register_routes();

			// Delegate retroactive transcript-to-memory mining job routes.
			$transcript_mining_controller = new WP_MCP_AI_REST_Transcript_Mining_Controller();
			$transcript_mining_controller->register_routes();

			// Delegate chat-session SSE stream to Chat Session Stream Controller.
			if ( class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-chat-session-stream-controller.php';
				$chat_session_stream_controller = new WP_MCP_AI_REST_Chat_Session_Stream_Controller( $this->authenticator, $this->validator );
				$chat_session_stream_controller->register_routes();
			}

			// Delegate A2A protocol routes to A2A Controller.
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			if ( ! empty( $settings['enable_a2a_server'] ) ) {
				$a2a_controller = new WP_MCP_AI_REST_A2A_Controller( $this, $this->authenticator, $this->validator );
				$a2a_controller->register_routes();
			}

			// Note: /assistants route now handled by MCP Controller (Phase 3.3).

			// Note: /chat route now handled by Chat Controller (Phase 3.2).

			// Note: /chat-client route now handled by Chat Controller (Phase 3.2).

			// Note: /chat-transcripts routes now handled by Chat Controller (Phase 3.2).

			// Note: /tools route now handled by Tools Controller (Phase 3.4).

			// Note: /files/{file_id}/download route now handled by Tools Controller (Phase 3.4).

			// Note: /cron-status route now handled by Tools Controller (Phase 3.4).

			// Register thread CRUD endpoints.
			$threads_controller = new WP_MCP_AI_REST_Threads_Controller( $this );
			$threads_controller->register_routes();

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
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id, $request );

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
						__( 'Authentication nonce is required. Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ).', 'mcp-ai-wpoos' ),
						array( 'status' => 401 )
					);
				}

				if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return new WP_Error(
						'rest_invalid_nonce',
						__( 'Could not verify the request nonce.', 'mcp-ai-wpoos' ),
						array( 'status' => 403 )
					);
				}
			}

			if ( $user_id && $current_user && $user_id === $current_user ) {
				if ( ! is_user_logged_in() ) {
					return new WP_Error(
						'wp_mcp_ai_forbidden',
						__( 'You do not have permission to view chat transcripts.', 'mcp-ai-wpoos' ),
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
				__( 'You do not have permission to view chat transcripts.', 'mcp-ai-wpoos' ),
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
					__( 'A valid user is required to query chat transcripts. Please log in to view your chat history.', 'mcp-ai-wpoos' ),
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
					__( 'No valid messages to save.', 'mcp-ai-wpoos' ),
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
			// Since this is just saving a conversation without a new response,.
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

			$repository = $this->get_transcript_repository();
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
				return new WP_Error(
					'wp_mcp_ai_transcripts_delete_failed',
					__( 'Failed to delete the transcript.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'deleted' => $deleted,
					'message' => __( 'Transcript deleted successfully.', 'mcp-ai-wpoos' ),
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
			$user_id      = ! empty( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : get_current_user_id();

			$limit = $request->get_param( 'limit' );
			if ( ! $limit ) {
				$limit = 10;
			}

			$assistant_id = $request->get_param( 'assistant_id' );
			if ( $assistant_id ) {
				// Use the shared sanitization method from Tools Controller.
				require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-controller-base.php';
				require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-tools-controller.php';
				$assistant_id = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( $assistant_id );
			}

			// When SSE streaming is requested, send headers and a keepalive
			// frame immediately so Cloudflare and other proxies do not time
			// out waiting for the first byte while we gather the initial
			// snapshot data (which can be expensive on sites with large
			// options tables or many async jobs).
			$wants_sse = $this->sse_handler && $this->sse_handler->request_wants_event_stream( $request );

			if ( $wants_sse ) {
				// Send SSE headers and an immediate keepalive frame. The
				// send_sse_headers() method also extends execution time
				// and flushes output, which prevents Cloudflare 524
				// timeouts during the data-gathering phase below.
				$this->sse_handler->send_sse_headers();
				$this->sse_handler->send_sse_comment( 'keepalive' );
			}

			// Get status summary and counts with optional assistant filter.
			$jobs   = $service->get_status_summary( $user_id, $limit, $assistant_id );
			$counts = $service->get_status_counts( $user_id, $assistant_id );

			$response = array(
				'jobs'          => $jobs,
				'counts'        => $counts,
				'system_status' => $service->get_system_status(),
			);

			// Include assistant_id in response if filtered.
			if ( $assistant_id ) {
				$response['assistant_id'] = $assistant_id;
			}

			// Check if SSE streaming was requested.
			if ( $wants_sse ) {
				// Phase 2 slice 2b: real polling loop emitting typed `job:*`
				// diff frames with monotonic `id:` lines + `Last-Event-ID`
				// resume. See docs/features/chat/cron-status-tasks-drawer-plan.md.
				//
				// Headers were already sent above; pass the gathered data
				// directly into the polling loop with headers_sent=true.
				return $this->stream_status_summary_updates( $request, $response, $service, $user_id, $limit, $assistant_id, true );
			}

			/**
			 * Fires after a one-shot cron-status snapshot is built.
			 *
			 * Allows OTel subscribers and monitoring hooks to record a span /
			 * metric for the snapshot request. Consumers MUST NOT modify
			 * $response here — use the `wp_mcp_ai_cron_status_response` filter
			 * for that.
			 *
			 * @since 1.9.4
			 *
			 * @param array    $response     The snapshot payload (jobs, counts, system_status).
			 * @param int      $user_id      Authenticated user ID.
			 * @param int|null $assistant_id Optional assistant filter.
			 */
			do_action( 'wp_mcp_ai_chat_jobs_snapshot', $response, $user_id, $assistant_id );

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
			$user_id      = ! empty( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : get_current_user_id();

			// Get job ID from URL parameter.
			$job_id = $request->get_param( 'job_id' );

			// Self-healing inline kick for async-tool jobs that are stuck
			// in `pending` past the stale threshold. Schedules a shutdown
			// action that drives the job forward after this response is
			// flushed, so the chat client's poll loop automatically heals
			// stuck jobs on hosts where the WP-Cron loopback never fires.
			// No-op for non-async job IDs (veo_*, regular cron jobs, etc.)
			// and for jobs that have already advanced past `pending`.
			if ( is_string( $job_id ) && 0 === strpos( $job_id, 'async_' ) && class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
				$executor = new WP_MCP_AI_Tool_Async_Executor();
				$executor->kick_inline_if_stale( $job_id );
			}

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
		 * Stream `/cron-status` list snapshot updates via SSE (Phase 2 slice 2b).
		 *
		 * Replaces the one-shot `stream_event_stream_payload()` SSE snapshot
		 * on the list endpoint with a real polling loop that emits typed
		 * `job:queued` / `job:started` / `job:progress` / `job:completed` /
		 * `job:failed` / `job:cancelled` / `job:retried` diff frames per the
		 * canonical schema documented in
		 * `docs/features/chat/cron-status-tasks-drawer-plan.md`.
		 *
		 * Behaviours:
		 * - Initial frame: `event: cron_status` carrying the current snapshot
		 *   (back-compat with existing consumers).
		 * - Diff frames: each state transition (per
		 *   {@see WP_MCP_AI_Cron_Status_Service::classify_job_diff_event()})
		 *   emits the typed event name carrying the full normalized job record.
		 * - Heartbeat: explicit `event: ping` every
		 *   `SSE_JOB_HEARTBEAT_INTERVAL` polls so proxies hold the
		 *   connection open and clients can detect stalled streams.
		 * - Monotonic `id:` lines on every frame so EventSource populates
		 *   `lastEventId`; clients reissue it on reconnect via the
		 *   `Last-Event-ID` header (parsed from `HTTP_LAST_EVENT_ID`).
		 *
		 * @since 1.9.3
		 *
		 * @param WP_REST_Request               $request      Incoming REST request.
		 * @param array                         $initial      Initial snapshot payload (jobs, counts, system_status).
		 * @param WP_MCP_AI_Cron_Status_Service $service      Cron status service instance.
		 * @param int                           $user_id      Authenticated user ID.
		 * @param int                           $limit        Snapshot limit.
		 * @param int|null                      $assistant_id Optional assistant filter.
		 * @param bool                          $headers_sent Whether SSE headers were already sent by the caller.
		 * @return void Streams SSE updates and exits.
		 */
		protected function stream_status_summary_updates( WP_REST_Request $request, array $initial, $service, $user_id, $limit, $assistant_id, $headers_sent = false ) {
			$stream_started_micros = (int) round( microtime( true ) * 1e6 );

			/**
			 * Fires when a cron-status SSE stream is established.
			 *
			 * @since 1.9.4
			 *
			 * @param int      $user_id      Authenticated user ID.
			 * @param int|null $assistant_id Optional assistant filter.
			 */
			do_action( 'wp_mcp_ai_before_chat_jobs_stream', $user_id, $assistant_id );

			if ( ! $headers_sent ) {
				$this->sse_handler->send_sse_headers();
			}

			// Parse `Last-Event-ID` so reconnecting clients resume the
			// monotonic counter from where they left off. The header is
			// surfaced via SAPI under HTTP_LAST_EVENT_ID; we also honour the
			// `last_event_id` query param for transports that strip headers.
			$last_event_id = 0;
			$header_value  = $request->get_header( 'last_event_id' );
			if ( null === $header_value && isset( $_SERVER['HTTP_LAST_EVENT_ID'] ) ) {
				$header_value = sanitize_text_field( wp_unslash( $_SERVER['HTTP_LAST_EVENT_ID'] ) );
			}
			if ( null === $header_value ) {
				$query_value = $request->get_param( 'last_event_id' );
				if ( null !== $query_value ) {
					$header_value = $query_value;
				}
			}
			if ( is_scalar( $header_value ) ) {
				$last_event_id = max( 0, (int) $header_value );
			}

			// Monotonic counter starts after the last-acknowledged ID so
			// clients never see a repeat ID on resume.
			$event_id_seq = $last_event_id;

			// Normalize the initial snapshot for safe JSON encoding.
			$initial = $this->normalize_data_recursive( $initial );

			// Emit the initial cron_status snapshot frame for back-compat
			// with consumers built against the one-shot SSE payload.
			++$event_id_seq;
			$this->sse_handler->send_sse_event_with_id( 'cron_status', $initial, (string) $event_id_seq );

			// Seed the diff baseline from the initial snapshot so
			// subsequent polls only emit frames for real transitions.
			$prev_jobs = $this->index_jobs_by_id( isset( $initial['jobs'] ) ? $initial['jobs'] : array() );

			$max_polls     = self::SSE_JOB_MAX_POLLS;
			$poll_interval = self::SSE_JOB_POLL_INTERVAL;
			$poll_count    = 0;

			if ( ! $headers_sent ) {
				$required_time = ( $max_polls * $poll_interval ) + 60;
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( $required_time ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort timeout extension.
				}
			}

			while ( $poll_count < $max_polls ) {
				if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
					break;
				}

				sleep( $poll_interval );
				++$poll_count;

				if ( 0 === $poll_count % self::SSE_JOB_HEARTBEAT_INTERVAL && function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}

				$jobs   = $service->get_status_summary( $user_id, $limit, $assistant_id );
				$counts = $service->get_status_counts( $user_id, $assistant_id );
				$jobs   = $this->normalize_data_recursive( $jobs );

				$next_jobs = $this->index_jobs_by_id( $jobs );

				// Emit one typed frame per changed job.
				foreach ( $next_jobs as $job_id => $next_record ) {
					$prev_record = isset( $prev_jobs[ $job_id ] ) ? $prev_jobs[ $job_id ] : null;
					$event_name  = $service->classify_job_diff_event( $prev_record, $next_record );
					if ( '' === $event_name ) {
						continue;
					}
					++$event_id_seq;
					$this->sse_handler->send_sse_event_with_id( $event_name, $next_record, (string) $event_id_seq );
				}

				$prev_jobs = $next_jobs;

				// Heartbeat frame keeps proxies and clients alive between
				// genuine diffs. Sent every SSE_JOB_HEARTBEAT_INTERVAL polls.
				if ( 0 === $poll_count % self::SSE_JOB_HEARTBEAT_INTERVAL ) {
					++$event_id_seq;
					$this->sse_handler->send_sse_event_with_id(
						'ping',
						array(
							'counts'        => $counts,
							'system_status' => $service->get_system_status(),
							'ts'            => time(),
						),
						(string) $event_id_seq
					);
				}
			}

			$this->sse_handler->send_sse_done();

			/**
			 * Fires when a cron-status SSE stream ends (connection aborted or
			 * max polls reached).
			 *
			 * @since 1.9.4
			 *
			 * @param int      $poll_count   Number of polls completed.
			 * @param int      $user_id      Authenticated user ID.
			 * @param int|null $assistant_id Optional assistant filter.
			 * @param int      $duration_ms  Stream duration in milliseconds (0 if unavailable).
			 */
			$duration_ms = $stream_started_micros > 0 ? (int) round( ( microtime( true ) * 1e6 - $stream_started_micros ) / 1000 ) : 0;
			do_action( 'wp_mcp_ai_after_chat_jobs_stream', $poll_count, $user_id, $assistant_id, $duration_ms );

			$this->sse_handler->finish();
		}

		/**
		 * Index a flat list of job records by their `job_id` for diff lookups.
		 *
		 * Records missing a `job_id` are skipped so a malformed source can't
		 * collide with the diff baseline.
		 *
		 * @since 1.9.3
		 *
		 * @param array<int,array<string,mixed>> $jobs Flat list of normalized job records.
		 * @return array<string,array<string,mixed>>
		 */
		protected function index_jobs_by_id( $jobs ) {
			$indexed = array();
			if ( ! is_array( $jobs ) ) {
				return $indexed;
			}
			foreach ( $jobs as $job ) {
				if ( ! is_array( $job ) || empty( $job['job_id'] ) ) {
					continue;
				}
				$indexed[ (string) $job['job_id'] ] = $job;
			}
			return $indexed;
		}

		/**
		 * Stream job status updates via SSE.
		 *
		 * Keeps the SSE connection open and periodically polls for job status updates,
		 * sending events to the client as the job progresses from pending → polling → completed.
		 *
		 * @param array                         $initial_details Initial job details.
		 * @param string                        $job_id          Job identifier.
		 * @param WP_MCP_AI_Cron_Status_Service $service         Cron status service instance.
		 * @param int                           $user_id         User ID for permission checks.
		 * @return void Streams SSE updates and exits.
		 */
		protected function stream_job_status_updates( $initial_details, $job_id, $service, $user_id ) {
			// Send SSE headers and initialize streaming.
			$this->sse_handler->send_sse_headers();

			// Normalize initial details to ensure JSON serializability.
			// This converts any WP_Error objects to serializable arrays.
			$initial_details = $this->normalize_data_recursive( $initial_details );

			// Send initial status.
			$this->sse_handler->send_sse_event( 'cron_job_status', $initial_details );

			// Check if job is already in a terminal state.
			$status = isset( $initial_details['status'] ) ? $initial_details['status'] : 'unknown';
			if ( in_array( $status, array( 'completed', 'failed' ), true ) ) {
				// Job is already done, send completion marker and finish.
				$this->sse_handler->send_sse_done();
				$this->sse_handler->finish();
				return;
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
			// - Warning when safe mode is enabled.
			// - Warning when running as Apache module with certain configurations.
			// These warnings are expected and can be safely ignored as we're providing.
			// a best-effort timeout extension for SSE streaming.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $required_time ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: set_time_limit() may emit warnings on restricted hosts; failure is non-critical (best-effort timeout extension).
			}

			while ( $poll_count < $max_polls ) {
				// Wait before next poll.
				sleep( $poll_interval );
				++$poll_count;

				// Trigger WordPress cron to ensure async jobs continue processing.
				// WordPress cron only runs on page loads by default. When a client
				// is waiting on an SSE connection, no new page loads occur, so cron.
				// jobs (including veo video polling) may not run. Calling spawn_cron()
				// ensures any scheduled cron events execute, allowing the job to progress.
				// We call this periodically (every heartbeat interval) to balance.
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
					$this->sse_handler->finish();
					return;
				}

				// Normalize updated details to ensure JSON serializability.
				// This converts any WP_Error objects to serializable arrays.
				$updated_details = $this->normalize_data_recursive( $updated_details );

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
					$this->sse_handler->finish();
					return;
				}
			}

			// Timeout reached - send timeout event.
			$this->sse_handler->send_sse_event(
				'cron_job_status',
				array(
					'job_id' => $job_id,
					'status' => 'timeout',
					'error'  => __( 'Job status polling timed out. Job may still be running.', 'mcp-ai-wpoos' ),
				)
			);
			$this->sse_handler->send_sse_done();
			$this->sse_handler->finish();
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

			// Parse pagination parameters; -1 (or null) means return all (default).
			$per_page = $request->get_param( 'per_page' );
			$per_page = ( null !== $per_page ) ? intval( $per_page ) : -1;
			$page_raw = $request->get_param( 'page' );
			$page     = max( 1, absint( null !== $page_raw ? (int) $page_raw : 1 ) );

			$total_assistants = 0;
			$total_pages      = 1;
			$assistants       = array();

			if ( $scoped_assistant ) {
				// Token-scoped: single assistant, skip caching.
				$assistant_post = $this->validate_assistant_access( $scoped_assistant );

				if ( is_wp_error( $assistant_post ) ) {
					return $assistant_post;
				}

				$summary          = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );
				$assistants       = array( $summary );
				$total_assistants = 1;
				$total_pages      = 1;
			} else {
				// Build cache key from query parameters (not _fields — filtered after cache retrieval).
				$cache_params = array_filter(
					array(
						'search'   => $request->get_param( 'search' ),
						'include'  => $request->get_param( 'include' ),
						'per_page' => ( $per_page > 0 ) ? $per_page : null,
						'page'     => ( $per_page > 0 && $page > 1 ) ? $page : null,
					),
					static function ( $v ) {
						return null !== $v;
					}
				);

				$cached_data = WP_MCP_AI_REST_Cache::get_response( 'assistants', $cache_params );

				if ( false !== $cached_data && is_array( $cached_data ) ) {
					// Serve from cache.
					$assistants       = $cached_data['assistants'];
					$total_assistants = $cached_data['total'];
					$total_pages      = $cached_data['total_pages'];
				} else {
					// Build the WP_Query arguments.
					$query_args = array(
						'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
						'post_status' => array( 'publish' ),
						'orderby'     => 'title',
						'order'       => 'ASC',
					);

					if ( $per_page > 0 ) {
						$query_args['posts_per_page'] = $per_page;
						$query_args['paged']          = $page;
					} else {
						$query_args['posts_per_page'] = -1;
						$query_args['no_found_rows']  = true; // Skip COUNT query for unlimited requests.
					}

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

					foreach ( $filtered as $assistant_post ) {
						$summary      = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );
						$assistants[] = $summary;
					}

					$assistants = array_values( $assistants );

					// Compute totals.
					if ( $per_page > 0 ) {
						$total_assistants = absint( $query->found_posts );
						$total_pages      = (int) ceil( $total_assistants / $per_page );
					} else {
						$total_assistants = count( $assistants );
						$total_pages      = 1;
					}

					// Cache the unscoped list for future requests.
					WP_MCP_AI_REST_Cache::set_response(
						'assistants',
						$cache_params,
						array(
							'assistants'  => $assistants,
							'total'       => $total_assistants,
							'total_pages' => $total_pages,
						),
						WP_MCP_AI_REST_Cache::ASSISTANT_LIST_EXPIRATION
					);
				}
			}

			// Apply _fields filtering to each assistant summary.
			$fields_param = $request->get_param( '_fields' );
			if ( $fields_param && is_string( $fields_param ) ) {
				$allowed_fields = wp_parse_list( $fields_param );
				if ( ! empty( $allowed_fields ) ) {
					// Always include 'id' per REST API convention.
					if ( ! in_array( 'id', $allowed_fields, true ) ) {
						$allowed_fields[] = 'id';
					}
					$allowed_map = array_flip( $allowed_fields );
					$assistants  = array_map(
						static function ( $assistant ) use ( $allowed_map ) {
							return array_intersect_key( $assistant, $allowed_map );
						},
						$assistants
					);
				}
			}

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
				'name'    => 'NV oOS',
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

			if ( $this->request_wants_event_stream( $request ) || $this->request_accept_wants_event_stream( $request ) ) {
				return $this->stream_event_stream_payload( $response_data, 'directory' );
			}

			$response = new WP_REST_Response( $response_data, 200 );
			$response->header( 'X-WP-Total', $total_assistants );
			$response->header( 'X-WP-TotalPages', $total_pages );

			return $response;
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
				'has_corpus'          => ( isset( $config['corpus_name'] ) && '' !== $config['corpus_name'] ),
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
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id, $request );

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

				// If the bearer token was validated but did not map to a WordPress user,
				// reject the request if the assistant requires an authenticated user.
				// This prevents privilege escalation where an unmapped bearer token
				// could piggyback on an existing WordPress session cookie.
				$auth_user_id = isset( $this->auth_context['authenticated_user_id'] )
					? (int) $this->auth_context['authenticated_user_id']
					: 0;
				if ( $requires_authenticated_user && $auth_user_id <= 0 ) {
					// Check if the mapped user ID is available from the authenticator context.
					$mapped_id = isset( $this->auth_context['user_id'] )
						? (int) $this->auth_context['user_id']
						: 0;
					if ( $mapped_id <= 0 ) {
						return $this->insufficient_permissions_error( $capability );
					}
					// Use the mapped user for capability checks.
					$mapped_user = get_userdata( $mapped_id );
					if ( ! $mapped_user || ! user_can( $mapped_id, $capability ) ) {
						return $this->insufficient_permissions_error( $capability );
					}
				}

				// Check rate limiting for bearer token authenticated requests.
				$user_id          = get_current_user_id();
				$rate_limit_check = $this->check_rate_limit( $user_id );
				if ( is_wp_error( $rate_limit_check ) ) {
					return $rate_limit_check;
				}
				return true;
			}

			/**
			 * Allow raw assistant credentials (cred_xxx.yyy) without the
			 * "Bearer " scheme prefix.
			 *
			 * Mirrors the compatibility handling in permissions_check_mcp().
			 *
			 * @since 1.1.55
			 *
			 * @param bool $accept_raw_credential_header Whether to accept raw credential headers. Default true.
			 */
			$accept_raw_credential = apply_filters( 'wp_mcp_ai_accept_raw_credential_header', true );
			if ( $accept_raw_credential && ! empty( $bearer ) && preg_match( '/^cred_[A-Za-z0-9]+\.[A-Za-z0-9_-]{8,}$/', trim( $bearer ) ) ) {
				$token = trim( $bearer );
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
					__( 'Authentication is required. Provide an Auth0 bearer token or a WordPress REST nonce.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 401,
						'actions' => array(
							'supply_bearer_token' => __( 'Include an Auth0-issued access token using the Authorization: Bearer YOUR_TOKEN header.', 'mcp-ai-wpoos' ),
							'include_rest_nonce'  => __( 'Include the X-WP-Nonce header from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'mcp-ai-wpoos' ),
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( $capability && ! current_user_can( $capability ) ) {
				return $this->insufficient_permissions_error( $capability );
			}

			$this->set_authenticated_user_id( get_current_user_id() );

			// Enforce capability check for non-admin users authenticated via WP nonce.
			// Admin users bypass this check; all others must have the required capability.
			if ( $requires_authenticated_user && ! current_user_can( 'administrator' ) && ! current_user_can( $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.RoleFound -- Intentional super-admin bypass; 'administrator' role is checked as a gate for admin users who always hold all capabilities.
				return $this->insufficient_permissions_error( $capability );
			}

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

			// Authenticated WordPress users with at least 'read' capability always
			// have access to the assistant directory via the Pro SPA admin interface.
			// The rest_enable_assistant_list setting only gates external API access.
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) && current_user_can( 'read' ) ) {
				return true;
			}

			// Then check if REST assistant listing is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['rest_enable_assistant_list'] ) ) {
				return new WP_Error(
					'rest_assistant_list_disabled',
					__( 'Listing assistants via REST API is currently disabled. Enable it in Settings → NV oOS → Authentication → REST API Capabilities.', 'mcp-ai-wpoos' ),
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
					__( 'Creating assistants via REST API is currently disabled. Enable it in Settings → NV oOS → Authentication.', 'mcp-ai-wpoos' ),
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
					__( 'Deleting assistants via REST API is currently disabled. Enable it in Settings → NV oOS → Authentication.', 'mcp-ai-wpoos' ),
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
					__( 'Sorry, you are not allowed to delete this assistant.', 'mcp-ai-wpoos' ),
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
					__( 'Invalid assistant ID.', 'mcp-ai-wpoos' ),
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
					__( 'The assistant cannot be deleted.', 'mcp-ai-wpoos' ),
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

			// Title is required for actual creation; when absent treat as a
			// connectivity check and return the directory listing instead.
			if ( empty( $title ) ) {
				return $this->handle_assistants_index( $request );
			}

			// When actually creating, verify that REST assistant creation is enabled.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( empty( $settings['rest_enable_assistant_create'] ) ) {
				return new WP_Error(
					'rest_assistant_create_disabled',
					__( 'Creating assistants via REST API is currently disabled. Enable it in Settings → NV oOS → Authentication.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
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
					__( 'Could not create the assistant.', 'mcp-ai-wpoos' ),
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

				$mcp_url = rest_url( 'mcp-ai/v1/mcp' );

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

				// Validate OAuth 2.0 access token with audience check.
				$oauth = $this->validate_oauth_token( $token, $request, $mcp_url );
				if ( true === $oauth ) {
					return true;
				} elseif ( is_wp_error( $oauth ) ) {
					return $oauth;
				}

				// Validate Auth0 or other bearer token.
				$validated = $this->validate_bearer_token( $token, $request );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				return true;
			}

				/**
				 * Allow raw assistant credentials (cred_xxx.yyy) without the
				 * "Bearer " scheme prefix.
				 *
				 * Some agent configurations forward the Authorization header value
				 * verbatim as configured (e.g. Cloudways Agent). The credential
				 * itself is the secret; the scheme label adds no security, so we
				 * accept the raw form for compatibility. Disable this filter to
				 * require strict RFC 6750 bearer syntax.
				 *
				 * @since 1.1.55
				 *
				 * @param bool $accept_raw_credential_header Whether to accept raw credential headers. Default true.
				 */
				$accept_raw_credential = apply_filters( 'wp_mcp_ai_accept_raw_credential_header', true );
			if ( $accept_raw_credential && ! empty( $bearer ) && preg_match( '/^cred_[A-Za-z0-9]+\.[A-Za-z0-9_-]{8,}$/', trim( $bearer ) ) ) {
				$token = trim( $bearer );

				// Validate local credential token (raw form).
				$local = $this->validate_local_token( $token, $request );
				if ( true === $local ) {
					return true;
				} elseif ( $local instanceof WP_Error ) {
					return $local;
				}
			}

				// Check for WordPress Basic auth (Application Passwords).
			if ( ! empty( $bearer ) && 0 === stripos( $bearer, 'Basic ' ) ) {
				$basic_result = $this->validate_wp_basic_auth( $request );
				if ( true === $basic_result ) {
					return true;
				} elseif ( is_wp_error( $basic_result ) ) {
					return $basic_result;
				}
			}

				// Allow WordPress nonce authentication ONLY for internal admin diagnostic testing.
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				$internal_header = $request->get_header( 'X-WP-MCP-AI-Internal-Diagnostic' );
				$is_local_origin = isset( $_SERVER['HTTP_ORIGIN'] )
					&& wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST );
				$is_internal     = ( '1' === $internal_header ) || $is_local_origin;
				if ( $is_internal ) {
					$this->mark_token_authenticated( 'nonce_admin', array( 'admin_user' => get_current_user_id() ) );
					return true;
				}
			}

				// 401 — return WWW-Authenticate header per MCP Authorization spec.
				$error = new WP_Error(
					'wp_mcp_ai_mcp_auth_required',
					__( 'Authentication required. Use a Bearer token, OAuth 2.0, Application Password (Basic auth), or Mesh Key.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 401,
						'actions' => array(
							'supply_bearer_token' => __( 'Add an Authorization: Bearer YOUR_TOKEN header.', 'mcp-ai-wpoos' ),
							'connect_via_oauth'   => __( 'Connect via OAuth 2.0 using an MCP client that supports it (Codex, Claude Desktop).', 'mcp-ai-wpoos' ),
							'use_app_password'    => __( 'Use Authorization: Basic with a WordPress Application Password.', 'mcp-ai-wpoos' ),
							'supply_mesh_key'     => __( 'Use the X-WP-MCP-AI-Mesh-Key header for mesh network access.', 'mcp-ai-wpoos' ),
						),
					)
				);

				// Per MCP spec: include WWW-Authenticate header with resource_metadata URL.
			if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
				$www_auth = WP_MCP_AI_OAuth_Server::build_www_authenticate( $mcp_url );
				$error->add_data(
					array(
						'www_authenticate' => $www_auth,
					)
				);
				// Also send as an actual HTTP header so clients can discover OAuth support.
				if ( ! headers_sent() ) {
					header( 'WWW-Authenticate: ' . $www_auth );
				}
			}

				return $error;
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
			// extract_guest_token already supports both header and query parameter.
			$guest_token = $this->extract_guest_token( $request );
			if ( $guest_token && class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				$guest_assistant = WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, 0, $request );

				if ( $guest_assistant ) {
					// Guest users can view their own cron jobs (user_id = 0).
					$this->set_authenticated_user_id( 0 );
					return true;
				}
			}

			// Fallback: If a WordPress user is already authenticated via session cookie,
			// use their identity for cron-status access (e.g. browser-based SSE connections).
			$current_user_id = get_current_user_id();
			if ( $current_user_id > 0 ) {
				$this->set_authenticated_user_id( $current_user_id );
				return true;
			}

			// Check for WordPress nonce authentication.
			// Support both header and query parameter for SSE connections.
			// EventSource (SSE) cannot send custom headers, so we accept _wpnonce as a query param.
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( empty( $nonce ) ) {
				// Fall back to query parameter for SSE connections.
				$nonce_param = $request->get_param( '_wpnonce' );

				if ( is_string( $nonce_param ) && '' !== $nonce_param ) {
					$nonce = $nonce_param;
				}
			}

			if ( empty( $nonce ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_credentials',
					__( 'Authentication is required to view cron status.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 401,
						'actions' => array(
							'supply_bearer_token' => __( 'Include a bearer token using the Authorization: Bearer YOUR_TOKEN header.', 'mcp-ai-wpoos' ),
							'supply_guest_token'  => __( 'Include a guest token using the X-WP-MCP-AI-Guest header or guest_token query parameter for public chat surfaces.', 'mcp-ai-wpoos' ),
							'include_rest_nonce'  => __( 'Include the X-WP-Nonce header or _wpnonce query parameter from wp_create_nonce( "wp_rest" ) when calling this endpoint from WordPress.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_invalid_nonce',
					__( 'Could not verify the request nonce.', 'mcp-ai-wpoos' ),
					array(
						'status'  => rest_authorization_required_code(),
						'actions' => array(
							'refresh_nonce' => __( 'Refresh your WordPress session to obtain a fresh nonce and retry the request.', 'mcp-ai-wpoos' ),
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
			 * Validate an MCP OAuth 2.0 access token.
			 *
			 * @since 1.7.0
			 *
			 * @param string          $token    Raw bearer token string.
			 * @param WP_REST_Request $request  Current REST request.
			 * @param string          $audience Expected audience (MCP server URL).
			 * @return true|WP_Error|null
			 */
		protected function validate_oauth_token( $token, WP_REST_Request $request, $audience = '' ) {
			return $this->authenticator->validate_oauth_token( $token, $request, $audience );
		}

			/**
			 * Validate authentication via WordPress Application Passwords (Basic auth).
			 *
			 * @since 1.7.0
			 *
			 * @param WP_REST_Request $request      Current REST request.
			 * @param string          $required_cap WordPress capability the user must hold.
			 * @return true|WP_Error|null
			 */
		protected function validate_wp_basic_auth( WP_REST_Request $request, $required_cap = 'read' ) {
			return $this->authenticator->validate_wp_basic_auth( $request, $required_cap );
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

			// GET/HEAD requests are cheap, read-only probes (endpoint discovery,
			// SSE stream checks). MCP client retry loops can legitimately issue
			// many of them per hour, so they must not consume the budget aimed
			// at expensive or state-changing traffic.
			$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
			if ( in_array( strtoupper( $request_method ), array( 'GET', 'HEAD' ), true ) ) {
				return true;
			}

			// Get rate limit configuration.
			$max_requests = isset( $settings['rate_limit_requests'] ) ? absint( $settings['rate_limit_requests'] ) : 100;
			$time_window  = isset( $settings['rate_limit_window'] ) ? absint( $settings['rate_limit_window'] ) : 3600;

			// Create a unique key. Guests (user_id=0) get an IP-based
			// key to prevent one attacker from exhausting the global quota.
			if ( $user_id > 0 ) {
				$transient_key = 'wp_mcp_ai_rate_limit_user_' . $user_id;
			} else {
				$client_ip     = isset( $_SERVER['REMOTE_ADDR'] )
					? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
					: 'unknown';
				$transient_key = 'wp_mcp_ai_rate_limit_ip_' . md5( $client_ip . NONCE_SALT );
			}
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

				// Log to the security audit table for SIEM / dashboard visibility.
				if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
					WP_MCP_AI_Security_Audit_Logger::log_event(
						WP_MCP_AI_Security_Audit_Logger::EVENT_RATE_LIMIT_HIT,
						$user_id,
						array(
							'max_requests'  => $max_requests,
							'time_window'   => $time_window,
							'current_count' => $current_count,
						)
					);
				}

				return new WP_Error(
					'wp_mcp_ai_rate_limit_exceeded',
					sprintf(
						/* translators: 1: Maximum requests allowed, 2: Time window in seconds */
						__( 'Rate limit exceeded. Maximum %1$d requests allowed per %2$d seconds.', 'mcp-ai-wpoos' ),
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
		 * Check per-user, per-tool rate limiting for the tool execution endpoint.
		 *
		 * This is a separate rate limiter from check_rate_limit() which handles
		 * chat requests. Tool execution uses a shorter, tighter window to prevent
		 * tool abuse while allowing reasonable usage.
		 *
		 * Limits are configurable via the Security → Tool Rate Limiting settings
		 * (tool_rate_limit_max / tool_rate_limit_window). Credential-token
		 * (AI agent) traffic is exempt by default because an assistant credential
		 * is already an explicit grant of its tool set — agents legitimately fire
		 * tool calls in bursts. Guest and nonce (chat UI) traffic stays limited.
		 *
		 * @since 1.2.0
		 * @since 1.1.55 Added settings-driven limits and token exemption.
		 *
		 * @param int   $user_id      User ID making the request (0 for guests).
		 * @param array $auth_context Optional auth context (token_authenticated flag).
		 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
		 */
		protected function check_tool_rate_limit( $user_id, $auth_context = array() ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Exempt credential-token (AI agent) traffic when enabled.
			// An assistant credential is an explicit grant of the assistant's tool
			// set; rate limiting exists to stop chat-UI/guest abuse.
			$exempt_tokens = isset( $settings['tool_rate_limit_exempt_tokens'] ) ? (bool) $settings['tool_rate_limit_exempt_tokens'] : true;
			if ( $exempt_tokens && ! empty( $auth_context['token_authenticated'] ) ) {
				return true;
			}

			// Resolve the window and max from settings, falling back to the class
			// constants for backward compatibility.
			$window_default = isset( $settings['tool_rate_limit_window'] ) ? absint( $settings['tool_rate_limit_window'] ) : self::TOOL_RATE_LIMIT_WINDOW;
			$window_default = max( 10, $window_default );

			$max_default = isset( $settings['tool_rate_limit_max'] ) ? absint( $settings['tool_rate_limit_max'] ) : self::TOOL_RATE_LIMIT_MAX;
			$max_default = max( 0, $max_default );

			/**
			 * Filters the tool rate limit window in seconds.
			 *
			 * @since 1.2.0
			 *
			 * @param int $window Window in seconds. Defaults to the
			 *                    tool_rate_limit_window setting (60).
			 */
			$window = apply_filters( 'wp_mcp_ai_tool_rate_limit_window', $window_default );

			/**
			 * Filters the maximum tool executions per window.
			 *
			 * @since 1.2.0
			 *
			 * @param int $max Maximum executions. Defaults to the
			 *                 tool_rate_limit_max setting (300). 0 = unlimited.
			 */
			$max = apply_filters( 'wp_mcp_ai_tool_rate_limit_max', $max_default );

			$window = max( 10, absint( $window ) );
			$max    = max( 0, absint( $max ) );

			// 0 disables the limiter.
			if ( 0 === $max ) {
				return true;
			}

			// Create a unique key. Guests (user_id=0) get an IP-based
			// key to prevent one attacker from exhausting the global quota.
			if ( $user_id > 0 ) {
				$transient_key = 'wp_mcp_ai_tool_rl_' . $user_id;
			} else {
				$client_ip     = isset( $_SERVER['REMOTE_ADDR'] )
					? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
					: 'unknown';
				$transient_key = 'wp_mcp_ai_tool_rl_ip_' . md5( $client_ip . NONCE_SALT );
			}

			$current_count = get_transient( $transient_key );

			if ( false === $current_count ) {
				// First request in this time window, start counting.
				set_transient( $transient_key, 1, $window );
				return true;
			}

			if ( $current_count >= $max ) {
				// Rate limit exceeded.
				WP_MCP_AI_Logger::log_event(
					'tool_rate_limit_exceeded',
					sprintf(
						'User %d exceeded tool rate limit of %d executions per %d seconds.',
						$user_id,
						$max,
						$window
					),
					array(
						'user_id'    => $user_id,
						'max'        => $max,
						'window'     => $window,
						'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
					)
				);

				return new WP_Error(
					'wp_mcp_ai_tool_rate_limit_exceeded',
					sprintf(
						/* translators: 1: Maximum executions allowed, 2: Time window in seconds */
						__( 'Tool rate limit exceeded. Maximum %1$d tool executions allowed per %2$d seconds.', 'mcp-ai-wpoos' ),
						$max,
						$window
					),
					array(
						'status'        => 429,
						'retry_after'   => $window,
						'max'           => $max,
						'window'        => $window,
						'current_count' => $current_count,
					)
				);
			}

			// Increment the counter.
			set_transient( $transient_key, $current_count + 1, $window );
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

			// Feature flag: route to the framework-agnostic OOS engine when enabled.
			// Activate via ?engine=oos, X-WP-MCP-AI-Engine: oos header, or
			// define('WP_MCP_AI_OOS_ENGINE', true).
			if ( function_exists( 'wp_mcp_ai_oos_engine_enabled' ) && wp_mcp_ai_oos_engine_enabled() ) {
				return $this->handle_chat_request_oos( $request );
			}

			// Check if this is a unified team, profession test, or regular assistant request.
			$raw_assistant_id = $request->get_param( 'assistant_id' );
			$team_id          = $this->extract_team_id( $raw_assistant_id );
			$profession_id    = $this->extract_profession_id( $raw_assistant_id );

			// If this is a unified team request, handle it through team orchestration.
			if ( $team_id ) {
				return $this->handle_unified_team_request( $request, $team_id );
			}

			$assistant_id = $this->resolve_assistant_id( $raw_assistant_id );
			$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
			if ( is_wp_error( $scoped_id ) ) {
				return $scoped_id;
			}

			$assistant_id = $scoped_id;

			if ( ! $assistant_id && ! $profession_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			// Validate assistant access only if we have an assistant ID.
			// For profession testing without an associated assistant, we'll use an empty config.
			if ( $assistant_id ) {
				$assistant_post = $this->validate_assistant_access( $assistant_id );
				if ( is_wp_error( $assistant_post ) ) {
					return $assistant_post;
				}
			}

			$sanitized_messages = $this->validator->sanitize_messages( $request->get_param( 'messages' ) );
			if ( is_wp_error( $sanitized_messages ) ) {
				return $sanitized_messages;
			}

			$messages    = $sanitized_messages['messages'];
			$attachments = $sanitized_messages['attachments'];

			if ( empty( $messages ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_messages', __( 'Messages must be provided as an array of role/content pairs.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			// ----- Layer I Guardrails: pre-screen the last user message -----
			$last_user_message = '';
			for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
				if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
					$last_user_message = isset( $messages[ $i ]['content'] ) ? (string) $messages[ $i ]['content'] : '';
					break;
				}
			}

			if ( '' !== $last_user_message ) {
				/**
				 * Filter: pre-screen a chat message before it reaches the LLM.
				 *
				 * The Layer I guardrails subscriber hooks here to detect off-topic,
				 * jailbreak, and prompt-injection messages. Returning a WP_Error
				 * blocks the message.
				 *
				 * @since 1.12.0
				 *
				 * @param array|WP_Error|null $result       Pass-through or WP_Error to block.
				 * @param string              $message      The user's message text.
				 * @param int                 $assistant_id Assistant post ID.
				 * @param array               $context      Additional context: { surface, request }.
				 */
				$screen_result = apply_filters(
					'wp_mcp_ai_pre_chat_message',
					null,
					$last_user_message,
					isset( $assistant_id ) ? (int) $assistant_id : 0,
					array(
						'surface' => 'rest_chat',
						'request' => $request,
					)
				);

				if ( is_wp_error( $screen_result ) ) {
					return $screen_result;
				}
			}

			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

			// Check if this is an embedded provider with a client-side (WebLLM) model.
			// Server-side GGUF models (llama.cpp) share the 'embedded' provider key but are
			// processed here on the server — only pure WebLLM requests should be rejected.
			if ( isset( $assistant_config['provider'] ) && 'embedded' === $assistant_config['provider'] ) {
				$model_slug = isset( $assistant_config['model'] ) ? $assistant_config['model'] : '';

				// When no model is explicitly set on the assistant, fall back to the global
				// embedded_server_model setting so server-side GGUF inference can proceed.
				if ( empty( $model_slug ) && class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
					$global_settings = WP_MCP_AI_Admin_Settings::get_settings();
					$model_slug      = isset( $global_settings['embedded_server_model'] ) ? $global_settings['embedded_server_model'] : '';
				}

				$is_server_model = class_exists( 'WP_MCP_AI_Embedded_Client' ) && WP_MCP_AI_Embedded_Client::is_server_model_slug( $model_slug );

				if ( ! $is_server_model ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_provider_server_request_blocked',
						'Embedded WebLLM provider detected in server-side chat request',
						array(
							'assistant_id' => $assistant_id,
							'model'        => $model_slug,
							'endpoint'     => $request->get_route(),
						)
					);

					return new WP_Error(
						'wp_mcp_ai_embedded_client_side_only',
						__( 'This assistant is configured with an embedded LLM provider which runs entirely client-side in the browser. Please ensure your chat interface is properly configured to use client-side execution for embedded providers.', 'mcp-ai-wpoos' ),
						array(
							'status'        => 400,
							'provider'      => 'embedded',
							'model'         => $model_slug,
							'documentation' => 'Embedded providers bypass server-side REST APIs and execute using WebLLM in the browser. Check that your chat configuration includes provider and model information.',
						)
					);
				}
			}

			// Debug logging for assistant configuration loading.
			WP_MCP_AI_Logger::log_event(
				'rest_chat_assistant_config_loaded',
				'Assistant configuration loaded in handle_chat_request',
				array(
					'assistant_id'          => $assistant_id,
					'has_system_prompt'     => ! empty( $assistant_config['system_prompt'] ),
					'system_prompt_length'  => ! empty( $assistant_config['system_prompt'] ) ? strlen( $assistant_config['system_prompt'] ) : 0,
					'system_prompt_preview' => ! empty( $assistant_config['system_prompt'] ) ? substr( $assistant_config['system_prompt'], 0, 200 ) : '',
					'provider'              => isset( $assistant_config['provider'] ) ? $assistant_config['provider'] : '',
					'model'                 => isset( $assistant_config['model'] ) ? $assistant_config['model'] : '',
					'tools_count'           => isset( $assistant_config['tools'] ) && is_array( $assistant_config['tools'] ) ? count( $assistant_config['tools'] ) : 0,
				)
			);

			// If testing a profession, merge profession configuration.
			if ( $profession_id ) {
				$assistant_config = $this->load_profession_configuration( $profession_id, $assistant_config );
			}

			// If a professional_prompt is provided (from professional selector), prepend it to system prompt.
			$professional_prompt = $request->get_param( 'professional_prompt' );
			if ( ! empty( $professional_prompt ) && is_string( $professional_prompt ) ) {
				$professional_prompt = sanitize_textarea_field( $professional_prompt );
				if ( ! empty( $assistant_config['system_prompt'] ) ) {
					// Prepend professional prompt to existing system prompt.
					$assistant_config['system_prompt'] = $professional_prompt . "\n\n---\n\n# Additional Instructions\n\n" . $assistant_config['system_prompt'];
				} else {
					// Use professional prompt as the system prompt.
					$assistant_config['system_prompt'] = $professional_prompt;
				}
			}

			/**
			 * Filter the resolved system prompt just before it is consumed by
			 * the chat path. The harness Prompt Cue injector subscribes to
			 * this hook to prepend cues from the assistant's harness profile.
			 *
			 * @since 1.4.0
			 *
			 * @param string $system_prompt The system prompt as resolved so far.
			 * @param int    $assistant_id  Assistant post ID (0 if none).
			 * @param array  $context       Surface context: { surface: 'rest_chat', request: WP_REST_Request }.
			 */
			$assistant_config['system_prompt'] = (string) apply_filters(
				'wp_mcp_ai_resolved_system_prompt',
				isset( $assistant_config['system_prompt'] ) ? (string) $assistant_config['system_prompt'] : '',
				isset( $assistant_id ) ? (int) $assistant_id : 0,
				array(
					'surface' => 'rest_chat',
					'request' => $request,
				)
			);

			// If additional_tools are provided (for context-specific tools like research pages), merge them into the assistant's tools.
			$additional_tools = $request->get_param( 'additional_tools' );
			if ( ! empty( $additional_tools ) && is_array( $additional_tools ) ) {
				// Sanitize the additional tools array.
				$additional_tools = array_filter( array_map( 'sanitize_key', $additional_tools ) );

				if ( ! empty( $additional_tools ) ) {
					// Merge with existing tools, ensuring no duplicates.
					if ( ! isset( $assistant_config['tools'] ) || ! is_array( $assistant_config['tools'] ) ) {
						$assistant_config['tools'] = array();
					}
					$assistant_config['tools'] = array_values( array_unique( array_merge( $assistant_config['tools'], $additional_tools ) ) );
				}
			}

			$options = $this->validator->sanitize_options( $request->get_param( 'options' ), $assistant_config );

			$limit_context = $this->build_chat_limit_context( $assistant_id, $options, $request );
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
					'message'      => __( 'Chat probe acknowledged.', 'mcp-ai-wpoos' ),
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

			// Reorder messages for optimal prompt caching when enabled.
			if ( ! empty( $options['cache_system_prompt'] ) && class_exists( 'WP_MCP_AI_Prompt_Optimizer' ) ) {
				$messages = WP_MCP_AI_Prompt_Optimizer::order_for_cache_hit( $messages, $options );

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'prompt_cache_reordered',
						'Messages reordered for prefix cache optimization',
						array(
							'message_count' => count( $messages ),
							'first_role'    => isset( $messages[0]['role'] ) ? $messages[0]['role'] : 'none',
						)
					);
				}
			}

			// Check if streaming is requested for agentic loop support.
			$wants_streaming = $this->request_wants_event_stream( $request );

			// Agentic loop: automatically execute tools server-side when LLM requests them.
			// Industry standard base default is 5 iterations (OpenAI SDK defaults to 15; Anthropic
			// Claude Code agent loop caps at 50). A base of 5 allows multi-step tool workflows
			// (search → analyse → respond) while preventing runaway loops on misconfigured tools.
			// Entry points raise this via the wp_mcp_ai_max_agentic_iterations filter:
			// - Chat client (browser UI): 15 iterations (priority 10).
			// - Channel webhooks (Telegram/Slack/WhatsApp): 10 iterations (priority 10).
			// - Scheduled runs: 10 iterations (priority 10).
			// - Admin setting (filter_max_agentic_iterations): priority 5.
			// - PSO optimiser: dynamic (priority 50).
			$max_iterations = 5;
			$max_iterations = (int) apply_filters( 'wp_mcp_ai_max_agentic_iterations', $max_iterations, $assistant_config );
			$max_iterations = max( 1, min( 50, $max_iterations ) ); // Safety bounds: 1-50.
			$iteration      = 0;

			// Phase 3: agentic-loop output guard. Tracks cumulative tool-output bytes
			// across all iterations and substitutes oversized payloads with artifact
			// references so the LLM context stays bounded.
			$budget_tracker = new WP_MCP_AI_Data_Budget_Tracker( 'chat-' . $assistant_id . '-' . wp_generate_uuid4() );

			// Track original tool results for frontend display.
			$tool_result_messages = array();

			// Track intermediate assistant messages with tool_calls so that
			// server-side callers (e.g. the Telegram reply job) can fall back to
			// them when the final choice has empty content after the agentic loop
			// exhausts its iteration cap.
			$agentic_tool_messages = array();

			// Accumulate cost across all agentic-loop LLM calls so that
			// intermediate iterations are not silently discarded.
			// Phase 8: Per-iteration cost tracking.
			$agentic_cost_accumulator = array(
				'cost_usd'                => 0.0,
				'total_prompt_tokens'     => 0,
				'total_completion_tokens' => 0,
				'total_cached_tokens'     => 0,
				'iterations'              => array(),
			);

			// If streaming is requested, use streaming-enabled agentic loop.
			if ( $wants_streaming ) {
				// Enforce SSE rate limits before opening a streaming connection.
				if ( class_exists( 'WP_MCP_AI_SSE_Rate_Limiter' ) ) {
					$sse_limiter       = new WP_MCP_AI_SSE_Rate_Limiter();
					$rate_limit_result = $sse_limiter->check_connection_allowed();

					if ( is_wp_error( $rate_limit_result ) ) {
						$error_data  = $rate_limit_result->get_error_data();
						$retry_after = isset( $error_data['retry_after'] ) ? (int) $error_data['retry_after'] : 30;
						$response    = rest_ensure_response(
							array(
								'code'    => $rate_limit_result->get_error_code(),
								'message' => $rate_limit_result->get_error_message(),
								'data'    => $error_data,
							)
						);
						$response->set_status( 429 );
						$response->header( 'Retry-After', (string) $retry_after );
						return $response;
					}
				}

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

			// Pre-flight TPM validation: truncate or switch model before the
			// initial LLM call to avoid TPM-limit rejections (especially
			// relevant for Anthropic models with low TPM ceilings).
			$preflight = $this->preflight_tpm_check( $messages, $options, $assistant_id );
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
			$messages = $preflight['messages'];
			$options  = $preflight['options'];

			// Check response cache before making LLM call.
			$response_cache  = new WP_MCP_AI_Chat_Response_Cache();
			$cached_response = $response_cache->get_cached_response( $messages, $options );
			if ( false !== $cached_response ) {
				// Fire the after-chat-response action for cache hits too.
				do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $cached_response, $request );
				return rest_ensure_response( $cached_response );
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

			// Capture cost of the initial LLM call before entering the agentic loop.
			// This ensures the first response (which may contain tool_calls) is
			// counted even when the loop executes multiple iterations.
			$initial_cost = $this->calculate_response_cost( $response, $options, $assistant_id, $user_id, 'initial chat response' );
			if ( is_array( $initial_cost ) ) {
				$agentic_cost_accumulator['cost_usd']                += $initial_cost['cost_usd'];
				$agentic_cost_accumulator['total_prompt_tokens']     += isset( $initial_cost['prompt_tokens'] ) ? (int) $initial_cost['prompt_tokens'] : 0;
				$agentic_cost_accumulator['total_completion_tokens'] += isset( $initial_cost['completion_tokens'] ) ? (int) $initial_cost['completion_tokens'] : 0;
				$agentic_cost_accumulator['iterations'][]             = array_merge(
					$initial_cost,
					array(
						'iteration' => 0,
						'phase'     => 'initial',
					)
				);
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
					$messages[]              = $assistant_message;
					$agentic_tool_messages[] = $assistant_message;
				}

				// Execute each tool and collect results.
				// Track if any tool returned async pending result (requires exiting agentic loop).
				$has_async_pending_result = false;
				$pending_async_jobs       = array();

				foreach ( $tool_calls as $tool_call ) {
					$tool_result = $this->execute_tool_call_internal( $tool_call, $assistant_id, $assistant_config, $user_id, $request, $iteration, $max_iterations, $transcript_context );

					// Convert WP_Error to serializable format to prevent JSON encoding failures.
					$tool_result = $this->normalize_tool_result( $tool_result );

					// Extract tool call metadata for message construction.
					$tool_call_id = isset( $tool_call['id'] ) ? $tool_call['id'] : '';
					$tool_name    = isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : '';

					// Ensure tool_call_id is never empty so downstream
					// tool-result messages are valid for the next turn.
					if ( '' === $tool_call_id ) {
						$tool_call_id = uniqid( 'tool_', true );
					}

					// Check if this is an async pending result (background-only tools like video generation).
					// When a tool returns {async: true, status: 'pending'}, we need to exit the agentic loop
					// after processing this iteration. The frontend will handle polling for the async result.
					if ( is_array( $tool_result ) && ! empty( $tool_result['async'] ) && 'pending' === ( $tool_result['status'] ?? '' ) ) {
						$has_async_pending_result = true;
						$pending_job_id           = isset( $tool_result['job_id'] ) ? (string) $tool_result['job_id'] : '';
						if ( '' !== $pending_job_id ) {
							$pending_async_jobs[] = array(
								'job_id'       => $pending_job_id,
								'tool_call_id' => (string) $tool_call_id,
								'tool_name'    => (string) $tool_name,
							);
						}
						WP_MCP_AI_Logger::log_event(
							'async_tool_pending_in_agentic_loop',
							'Async tool returned pending status, will exit agentic loop after this iteration',
							array(
								'tool_name' => $tool_name,
								'job_id'    => $tool_result['job_id'] ?? 'unknown',
								'iteration' => $iteration,
							)
						);
					}

					// Get the tool instance for interface-based sanitization.
					$tool_instance   = null;
					$allowed_tools   = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
					$tool_candidates = $this->generate_tool_slug_candidates( $tool_name );
					$tool_slug       = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );
					if ( $tool_slug && in_array( $tool_slug, $allowed_tools, true ) ) {
						$tool_instance = $this->registry->get_tool( $tool_slug );
					}

					// Extract usage information from tool result before sanitization.
					// Phase 7: Enhanced Token Tracking - Include usage data in tool responses.
					$tool_usage_info = $this->extract_usage_info_from_tool_result( $tool_result );

					// Create a full tool message with structured result for frontend.
					// Use the tool's sanitize_for_llm method if available to strip base64 content.
					$full_tool_message = array(
						'role'    => 'tool',
						'content' => $this->validator->sanitize_tool_result_for_display( $tool_result, $tool_name, $tool_instance ),
					);

					// Always include tool_call_id so the frontend never sends back
					// a tool message that fails REST validation on the next turn.
					$full_tool_message['tool_call_id'] = '' !== $tool_call_id
						? $tool_call_id
						: uniqid( 'tool_', true );

					if ( '' !== $tool_name ) {
						$full_tool_message['name'] = $tool_name;
					}

					// Include usage information in tool message for frontend display.
					// Phase 7: Enhanced Token Tracking - Tool-level usage data.
					if ( ! empty( $tool_usage_info ) ) {
						$full_tool_message['usage'] = $tool_usage_info;
					}

					// Include capability flags for frontend badge display.
					$capability_flags = $this->extract_capability_flags_from_tool( $tool_instance );
					if ( ! empty( $capability_flags ) ) {
						$full_tool_message['capability_flags'] = $capability_flags;
					}

					// Store sanitized tool result for frontend.
					$tool_result_messages[] = $full_tool_message;

					// Create a sanitized version for the LLM (strip large content fields).
					$sanitized_result = $this->validator->sanitize_tool_result_for_llm( $tool_result, $tool_name, $assistant_config, $tool_instance );

					// Phase 3: agentic-loop output guard. If this single message or the
					// cumulative request budget would be exceeded, spill the payload to
					// an artifact and substitute a small reference envelope.
					$message_bytes = is_string( $sanitized_result ) ? strlen( $sanitized_result ) : 0;
					if ( $budget_tracker->should_spill( $message_bytes ) ) {
						$sanitized_result = WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result(
							$sanitized_result,
							$tool_name,
							array(
								'assistant_id' => $assistant_id,
								'iteration'    => $iteration,
								'tool_call_id' => $tool_call_id,
								'request_id'   => $budget_tracker->request_id(),
							)
						);
						$budget_tracker->note_spill();
						$message_bytes = is_string( $sanitized_result ) ? strlen( $sanitized_result ) : 0;
					}
					$budget_tracker->record( $message_bytes );

					$tool_message = array(
						'role'    => 'tool',
						// sanitize_tool_result_for_llm() always returns a string (truncated + delimiter-neutralised).
						'content' => $sanitized_result,
					);

					// Always include tool_call_id to keep the message valid
					// for provider payload filtering and subsequent turns.
					$tool_message['tool_call_id'] = '' !== $tool_call_id
					? $tool_call_id
					: uniqid( 'tool_', true );

					if ( '' !== $tool_name ) {
						$tool_message['name'] = $tool_name;
					}

					$messages[] = $tool_message;
				}

				// If any tool returned an async pending result (e.g., video generation),
				// exit the agentic loop. The frontend will poll for the async job completion.
				if ( $has_async_pending_result ) {
					WP_MCP_AI_Logger::log_event(
						'agentic_loop_exit_async_pending',
						'Exiting agentic loop due to async pending tool result',
						array(
							'iteration'    => $iteration,
							'assistant_id' => $assistant_id,
							'tool_count'   => count( $tool_result_messages ),
						)
					);
						$this->snapshot_chat_continuation_on_async_pending(
							$pending_async_jobs,
							$messages,
							$assistant_id,
							$user_id,
							$options,
							$transcript_context
						);
						break;
				}

				// Phase 4: Proactive agentic-loop context compaction.
				// Industry standard (LangChain Deep Agents, Vercel AI SDK):
				// compact context BEFORE hitting the TPM limit, not after.
				// At 70% capacity: offload old tool results.
				// At 85% capacity: summarize middle iterations.
				$messages = $this->maybe_compact_agentic_context( $messages, $iteration, $options, $assistant_id );

				// Validate token budget before next iteration to prevent TPM limit errors.
				$model             = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';
				$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;
				$tpm_validation    = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

				if ( is_wp_error( $tpm_validation ) ) {
					// Check if we should switch to a higher-capacity model.
					$settings            = WP_MCP_AI_Admin_Settings::get_settings();
					$fallback_model      = WP_MCP_AI_Model_Selector::resolve_fallback_model( $model, $settings );
					$auto_switch_enabled = isset( $settings['enable_high_token_model_switch'] ) ? (bool) $settings['enable_high_token_model_switch'] : true;
					$switched_model      = false;

					if ( $auto_switch_enabled && $fallback_model !== $model ) {
						// Try the fallback model.
						$fallback_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $fallback_model, $max_output_tokens );

						if ( ! is_wp_error( $fallback_validation ) ) {
							// Fallback model can handle the request.
							$original_model    = $model;
							$original_provider = isset( $options['provider'] ) ? $options['provider'] : '';
							$options['model']  = $fallback_model;
							$model             = $fallback_model;
							$switched_model    = true;

							// Also switch provider to match the fallback model so the router
							// sends the request to the correct LLM API endpoint.
							$fallback_model_config = class_exists( 'WP_MCP_AI_Model_Config' )
								? WP_MCP_AI_Model_Config::get_model_config( $fallback_model )
								: null;
							if ( $fallback_model_config && ! empty( $fallback_model_config['provider'] ) ) {
								$options['provider'] = sanitize_key( $fallback_model_config['provider'] );
							}

							WP_MCP_AI_Logger::log_event(
								'agentic_model_switched',
								'Switched to higher-capacity model due to token limits',
								array(
									'iteration'         => $iteration,
									'original_model'    => $original_model,
									'original_provider' => $original_provider,
									'new_model'         => $fallback_model,
									'new_provider'      => isset( $options['provider'] ) ? $options['provider'] : '',
									'assistant_id'      => $assistant_id,
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

				// Capture cost for this agentic-loop LLM call so intermediate
				// iterations are tracked and not silently discarded.
				$iter_cost = $this->calculate_response_cost( $response, $options, $assistant_id, $user_id, 'agentic iteration ' . ( $iteration + 1 ) );
				if ( is_array( $iter_cost ) ) {
					$agentic_cost_accumulator['cost_usd']                += $iter_cost['cost_usd'];
					$agentic_cost_accumulator['total_prompt_tokens']     += isset( $iter_cost['prompt_tokens'] ) ? (int) $iter_cost['prompt_tokens'] : 0;
					$agentic_cost_accumulator['total_completion_tokens'] += isset( $iter_cost['completion_tokens'] ) ? (int) $iter_cost['completion_tokens'] : 0;
					if ( isset( $iter_cost['cached_tokens'] ) ) {
						$agentic_cost_accumulator['total_cached_tokens'] += (int) $iter_cost['cached_tokens'];
					}
					$agentic_cost_accumulator['iterations'][] = array_merge(
						$iter_cost,
						array(
							'iteration' => $iteration + 1,
							'phase'     => 'agentic',
						)
					);
				}

				++$iteration;

					/**
					 * Fires after a single agentic-loop iteration has completed in
					 * the non-streaming REST chat path. Pure notification hook —
					 * consumed by the measurement observer to emit the
					 * `chat.agentic.iterations` histogram. No behaviour change.
					 *
					 * @since 1.3.0
					 *
					 * @param int   $iteration    Total iterations completed so far (1-based).
					 * @param mixed $assistant_id Assistant identifier.
					 */
					do_action( 'wp_mcp_ai_agentic_iteration_complete', $iteration, $assistant_id );
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

				// When the loop exits because it hit max_iterations, the final LLM response may
				// still contain tool_calls that were never executed (the PHP side did not make
				// another iteration to process them). If those tool_calls are forwarded to the
				// browser client as-is, the JS will persist an assistant message that has
				// tool_call_ids with no matching tool-response messages. On the very next user
				// turn the full conversation — including that orphaned assistant message — is
				// sent back to OpenAI, which rejects the request with:
				// "An assistant message with 'tool_calls' must be followed by tool messages
				// responding to each 'tool_call_id'."
				// Stripping the unexecuted tool_calls from the final response prevents the
				// client from ever storing that invalid state. The defensive filter inside
				// filter_tool_messages_for_payload() provides a second layer of protection for
				// any orphaned messages that may have been stored in a previous session.
				$this->strip_orphaned_tool_calls_from_response( $response, $assistant_id, $iteration, 'Non-SSE' );
			}

			// FALLBACK: If the LLM returned no text content but we have tool results, inject the
			// tool result text into the response so the frontend has something to display.
			// This mirrors the same fallback applied in handle_chat_request_with_streaming for SSE,
			// and ensures providers like Anthropic that sometimes omit a follow-up text response
			// after tool execution do not leave the chat interface blank.
			if ( ! empty( $tool_result_messages ) && ! is_wp_error( $response ) ) {
				$llm_text = '';
				if ( ! empty( $response['choices'][0]['message']['content'] ) ) {
					$llm_text = $this->normalise_message_content( $response['choices'][0]['message']['content'] );
				} elseif ( isset( $response['content'] ) ) {
					$llm_text = $this->normalise_message_content( $response['content'] );
				}

				if ( '' === $llm_text || ! is_string( $llm_text ) ) {
					$fallback_text = $this->extract_text_from_tool_results( $tool_result_messages );
					if ( '' !== $fallback_text ) {
						if ( ! isset( $response['choices'][0] ) ) {
							$response['choices'][0] = array( 'message' => array() );
						} elseif ( ! isset( $response['choices'][0]['message'] ) ) {
							$response['choices'][0]['message'] = array();
						}
						$response['choices'][0]['message']['content'] = $fallback_text;

						WP_MCP_AI_Logger::log_event(
							'debug',
							'Non-SSE chat: Extracted text from tool results (LLM returned no content)',
							array(
								'extracted_length' => strlen( $fallback_text ),
								'tool_count'       => count( $tool_result_messages ),
								'assistant_id'     => $assistant_id,
							)
						);
					}
				}
			}

			// Update response completion timestamp after agentic loop.
				$transcript_context['response_completed_at'] = microtime( true );

				/**
				 * Fires after the full agentic loop has completed (all iterations
				 * finished, whether by completion or by hitting max_iterations).
				 *
				 * Consumed by the Continual Harness evolver to trigger online
				 * harness adaptation after a batch of tool executions.
				 *
				 * @since 1.2.0
				 *
				 * @param int   $iteration     Total iterations completed.
				 * @param int   $assistant_id  Assistant post ID.
				 * @param array $tool_results  Array of tool results from the final iteration.
				 * @param bool  $limit_reached Whether the loop exited because max_iterations was reached.
				 */
				do_action( 'wp_mcp_ai_agentic_loop_completed', $iteration, $assistant_id, $tool_result_messages, $iteration >= $max_iterations );

				WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

			$recorded_session_key = null;
			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				// Augment the response with tool_results so they are persisted
				// alongside the LLM response in the transcript record. This
				// ensures server-side transcripts carry the full agentic-loop
				// context (tool names, results, and call IDs) for consumers.
				$transcript_response = $response;
				if ( ! empty( $tool_result_messages ) ) {
					$transcript_response['tool_results'] = $tool_result_messages;
				}

				$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
					$assistant_id,
					$messages,
					$options,
					$transcript_response,
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

			// Merge accumulated agentic-loop costs into the final cost data so that
			// intermediate iterations are not lost. The response payload (and all
			// downstream consumers such as the billing observer and measurement
			// collector) receive the true total cost.
			if ( is_array( $cost_data ) ) {
				$cost_data['cost_usd']                += $agentic_cost_accumulator['cost_usd'];
				$cost_data['prompt_tokens']            = isset( $cost_data['prompt_tokens'] ) ? (int) $cost_data['prompt_tokens'] : 0;
				$cost_data['completion_tokens']        = isset( $cost_data['completion_tokens'] ) ? (int) $cost_data['completion_tokens'] : 0;
				$cost_data['prompt_tokens']           += $agentic_cost_accumulator['total_prompt_tokens'];
				$cost_data['completion_tokens']       += $agentic_cost_accumulator['total_completion_tokens'];
				$cost_data['agentic_accumulated']      = $agentic_cost_accumulator;
				$cost_data['agentic_iterations_count'] = $iteration;
			} elseif ( $agentic_cost_accumulator['cost_usd'] > 0.0 ) {
				// Final response had no usable usage data, but intermediate iterations
				// did — surface the accumulated cost so it is not entirely lost.
				$cost_data = array(
					'cost_usd'                 => $agentic_cost_accumulator['cost_usd'],
					'provider'                 => isset( $options['provider'] ) ? $options['provider'] : 'openai',
					'model'                    => isset( $options['model'] ) ? $options['model'] : '',
					'is_estimated'             => false,
					'prompt_tokens'            => $agentic_cost_accumulator['total_prompt_tokens'],
					'completion_tokens'        => $agentic_cost_accumulator['total_completion_tokens'],
					'agentic_accumulated'      => $agentic_cost_accumulator,
					'agentic_iterations_count' => $iteration,
				);
			}

			// Extract usage information from response for frontend display.
			$usage_data = null;
			if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
				$usage_data = array(
					'prompt_tokens'     => isset( $response['usage']['prompt_tokens'] ) ? absint( $response['usage']['prompt_tokens'] ) : 0,
					'completion_tokens' => isset( $response['usage']['completion_tokens'] ) ? absint( $response['usage']['completion_tokens'] ) : 0,
					'total_tokens'      => isset( $response['usage']['total_tokens'] ) ? absint( $response['usage']['total_tokens'] ) : 0,
				);

				// Add provider and model info to usage data.
				if ( isset( $options['provider'] ) ) {
					$usage_data['provider'] = $options['provider'];
				}
				if ( isset( $options['model'] ) ) {
					$usage_data['model'] = $options['model'];
				}

				// Include accumulated agentic-loop tokens for frontend badge display.
				if ( $agentic_cost_accumulator['total_prompt_tokens'] > 0 || $agentic_cost_accumulator['total_completion_tokens'] > 0 ) {
					$usage_data['agentic_prompt_tokens']     = $agentic_cost_accumulator['total_prompt_tokens'];
					$usage_data['agentic_completion_tokens'] = $agentic_cost_accumulator['total_completion_tokens'];
					$usage_data['agentic_iterations']        = $iteration;
				}
			}

			$payload = array(
				'assistant_id' => $assistant_id,
				'data'         => $response,
			);

			// Attach intermediate agentic assistant messages (with tool_calls) to the
			// response data so that server-side callers such as the Telegram reply job
			// can fall back to partial content when the final choice has empty content.
			if ( ! empty( $agentic_tool_messages ) ) {
				$payload['data']['agentic_tool_messages'] = $agentic_tool_messages;
			}

			// Include usage data if available for frontend badge display.
			if ( $usage_data ) {
				$payload['usage'] = $usage_data;
			}

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

			// Include the session key in the response so the client can save it.
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

			// Cache the successful LLM response to avoid redundant API calls.
			if ( isset( $response_cache ) && isset( $response ) && ! is_wp_error( $response ) ) {
				$response_cache->set_cached_response( $messages, $options, $response );
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

			// If admin setting was applied by custom filters applicator (priority 5),.
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
		 * @return void Streams SSE updates and exits.
		 */
		protected function handle_chat_request_with_streaming( $assistant_id, $messages, $options, $assistant_config, $transcript_context, $request, $user_id, $max_iterations ) {
			// Set up SSE headers.
			$this->send_sse_headers();

			// Phase 3: agentic-loop output guard (streaming branch).
			$budget_tracker = new WP_MCP_AI_Data_Budget_Tracker( 'chat-stream-' . $assistant_id . '-' . wp_generate_uuid4() );

			// Extend PHP execution time for the duration of the SSE stream.
			// The default max_execution_time (often 30 s) is too short for embedded LLM
			// inference (which can take 60–120 s) and long agentic loops.
			// A bounded 300 s (5 min) limit keeps streams alive for long agentic loops
			// without removing the safety net entirely.  ignore_user_abort(true) keeps PHP alive even if nginx closes the upstream connection.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: set_time_limit() may emit warnings on restricted hosts; failure is non-critical.
			}
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			// Track request start time for timing indicators.
			$request_start_timestamp = time();

			// Send initial status.
			$this->send_sse_event(
				'status',
				array(
					'type'         => 'thinking',
					'message'      => __( 'Processing your request…', 'mcp-ai-wpoos' ),
					'assistant_id' => $assistant_id,
					'timestamp'    => $request_start_timestamp,
				)
			);

			$iteration             = 0;
			$tool_result_messages  = array();
			$agentic_tool_messages = array();
			$native_streaming_used = false; // True when LM Studio real-time SSE streaming is active.

			// Accumulate cost across all agentic-loop LLM calls so that
			// intermediate iterations are not silently discarded.
			// Phase 8: Per-iteration cost tracking (streaming path).
			$agentic_cost_accumulator = array(
				'cost_usd'                => 0.0,
				'total_prompt_tokens'     => 0,
				'total_completion_tokens' => 0,
				'total_cached_tokens'     => 0,
				'iterations'              => array(),
			);

			// Send status for attachment processing if attachments are present.
			// This provides user feedback when images or documents are being analyzed.
			if ( ! empty( $options['attachments'] ) ) {
				$attachment_count = count( $options['attachments'] );
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'processing_attachments',
						'message' => sprintf(
							/* translators: %d: Number of attachments being processed */
							_n(
								'Processing %d attachment…',
								'Processing %d attachments…',
								$attachment_count,
								'mcp-ai-wpoos'
							),
							$attachment_count
						),
						'count'   => $attachment_count,
					)
				);
			}

			// Send status for memory document loading if present.
			if ( ! empty( $options['memory_documents'] ) ) {
				$memory_count = count( $options['memory_documents'] );
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'loading_memory',
						'message' => sprintf(
							/* translators: %d: Number of memory documents being loaded */
							_n(
								'Loading %d memory document…',
								'Loading %d memory documents…',
								$memory_count,
								'mcp-ai-wpoos'
							),
							$memory_count
						),
						'count'   => $memory_count,
					)
				);
			}

			// Send status update to indicate AI is generating response.
			$this->send_sse_event(
				'status',
				array(
					'type'    => 'generating',
					'message' => __( 'Generating response…', 'mcp-ai-wpoos' ),
				)
			);

			$transcript_context['request_started_at'] = microtime( true );

			// Pre-flight TPM validation (streaming path): truncate or switch
			// model before the initial LLM call to avoid TPM-limit rejections.
			$preflight = $this->preflight_tpm_check( $messages, $options, $assistant_id );
			if ( is_wp_error( $preflight ) ) {
				$this->send_sse_event(
					'error',
					array(
						'code'    => $preflight->get_error_code(),
						'message' => $preflight->get_error_message(),
					)
				);
				$this->send_sse_done();
				$this->finish_sse();
				return;
			}
			$messages = $preflight['messages'];
			$options  = $preflight['options'];

			// Resolved provider slug, used for native streaming checks below.
			$resolved_provider = sanitize_key( isset( $options['provider'] ) ? $options['provider'] : '' );

			// Enable real-time SSE streaming for providers that support curl-based
				// streaming (LM Studio local models and DeepSeek cloud API).  Each
				// provider's client has a do_realtime_curl_stream() method that forwards
				// content/reasoning tokens to the browser as they are generated.
				//
				// When disabled via the wp_mcp_ai_disable_native_streaming filter or the
					// Disable Native Streaming setting (Advanced → System), the system falls
					// back to simulated chunking (full response split into pieces with delays).
					$disable_native = (bool) apply_filters( 'wp_mcp_ai_disable_native_streaming', false );
					$settings       = WP_MCP_AI_Admin_Settings::get_settings();
					$disable_native = $disable_native || ( ! empty( $settings['disable_native_streaming'] ) );
			if ( ! $disable_native ) {
				$native_streaming_providers = apply_filters(
					'wp_mcp_ai_native_streaming_providers',
					array( 'lm_studio', 'deepseek', 'openai', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'nvidia', 'huggingface' )
				);
				if ( function_exists( 'curl_init' ) && in_array( $resolved_provider, $native_streaming_providers, true ) ) {
					$native_streaming_used      = true;
					$options['stream']          = true;
					$options['stream_callback'] = function ( $chunk ) {
						$this->send_sse_event( 'message', $chunk );
					};
				}
			}

			// Wrap LLM call in try-catch to handle any uncaught exceptions
			// and ensure SSE stream completes properly even on fatal errors.
			try {
				$response = $this->client->create_chat_completion( $messages, $options );
			} catch ( Exception $e ) {
				WP_MCP_AI_Logger::log_error(
					'sse_llm_exception',
					'Exception during LLM call in streaming mode',
					array(
						'exception' => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
				$this->send_sse_event(
					'error',
					array(
						'code'    => 'llm_exception',
						'message' => sprintf(
							/* translators: %s: exception message */
							__( 'An error occurred while processing your request: %s', 'mcp-ai-wpoos' ),
							$e->getMessage()
						),
					)
				);
				$this->send_sse_done();
				$this->finish_sse();
				return;
			} catch ( Error $e ) {
				// Log detailed error information for debugging.
				$error_class = get_class( $e );
				$error_file  = $e->getFile();
				$error_line  = $e->getLine();

				WP_MCP_AI_Logger::log_error(
					'sse_llm_fatal_error',
					'Fatal error during LLM call in streaming mode',
					array(
						'error'       => $e->getMessage(),
						'error_class' => $error_class,
						'file'        => $error_file,
						'line'        => $error_line,
						'trace'       => $e->getTraceAsString(),
					)
				);

				// Determine if we can provide a more helpful user-facing message.
				$user_message = __( 'A fatal error occurred while processing your request.', 'mcp-ai-wpoos' );

				// Provide specific guidance for common error scenarios.
				// Check for common PHP Error types that indicate configuration issues.
				if ( $e instanceof TypeError ) {
					$user_message = __( 'The selected AI provider is not properly configured. Please check your provider settings.', 'mcp-ai-wpoos' );
				} elseif ( 'Error' === $error_class && preg_match( '/Call to .+ on (null|bool|int|string|array)/', $e->getMessage() ) ) {
					// Method call on invalid type (null, scalar, array instead of object).
					$user_message = __( 'The selected AI provider is not properly configured. Please check your provider settings.', 'mcp-ai-wpoos' );
				} elseif ( preg_match( "/Class ['\"]?\w+['\"]? not found/", $e->getMessage() ) ) {
					// Missing class indicates incomplete installation or missing dependencies.
					$user_message = __( 'A required component is missing. This may be due to plugin version mismatch or incomplete installation.', 'mcp-ai-wpoos' );
				}

				$this->send_sse_event(
					'error',
					array(
						'code'    => 'llm_fatal_error',
						'message' => $user_message,
					)
				);
				$this->send_sse_done();
				$this->finish_sse();
				return;
			}

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
				$this->finish_sse();
				return;
			}

			// Capture cost of the initial LLM call before entering the agentic loop.
			// This ensures the first response (which may contain tool_calls) is
			// counted even when the loop executes multiple iterations.
			$initial_cost = $this->calculate_response_cost( $response, $options, $assistant_id, $user_id, 'initial streaming chat response' );
			if ( is_array( $initial_cost ) ) {
				$agentic_cost_accumulator['cost_usd']                += $initial_cost['cost_usd'];
				$agentic_cost_accumulator['total_prompt_tokens']     += isset( $initial_cost['prompt_tokens'] ) ? (int) $initial_cost['prompt_tokens'] : 0;
				$agentic_cost_accumulator['total_completion_tokens'] += isset( $initial_cost['completion_tokens'] ) ? (int) $initial_cost['completion_tokens'] : 0;
				$agentic_cost_accumulator['iterations'][]             = array_merge(
					$initial_cost,
					array(
						'iteration' => 0,
						'phase'     => 'initial',
					)
				);
			}

			// Remove native-streaming options to prevent them from leaking to a
			// different provider if a TPM-triggered model switch occurs in the loop.
			unset( $options['stream'], $options['stream_callback'] );

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
					$messages[]              = $assistant_message;
					$agentic_tool_messages[] = $assistant_message;
				}

				// Execute each tool and stream results.
				// Track if any tool returned async pending result (requires exiting agentic loop).
				$has_async_pending_result = false;
				$pending_async_jobs       = array();

				foreach ( $tool_calls as $tool_call ) {
					$tool_name    = isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : '';
					$tool_call_id = isset( $tool_call['id'] ) ? $tool_call['id'] : '';

					// Ensure tool_call_id is never empty so downstream
					// tool-result messages are valid for the next turn.
					if ( '' === $tool_call_id ) {
						$tool_call_id = uniqid( 'tool_', true );
					}

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

					// Wrap tool execution in try-catch to handle any uncaught exceptions
					// and ensure SSE stream continues even if tool execution fails.
					try {
						$tool_result = $this->execute_tool_call_internal( $tool_call, $assistant_id, $assistant_config, $user_id, $request, $iteration, $max_iterations, $transcript_context );
					} catch ( Exception $e ) {
						WP_MCP_AI_Logger::log_error(
							'sse_tool_exception',
							'Exception during tool execution in streaming mode',
							array(
								'tool_name' => $tool_name,
								'exception' => $e->getMessage(),
								'trace'     => $e->getTraceAsString(),
							)
						);
						$tool_result = new WP_Error(
							'tool_exception',
							sprintf(
								/* translators: 1: tool name, 2: exception message */
								__( 'Tool %1$s failed with exception: %2$s', 'mcp-ai-wpoos' ),
								$tool_name,
								$e->getMessage()
							)
						);
					} catch ( Error $e ) {
						WP_MCP_AI_Logger::log_error(
							'sse_tool_fatal_error',
							'Fatal error during tool execution in streaming mode',
							array(
								'tool_name' => $tool_name,
								'error'     => $e->getMessage(),
								'trace'     => $e->getTraceAsString(),
							)
						);
						$tool_result = new WP_Error(
							'tool_fatal_error',
							sprintf(
								/* translators: 1: tool name, 2: error message */
								__( 'Tool %1$s failed with a fatal error: %2$s', 'mcp-ai-wpoos' ),
								$tool_name,
								$e->getMessage()
							)
						);
					}

					// Convert WP_Error to serializable format to prevent JSON encoding failures.
					$tool_result = $this->normalize_tool_result( $tool_result );

					// Check if this is an async pending result (background-only tools like video generation).
					// When a tool returns {async: true, status: 'pending'}, we need to exit the agentic loop
					// after processing this iteration. The frontend will handle polling for the async result.
					// Continuing to call the LLM with a pending status would cause issues since the LLM.
					// doesn't understand async job states and might try to call the same tool again.
					if ( is_array( $tool_result ) && ! empty( $tool_result['async'] ) && 'pending' === ( $tool_result['status'] ?? '' ) ) {
						$has_async_pending_result = true;
						$pending_job_id           = isset( $tool_result['job_id'] ) ? (string) $tool_result['job_id'] : '';
						if ( '' !== $pending_job_id ) {
							$pending_async_jobs[] = array(
								'job_id'       => $pending_job_id,
								'tool_call_id' => (string) $tool_call_id,
								'tool_name'    => (string) $tool_name,
							);
						}
						WP_MCP_AI_Logger::log_event(
							'async_tool_pending_in_agentic_loop',
							'Async tool returned pending status, will exit agentic loop after this iteration',
							array(
								'tool_name' => $tool_name,
								'job_id'    => $tool_result['job_id'] ?? 'unknown',
								'iteration' => $iteration,
								'tool_slug' => $tool_slug ?? $tool_name,
							)
						);
					}

					// Get the tool instance for interface-based sanitization.
					$tool_instance   = null;
					$allowed_tools   = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
					$tool_candidates = $this->generate_tool_slug_candidates( $tool_name );
					$tool_slug       = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );
					if ( $tool_slug && in_array( $tool_slug, $allowed_tools, true ) ) {
						$tool_instance = $this->registry->get_tool( $tool_slug );
					}

					// Extract usage information from tool result before sanitization.
					// Phase 7: Enhanced Token Tracking - Include usage data in tool responses.
					$tool_usage_info = $this->extract_usage_info_from_tool_result( $tool_result );

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

					// G8 Phase 2 — emit a `memory_event` SSE frame mid-stream
					// when the tool that just ran touched the agent-memory
					// subsystem, so the chat client can announce a transient
					// "🧠 Used / saved long-term memory." toast immediately
					// instead of waiting for the assistant message to render.
					$memory_event_action = $this->classify_memory_tool_action( $tool_name );
					if ( null !== $memory_event_action ) {
						$this->send_sse_event(
							'memory_event',
							array(
								'action'    => $memory_event_action,
								'tool_name' => $tool_name,
								'tool_id'   => $tool_call_id,
							)
						);
					}

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

					// Always include tool_call_id so the frontend never sends back
					// a tool message that fails REST validation on the next turn.
					$full_tool_message['tool_call_id'] = '' !== $tool_call_id
						? $tool_call_id
						: uniqid( 'tool_', true );

					if ( '' !== $tool_name ) {
						$full_tool_message['name'] = $tool_name;
					}

					// Include usage information in tool message for frontend display.
					// Phase 7: Enhanced Token Tracking - Tool-level usage data.
					if ( ! empty( $tool_usage_info ) ) {
						$full_tool_message['usage'] = $tool_usage_info;
					}

					// Include capability flags for frontend badge display.
					$capability_flags = $this->extract_capability_flags_from_tool( $tool_instance );
					if ( ! empty( $capability_flags ) ) {
						$full_tool_message['capability_flags'] = $capability_flags;
					}

					$tool_result_messages[] = $full_tool_message;

					// Create sanitized version for LLM.
					$sanitized_result = $this->validator->sanitize_tool_result_for_llm( $tool_result, $tool_name, $assistant_config, $tool_instance );

					// Phase 3: agentic-loop output guard.
					$message_bytes = is_string( $sanitized_result ) ? strlen( $sanitized_result ) : 0;
					if ( $budget_tracker->should_spill( $message_bytes ) ) {
						$sanitized_result = WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result(
							$sanitized_result,
							$tool_name,
							array(
								'assistant_id' => $assistant_id,
								'iteration'    => isset( $iteration ) ? $iteration : 0,
								'tool_call_id' => $tool_call_id,
								'request_id'   => $budget_tracker->request_id(),
							)
						);
						$budget_tracker->note_spill();
						$this->send_sse_event(
							'tool_output_truncated',
							array(
								'tool_name'    => $tool_name,
								'tool_call_id' => $tool_call_id,
							)
						);
						$message_bytes = is_string( $sanitized_result ) ? strlen( $sanitized_result ) : 0;
					}
					$budget_tracker->record( $message_bytes );

					$tool_message = array(
						'role'    => 'tool',
						// sanitize_tool_result_for_llm() always returns a string (truncated + delimiter-neutralised).
						'content' => $sanitized_result,
					);

					// Always include tool_call_id to keep the message valid
					// for provider payload filtering and subsequent turns.
					$tool_message['tool_call_id'] = '' !== $tool_call_id
					? $tool_call_id
					: uniqid( 'tool_', true );

					if ( '' !== $tool_name ) {
						$tool_message['name'] = $tool_name;
					}

					$messages[] = $tool_message;
				}

				// If any tool returned an async pending result (e.g., video generation),
				// exit the agentic loop. The frontend is notified via SSE and will poll
				// for the async job completion. Continuing to call the LLM with pending
				// status would cause confusion and potential infinite loops.
				if ( $has_async_pending_result ) {
					WP_MCP_AI_Logger::log_event(
						'agentic_loop_exit_async_pending',
						'Exiting agentic loop due to async pending tool result',
						array(
							'iteration'    => $iteration,
							'assistant_id' => $assistant_id,
							'tool_count'   => count( $tool_result_messages ),
						)
					);
						$this->snapshot_chat_continuation_on_async_pending(
							$pending_async_jobs,
							$messages,
							$assistant_id,
							$user_id,
							$options,
							$transcript_context
						);
						break;
				}

				// Stream thinking status immediately after tool execution completes.
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'thinking',
						'message' => __( 'Analyzing tool results…', 'mcp-ai-wpoos' ),
					)
				);

				// Log after sending thinking status to track agentic loop progress.
				WP_MCP_AI_Logger::log_event(
					'sse_streaming_after_tools',
					'Sent thinking status after tool execution, preparing for next LLM call',
					array(
						'iteration'         => $iteration,
						'assistant_id'      => $assistant_id,
						'tool_count'        => count( $tool_result_messages ),
						'has_async_pending' => $has_async_pending_result,
					)
				);

				// Validate token budget before next iteration.
				$model             = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';
				$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;
				$tpm_validation    = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

				if ( is_wp_error( $tpm_validation ) ) {
					// Handle model switching or truncation (same logic as non-streaming).
					$settings            = WP_MCP_AI_Admin_Settings::get_settings();
					$fallback_model      = WP_MCP_AI_Model_Selector::resolve_fallback_model( $model, $settings );
					$auto_switch_enabled = isset( $settings['enable_high_token_model_switch'] ) ? (bool) $settings['enable_high_token_model_switch'] : true;
					$switched_model      = false;

					if ( $auto_switch_enabled && $fallback_model !== $model ) {
						$fallback_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $fallback_model, $max_output_tokens );

						if ( ! is_wp_error( $fallback_validation ) ) {
							$options['model'] = $fallback_model;
							$model            = $fallback_model;
							$switched_model   = true;

							// Also switch provider to match the fallback model so the router
							// sends the request to the correct LLM API endpoint.
							$fallback_model_config = class_exists( 'WP_MCP_AI_Model_Config' )
								? WP_MCP_AI_Model_Config::get_model_config( $fallback_model )
								: null;
							if ( $fallback_model_config && ! empty( $fallback_model_config['provider'] ) ) {
								$options['provider'] = sanitize_key( $fallback_model_config['provider'] );
							}

							$this->send_sse_event(
								'status',
								array(
									'type'    => 'model_switched',
									'message' => sprintf(
										/* translators: %s: New model name */
										__( 'Switched to %s for higher token capacity.', 'mcp-ai-wpoos' ),
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
							$this->finish_sse();
							return;
						}

						$this->send_sse_event(
							'status',
							array(
								'type'    => 'messages_truncated',
								'message' => __( 'Reduced context to fit token limits.', 'mcp-ai-wpoos' ),
							)
						);
					}
				}

				// Send status update to indicate AI is generating response after tool execution.
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'generating',
						'message' => __( 'Generating response…', 'mcp-ai-wpoos' ),
					)
				);

				// Log before second LLM call to track the flow.
				WP_MCP_AI_Logger::log_event(
					'sse_streaming_before_llm_call',
					'About to call LLM again with tool results',
					array(
						'iteration'     => $iteration,
						'assistant_id'  => $assistant_id,
						'message_count' => count( $messages ),
					)
				);

				// Call LLM again with tool results.
				// Re-enable native streaming if still on a streaming-capable provider (may have switched for TPM).
				$loop_provider              = sanitize_key( isset( $options['provider'] ) ? $options['provider'] : '' );
				$native_streaming_providers = apply_filters(
					'wp_mcp_ai_native_streaming_providers',
					array( 'lm_studio', 'deepseek', 'openai', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'nvidia', 'huggingface' )
				);
				if ( $native_streaming_used && in_array( $loop_provider, $native_streaming_providers, true ) ) {
					$options['stream']          = true;
					$options['stream_callback'] = function ( $chunk ) {
						$this->send_sse_event( 'message', $chunk );
					};
				}
				$response = $this->client->create_chat_completion( $messages, $options );
				unset( $options['stream'], $options['stream_callback'] );

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
					$this->finish_sse();
					return;
				}

				// Capture cost for this agentic-loop LLM call so intermediate
				// iterations are tracked and not silently discarded.
				$iter_cost = $this->calculate_response_cost( $response, $options, $assistant_id, $user_id, 'agentic streaming iteration ' . ( $iteration + 1 ) );
				if ( is_array( $iter_cost ) ) {
					$agentic_cost_accumulator['cost_usd']                += $iter_cost['cost_usd'];
					$agentic_cost_accumulator['total_prompt_tokens']     += isset( $iter_cost['prompt_tokens'] ) ? (int) $iter_cost['prompt_tokens'] : 0;
					$agentic_cost_accumulator['total_completion_tokens'] += isset( $iter_cost['completion_tokens'] ) ? (int) $iter_cost['completion_tokens'] : 0;
					if ( isset( $iter_cost['cached_tokens'] ) ) {
						$agentic_cost_accumulator['total_cached_tokens'] += (int) $iter_cost['cached_tokens'];
					}
					$agentic_cost_accumulator['iterations'][] = array_merge(
						$iter_cost,
						array(
							'iteration' => $iteration + 1,
							'phase'     => 'agentic',
						)
					);
				}

				++$iteration;

				/**
				 * Fires after a single agentic-loop iteration has completed in
				 * the streaming (SSE) REST chat path. Pure notification hook —
				 * consumed by the measurement observer to emit the
				 * `chat.agentic.iterations` histogram. No behaviour change.
				 *
				 * @since 1.3.0
				 *
				 * @param int   $iteration    Total iterations completed so far (1-based).
				 * @param mixed $assistant_id Assistant identifier.
				 */
				do_action( 'wp_mcp_ai_agentic_iteration_complete', $iteration, $assistant_id );
			}

			if ( $iteration >= $max_iterations ) {
				$this->send_sse_event(
					'status',
					array(
						'type'    => 'max_iterations',
						'message' => __( 'Reached maximum tool execution iterations.', 'mcp-ai-wpoos' ),
					)
				);

				// Same as the non-SSE path: when the loop exits because max_iterations was
				// reached the final LLM response may still have unexecuted tool_calls.
				// Strip them so the SSE "message" event does not include orphaned tool_call_ids
				// that would later cause "An assistant message with 'tool_calls' must be
				// followed by tool messages responding to each 'tool_call_id'" errors.
				$this->strip_orphaned_tool_calls_from_response( $response, $assistant_id, $iteration, 'SSE' );
			}

			// Update response completion timestamp after agentic loop.
				$transcript_context['response_completed_at'] = microtime( true );

				/**
				 * Fires after the full SSE-streaming agentic loop has completed.
				 *
				 * @since 1.2.0
				 *
				 * @param int   $iteration     Total iterations completed.
				 * @param int   $assistant_id  Assistant post ID.
				 * @param array $tool_results  Array of tool results from the final iteration.
				 * @param bool  $limit_reached Whether the loop exited because max_iterations was reached.
				 */
				do_action( 'wp_mcp_ai_agentic_loop_completed', $iteration, $assistant_id, $tool_result_messages, $iteration >= $max_iterations );

				// Log and record transcript.
				WP_MCP_AI_Logger::log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id );

			$recorded_session_key = null;
			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				// Augment the response with tool_results so they are persisted
				// alongside the LLM response in the transcript record.
				$transcript_response = $response;
				if ( ! empty( $tool_result_messages ) ) {
					$transcript_response['tool_results'] = $tool_result_messages;
				}

				$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
					$assistant_id,
					$messages,
					$options,
					$transcript_response,
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

			// Merge accumulated agentic-loop costs into the final cost data so that
			// intermediate iterations are not lost. The response payload (and all
			// downstream consumers such as the billing observer and measurement
			// collector) receive the true total cost.
			if ( is_array( $cost_data ) ) {
				$cost_data['cost_usd']                += $agentic_cost_accumulator['cost_usd'];
				$cost_data['prompt_tokens']            = isset( $cost_data['prompt_tokens'] ) ? (int) $cost_data['prompt_tokens'] : 0;
				$cost_data['completion_tokens']        = isset( $cost_data['completion_tokens'] ) ? (int) $cost_data['completion_tokens'] : 0;
				$cost_data['prompt_tokens']           += $agentic_cost_accumulator['total_prompt_tokens'];
				$cost_data['completion_tokens']       += $agentic_cost_accumulator['total_completion_tokens'];
				$cost_data['agentic_accumulated']      = $agentic_cost_accumulator;
				$cost_data['agentic_iterations_count'] = $iteration;
			} elseif ( $agentic_cost_accumulator['cost_usd'] > 0.0 ) {
				// Final response had no usable usage data, but intermediate iterations
				// did — surface the accumulated cost so it is not entirely lost.
				$cost_data = array(
					'cost_usd'                 => $agentic_cost_accumulator['cost_usd'],
					'provider'                 => isset( $options['provider'] ) ? $options['provider'] : 'openai',
					'model'                    => isset( $options['model'] ) ? $options['model'] : '',
					'is_estimated'             => false,
					'prompt_tokens'            => $agentic_cost_accumulator['total_prompt_tokens'],
					'completion_tokens'        => $agentic_cost_accumulator['total_completion_tokens'],
					'agentic_accumulated'      => $agentic_cost_accumulator,
					'agentic_iterations_count' => $iteration,
				);
			}

			// Extract thinking/reasoning text from the response if present.
			// Supports multiple providers:
			// - Anthropic extended thinking: message['thinking'] + provider='anthropic'.
			// - Gemini 2.0 Flash Thinking mode: message['thinking'].
			// - OpenAI reasoning models (future): message['reasoning_content'] or message['reasoning'].
			$thinking_text            = '';
			$thinking_provider_format = 'gemini'; // Default to Gemini format.

			// Detect response provider for correct thinking format.
			$response_provider = isset( $response['provider'] ) ? sanitize_key( $response['provider'] ) : '';

			// Validate response structure before accessing nested keys.
			if ( ! empty( $response['choices'] ) && is_array( $response['choices'] ) && isset( $response['choices'][0]['message'] ) ) {
				$message = $response['choices'][0]['message'];

				// Check for thinking text (Anthropic or Gemini).
				if ( ! empty( $message['thinking'] ) ) {
					$thinking_text            = $message['thinking'];
					$thinking_provider_format = 'anthropic' === $response_provider ? 'anthropic' : 'gemini';
				} elseif ( ! empty( $message['reasoning_content'] ) ) {
					// Check for OpenAI reasoning_content (future-ready).
					$thinking_text            = $message['reasoning_content'];
					$thinking_provider_format = 'openai';
				} elseif ( ! empty( $message['reasoning'] ) ) {
					// Check for OpenAI reasoning (alternative field).
					$thinking_text            = $message['reasoning'];
					$thinking_provider_format = 'openai';
				}
			}

			// Send thinking text in chunks BEFORE sending main content (if present).
			// This allows the client to display thinking text in the status section.
			// Skip when native streaming was active — reasoning tokens were
			// already forwarded in real time via the stream_callback.
			if ( ! $native_streaming_used && is_string( $thinking_text ) && '' !== $thinking_text ) {
				// Format thinking chunks based on provider for optimal client compatibility.
				if ( 'openai' === $thinking_provider_format ) {
					// Use OpenAI format for reasoning fields.
					$thinking_formatter = function ( $chunk ) {
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
				} elseif ( 'anthropic' === $thinking_provider_format ) {
					// Use Anthropic content_block_delta format for extended thinking.
					$thinking_formatter = function ( $chunk ) {
						return array(
							'type'  => 'content_block_delta',
							'delta' => array(
								'type'     => 'thinking_delta',
								'thinking' => $chunk,
							),
						);
					};
				} else {
					// Use Gemini format for thinking field.
					$thinking_formatter = function ( $chunk ) {
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
			// Skip when native streaming was active — content tokens were
			// already forwarded in real time via the stream_callback.
			if ( ! $native_streaming_used && is_string( $text_content ) && '' !== $text_content ) {
				// Format content chunks in OpenAI-compatible format.
				$content_formatter = function ( $chunk ) {
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
			} elseif ( ! $native_streaming_used ) {
				// Log when no chunks are sent (helps diagnose streaming issues).
				WP_MCP_AI_Logger::log_event(
					'debug',
					'SSE Streaming: No text chunks to send',
					array(
						'has_text_content'  => ! empty( $text_content ),
						'is_string'         => is_string( $text_content ),
						'response_keys'     => array_keys( $response ),
						'tool_result_count' => count( $tool_result_messages ),
						'assistant_id'      => $assistant_id,
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

			// Include the session key in the response so the client can save it.
			if ( $recorded_session_key ) {
				$payload['sessionKey'] = $recorded_session_key;
			}

			// ALWAYS include tool_results in payload if they exist.
			// This ensures the chat client receives tool execution results even when
			// the LLM response is empty or doesn't include commentary.
			// Critical for tools like vectorize_image where the tool result contains
			// the primary information (SVG URL, attachment ID, success message).
			if ( ! empty( $tool_result_messages ) ) {
				$payload['tool_results'] = $tool_result_messages;

				// Log final message payload for debugging tool result delivery.
				WP_MCP_AI_Logger::log_event(
					'sse_final_message_with_tools',
					'Sending final SSE message event with tool results',
					array(
						'assistant_id'      => $assistant_id,
						'tool_result_count' => count( $tool_result_messages ),
						'has_response_data' => ! empty( $response ),
						'has_text_content'  => ! empty( $response['choices'][0]['message']['content'] ) || ! empty( $response['content'] ),
					)
				);
			}

			// Include intermediate assistant messages with tool_calls so the client can
			// reconstruct the correct conversation order (assistant+tool_use → tool_result →
			// final assistant) required by Anthropic and other providers.
			if ( ! empty( $agentic_tool_messages ) ) {
				$payload['agentic_tool_messages'] = $agentic_tool_messages;
			}

			// Normalize payload to ensure all data is JSON-serializable.
			// This prevents SSE stream failures due to non-serializable objects (WP_Error, WP_Post, etc.)
			// that might be nested in tool results or response data.
			$payload = $this->normalize_data_recursive( $payload );

			// Log final SSE message sending for diagnostics.
			WP_MCP_AI_Logger::log_event(
				'sse_final_message_sending',
				'Sending final SSE message event to client',
				array(
					'assistant_id'     => $assistant_id,
					'has_data'         => isset( $payload['data'] ),
					'has_tool_results' => isset( $payload['tool_results'] ),
					'has_session_key'  => isset( $payload['sessionKey'] ),
					'has_cost'         => isset( $payload['cost'] ),
					'payload_keys'     => array_keys( $payload ),
					'data_has_choices' => isset( $payload['data']['choices'] ) && is_array( $payload['data']['choices'] ) && count( $payload['data']['choices'] ) > 0,
					'data_has_message' => isset( $payload['data']['choices'] ) && is_array( $payload['data']['choices'] ) && count( $payload['data']['choices'] ) > 0 && isset( $payload['data']['choices'][0]['message'] ),
					'endpoint'         => $request->get_route(),
				)
			);

			$this->send_sse_event( 'message', $payload );

			// Log that [DONE] marker is being sent.
			WP_MCP_AI_Logger::log_event(
				'sse_done_marker_sending',
				'Sending SSE [DONE] marker',
				array(
					'assistant_id' => $assistant_id,
					'endpoint'     => $request->get_route(),
				)
			);

			$this->send_sse_done();

			$this->finish_sse();
		}

		/**
		 * Classify a tool name as a memory-retrieving / memory-storing op.
		 *
		 * Mirrors the JS lists in `assets/js/chat-memory-drawer.js`. Used by the
		 * SSE streaming path to emit `memory_event` frames mid-stream so the
		 * chat client can announce a "🧠 Memory" toast as soon as the tool runs
		 * (G8 Phase 2), rather than waiting for the assistant bubble to render.
		 *
		 * @since 1.1.14
		 *
		 * @param string $tool_name OpenAI-style tool function name.
		 * @return string|null 'retrieved' / 'stored' / null when the tool is not
		 *                     a memory tool.
		 */
		protected function classify_memory_tool_action( $tool_name ) {
			if ( ! is_string( $tool_name ) || '' === $tool_name ) {
				return null;
			}
			$retrieve_tools = array(
				'recall_memory',
				'wake_up_context',
				'semantic_context_search',
				'retrieve_agent_memory',
			);
			$store_tools    = array(
				'store_agent_context',
				'update_agent_memory',
				'capture_memory',
			);
			if ( in_array( $tool_name, $retrieve_tools, true ) ) {
				return 'retrieved';
			}
			if ( in_array( $tool_name, $store_tools, true ) ) {
				return 'stored';
			}
			return null;
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
		 * Finish an SSE streaming response cleanly.
		 *
		 * Uses fastcgi_finish_request() when available so that the HTTP/2
		 * DATA+END_STREAM frame is sent properly, preventing ERR_HTTP2_PROTOCOL_ERROR.
		 * Falls back to exit() on non-FPM environments.
		 *
		 * Delegates to SSE handler.
		 *
		 * @since 1.2.0
		 */
		protected function finish_sse() {
			$this->sse_handler->finish();
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
		 * Check whether the request's Accept header prefers an event-stream response.
		 *
		 * Unlike request_wants_event_stream(), this only inspects the HTTP Accept
		 * header and is used exclusively for the assistant directory endpoint so
		 * that browser-based MCP clients using "Accept: text/event-stream" get a
		 * proper SSE response. The generic /mcp endpoint deliberately ignores the
		 * Accept header (see WP_MCP_AI_SSE_Handler::request_wants_event_stream).
		 *
		 * @param WP_REST_Request $request REST request instance.
		 * @return bool
		 */
		protected function request_accept_wants_event_stream( WP_REST_Request $request ) {
			$accept = $request->get_header( 'Accept' );

			if ( ! $accept ) {
				return false;
			}

			foreach ( preg_split( '/\s*,\s*/', $accept ) as $token ) {
				$parts      = explode( ';', $token );
				$media_type = trim( $parts[0] );

				if ( 'text/event-stream' === $media_type ) {
					return true;
				}
			}

			return false;
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

			return __( 'The assistant response failed to generate.', 'mcp-ai-wpoos' );
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

			// Try to serve from cache (keyed on assistant_id).
			$cache_params = array_filter(
				array( 'assistant_id' => $assistant_id ? $assistant_id : null ),
				static function ( $v ) {
					return null !== $v;
				}
			);

			$cached_tools = WP_MCP_AI_REST_Cache::get_response( 'tools', $cache_params );

			if ( false !== $cached_tools && is_array( $cached_tools ) ) {
				$tools_list = $cached_tools;
			} else {
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

				// Cache the full tools list; _fields filtering is applied after retrieval.
				WP_MCP_AI_REST_Cache::set_response( 'tools', $cache_params, $tools_list );
			}

			// Apply _fields filtering to reduce response payload when requested.
			$fields_param = $request->get_param( '_fields' );
			if ( $fields_param && is_string( $fields_param ) ) {
				$allowed_fields = wp_parse_list( $fields_param );
				// Valid tool fields: name, description, inputSchema. 'name' is always included.
				$valid_fields   = array( 'name', 'description', 'inputSchema' );
				$allowed_fields = array_intersect( $allowed_fields, $valid_fields );
				if ( ! empty( $allowed_fields ) ) {
					if ( ! in_array( 'name', $allowed_fields, true ) ) {
						$allowed_fields[] = 'name';
					}
					$allowed_map = array_flip( $allowed_fields );
					$tools_list  = array_map(
						static function ( $tool ) use ( $allowed_map ) {
							return array_intersect_key( $tool, $allowed_map );
						},
						$tools_list
					);
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
				return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
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

			// Auto-enable utility tools that provide essential chat client functionality.
			$tools_added = false;
			foreach ( self::AUTO_ENABLED_UTILITY_TOOLS as $utility_tool_slug ) {
				if ( $this->candidates_include_slug( $tool_candidates, $utility_tool_slug ) && ! in_array( $utility_tool_slug, $allowed_tools, true ) ) {
					$assistant_config = $this->ensure_tool_in_config( $assistant_config, $utility_tool_slug );
					$tools_added      = true;
				}
			}

			// Refresh allowed_tools if any utility tools were added.
			if ( $tools_added ) {
				$allowed_tools = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
			}

			$tool_slug = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );

			if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
				return new WP_Error( 'wp_mcp_ai_tool_forbidden', __( 'This assistant is not allowed to execute the requested tool.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
			}

			$tool = $this->registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				return new WP_Error( 'wp_mcp_ai_tool_missing', __( 'The requested tool is not registered.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
			}

			$auth_context = $this->get_auth_context();
			$user_id      = ! empty( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : get_current_user_id();
			$is_guest     = ! empty( $auth_context['is_guest'] );

			// Enforce per-user, per-tool rate limiting.
			$tool_rate_limit = $this->check_tool_rate_limit( $user_id, $auth_context );
			if ( is_wp_error( $tool_rate_limit ) ) {
				return $tool_rate_limit;
			}

			$context = array(
				'user_id'          => $user_id,
				'assistant_id'     => $assistant_id,
				'request'          => $request,
				'assistant_config' => $assistant_config,
				'guest_request'    => $is_guest,
			);

			if ( ! empty( $auth_context['token_authenticated'] ) ) {
				$context['token_authenticated'] = true;
				$context['token_type']          = $auth_context['token_type'];

				if ( ! empty( $auth_context['token_context'] ) ) {
					$context['token_context'] = $auth_context['token_context'];
				}
			}

			if ( empty( $context['user_id'] ) && empty( $auth_context['token_authenticated'] ) && ! $is_guest ) {
				return new WP_Error( 'wp_mcp_ai_anonymous_user', __( 'You must be logged in to execute tools.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
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

			// Ensure agentic_loop is false for direct tools/call requests so
			// the async orchestrator doesn't force-sync (Priority 5). This
			// matches the behavior of execute_tool_call_internal() which sets
			// agentic_loop => true only when called from the chat agentic loop.
			if ( ! isset( $context['agentic_loop'] ) ) {
				$context['agentic_loop'] = false;
			}

			// Orchestration Layer: Check if tool should execute asynchronously
			// (1.1.44). Mirrors the async decision path in
			// execute_tool_call_internal() for consistent behavior across the
			// direct tools/call endpoint and the chat agentic loop.
			$orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
			$should_async = $orchestrator->should_execute_async( $tool, $prepared_arguments, $context );

			if ( $should_async ) {
				$executor = wp_mcp_ai_get_async_tool_executor();
				$job_id   = $executor->queue_tool( $tool_slug, $prepared_arguments, $context );

				if ( ! is_wp_error( $job_id ) ) {
					return rest_ensure_response(
						array(
							'assistant_id'     => $assistant_id,
							'tool'             => $tool_slug,
							'status'           => 'pending',
							'job_id'           => $job_id,
							'message'          => sprintf(
								/* translators: 1: tool name, 2: job ID */
								__( 'Tool "%1$s" is processing in the background (Job ID: %2$s).', 'mcp-ai-wpoos' ),
								$tool->get_name(),
								$job_id
							),
							'async'            => true,
							'capability_flags' => $this->extract_capability_flags_from_tool( $tool ),
						)
					);
				}
				// Fall through: queueing failed → execute synchronously.
			}

			// Orchestration Layer: Wrap in try-catch to handle budget enforcement.
			try {
				try {
					do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $prepared_arguments, $context );
				} catch ( WP_MCP_AI_Destructive_Confirmation_Required $wp_mcp_ai_gate_exception ) {
					// Destructive-ops gate: return the confirmation request as a
					// WP_Error envelope (HTTP 428) through the normal pipeline.
					return $wp_mcp_ai_gate_exception->to_wp_error();
				} catch ( WP_MCP_AI_Concurrency_Limit_Reached $e ) {
					// Concurrency guard (1.1.44): operation type at capacity.
					return $e->to_wp_error();
				} catch ( WP_MCP_AI_Cost_Budget_Exceeded $e ) {
					// Cost tracker (1.1.44): assistant budget exceeded.
					return $e->to_wp_error();
				}

				$wp_mcp_ai_tool_start = microtime( true );

				/**
				 * Filter that allows interceptors (e.g. the markup subsystem) to
				 * short-circuit tool execution. When the filter returns a non-null
				 * value, that value is used as the tool result and `execute()` is
				 * skipped.
				 *
				 * @since 1.3.0
				 * @param mixed                    $short_circuit Default null.
				 * @param WP_MCP_AI_Tool_Interface $tool          Tool being executed.
				 * @param array                    $prepared_arguments Tool arguments.
				 * @param array                    $context       Execution context.
				 */
				$short_circuit = apply_filters( 'wp_mcp_ai_pre_execute_tool', null, $tool, $prepared_arguments, $context );

				if ( null !== $short_circuit ) {
					$result = $short_circuit;
				} else {
					$result = $tool->execute( $prepared_arguments, $context );
				}

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
				 * @param string $tool_slug  Tool identifier.
				 * @param array  $arguments  Arguments passed in the request.
				 * @param array  $context    Execution context including user_id and assistant_id.
				 * @param mixed  $result     Tool result after filters have been applied.
				 * @param array  $descriptor Normalised lifecycle descriptor
				 *                           ({success, error_code, data_type, duration_ms}).
				 *                           Subscribers with `accepted_args = 4` ignore this.
				 */
				do_action(
					'wp_mcp_ai_after_tool_execution',
					$tool_slug,
					$prepared_arguments,
					$context,
					$result,
					WP_MCP_AI_Tool_Lifecycle_Descriptor::build( $result, $wp_mcp_ai_tool_start, $tool_slug, $context )
				);

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
					'assistant_id'     => $assistant_id,
					'tool'             => $tool_slug,
					'result'           => $result,
					'capability_flags' => $this->extract_capability_flags_from_tool( $tool ),
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

			// If a candidate ends with `_validated`, also try its base slug.
			// This handles the registry auto-upgrade where get_tool( 'web_search' )
			// transparently returns the web_search_validated instance, and the MCP
			// tools/list endpoint reports the validated slug while the assistant
			// config stores the base slug.
			foreach ( $candidates as $candidate ) {
				if ( substr( $candidate, -10 ) === '_validated' ) {
					$base = substr( $candidate, 0, -10 );
					if ( '' !== $base && isset( $allowed_lookup[ $base ] ) ) {
						return $allowed_lookup[ $base ];
					}
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
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
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
				return new WP_Error( 'wp_mcp_ai_file_download_empty', __( 'The downloaded OpenAI file was empty.', 'mcp-ai-wpoos' ) );
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

					echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is a raw HTTP proxy response sent directly to the client; HTML escaping would corrupt binary/JSON/text content.

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
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}

			global $wpdb;

			$meta_key = WP_MCP_AI_Message_Attachments::OPENAI_FILE_META_KEY;
			$like     = '%' . $wpdb->esc_like( $file_id ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
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
					__( 'The requested file could not be located or is no longer available.', 'mcp-ai-wpoos' ),
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
					__( 'You do not have permission to download this file.', 'mcp-ai-wpoos' ),
					array(
						'status'        => 403,
						'attachment_id' => $unauthorised_id,
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_file_download_not_found',
				__( 'The requested file could not be located or is no longer available.', 'mcp-ai-wpoos' ),
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
			// Check if this is a unified team request with format "unified_team_123".
			if ( is_string( $assistant_id ) && 0 === strpos( $assistant_id, 'unified_team_' ) ) {
				// Extract team ID.
				$team_id = $this->extract_team_id( $assistant_id );

				if ( $team_id ) {
					// Return 0 to signal this is a team coordination request.
					// The team will be handled by the orchestration workflow.
					return 0;
				}
			}

			// Check if this is a team member test request with format "team_123_member_456".
			// This format is used when testing individual team members from the test team page.
			if ( is_string( $assistant_id ) && preg_match( '/^team_(\d+)_member_(\d+)$/', $assistant_id, $matches ) ) {
				$team_id   = absint( $matches[1] );
				$member_id = absint( $matches[2] );

				if ( $member_id > 0 ) {
					// Verify the member (profession) exists.
					$profession_post = get_post( $member_id );
					if ( $profession_post && 'mcp_ai_profession' === $profession_post->post_type ) {
						// Check if profession has an associated assistant.
						$associated_assistant = get_post_meta( $member_id, '_wp_mcp_ai_profession_associated_assistant', true );
						$associated_assistant = absint( $associated_assistant );

						if ( $associated_assistant > 0 ) {
							// Verify the associated assistant exists and is published.
							$assistant_post = get_post( $associated_assistant );
							if ( $assistant_post && 'mcp_ai_assistant' === $assistant_post->post_type && 'publish' === $assistant_post->post_status ) {
								// Use the profession's associated assistant.
								return $associated_assistant;
							}
						}

						// No valid associated assistant - return 0 to allow profession-only testing.
						// The profession will be treated as a temporary primary role.
						return 0;
					}
				}
			}

			// Check if this is a profession test request with format "profession_123".
			if ( is_string( $assistant_id ) && 0 === strpos( $assistant_id, 'profession_' ) ) {
				// Extract profession ID.
				$profession_id = $this->extract_profession_id( $assistant_id );

				if ( $profession_id ) {
					// Check if profession has an associated assistant.
					$associated_assistant = get_post_meta( $profession_id, '_wp_mcp_ai_profession_associated_assistant', true );
					$associated_assistant = absint( $associated_assistant );

					if ( $associated_assistant > 0 ) {
						// Verify the associated assistant exists and is published.
						$assistant_post = get_post( $associated_assistant );
						if ( $assistant_post && 'mcp_ai_assistant' === $assistant_post->post_type && 'publish' === $assistant_post->post_status ) {
							// Use the profession's associated assistant.
							// The assistant will have main knowledge, profession data will be appended.
							return $associated_assistant;
						}
					}
				}

				// No valid associated assistant - return 0 to allow profession-only testing.
				// The profession will be treated as a temporary primary role, using the same.
				// logic that assistants use when they have primary roles assigned.
				return 0;
			}

			$assistant_id = absint( $assistant_id );
			if ( $assistant_id ) {
				return $assistant_id;
			}

			// When a token is authenticated, prefer its scoped assistant over the
			// site default. This prevents scope-mismatch errors in downstream
			// callers like mcp_tools_list when the client doesn't send an explicit
			// assistant_id but the site default differs from the token's assistant.
			$auth_context = $this->get_auth_context();
			if ( ! empty( $auth_context['token_authenticated'] ) && 'local_token' === $auth_context['token_type'] ) {
				$token_assistant = isset( $auth_context['assistant_id'] ) ? absint( $auth_context['assistant_id'] ) : 0;
				if ( ! $token_assistant && isset( $auth_context['token_context']['credential']['assistant_id'] ) ) {
					$token_assistant = absint( $auth_context['token_context']['credential']['assistant_id'] );
				}
				if ( $token_assistant ) {
					return $token_assistant;
				}
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$default  = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;

			return $default;
		}

		/**
		 * Extract team ID from assistant_id parameter if it has unified_team_ prefix.
		 *
		 * @param mixed $assistant_id Assistant ID parameter from request.
		 * @return int|false Team ID or false if not a unified team request.
		 */
		protected function extract_team_id( $assistant_id ) {
			if ( ! is_string( $assistant_id ) || 0 !== strpos( $assistant_id, 'unified_team_' ) ) {
				return false;
			}

			$team_id = absint( str_replace( 'unified_team_', '', $assistant_id ) );
			if ( ! $team_id ) {
				return false;
			}

			// Verify it's actually a team post.
			$team_post = get_post( $team_id );
			if ( ! $team_post || 'mcp_ai_team' !== $team_post->post_type ) {
				return false;
			}

			return $team_id;
		}

		/**
		 * Extract profession ID from assistant_id parameter if it has profession_ prefix
		 * or team_XXX_member_YYY pattern.
		 *
		 * @param mixed $assistant_id Assistant ID parameter from request.
		 * @return int|false Profession ID or false if not a profession test request.
		 */
		protected function extract_profession_id( $assistant_id ) {
			if ( ! is_string( $assistant_id ) ) {
				return false;
			}

			$profession_id = false;

			// Check for team_XXX_member_YYY pattern (individual team member testing).
			if ( preg_match( '/^team_(\d+)_member_(\d+)$/', $assistant_id, $matches ) ) {
				$profession_id = absint( $matches[2] );
			} elseif ( 0 === strpos( $assistant_id, 'profession_' ) ) {
				// Check for profession_XXX pattern (direct profession testing).
				$profession_id = absint( str_replace( 'profession_', '', $assistant_id ) );
			}

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
		 * Handle unified team chat request with multi-agent orchestration.
		 *
		 * Routes the request through the Agent Team Orchestrator for coordinated
		 * multi-agent execution following DeepSeek V4 patterns.
		 *
		 * @param WP_REST_Request $request REST request object.
		 * @param int             $team_id Team post ID.
		 * @return WP_REST_Response|WP_Error Response or error.
		 */
		protected function handle_unified_team_request( $request, $team_id ) {
			// Load team configuration.
			$team_post = get_post( $team_id );
			if ( ! $team_post || 'mcp_ai_team' !== $team_post->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_team',
					__( 'Invalid team ID provided.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			// Check if multi-agent teams are enabled.
			$enable_multi_agent_teams = WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true );
			if ( ! $enable_multi_agent_teams ) {
				return new WP_Error(
					'wp_mcp_ai_teams_disabled',
					__( 'Multi-agent teams are disabled. Enable them in Settings → Orchestration.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
				);
			}

			// Get driver assistant (optional - for logging/tracking only).
			$driver_assistant_id = get_post_meta( $team_id, '_wp_mcp_ai_team_driver_assistant', true );

			// If not set on team, try global default.
			if ( ! $driver_assistant_id ) {
				$driver_assistant_id = get_option( 'wp_mcp_ai_team_default_driver_assistant', 0 );
			}

			// Get team members.
			$team_members = get_post_meta( $team_id, '_wp_mcp_ai_team_members', true );
			if ( ! is_array( $team_members ) || empty( $team_members ) ) {
				return new WP_Error(
					'wp_mcp_ai_empty_team',
					__( 'Team has no members configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Get orchestration settings.
			$orchestration_mode = get_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', true );
			$result_aggregation = get_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', true );

			$orchestration_mode = $orchestration_mode ? $orchestration_mode : 'sequential';
			$result_aggregation = $result_aggregation ? $result_aggregation : 'consensus';

			// Sanitize messages.
			$sanitized_messages = $this->validator->sanitize_messages( $request->get_param( 'messages' ) );
			if ( is_wp_error( $sanitized_messages ) ) {
				return $sanitized_messages;
			}

			$messages    = $sanitized_messages['messages'];
			$attachments = $sanitized_messages['attachments'];

			if ( empty( $messages ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_messages',
					__( 'Messages must be provided as an array of role/content pairs.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'rest_unified_team_request',
				'Unified team chat request initiated',
				array(
					'team_id'            => $team_id,
					'team_name'          => $team_post->post_title,
					'driver_assistant'   => $driver_assistant_id,
					'member_count'       => count( $team_members ),
					'orchestration_mode' => $orchestration_mode,
					'result_aggregation' => $result_aggregation,
					'message_count'      => count( $messages ),
				)
			);

			// Load agent team orchestrator.
			if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-agent-team-orchestrator.php';
			}

			// Initialize orchestrator.
			$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

			// Execute team orchestration based on mode.
			$team_response = $this->execute_team_orchestration(
				$orchestrator,
				$team_id,
				$team_members,
				$messages,
				$orchestration_mode,
				$result_aggregation,
				$request
			);

			if ( is_wp_error( $team_response ) ) {
				return $team_response;
			}

			$assistant_slug = 'unified_team_' . $team_id;
			$payload        = array(
				'assistant_id' => $assistant_slug,
				'data'         => $team_response,
			);

			if ( $this->request_wants_event_stream( $request ) ) {
				return $this->stream_event_stream_payload( $payload, 'message' );
			}

			// Return the unified team response in the same shape as chat-client payloads.
			return rest_ensure_response( $payload );
		}

		/**
		 * Execute team orchestration workflow.
		 *
		 * @param WP_MCP_AI_Agent_Team_Orchestrator $orchestrator       Orchestrator instance.
		 * @param int                               $team_id            Team ID.
		 * @param array                             $team_members       Array of profession IDs.
		 * @param array                             $messages           Chat messages.
		 * @param string                            $orchestration_mode Orchestration mode (sequential/parallel/swarm).
		 * @param string                            $result_aggregation Result aggregation strategy.
		 * @param WP_REST_Request                   $request            Original request.
		 * @return array|WP_Error Response array or error.
		 */
		protected function execute_team_orchestration( $orchestrator, $team_id, $team_members, $messages, $orchestration_mode, $result_aggregation, $request ) {
			$member_responses = array();
			$errors           = array();

			// Get the user's message (last message in the array).
			$user_message = end( $messages );
			$task_content = isset( $user_message['content'] ) ? $user_message['content'] : '';

			// Execute based on orchestration mode.
			switch ( $orchestration_mode ) {
				case 'sequential':
					// Execute members sequentially, each building on previous results.
					$member_responses = $this->execute_sequential_orchestration( $team_members, $messages, $request );
					break;

				case 'parallel':
					// Execute all members in parallel (simulate concurrency in PHP).
					$member_responses = $this->execute_parallel_orchestration( $team_members, $messages, $request );
					break;

				case 'swarm':
					// Swarm intelligence - members collaborate and iterate.
					$member_responses = $this->execute_swarm_orchestration( $team_members, $messages, $request );
					break;

				case 'single':
				default:
					// Single member handles the request (first member).
					$member_responses = $this->execute_single_orchestration( $team_members, $messages, $request );
					break;
			}

			if ( is_wp_error( $member_responses ) ) {
				return $member_responses;
			}

			// Aggregate results based on strategy.
			$aggregated_response = $this->aggregate_team_results( $member_responses, $result_aggregation );

			if ( is_wp_error( $aggregated_response ) ) {
				return $aggregated_response;
			}

			WP_MCP_AI_Logger::log_event(
				'rest_unified_team_complete',
				'Unified team orchestration completed',
				array(
					'team_id'            => $team_id,
					'orchestration_mode' => $orchestration_mode,
					'result_aggregation' => $result_aggregation,
					'members_responded'  => count( $member_responses ),
					'response_length'    => strlen( $aggregated_response ),
				)
			);

			// Format response in the same shape returned by chat-client responses.
			return array(
				'choices' => array(
					array(
						'message' => array(
							'role'     => 'assistant',
							'content'  => $aggregated_response,
							'metadata' => array(
								'team_id'            => $team_id,
								'orchestration_mode' => $orchestration_mode,
								'result_aggregation' => $result_aggregation,
								'members_involved'   => count( $member_responses ),
							),
						),
					),
				),
			);
		}

		/**
		 * Execute sequential orchestration - members process in order.
		 *
		 * @param array           $team_members Member profession IDs.
		 * @param array           $messages     Chat messages.
		 * @param WP_REST_Request $request      Original request.
		 * @return array|WP_Error Array of member responses or error.
		 */
		protected function execute_sequential_orchestration( $team_members, $messages, $request ) {
			$responses        = array();
			$context_messages = $messages;

			foreach ( $team_members as $member_id ) {
				// Each member builds on previous responses.
				$member_response = $this->invoke_team_member( $member_id, $context_messages, $request );

				if ( is_wp_error( $member_response ) ) {
					// Log error but continue with other members.
					WP_MCP_AI_Logger::log_warning(
						'Team member failed in sequential orchestration',
						array(
							'member_id' => $member_id,
							'error'     => $member_response->get_error_message(),
						)
					);
					continue;
				}

				$responses[] = $member_response;

				// Add this member's response to context for next member.
				$context_messages[] = array(
					'role'    => 'assistant',
					'content' => $member_response['content'],
				);
			}

			return $responses;
		}

		/**
		 * Execute parallel orchestration - all members process independently.
		 *
		 * @param array           $team_members Member profession IDs.
		 * @param array           $messages     Chat messages.
		 * @param WP_REST_Request $request      Original request.
		 * @return array|WP_Error Array of member responses or error.
		 */
		protected function execute_parallel_orchestration( $team_members, $messages, $request ) {
			$responses = array();

			// In PHP, we simulate parallel execution by invoking all members with same context.
			foreach ( $team_members as $member_id ) {
				$member_response = $this->invoke_team_member( $member_id, $messages, $request );

				if ( is_wp_error( $member_response ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Team member failed in parallel orchestration',
						array(
							'member_id' => $member_id,
							'error'     => $member_response->get_error_message(),
						)
					);
					continue;
				}

				$responses[] = $member_response;
			}

			return $responses;
		}

		/**
		 * Execute swarm orchestration - collaborative iteration.
		 *
		 * @param array           $team_members Member profession IDs.
		 * @param array           $messages     Chat messages.
		 * @param WP_REST_Request $request      Original request.
		 * @return array|WP_Error Array of member responses or error.
		 */
		protected function execute_swarm_orchestration( $team_members, $messages, $request ) {
			// Swarm: Initial parallel execution, then refinement round.
			$initial_responses = $this->execute_parallel_orchestration( $team_members, $messages, $request );

			if ( is_wp_error( $initial_responses ) || empty( $initial_responses ) ) {
				return $initial_responses;
			}

			// Add all initial responses to context.
			$refinement_context   = $messages;
			$refinement_context[] = array(
				'role'    => 'assistant',
				'content' => "Initial team responses:\n\n" . implode( "\n\n---\n\n", array_column( $initial_responses, 'content' ) ),
			);

			// Have first member (critic or leader) refine based on all inputs.
			$leader_id        = $team_members[0];
			$refined_response = $this->invoke_team_member( $leader_id, $refinement_context, $request );

			if ( is_wp_error( $refined_response ) ) {
				// Fall back to parallel aggregation.
				return $initial_responses;
			}

			// Return refined response as primary, with initial responses as context.
			return array( $refined_response );
		}

		/**
		 * Execute single member orchestration - first member handles request.
		 *
		 * @param array           $team_members Member profession IDs.
		 * @param array           $messages     Chat messages.
		 * @param WP_REST_Request $request      Original request.
		 * @return array|WP_Error Array of member responses or error.
		 */
		protected function execute_single_orchestration( $team_members, $messages, $request ) {
			$member_id = $team_members[0];
			$response  = $this->invoke_team_member( $member_id, $messages, $request );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return array( $response );
		}

		/**
		 * Invoke a single team member (profession) with the given messages.
		 *
		 * @param int             $member_id Member profession ID.
		 * @param array           $messages  Chat messages.
		 * @param WP_REST_Request $request   Original request.
		 * @return array|WP_Error Member response or error.
		 */
		protected function invoke_team_member( $member_id, $messages, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter reserved for future implementation.
			// Load profession configuration.
			$profession_config = $this->load_profession_configuration( $member_id, array() );

			// Use profession's provider/model or defaults.
			$provider = isset( $profession_config['provider'] ) ? $profession_config['provider'] : '';
			$model    = isset( $profession_config['model'] ) ? $profession_config['model'] : '';

			// Get default settings if not set on profession.
			if ( empty( $provider ) ) {
				$settings = WP_MCP_AI_Admin_Settings::get_settings();
				$provider = isset( $settings['provider'] ) ? $settings['provider'] : 'openai';
			}

			// Prepare options for create_chat_completion.
			$options = array(
				'provider' => $provider,
				'model'    => $model,
			);

			// Add system prompt if available.
			if ( ! empty( $profession_config['system_prompt'] ) ) {
				// Check if first message is already a system message.
				$has_system_message = false;
				if ( ! empty( $messages ) && isset( $messages[0]['role'] ) && 'system' === $messages[0]['role'] ) {
					$has_system_message = true;
				}

				// Only prepend system message if one doesn't already exist.
				if ( ! $has_system_message ) {
					// Prepend system message to messages array.
					$messages = array_merge(
						array(
							array(
								'role'    => 'system',
								'content' => $profession_config['system_prompt'],
							),
						),
						$messages
					);
				}
			}

			// Add tools if available.
			if ( ! empty( $profession_config['tools'] ) && is_array( $profession_config['tools'] ) ) {
				$options['tools'] = $profession_config['tools'];
			}

			// Make the AI request using the router.
			try {
				$response = $this->client->create_chat_completion( $messages, $options );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// Extract content from response.
				$content = '';
				if ( isset( $response['choices'][0]['message']['content'] ) ) {
					$content = $response['choices'][0]['message']['content'];
				} elseif ( isset( $response['content'] ) ) {
					$content = $response['content'];
				}

				return array(
					'member_id' => $member_id,
					'content'   => $content,
					'metadata'  => array(
						'provider' => $provider,
						'model'    => $model,
					),
				);

			} catch ( Exception $e ) {
				return new WP_Error(
					'wp_mcp_ai_member_invocation_failed',
					sprintf(
						/* translators: %s: Error message */
						__( 'Team member invocation failed: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					)
				);
			}
		}

		/**
		 * Aggregate team member results based on strategy.
		 *
		 * @param array  $responses         Array of member responses.
		 * @param string $aggregation_strategy Aggregation strategy.
		 * @return string|WP_Error Aggregated response or error.
		 */
		protected function aggregate_team_results( $responses, $aggregation_strategy ) {
			if ( empty( $responses ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_responses',
					__( 'No team members provided responses.', 'mcp-ai-wpoos' )
				);
			}

			switch ( $aggregation_strategy ) {
				case 'consensus':
					// Combine all responses with clear attribution.
					$aggregated = "# Team Response (Consensus)\n\n";
					foreach ( $responses as $index => $response ) {
						$member_num  = $index + 1;
						$aggregated .= "## Team Member {$member_num}\n\n";
						$aggregated .= $response['content'] . "\n\n";
					}
					return $aggregated;

				case 'weighted':
					// First response gets priority (leader/planner).
					$primary        = $responses[0]['content'];
					$response_count = count( $responses );
					if ( $response_count > 1 ) {
						$primary .= "\n\n---\n\n## Additional Perspectives\n\n";
						for ( $i = 1; $i < $response_count; $i++ ) {
							$primary .= $responses[ $i ]['content'] . "\n\n";
						}
					}
					return $primary;

				case 'hierarchical':
					// Last response (after refinement) takes precedence.
					return end( $responses )['content'];

				case 'first':
					// First member's response only.
					return $responses[0]['content'];

				case 'best':
					// Longest response (proxy for most comprehensive).
					usort(
						$responses,
						function ( $a, $b ) {
							return strlen( $b['content'] ) - strlen( $a['content'] );
						}
					);
					return $responses[0]['content'];

				default:
					// Default to consensus.
					return $this->aggregate_team_results( $responses, 'consensus' );
			}
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

			// Use the same primary role logic that assistants use.
			// Temporarily treat this profession as the primary role for testing.
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
			}

			// Build profession prompt using the assistant's primary role logic.
			$profession_prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( array( $profession_id ) );

			// Get profession meta data for additional configuration.
			$default_tools        = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_tools', true );
			$memory_files         = get_post_meta( $profession_id, '_wp_mcp_ai_profession_memory_files', true );
			$default_provider_val = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', true );
			$default_model_val    = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', true );
			$default_temp_val     = get_post_meta( $profession_id, '_wp_mcp_ai_profession_default_temperature', true );

			// Determine if we have an assistant base configuration.
			$has_assistant_base = ! empty( $assistant_config ) && isset( $assistant_config['system_prompt'] ) && ! empty( $assistant_config['system_prompt'] );

			// Merge profession configuration with assistant configuration.
			if ( ! empty( $profession_prompt ) ) {
				if ( $has_assistant_base ) {
					// If assistant exists, append profession role to existing instructions.
					// This ensures the assistant's base knowledge is primary and profession data supplements it.
					$assistant_config['system_prompt'] .= "\n\n" . __( 'Professional Role & Expertise:', 'mcp-ai-wpoos' ) . "\n" . $profession_prompt;
				} else {
					// No assistant base - use profession role as the primary system prompt.
					$assistant_config['system_prompt'] = $profession_prompt;
				}
			}

			// For tools: If assistant has tools and profession has tools, merge them.
			// If only profession has tools, use profession tools.
			// If only assistant has tools, keep assistant tools (handled by not modifying).
			if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
				if ( isset( $assistant_config['tools'] ) && is_array( $assistant_config['tools'] ) && ! empty( $assistant_config['tools'] ) ) {
					// Merge tools, ensuring uniqueness and keeping profession tools prioritized.
					$assistant_config['tools'] = array_unique( array_merge( $assistant_config['tools'], $default_tools ) );
				} else {
					// No assistant tools, use profession tools.
					$assistant_config['tools'] = $default_tools;
				}
			}

			// For memory files: Similar merge logic as tools.
			if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
				if ( isset( $assistant_config['memory_files'] ) && is_array( $assistant_config['memory_files'] ) && ! empty( $assistant_config['memory_files'] ) ) {
					// Merge memory files, ensuring uniqueness.
					$assistant_config['memory_files'] = array_unique( array_merge( $assistant_config['memory_files'], $memory_files ) );
				} else {
					// No assistant memory files, use profession memory files.
					$assistant_config['memory_files'] = $memory_files;
				}
			}

			// Provider, model, and temperature from profession take priority when set (for testing specifics).
			// Use explicit checks instead of empty() to handle edge cases like temperature = 0.
			if ( null !== $default_provider_val && '' !== $default_provider_val && false !== $default_provider_val ) {
				$assistant_config['provider'] = $default_provider_val;
			}

			if ( null !== $default_model_val && '' !== $default_model_val && false !== $default_model_val ) {
				$assistant_config['model'] = $default_model_val;
			}

			if ( null !== $default_temp_val && false !== $default_temp_val && '' !== $default_temp_val && is_numeric( $default_temp_val ) ) {
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
					__( 'The provided credential cannot access the requested assistant.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 403,
						'actions' => array(
							'use_scoped_assistant' => __( 'Retry the request without overriding the assistant or request a credential for the desired assistant.', 'mcp-ai-wpoos' ),
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
					__( 'You do not have access to this assistant.', 'mcp-ai-wpoos' ),
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
					__( 'You do not have access to this assistant.', 'mcp-ai-wpoos' ),
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
		 * @param int             $assistant_id Assistant identifier.
		 * @param array           $options      Prepared chat options.
		 * @param WP_REST_Request $request      Optional. REST request for client-sent BME overrides.
		 * @return array
		 */
		protected function build_chat_limit_context( $assistant_id, array $options, $request = null ) {
			$context = array(
				'assistant_id' => absint( $assistant_id ),
				'provider'     => isset( $options['provider'] ) ? sanitize_key( $options['provider'] ) : '',
				'model'        => isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : '',
			);

			// Include client-sent BME overrides if available.
			if ( null !== $request && $request instanceof WP_REST_Request ) {
				$end_window_size = $request->get_param( 'end_window_size' );
				if ( null !== $end_window_size ) {
					$context['end_window_size'] = absint( $end_window_size );
				}

				$client_strategy = $request->get_param( 'context_strategy' );
				if ( null !== $client_strategy && is_string( $client_strategy ) ) {
					$context['client_context_strategy'] = sanitize_key( $client_strategy );
				}
			}

			return $context;
		}

		/**
		 * Pre-flight TPM (tokens-per-minute) check with automatic truncation.
		 *
		 * Attempts model switching first (when enabled), then falls back to
		 * message truncation.  Returns the (possibly modified) messages and
		 * options arrays, or a WP_Error when the request cannot be shrunk to fit.
		 *
		 * @param array $messages Messages array.
		 * @param array $options  Chat completion options (passed by reference for model switching).
		 * @param int   $assistant_id Assistant post ID (for logging).
		 * @return array|WP_Error Array with 'messages' and 'options' keys, or WP_Error.
		 */
		protected function preflight_tpm_check( array $messages, array $options, $assistant_id = 0 ) {
			if ( ! class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
				return array(
					'messages' => $messages,
					'options'  => $options,
				);
			}

			$model             = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';
			$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;

			// If max_output_tokens alone would consume more than 50% of the model's TPM budget,
			// cap it proactively to leave room for input tokens. This is especially important for
			// Anthropic models with low Tier 1 TPM limits (e.g. 40K for claude-opus-4-6).
			if ( $max_output_tokens > 0 ) {
				$tpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $model );
				if ( null !== $tpm_limit && $tpm_limit > 0 && $max_output_tokens > (int) ( $tpm_limit * 0.5 ) ) {
					$capped                = max( 1024, (int) ( $tpm_limit * 0.5 ) );
					$options['max_tokens'] = $capped;
					$max_output_tokens     = $capped;

					WP_MCP_AI_Logger::log_event(
						'preflight_max_tokens_capped',
						'Capped max_tokens to fit within TPM budget during pre-flight check.',
						array(
							'model'          => $model,
							'original_value' => isset( $options['max_tokens'] ) ? $options['max_tokens'] : 0,
							'capped_value'   => $capped,
							'tpm_limit'      => $tpm_limit,
							'assistant_id'   => $assistant_id,
						)
					);
				}
			}

			$tpm_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

			if ( ! is_wp_error( $tpm_validation ) ) {
				return array(
					'messages' => $messages,
					'options'  => $options,
				);
			}

			// Try switching to a fallback model with a higher TPM limit.
			$settings            = WP_MCP_AI_Admin_Settings::get_settings();
			$fallback_model      = WP_MCP_AI_Model_Selector::resolve_fallback_model( $model, $settings );
			$auto_switch_enabled = isset( $settings['enable_high_token_model_switch'] ) ? (bool) $settings['enable_high_token_model_switch'] : true;

			if ( $auto_switch_enabled && $fallback_model !== $model ) {
				$fallback_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $fallback_model, $max_output_tokens );

				if ( ! is_wp_error( $fallback_validation ) ) {
					$options['model'] = $fallback_model;

					$fallback_model_config = class_exists( 'WP_MCP_AI_Model_Config' )
						? WP_MCP_AI_Model_Config::get_model_config( $fallback_model )
						: null;
					if ( $fallback_model_config && ! empty( $fallback_model_config['provider'] ) ) {
						$options['provider'] = sanitize_key( $fallback_model_config['provider'] );
					}

					WP_MCP_AI_Logger::log_event(
						'preflight_model_switched',
						'Switched to higher-capacity model before initial chat request.',
						array(
							'original_model' => $model,
							'new_model'      => $fallback_model,
							'assistant_id'   => $assistant_id,
						)
					);

					return array(
						'messages' => $messages,
						'options'  => $options,
					);
				}
			}

			// Model switch not available/helpful — truncate messages.
			$tpm_limit     = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $model );
			$target_tokens = $tpm_limit ? (int) ( $tpm_limit * self::TPM_SAFETY_MARGIN ) : self::TPM_FALLBACK_TOKENS;
			$messages      = WP_MCP_AI_Token_Budget_Manager::truncate_messages( $messages, $model, $target_tokens );

			// Re-validate after truncation.
			$tpm_validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

			if ( is_wp_error( $tpm_validation ) ) {
				WP_MCP_AI_Logger::log_error(
					'Pre-flight TPM check failed even after truncation.',
					array(
						'assistant_id' => $assistant_id,
						'model'        => $model,
						'tpm_limit'    => $tpm_limit,
						'error'        => $tpm_validation->get_error_message(),
					)
				);
				return $tpm_validation;
			}

			WP_MCP_AI_Logger::log_event(
				'preflight_messages_truncated',
				'Messages truncated to fit within TPM limits before initial chat request.',
				array(
					'model'         => $model,
					'target_tokens' => $target_tokens,
					'assistant_id'  => $assistant_id,
				)
			);

			return array(
				'messages' => $messages,
				'options'  => $options,
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
		 * Apply Beginning-Middle-End (BME) strategy to message list.
		 *
		 * Separates messages into:
		 * - Beginning: system prompts + auto-generated summary.
		 * - End: last N full-fidelity non-system messages (verbatim).
		 * - Middle: summarized older messages.
		 *
		 * @since 2.0.0
		 *
		 * @param array $messages Full message array.
		 * @param array $settings Plugin settings.
		 * @param array $context  Request context.
		 * @return array Reordered/trimmed messages.
		 */
		protected function trim_messages_bme( array $messages, array $settings, array $context ) {
			// Separate system messages from conversation messages.
			$system_messages = array();
			$conv_messages   = array();

			foreach ( $messages as $message ) {
				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';
				if ( 'system' === $role ) {
					$system_messages[] = $message;
				} else {
					$conv_messages[] = $message;
				}
			}

			$end_window_size    = isset( $context['end_window_size'] ) && $context['end_window_size'] > 0
				? absint( $context['end_window_size'] )
				: ( isset( $settings['end_window_size'] ) ? absint( $settings['end_window_size'] ) : 10 );
			$end_window_size    = max( 2, $end_window_size );
			$summary_trigger    = isset( $settings['summary_trigger_count'] ) ? absint( $settings['summary_trigger_count'] ) : 30;
			$trigger_tokens     = isset( $settings['summary_trigger_tokens'] ) ? absint( $settings['summary_trigger_tokens'] ) : 0;
			$summary_max_tokens = isset( $settings['summary_max_tokens'] ) ? absint( $settings['summary_max_tokens'] ) : 500;
			$summary_model      = isset( $settings['summary_model'] ) ? sanitize_text_field( $settings['summary_model'] ) : '';

			// Determine if summarization should trigger.
			$should_summarize = false;

			if ( $trigger_tokens > 0 ) {
				// Token-aware triggering: ~80% of trigger_tokens for headroom (industry std: 70-80%).
				$effective_threshold = (int) ( $trigger_tokens * 0.8 );
				$estimated_tokens    = $this->estimate_messages_tokens( $conv_messages );
				$should_summarize    = $estimated_tokens > $effective_threshold;

				WP_MCP_AI_Logger::log_event(
					'bme_token_check',
					'Token-aware summarization check.',
					array(
						'estimated_tokens'    => $estimated_tokens,
						'effective_threshold' => $effective_threshold,
						'configured_trigger'  => $trigger_tokens,
						'should_summarize'    => $should_summarize,
					)
				);
			} else {
				// Count-based triggering (original behavior).
				$should_summarize = count( $conv_messages ) > $summary_trigger;
			}
			$summary_max_tokens = isset( $settings['summary_max_tokens'] ) ? absint( $settings['summary_max_tokens'] ) : 500;

			// If conversation is short enough, no summarization needed.
			if ( ! $should_summarize ) {
				return array_merge( $system_messages, $conv_messages );
			}

			// Split: end (most recent) vs middle (older, to summarize).
			$end_messages    = array_slice( $conv_messages, -$end_window_size );
			$middle_messages = array_slice( $conv_messages, 0, -$end_window_size );

			if ( empty( $middle_messages ) ) {
				return array_merge( $system_messages, $end_messages );
			}

			// Generate summary of middle messages.
			$summary = $this->generate_conversation_summary( $middle_messages, $context, $summary_max_tokens, $summary_model );

			if ( is_wp_error( $summary ) || '' === $summary ) {
				// Fallback: just use sliding window if summarization fails.
				WP_MCP_AI_Logger::log_event(
					'bme_summary_failed_fallback',
					'BME summarization failed, falling back to sliding window.',
					array(
						'error'        => is_wp_error( $summary ) ? $summary->get_error_message() : 'empty_summary',
						'middle_count' => count( $middle_messages ),
						'end_count'    => count( $end_messages ),
					)
				);

				// Fall back to just keeping recent messages.
				$max_history  = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 8;
				$end_messages = array_slice( $conv_messages, -$max_history );
				return array_merge( $system_messages, $end_messages );
			}

			// Build the summary message (inserted as a user-role context message).
			$summary_message = array(
				'role'    => 'user',
				'content' => '[Earlier conversation summary: ' . $summary . ']',
			);

			WP_MCP_AI_Logger::log_event(
				'bme_summary_applied',
				'BME summary applied to chat request.',
				array(
					'middle_summarized' => count( $middle_messages ),
					'end_kept'          => count( $end_messages ),
					'summary_length'    => strlen( $summary ),
				)
			);

			// Recombine: system + summary + end.
			return array_merge( $system_messages, array( $summary_message ), $end_messages );
		}

		/**
		 * Generate a conversation summary using the language model.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $middle_messages Messages to summarize.
		 * @param array  $context         Request context.
		 * @param int    $max_tokens      Max tokens for the summary.
		 * @param string $summary_model   Optional. Dedicated model for summarization.
		 * @return string|WP_Error Summary text or error.
		 */
		protected function generate_conversation_summary( array $middle_messages, array $context, $max_tokens, $summary_model = '' ) {
			try {
				$summarizer = new WP_MCP_AI_Conversation_Summarizer( $this->client );

				$options = array(
					'max_tokens' => $max_tokens,
				);

				// Use dedicated summary model if configured, otherwise fall back to assistant model.
				if ( ! empty( $summary_model ) ) {
					$options['model'] = $summary_model;
				} elseif ( ! empty( $context['model'] ) ) {
					$options['model'] = $context['model'];
				}

				// Pass provider from context if available.
				if ( ! empty( $context['provider'] ) ) {
					$options['provider'] = $context['provider'];
				}

				return $summarizer->summarize( $middle_messages, $options );
			} catch ( \Exception $e ) {
				return new WP_Error(
					'wp_mcp_ai_summary_error',
					$e->getMessage()
				);
			}
		}

		/**
		 * Proactive agentic-loop context compaction.
		 *
		 * Industry standard (LangChain Deep Agents, Vercel AI SDK prepareStep):
		 * compact context BEFORE hitting the TPM limit, not after.
		 *
		 * Strategy:
		 * - At 70% capacity: offload/trim old tool results from prior iterations.
		 * - At 85% capacity: summarize middle iterations into a running summary,
		 *   preserving system prompt + current iteration's messages verbatim.
		 *
		 * Always preserves:
		 * - System messages (persona, constraints, tool definitions).
		 * - The most recent assistant + tool_calls + tool results (current iteration).
		 * - At least 2 prior user/assistant turns for conversation continuity.
		 *
		 * @since 2.1.0
		 *
		 * @param array $messages     Current message array.
		 * @param int   $iteration    Current iteration number (0-based).
		 * @param array $options      Chat completion options (contains model).
		 * @param int   $assistant_id Assistant ID for logging.
		 * @return array Possibly compacted messages.
		 */
		protected function maybe_compact_agentic_context( array $messages, $iteration, array $options, $assistant_id ) {
			// Only compact after iteration 2+ (earliest that compaction matters).
			if ( $iteration < 2 ) {
				return $messages;
			}

			$model = isset( $options['model'] ) ? $options['model'] : 'gpt-4o-mini';

			// Get the model's context window limit.
			$max_tokens = WP_MCP_AI_Token_Budget_Manager::get_model_limit( $model );
			if ( $max_tokens <= 0 ) {
				$max_tokens = 128000; // Sensible fallback for unknown models.
			}

			// Estimate current token usage.
			$estimated = $this->estimate_messages_tokens( $messages );
			$pct_used  = $max_tokens > 0 ? ( $estimated / $max_tokens ) * 100 : 0;

			// Below 70%: no compaction needed.
			if ( $pct_used < 70 ) {
				return $messages;
			}

			// Separate system messages (always preserved).
			$system_msgs = array();
			$other_msgs  = array();
			foreach ( $messages as $msg ) {
				if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
					$system_msgs[] = $msg;
				} else {
					$other_msgs[] = $msg;
				}
			}

			// Split: keep the last 4 non-system messages (current iteration + prior turn).
			$keep_count = min( 4, count( $other_msgs ) );
			$keep_msgs  = array_slice( $other_msgs, -$keep_count );
			$old_msgs   = array_slice( $other_msgs, 0, -$keep_count );

			if ( empty( $old_msgs ) ) {
				return $messages;
			}

			// Phase 1 (70-84%): Trim old tool results — keep structure, drop large content.
			if ( $pct_used < 85 ) {
				$trimmed = $this->trim_old_tool_results( $old_msgs );

				WP_MCP_AI_Logger::log_event(
					'agentic_context_trimmed',
					'Agentic loop: trimmed old tool results to free context.',
					array(
						'iteration'    => $iteration,
						'assistant_id' => $assistant_id,
						'pct_used'     => round( $pct_used, 1 ),
						'phase'        => 'trim_tool_results',
						'old_count'    => count( $old_msgs ),
					)
				);

				return array_merge( $system_msgs, $trimmed, $keep_msgs );
			}

			// Phase 2 (85%+): Summarize old iterations into running context.
			$summary = $this->generate_running_agentic_summary( $old_msgs, $options );

			if ( is_wp_error( $summary ) || '' === $summary ) {
				// Fallback: trim old tool results instead.
				$trimmed = $this->trim_old_tool_results( $old_msgs );
				return array_merge( $system_msgs, $trimmed, $keep_msgs );
			}

			$summary_msg = array(
				'role'    => 'user',
				'content' => '[Agentic loop progress: ' . $summary . ']',
			);

			WP_MCP_AI_Logger::log_event(
				'agentic_context_summarized',
				'Agentic loop: summarized middle iterations into running context.',
				array(
					'iteration'      => $iteration,
					'assistant_id'   => $assistant_id,
					'pct_used'       => round( $pct_used, 1 ),
					'phase'          => 'summarize',
					'old_count'      => count( $old_msgs ),
					'summary_length' => strlen( $summary ),
				)
			);

			return array_merge( $system_msgs, array( $summary_msg ), $keep_msgs );
		}

		/**
		 * Trim large content from old tool results while preserving structure.
		 *
		 * Tool messages from prior iterations are replaced with a compact note
		 * showing the tool name and call ID. This preserves the message structure
		 * the LLM expects (assistant with tool_calls → tool responses) while
		 * freeing context space.
		 *
		 * @since 2.1.0
		 *
		 * @param array $old_msgs Old non-system messages.
		 * @return array Trimmed messages.
		 */
		protected function trim_old_tool_results( array $old_msgs ) {
			$trimmed = array();

			foreach ( $old_msgs as $msg ) {
				if ( isset( $msg['role'] ) && 'tool' === $msg['role'] ) {
					// Replace tool result content with a compact note.
					$tool_name = isset( $msg['name'] ) ? $msg['name'] : 'unknown';
					$call_id   = isset( $msg['tool_call_id'] ) ? substr( $msg['tool_call_id'], 0, 12 ) : '';

					$msg['content'] = sprintf(
						'[%s result — compacted to free context%s]',
						$tool_name,
						$call_id ? ' #' . $call_id : ''
					);
				}

				$trimmed[] = $msg;
			}

			return $trimmed;
		}

		/**
		 * Generate a running summary of older agentic-loop iterations.
		 *
		 * Uses the same ConversationSummarizer to compress middle iterations
		 * into a brief progress report that preserves key decisions and outcomes.
		 *
		 * @since 2.1.0
		 *
		 * @param array $old_msgs Older non-system messages to summarize.
		 * @param array $options  Chat completion options.
		 * @return string|WP_Error Summary text or error.
		 */
		protected function generate_running_agentic_summary( array $old_msgs, array $options ) {
			try {
				$summarizer = new WP_MCP_AI_Conversation_Summarizer( $this->client );

				$summary_options = array(
					'max_tokens' => 300, // Short summary — just key decisions/outcomes.
				);

				if ( ! empty( $options['provider'] ) ) {
					$summary_options['provider'] = $options['provider'];
				}
				if ( ! empty( $options['model'] ) ) {
					$summary_options['model'] = $options['model'];
				}

				return $summarizer->summarize( $old_msgs, $summary_options );
			} catch ( \Exception $e ) {
				return new WP_Error(
					'wp_mcp_ai_agentic_summary_error',
					$e->getMessage()
				);
			}
		}

		/**
		 * Inject RAG-retrieved memories into the message list for BME+RAG strategy.
		 *
		 * Queries the memory ecosystem (Paper Store + MemPalace/Chat Memory) for
		 * relevant context and inserts it as a context message after system prompts.
		 *
		 * @since 2.0.0
		 *
		 * @param array $messages Current messages (after BME trimming).
		 * @param array $context  Request context.
		 * @return array Messages with injected RAG context.
		 */
		protected function inject_rag_context( array $messages, array $context ) {
			if ( ! class_exists( 'WP_MCP_AI_Conversation_RAG_Bridge' ) ) {
				return $messages;
			}

			try {
				$rag_bridge = new WP_MCP_AI_Conversation_RAG_Bridge();

				// Extract the last user message as the retrieval query.
				$last_user_message = '';
				for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
					if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
						$content = $messages[ $i ]['content'];
						if ( is_string( $content ) ) {
							$last_user_message = $content;
						} elseif ( is_array( $content ) ) {
							// Multi-modal: extract text parts.
							$text_parts = array();
							foreach ( $content as $segment ) {
								if ( is_string( $segment ) ) {
									$text_parts[] = $segment;
								} elseif ( isset( $segment['text'] ) ) {
									$text_parts[] = $segment['text'];
								}
							}
							$last_user_message = implode( ' ', $text_parts );
						}
						break;
					}
				}

				if ( '' === $last_user_message ) {
					return $messages;
				}

				// Retrieve relevant memories.
				$memories = $rag_bridge->retrieve_relevant_memories( $last_user_message, $context );

				if ( empty( $memories ) ) {
					return $messages;
				}

				// Build RAG context message.
				$rag_message = $rag_bridge->build_rag_context_message( $memories );

				if ( empty( $rag_message ) ) {
					return $messages;
				}

				// Insert after any system messages (before conversation turns).
				$insert_pos = 0;
				foreach ( $messages as $i => $msg ) {
					if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
						$insert_pos = $i + 1;
					} else {
						break;
					}
				}

				array_splice( $messages, $insert_pos, 0, array( $rag_message ) );

				WP_MCP_AI_Logger::log_event(
					'bme_rag_context_injected',
					'RAG memories injected into message context.',
					array(
						'memory_count' => count( $memories ),
						'insert_pos'   => $insert_pos,
					)
				);

				return $messages;
			} catch ( \Exception $e ) {
				WP_MCP_AI_Logger::log_event(
					'bme_rag_injection_error',
					'RAG context injection failed.',
					array( 'error' => $e->getMessage() )
				);
				return $messages;
			}
		}

		/**
		 * Estimate token count for a list of messages using the text chunker heuristic.
		 *
		 * Used for token-aware BME summarization triggering.
		 *
		 * @since 2.0.0
		 *
		 * @param array $messages Array of message arrays.
		 * @return int Estimated token count.
		 */
		protected function estimate_messages_tokens( array $messages ) {
			$total = 0;
			foreach ( $messages as $message ) {
				if ( isset( $message['content'] ) ) {
					if ( is_string( $message['content'] ) ) {
						$total += WP_MCP_AI_Text_Chunker::estimate_tokens( $message['content'] );
					} elseif ( is_array( $message['content'] ) ) {
						foreach ( $message['content'] as $segment ) {
							if ( is_string( $segment ) ) {
								$total += WP_MCP_AI_Text_Chunker::estimate_tokens( $segment );
							} elseif ( isset( $segment['text'] ) ) {
								$total += WP_MCP_AI_Text_Chunker::estimate_tokens( $segment['text'] );
							}
						}
					}
				}
			}
			return $total;
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
							__( 'Request token count (%1$d) exceeds maximum allowed (%2$d) even after trimming. Please reduce message length.', 'mcp-ai-wpoos' ),
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

			// Determine context strategy, defaulting to sliding_window for backward compat.
			// Client-sent override takes precedence over global setting.
			$context_strategy = isset( $settings['context_strategy'] ) ? sanitize_key( $settings['context_strategy'] ) : 'sliding_window';
			if ( ! empty( $context['client_context_strategy'] ) ) {
				$context_strategy = $context['client_context_strategy'];
			}

			/**
			 * Filters the context strategy used for chat history management.
			 *
			 * @since 2.0.0
			 *
			 * @param string $context_strategy Strategy slug: 'sliding_window' or 'bme'.
			 * @param array  $messages         Current messages array.
			 * @param array  $context          Request context (assistant_id, provider, model).
			 */
			$context_strategy = (string) apply_filters( 'wp_mcp_ai_context_strategy', $context_strategy, $messages, $context );

			if ( 'bme' === $context_strategy || 'bme_rag' === $context_strategy ) {
				$messages = $this->trim_messages_bme( $messages, $settings, $context );

				// RAG extension: inject retrieved memories from the memory ecosystem.
				if ( 'bme_rag' === $context_strategy ) {
					$messages = $this->inject_rag_context( $messages, $context );
				}
			} elseif ( $max_message_count > 0 && count( $messages ) > $max_message_count ) {
				// Legacy sliding window path — unchanged from original.
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
					__( 'The chat request exceeds the maximum allowed size.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'reduce_request_size' => __( 'Reduce the length of the conversation before retrying.', 'mcp-ai-wpoos' ),
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

			$note        = '[' . __( 'Truncated', 'mcp-ai-wpoos' ) . '] ';
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

			/**
			 * Filter tool slugs before they are converted to LLM function payloads.
			 *
			 * This is the primary hook for attention-based tool selection.
			 * Plugins or addons can reduce the tool list based on semantic
			 * relevance, user capabilities, dependency availability, or
			 * risk-tier assessment — the Transformer-inspired "attention
			 * heads" that score tools on multiple dimensions.
			 *
			 * Return an empty array to use all allowed tools (bypass filtering).
			 *
			 * @since 1.8.0
			 *
			 * @param string[] $filtered_slugs   Filtered tool slugs (empty = use all).
			 * @param string[] $allowed_tool_slugs Original tool slugs from assistant config.
			 * @param array    $assistant_config   Full assistant configuration.
			 */
			$filtered_slugs = apply_filters( 'wp_mcp_ai_attention_tool_slugs', array(), $allowed_tool_slugs, $assistant_config );
			if ( ! empty( $filtered_slugs ) && is_array( $filtered_slugs ) ) {
				$allowed_tool_slugs = array_values( array_intersect( $filtered_slugs, $allowed_tool_slugs ) );
			}

			$chat_provider = isset( $assistant_config['provider'] ) ? sanitize_key( $assistant_config['provider'] ) : 'openai';

			$tools_payload = array();

				/**
				 * Maximum number of tools to include in a single chat request payload.
				 *
				 * OpenAI and most providers support up to 128 functions per request, but
				 * sending that many tools bloats the payload and can exhaust PHP memory
				 * during schema generation for complex tool definitions.  This guard
				 * prevents crashes when an assistant has too many tools assigned and
				 * logs a warning so the site owner can adjust the limit or reduce the
				 * assigned tools.
				 *
				 * @since 2.4.0
				 *
				 * @param int $max_tools Maximum number of tools to include (default 50).
				 */
				$max_tools = (int) apply_filters( 'wp_mcp_ai_max_chat_tools', 100 );
				$max_tools = max( 1, min( 128, $max_tools ) ); // Clamp to 1-128.

				/**
				 * Maximum combined token budget for tool definitions within a chat payload.
				 *
				 * Tool definitions are serialised JSON schemas that consume context-window
				 * tokens. Complex tools with large parameter schemas can consume thousands
				 * of tokens each. This budget provides a token-aware safety cap that
				 * supplements the count-based cap above — tools are included until either
				 * limit is reached.
				 *
				 * Default of 32000 tokens ≈ 25% of a 128K context window, following the
				 * industry guidance of keeping tool overhead under 25-33% of the window.
				 *
				 * @since 2.7.0
				 *
				 * @param int $max_tool_tokens Maximum combined tokens for tool definitions (default 32000).
				 */
				$max_tool_tokens = (int) apply_filters( 'wp_mcp_ai_max_chat_tool_tokens', 32000 );
				$max_tool_tokens = max( 1000, $max_tool_tokens );

			if ( count( $allowed_tool_slugs ) > $max_tools ) {
				WP_MCP_AI_Logger::log_event(
					'tools_truncated_for_chat',
					sprintf(
						'Assistant has %d tools but the chat payload is capped at %d. Only the first %d tools will be sent to the LLM. Reduce the number of assigned tools to avoid this.',
						count( $allowed_tool_slugs ),
						$max_tools,
						$max_tools
					),
					array(
						'total_tools'  => count( $allowed_tool_slugs ),
						'max_allowed'  => $max_tools,
						'assistant_id' => isset( $assistant_config['id'] ) ? $assistant_config['id'] : null,
					)
				);
				$allowed_tool_slugs = array_slice( $allowed_tool_slugs, 0, $max_tools );
			}

				// Track cumulative token consumption to enforce the token budget.
				$cumulative_tool_tokens          = 0;
				$tools_truncated_by_token_budget = false;
				$truncated_tool_count            = 0;

				// Track resolved tool slugs to prevent duplicate entries.
				// When both a base slug (e.g. generate_openai_image) and its
				// _validated variant are present in the assistant config, the
				// registry auto-upgrades both to the validated tool.  Without
				// deduplication the LLM receives duplicate function names and
				// rejects the request with "Tool names must be unique".
				$seen_resolved_slugs = array();

			foreach ( $allowed_tool_slugs as $slug ) {
				// Enforce the token budget: stop adding tools once the cumulative
				// token count exceeds the configured maximum.
				if ( $cumulative_tool_tokens >= $max_tool_tokens ) {
					$tools_truncated_by_token_budget = true;
					++$truncated_tool_count;
					continue;
				}
				$tool = $this->registry->get_tool( $slug );
				if ( ! $tool ) {
					WP_MCP_AI_Admin_Settings::log( 'Assistant references missing tool.', array( 'tool' => $slug ) );
					continue;
				}

				// Filter tools by user capabilities.
				// If the tool requires a specific capability, only include it if the current user has that capability.
				if ( method_exists( $tool, 'get_required_capability' ) ) {
					$required_capability = $tool->get_required_capability();
					if ( ! empty( $required_capability ) && ! current_user_can( $required_capability ) ) {
						WP_MCP_AI_Logger::log_event(
							'tool_filtered_by_capability',
							sprintf( 'Tool "%s" filtered from payload - user lacks required capability: %s', $slug, $required_capability ),
							array(
								'tool_slug'           => $slug,
								'required_capability' => $required_capability,
								'user_id'             => get_current_user_id(),
							)
						);
						continue;
					}
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

					// Prevent duplicate tool definitions when both base and
					// _validated variants resolve to the same registered tool.
					$resolved_slug = $tool->get_slug();
					if ( isset( $seen_resolved_slugs[ $resolved_slug ] ) ) {
						continue;
					}
					$seen_resolved_slugs[ $resolved_slug ] = true;

					// Use the original config slug as the tool name sent to
					// the LLM.  The registry transparently auto-upgrades to
					// the validated variant at execution time via get_tool().
					// This keeps the naming surface stable so the LLM calls
					// base slugs that always resolve correctly.
					$tools_payload[] = array(
						'type'     => 'function',
						'function' => array(
							'name'        => $slug,
							'description' => $description,
							'parameters'  => $schema,
						),
					);

					// Track cumulative token consumption for the token budget cap.
					if ( class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
						$tool_def_json           = wp_json_encode( end( $tools_payload ) );
						$cumulative_tool_tokens += WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $tool_def_json );
					}
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

			// Log token-budget truncation when tools were dropped.
			if ( $tools_truncated_by_token_budget ) {
				WP_MCP_AI_Logger::log_event(
					'tools_truncated_by_token_budget',
					sprintf(
						'Tool definitions reached the %d token budget after %d tools (%d dropped). Increase wp_mcp_ai_max_chat_tool_tokens filter or reduce tool schema complexity.',
						$max_tool_tokens,
						count( $tools_payload ),
						$truncated_tool_count
					),
					array(
						'max_token_budget'  => $max_tool_tokens,
						'tools_included'    => count( $tools_payload ),
						'tools_dropped'     => $truncated_tool_count,
						'cumulative_tokens' => $cumulative_tool_tokens,
						'assistant_id'      => isset( $assistant_config['id'] ) ? $assistant_config['id'] : null,
					)
				);
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
					__( 'Note: This tool uses the %s API for image processing. A valid Gemini API key must be configured in the plugin settings.', 'mcp-ai-wpoos' ),
					$this->format_provider_name( 'gemini' )
				);
			}

			// Check for OpenAI-specific tools when using Gemini.
			if ( 'gemini' === $chat_provider && in_array( 'openai', $required_providers, true ) ) {
				$fallback_parts[] = sprintf(
					/* translators: %s: required provider name (e.g., "OpenAI") */
					__( 'Note: This tool uses the %s API. A valid OpenAI API key must be configured in the plugin settings.', 'mcp-ai-wpoos' ),
					$this->format_provider_name( 'openai' )
				);
			}

			// If no specific fallback text was generated, create a generic one.
			if ( empty( $fallback_parts ) && ! empty( $required_providers ) ) {
				$provider_list    = implode( ', ', array_map( array( $this, 'format_provider_name' ), $required_providers ) );
				$fallback_parts[] = sprintf(
					/* translators: %s: comma-separated list of required providers */
					__( 'Note: This tool requires one of the following providers: %s.', 'mcp-ai-wpoos' ),
					$provider_list
				);
			}

			// Append fallback text to the description with proper punctuation handling.
			if ( ! empty( $fallback_parts ) ) {
				$description = rtrim( $description );
				// Add period if description doesn't end with punctuation.
				if ( '' !== $description && ! preg_match( '/[.!?]$/', $description ) ) {
					$description .= '.';
				}
				$description .= ' ' . implode( ' ', $fallback_parts );
			}

			return $description;
		}

		/**
		 * Format a provider identifier to a human-readable name.
		 *
		 * @param string $provider The provider identifier (e.g., 'openai', 'gemini').
		 * @return string The formatted provider name (e.g., 'OpenAI', 'Gemini').
		 */
		protected function format_provider_name( $provider ) {
			$provider_names = array(
				'openai'    => 'OpenAI',
				'gemini'    => 'Gemini',
				'anthropic' => 'Anthropic',
				'ollama'    => 'Ollama',
			);

			$provider = strtolower( $provider );

			return isset( $provider_names[ $provider ] ) ? $provider_names[ $provider ] : ucfirst( $provider );
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

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

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
						__( 'A memory file could not be located.', 'mcp-ai-wpoos' ),
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
						__( 'Could not determine the size of a memory file.', 'mcp-ai-wpoos' ),
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
						__( 'Memory files must be smaller than %1$s bytes. The requested file is %2$s bytes.', 'mcp-ai-wpoos' ),
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
					__( 'You do not have permission to use the requested memory files.', 'mcp-ai-wpoos' ),
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

				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP XMLReader API property.
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
					return new WP_Error( 'wp_mcp_ai_memory_file_unreadable', __( 'Unable to read memory file contents.', 'mcp-ai-wpoos' ) );
				}

				$contents      = '';
				$bytes_allowed = $byte_budget;

				while ( ! $file->eof() && $bytes_allowed > 0 ) {
					$read_length = min( $chunk_size, $bytes_allowed );
					$chunk       = $file->fread( $read_length );

					if ( false === $chunk ) {
						return new WP_Error( 'wp_mcp_ai_memory_file_read_failed', __( 'Failed to read memory file contents.', 'mcp-ai-wpoos' ) );
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

				return new WP_Error( 'wp_mcp_ai_memory_file_read_failed', __( 'Failed to read memory file contents.', 'mcp-ai-wpoos' ) );
			}

			return new WP_Error( 'wp_mcp_ai_memory_file_unreadable', __( 'Unable to read memory file contents.', 'mcp-ai-wpoos' ) );
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
				$chunks[ count( $chunks ) - 1 ] .= "\n\n[" . __( 'Truncated', 'mcp-ai-wpoos' ) . ']';
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
					__( 'The requested chat transcript could not be found.', 'mcp-ai-wpoos' ),
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

				// Filter out response messages that are already present in the conversation.
				// This prevents duplicates when transcripts are manually saved via the chat client,.
				// where all messages (including assistant responses) are stored in request_payload,.
				// and assistant messages are also constructed into response_payload.
				$filtered_response_messages = $this->filter_duplicate_messages( $messages, $response_messages );

				$before_response = count( $messages );
				$this->append_new_messages( $messages, $filtered_response_messages, $row['response_completed_at'], $row['cct_created'] );

				WP_MCP_AI_Logger::log_event(
					'debug',
					'get_transcript_session: appended response messages',
					array(
						'new_message_count'       => count( $messages ) - $before_response,
						'original_response_count' => count( $response_messages ),
						'filtered_response_count' => count( $filtered_response_messages ),
						'duplicates_removed'      => count( $response_messages ) - count( $filtered_response_messages ),
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
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
		 * Get AI provider instance by provider name.
		 *
		 * @param string $provider Provider name (openai, anthropic, google, ollama).
		 * @return object|WP_Error Provider instance or error.
		 */
		protected function get_ai_provider_instance( $provider ) {
			$provider  = sanitize_key( $provider );
			$container = wp_mcp_ai_container();

			try {
				switch ( $provider ) {
					case 'openai':
						return $container->get( 'client.openai' );

					case 'anthropic':
						return $container->get( 'client.anthropic' );

					case 'google':
					case 'gemini':
						if ( $container->has( 'client.google' ) ) {
							return $container->get( 'client.google' );
						}
						return new WP_Error(
							'wp_mcp_ai_provider_unavailable',
							__( 'Google/Gemini provider is not available.', 'mcp-ai-wpoos' )
						);

					case 'ollama':
						if ( $container->has( 'client.ollama' ) ) {
							return $container->get( 'client.ollama' );
						}
						return new WP_Error(
							'wp_mcp_ai_provider_unavailable',
							__( 'Ollama provider is not available.', 'mcp-ai-wpoos' )
						);

					default:
						// Default to OpenAI for unknown providers.
						return $container->get( 'client.openai' );
				}
			} catch ( Exception $e ) {
				return new WP_Error(
					'wp_mcp_ai_provider_init_failed',
					sprintf(
						/* translators: %1$s: Provider name, %2$s: Error message */
						__( 'Failed to initialize %1$s provider: %2$s', 'mcp-ai-wpoos' ),
						$provider,
						$e->getMessage()
					)
				);
			}
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

			$preview    = '';
			$turn_count = 0;

			if ( '' !== $session_key ) {
				$session_data = $this->get_session_preview_and_turn_count( $session_key, $user_id );
				$preview      = $session_data['preview'];
				$turn_count   = $session_data['turn_count'];
			}

			// Fall back to SQL COUNT(*) only when the per-message count is zero (no
			// request_payload rows found or payload missing messages).
			if ( 0 === $turn_count && isset( $row['turn_count'] ) ) {
				$turn_count = (int) $row['turn_count'];
			}

			return array(
				'session_key'     => $session_key,
				'assistant_id'    => $assistant_id,
				'assistant_title' => $assistant_title,
				'assistant_model' => $assistant_model,
				'started_at'      => $this->format_transcript_timestamp( isset( $row['started_at'] ) ? $row['started_at'] : '', isset( $row['first_created'] ) ? $row['first_created'] : '' ),
				'completed_at'    => $this->format_transcript_timestamp( isset( $row['completed_at'] ) ? $row['completed_at'] : '', isset( $row['last_created'] ) ? $row['last_created'] : '' ),
				'updated_at'      => $this->format_transcript_timestamp( isset( $row['last_created'] ) ? $row['last_created'] : '', isset( $row['completed_at'] ) ? $row['completed_at'] : '' ),
				'turn_count'      => $turn_count,
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

			// Escape table name for defense-in-depth and to satisfy WordPress Plugin Check tool.
			// Table name is constructed from $wpdb->prefix + 'jet_cct_' + constant slug.
			$table = esc_sql( $this->get_transcript_table_name() );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql() above.
			$query = $wpdb->prepare(
				"SELECT request_payload
             FROM {$table}
             WHERE session_key = %s AND cct_author_id = %d
             ORDER BY cct_created ASC
             LIMIT 1",
				$session_key,
				absint( $user_id )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql().
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
		 * Load preview text and accurate turn count from a session's stored
		 * request_payload in a single query.
		 *
		 * The listing query uses COUNT(*) which always returns 1 after the
		 * transcript recorder switched to upsert behaviour (each session_key
		 * maps to exactly one row).  Counting user messages inside the
		 * stored payload gives the true number of conversation turns.
		 *
		 * @since 1.8.0
		 *
		 * @param string $session_key Session key.
		 * @param int    $user_id     User identifier.
		 * @return array{preview: string, turn_count: int}
		 */
		protected function get_session_preview_and_turn_count( $session_key, $user_id ) {
			global $wpdb;

			$result = array(
				'preview'    => '',
				'turn_count' => 0,
			);

			if ( '' === $session_key ) {
				return $result;
			}

			$table = esc_sql( $this->get_transcript_table_name() );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql() above.
			$query = $wpdb->prepare(
				"SELECT request_payload
	             FROM {$table}
	             WHERE session_key = %s AND cct_author_id = %d
	             ORDER BY cct_created ASC
	             LIMIT 1",
				$session_key,
				absint( $user_id )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$row = $wpdb->get_row( $query, ARRAY_A );

			if ( empty( $row['request_payload'] ) ) {
				return $result;
			}

			$payload = json_decode( $row['request_payload'], true );

			if ( ! is_array( $payload ) || empty( $payload['messages'] ) || ! is_array( $payload['messages'] ) ) {
				return $result;
			}

			$user_message_count = 0;

			foreach ( $payload['messages'] as $message ) {
				if ( ! isset( $message['role'] ) ) {
					continue;
				}

				if ( 'user' === $message['role'] ) {
					++$user_message_count;

					// Capture the first user message as the preview.
					if ( '' === $result['preview'] ) {
						$text = $this->prepare_message_text( $message );
						if ( '' !== $text ) {
							$result['preview'] = $text;
						}
					}
				}
			}

			$result['turn_count'] = $user_message_count;

			return $result;
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

				// Extract message or text field from tool result.
				// Check both 'message' and 'text' to support different provider formats.
				if ( is_array( $content ) ) {
					$text = '';
					if ( isset( $content['message'] ) && is_string( $content['message'] ) ) {
						$text = trim( $content['message'] );
					} elseif ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
						$text = trim( $content['text'] );
					}
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
		 * @param array $arr Array to inspect.
		 * @return bool
		 */
		protected function is_sequential_array( $arr ) {
			if ( ! is_array( $arr ) ) {
				return false;
			}

			if ( array() === $arr ) {
				return true;
			}

			return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
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

			// Content must be an array to contain image segments.
			if ( ! is_array( $content ) ) {
				return false;
			}

			// Check if content is a sequential array of segments.
			if ( ! $this->is_sequential_array( $content ) ) {
				return false;
			}

			// Look for image_url or image_file type segments.
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

				// Skip system-role messages entirely — system prompts are internal
				// LLM context and must never be exposed to frontend consumers.
				if ( 'system' === $role ) {
					continue;
				}

				$content = $this->prepare_message_text( $message );

				// Check if message has image content (even if text content is empty).
				$has_image_content = $this->message_has_image_content( $message );

				// Skip messages with empty content, except:
				// - tool role messages (required for tool responses).
				// - assistant role messages with tool_calls (required for agentic flow).
				// - messages with image content (required to preserve images in chat).
				$has_tool_calls = 'assistant' === $role && isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) && ! empty( $message['tool_calls'] );

				if ( '' === $content && 'tool' !== $role && ! $has_tool_calls && ! $has_image_content ) {
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

				// If message has image content, preserve the original content structure.
				// instead of the extracted text (which would be empty for image-only messages).
				if ( $has_image_content && isset( $message['content'] ) ) {
					$message_entry['content'] = $message['content'];
				}

				// Always include tool_call_id for tool messages so the frontend never
				// sends back a tool message that fails REST validation on the next turn.
				// Use the stored id when present; generate a stable fallback otherwise.
				if ( 'tool' === $role ) {
					$message_entry['tool_call_id'] = ( isset( $message['tool_call_id'] ) && '' !== $message['tool_call_id'] )
						? sanitize_text_field( $message['tool_call_id'] )
						: uniqid( 'tool_loaded_', true );
				}

				// Preserve name for tool messages (optional but helpful for debugging).
				if ( 'tool' === $role && isset( $message['name'] ) && '' !== $message['name'] ) {
					$message_entry['name'] = sanitize_text_field( $message['name'] );
				}

				// Preserve tool_calls for assistant messages (required when assistant makes tool calls).
				if ( $has_tool_calls ) {
					$message_entry['tool_calls'] = $message['tool_calls'];
				}

				// Preserve display metadata for UI restoration (video attachments, bubble type, usage/cost badges).
				// This is critical for async video generation results to persist across sessions.
				if ( isset( $message['display'] ) && is_array( $message['display'] ) ) {
					$message_entry['display'] = $message['display'];
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

						// If message has image content, preserve the original content structure.
						// instead of the extracted text (which would be empty for image-only messages).
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
				$parts[] = sprintf( __( 'Tool call: %s', 'mcp-ai-wpoos' ), $name );
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
		 * Checks if two messages are semantically the same by comparing role, content,
		 * and relevant metadata fields (tool_call_id, name, tool_calls). This prevents
		 * duplicate messages when reconstructing conversations from multiple database rows.
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

			// Role must match.
			if ( $existing_role !== $candidate_role ) {
				return false;
			}

			// Content comparison - handle both string and array formats.
			$existing_content  = isset( $existing['content'] ) ? $existing['content'] : '';
			$candidate_content = isset( $candidate['content'] ) ? $candidate['content'] : '';

			// If both are strings, compare directly.
			if ( is_string( $existing_content ) && is_string( $candidate_content ) ) {
				if ( $existing_content !== $candidate_content ) {
					return false;
				}
			} elseif ( is_array( $existing_content ) && is_array( $candidate_content ) ) {
				// For array content (e.g., multimodal messages with images), do a deep comparison.
				// Use JSON encoding for reliable comparison.
				if ( wp_json_encode( $existing_content ) !== wp_json_encode( $candidate_content ) ) {
					return false;
				}
			} else {
				// Different types (one string, one array) - not a match.
				return false;
			}

			// For tool messages, also compare tool_call_id and name.
			if ( 'tool' === $existing_role ) {
				$existing_tool_call_id  = isset( $existing['tool_call_id'] ) ? (string) $existing['tool_call_id'] : '';
				$candidate_tool_call_id = isset( $candidate['tool_call_id'] ) ? (string) $candidate['tool_call_id'] : '';

				if ( $existing_tool_call_id !== $candidate_tool_call_id ) {
					return false;
				}

				$existing_name  = isset( $existing['name'] ) ? (string) $existing['name'] : '';
				$candidate_name = isset( $candidate['name'] ) ? (string) $candidate['name'] : '';

				if ( $existing_name !== $candidate_name ) {
					return false;
				}
			}

			// For assistant messages with tool_calls, compare the tool_calls array.
			if ( 'assistant' === $existing_role ) {
				$existing_has_tool_calls  = isset( $existing['tool_calls'] ) && is_array( $existing['tool_calls'] );
				$candidate_has_tool_calls = isset( $candidate['tool_calls'] ) && is_array( $candidate['tool_calls'] );

				// Both must have or not have tool_calls.
				if ( $existing_has_tool_calls !== $candidate_has_tool_calls ) {
					return false;
				}

				// If both have tool_calls, compare them.
				if ( $existing_has_tool_calls && $candidate_has_tool_calls ) {
					// Use JSON encoding for reliable deep comparison.
					if ( wp_json_encode( $existing['tool_calls'] ) !== wp_json_encode( $candidate['tool_calls'] ) ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Filter out candidate messages that already exist in the conversation.
		 *
		 * This method performs an O(n*m) comparison to find and remove duplicate messages.
		 * It's more thorough than append_new_messages prefix-based deduplication, which
		 * only handles cases where messages appear at the same position. This handles the
		 * case where manually saved transcripts have assistant messages in both request_payload
		 * (as part of the full conversation) and response_payload (extracted from the conversation).
		 *
		 * Performance note: While O(n*m) complexity could be improved with hashing, typical
		 * chat conversations have <100 messages and response_payload has <10 assistant messages,
		 * making the practical impact negligible. A hash-based approach would require
		 * custom serialization of message objects and add complexity without measurable benefit.
		 *
		 * @param array $conversation Current conversation array.
		 * @param array $candidates   Candidate messages to filter.
		 * @return array Filtered array with duplicates removed.
		 */
		protected function filter_duplicate_messages( array $conversation, array $candidates ) {
			if ( empty( $candidates ) ) {
				return array();
			}

			if ( empty( $conversation ) ) {
				return $candidates;
			}

			$filtered = array();

			foreach ( $candidates as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}

				$is_duplicate = false;

				foreach ( $conversation as $existing ) {
					if ( $this->messages_match( $existing, $candidate ) ) {
						$is_duplicate = true;
						break;
					}
				}

				if ( ! $is_duplicate ) {
					$filtered[] = $candidate;
				}
			}

			return $filtered;
		}

		/**
		 * Multibyte-safe string length helper.
		 *
		 * @param string $str String to measure.
		 * @return int
		 */
		protected function mb_strlen( $str ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $str ) : strlen( $str );
		}

		/**
		 * Multibyte-safe substring helper.
		 *
		 * @param string $str    Input string.
		 * @param int    $start  Start position.
		 * @param int    $length Length of substring.
		 * @return string
		 */
		protected function mb_substr( $str, $start, $length ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $str, $start, $length ) : substr( $str, $start, $length );
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
		 * Strip unexecuted tool_calls from an LLM response in-place.
		 *
		 * When the agentic loop exits because it has reached max_iterations, the
		 * final LLM response may still contain tool_calls that were never executed.
		 * Forwarding those to the browser would cause the JS client to persist an
		 * assistant message with orphaned tool_call_ids in localStorage. On the very
		 * next user turn those orphaned ids would be sent back to OpenAI, triggering:
		 *   "An assistant message with 'tool_calls' must be followed by tool messages
		 *    responding to each 'tool_call_id'."
		 *
		 * This method mutates $response directly and returns the number of stripped
		 * tool calls so the caller can log appropriately.
		 *
		 * @param array  $response     LLM response array (mutated in place).
		 * @param string $assistant_id Assistant identifier (for logging).
		 * @param int    $iteration    Iteration count at time of stripping (for logging).
		 * @param string $context_label Short label for the log message, e.g. 'Non-SSE' or 'SSE'.
		 * @return int Number of tool_calls stripped (0 if none).
		 */
		protected function strip_orphaned_tool_calls_from_response( array &$response, $assistant_id, $iteration, $context_label = '' ) {
			if ( is_wp_error( $response ) ) {
				return 0;
			}

			$orphaned = $this->extract_tool_calls_from_response( $response );

			if ( empty( $orphaned ) ) {
				return 0;
			}

			if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
				unset( $response['choices'][0]['message']['tool_calls'] );
			}

			if ( isset( $response['choices'][0]['finish_reason'] ) && 'tool_calls' === $response['choices'][0]['finish_reason'] ) {
				$response['choices'][0]['finish_reason'] = 'stop';
			}

			$label = '' !== $context_label ? trim( $context_label ) . ': ' : '';
			WP_MCP_AI_Logger::log_event(
				'stripped_orphaned_tool_calls',
				$label . 'Stripped unexecuted tool_calls from final response after reaching max_iterations.',
				array(
					'assistant_id'   => $assistant_id,
					'iterations'     => $iteration,
					'stripped_count' => count( $orphaned ),
				)
			);

			return count( $orphaned );
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
		 * @param array           $transcript_context Transcript context containing session_key for async job routing (default empty array).
		 * @return mixed Tool execution result.
		 */
		protected function execute_tool_call_internal( $tool_call, $assistant_id, $assistant_config, $user_id, $request, $iteration = 0, $max_iterations = 5, $transcript_context = array() ) {
			if ( ! isset( $tool_call['function']['name'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_tool_call', __( 'Tool call missing function name.', 'mcp-ai-wpoos' ) );
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
								return new WP_Error( 'wp_mcp_ai_invalid_tool_arguments', sprintf( __( 'Tool "%s" has invalid arguments: expected JSON object.', 'mcp-ai-wpoos' ), $tool_name ) );
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
							return new WP_Error( 'wp_mcp_ai_invalid_tool_arguments_json', sprintf( __( 'Tool "%1$s" has invalid JSON arguments: %2$s', 'mcp-ai-wpoos' ), $tool_name, $json_error ) );
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

			// Auto-enable essential read-only tools for chat-client endpoint.
			// These tools are needed to maintain agentic workflows in chat context.
			// Only read-only, safe tools are auto-enabled; write operations require explicit config.
			$endpoint = $request->get_route();
			if ( false !== strpos( $endpoint, '/chat-client' ) ) {
				// Define read-only tools essential for agentic workflow.
				$auto_enable_tools = array(
					'web_search',                  // Real-time information retrieval.
					'get_recent_posts',            // Access site content for context.
					'search_attachments',          // Access media library files.
					'get_jetengine_items',         // Access JetEngine CCT data (if available).
					'list_jetengine_rest_routes',  // Discover JetEngine routes (if available).
					'get_jetformbuilder_forms',    // Access JetFormBuilder forms (if available).
					'get_jetformbuilder_submissions', // Access form submissions (if available).
				);

				foreach ( $auto_enable_tools as $auto_tool ) {
					if ( $this->candidates_include_slug( $tool_candidates, $auto_tool ) &&
						! in_array( $auto_tool, $allowed_tools, true ) ) {
						$assistant_config = $this->ensure_tool_in_config( $assistant_config, $auto_tool );
						$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
					}
				}
			}

			$tool_slug = $this->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );

			if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
				/* translators: %s: tool name */
				return new WP_Error( 'wp_mcp_ai_tool_forbidden', sprintf( __( 'Tool "%s" is not allowed for this assistant.', 'mcp-ai-wpoos' ), $tool_name ), array( 'status' => 403 ) );
			}

			$tool = $this->registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				/* translators: %s: tool name */
				return new WP_Error( 'wp_mcp_ai_tool_missing', sprintf( __( 'Tool "%s" is not registered.', 'mcp-ai-wpoos' ), $tool_name ), array( 'status' => 404 ) );
			}

			// Extract tool_call_id if available (from OpenAI/Gemini tool calls).
			// This is critical for async tools to preserve the original tool_call_id.
			// in their completion responses instead of generating a new one.
			$tool_call_id = isset( $tool_call['id'] ) ? sanitize_text_field( $tool_call['id'] ) : '';

			// Determine guest status for tool permission bypass.
			// Guest requests come via guest tokens (auth_context) or anonymous users on public assistants.
			$auth_context = $this->get_auth_context();
			$is_guest     = ! empty( $auth_context['is_guest'] );
			$required_cap = isset( $assistant_config['required_capability'] ) ? $assistant_config['required_capability'] : '';
			if ( ! $is_guest && 0 === $user_id && 'public' === $required_cap ) {
				$is_guest = true;
			}

			$context = array(
				'user_id'               => $user_id,
				'assistant_id'          => $assistant_id,
				'request'               => $request,
				'assistant_config'      => $assistant_config,
				'guest_request'         => $is_guest,
				'agentic_loop'          => true,
				'iteration'             => $iteration,
				'max_iterations'        => $max_iterations,
				'endpoint'              => $request->get_route(),
				'allow_sensitive_tools' => $request->get_param( 'allow_sensitive_tools' ) === true,
			);

			// Add tool_call_id to context if available.
			// This ensures async jobs can preserve the original tool_call_id for proper.
			// correlation with the LLM's tool call requests.
			if ( '' !== $tool_call_id ) {
				$context['tool_call_id'] = $tool_call_id;
			}

			// Add session_key to context if available from transcript_context.
			// This enables async job completion notifications to be routed back to the correct chat session.
			// The async executor will sanitize this to session_id (matching the allowed context keys).
			if ( ! empty( $transcript_context['session_key'] ) ) {
				$context['session_id'] = $transcript_context['session_key'];
			}

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
			// This prevents "Invalid parameter(s)" errors when AI providers include extra.
			// parameters like 'messages' that aren't in the tool's schema.
			$arguments = $this->filter_tool_arguments_by_schema( $tool, $arguments );

			// Orchestration Layer: Check if tool should execute asynchronously.
			// Get async orchestrator to determine execution strategy.
			$orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
			$should_async = $orchestrator->should_execute_async( $tool, $arguments, $context );

			// CRITICAL: Force synchronous execution in agentic loop for most tools.
			// Async tools must complete before the loop continues to ensure the LLM.
			// receives actual results, not pending status. Without this, the agentic
			// loop would continue with pending tool results, and the final LLM response.
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
					// Include the job_id and tool_call_id prominently in the message so the LLM knows to tell the user.
					// Build the message with Call ID if available.
					$message = '';
					if ( '' !== $tool_call_id ) {
						$message = sprintf(
							/* translators: 1: tool name, 2: job ID, 3: call ID */
							__( 'Tool "%1$s" is processing in the background (Job ID: %2$s). The results will be available shortly and will appear here automatically when ready. (Call ID: %3$s)', 'mcp-ai-wpoos' ),
							$tool_name,
							$job_id,
							$tool_call_id
						);
					} else {
						$message = sprintf(
							/* translators: 1: tool name, 2: job ID */
							__( 'Tool "%1$s" is processing in the background (Job ID: %2$s). The results will be available shortly and will appear here automatically when ready.', 'mcp-ai-wpoos' ),
							$tool_name,
							$job_id
						);
					}

					// Build the base pending response.
					$pending_response = array(
						'status'    => 'pending',
						'job_id'    => $job_id,
						'message'   => $message,
						'async'     => true,
						'tool_slug' => $tool_slug,
					);

					// Check if tool provides pre-execution metadata for async responses.
					// This allows tools like video generation to provide expected_url and expected_filename.
					// so the UI can display a placeholder before the actual result is ready.
					if ( $tool instanceof WP_MCP_AI_Tool_Async_Metadata_Interface ) {
						$async_metadata = $tool->get_async_pending_metadata( $job_id, $arguments, $context );
						if ( is_array( $async_metadata ) && ! empty( $async_metadata ) ) {
							// Merge metadata into response (tool metadata takes precedence for message if provided).
							$pending_response = array_merge( $pending_response, $async_metadata );

							WP_MCP_AI_Logger::log_event(
								'async_tool_metadata_added',
								sprintf( 'Added pre-execution metadata for async tool %s', $tool_slug ),
								array(
									'tool_slug'     => $tool_slug,
									'job_id'        => $job_id,
									'metadata_keys' => array_keys( $async_metadata ),
								)
							);
						}
					}

					return $pending_response;
				}
			}

			// Execute tool synchronously (either not async-capable or async queueing failed).
			// Orchestration Layer: Wrap in try-catch to handle budget enforcement and timeouts.
			try {
				// Set execution time limit for synchronous tool execution in agentic loop.
				// to prevent PHP timeout. Default WordPress limit is 30s, we allow up to 60s
				// for tools that might take longer (like image generation).
				if ( ! empty( $context['agentic_loop'] ) ) {
					$original_time_limit = ini_get( 'max_execution_time' );
					$tool_timeout        = apply_filters( 'wp_mcp_ai_agentic_tool_timeout', 60, $tool_slug );

					// Only set if we can (some hosting environments don't allow this).
					if ( function_exists( 'set_time_limit' ) && 0 !== (int) $original_time_limit ) {
						@set_time_limit( $tool_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: set_time_limit() may emit warnings on restricted hosts; failure is non-critical (best-effort timeout extension).
					}
				}

				try {
					do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context );
				} catch ( WP_MCP_AI_Destructive_Confirmation_Required $wp_mcp_ai_gate_exception ) {
					// Destructive-ops gate: return the confirmation request as a
					// WP_Error envelope (HTTP 428) through the normal pipeline.
					return $wp_mcp_ai_gate_exception->to_wp_error();
				} catch ( WP_MCP_AI_Concurrency_Limit_Reached $e ) {
					// Concurrency guard (1.1.44): operation type at capacity.
					return $e->to_wp_error();
				} catch ( WP_MCP_AI_Cost_Budget_Exceeded $e ) {
					// Cost tracker (1.1.44): assistant budget exceeded.
					return $e->to_wp_error();
				}

				$wp_mcp_ai_tool_start = microtime( true );

				/**
				 * Filter that allows interceptors (e.g. the markup subsystem) to
				 * short-circuit tool execution inside the agentic loop. When the
				 * filter returns a non-null value, that value is used as the
				 * tool result and `execute()` is skipped.
				 *
				 * @since 1.3.0
				 * @param mixed                    $short_circuit Default null.
				 * @param WP_MCP_AI_Tool_Interface $tool          Tool being executed.
				 * @param array                    $arguments     Tool arguments.
				 * @param array                    $context       Execution context.
				 */
				$short_circuit = apply_filters( 'wp_mcp_ai_pre_execute_tool', null, $tool, $arguments, $context );

				if ( null !== $short_circuit ) {
					$result = $short_circuit;
				} else {
					$result = $tool->execute( $arguments, $context );
				}

				if ( is_wp_error( $result ) ) {
					WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $arguments, $result, $context );

					// Check if this is a pending status (e.g., HTTP 202 from web search)
					// rather than a hard error. Pending statuses should be handled differently
					// to allow the LLM to gracefully respond using alternative sources.
					$error_data = $result->get_error_data();
					$is_pending = is_array( $error_data ) && ! empty( $error_data['is_pending'] );
					$error_code = $result->get_error_code();
					$is_pending = $is_pending || 'wp_mcp_ai_search_pending' === $error_code;

					// In agentic loop, if sync execution failed and tool supports async,.
					// provide helpful error message instead of returning WP_Error object.
					// which would break the conversation flow.
					if ( ! empty( $context['agentic_loop'] ) ) {
						// For pending statuses, return an informational message that guides the LLM.
						// to use alternative sources rather than treating it as a hard failure.
						// This prevents the LLM from telling users "the search isn't working.".
						if ( $is_pending ) {
							// Load chat service class to access the constant.
							if ( ! class_exists( 'WP_MCP_AI_Chat_Service' ) ) {
								require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-service.php';
							}
							return WP_MCP_AI_Chat_Service::PENDING_TOOL_MESSAGE;
						}

						return sprintf(
							/* translators: 1: tool name, 2: error message */
							__( 'Tool "%1$s" execution failed: %2$s', 'mcp-ai-wpoos' ),
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

				do_action(
					'wp_mcp_ai_after_tool_execution',
					$tool_slug,
					$arguments,
					$context,
					$result,
					WP_MCP_AI_Tool_Lifecycle_Descriptor::build( $result, $wp_mcp_ai_tool_start, $tool_slug, $context )
				);

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

				// In agentic loop, provide a graceful error message that the LLM can understand.
				// and potentially work around, rather than breaking the conversation flow.
				if ( ! empty( $context['agentic_loop'] ) ) {
					return sprintf(
						/* translators: 1: tool name, 2: error message */
						__( 'Tool "%1$s" execution error: %2$s. The tool could not complete successfully.', 'mcp-ai-wpoos' ),
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
		public function handle_mcp_options( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
			/**
			 * Filter the Access-Control-Allow-Origin header value for OPTIONS requests.
			 *
			 * @see 'wp_mcp_ai_cors_allow_origin' filter in MCP methods trait.
			 */
			$settings       = WP_MCP_AI_Admin_Settings::get_settings();
			$cors_setting   = isset( $settings['cors_allow_origin'] ) ? $settings['cors_allow_origin'] : 'site';
			$default_origin = ( 'star' === $cors_setting ) ? '*' : get_site_url();
			$allow_origin   = apply_filters( 'wp_mcp_ai_cors_allow_origin', $default_origin );

			$response = new WP_REST_Response( null, 204 );
			$response->header( 'Access-Control-Allow-Origin', $allow_origin );
			$response->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
			$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest' );
			$response->header( 'Access-Control-Max-Age', '3600' );

			// Apply the centrally-managed security headers (gated behind Settings → Security → Network → "Enable Security Headers").
			$security         = new WP_MCP_AI_Security_Manager();
			$security_headers = $security->get_security_headers();
			foreach ( $security_headers as $name => $value ) {
				$response->header( $name, $value );
			}
			return $response;
		}

		/**
		 * Get the SELECT fields for transcript session queries.
		 *
		 * @return string SQL SELECT fields.
		 */
		private function get_transcript_select_fields() {
			return 'request_payload,
                    response_payload,
                    metadata,
                    request_started_at,
                    response_completed_at,
                    cct_created,
                    assistant_id,
                    assistant_model,
                    latency_ms';
		}

		/**
		 * Extract usage information from a tool result.
		 *
		 * Tools that make API calls (like image generation, text analysis) may include
		 * usage data in their results. This method extracts that information for
		 * display in the frontend chat UI (Phase 7: Enhanced Token Tracking).
		 *
		 * @since 1.1.0
		 *
		 * @param mixed $tool_result The raw tool result, which may be an array with usage data.
		 * @return array|null Usage info array with prompt_tokens, completion_tokens, total_tokens,
		 *                    optionally model, provider, is_estimated, and cost data. Null if no usage data.
		 */
		protected function extract_usage_info_from_tool_result( $tool_result ) {
			// Handle WP_Error - no usage data available.
			if ( is_wp_error( $tool_result ) ) {
				return null;
			}

			// Handle string results - no usage data available.
			if ( ! is_array( $tool_result ) ) {
				return null;
			}

			// Check if the tool result contains usage data.
			if ( ! isset( $tool_result['usage'] ) || ! is_array( $tool_result['usage'] ) ) {
				return null;
			}

			$usage = $tool_result['usage'];

			// Validate and extract token counts.
			$prompt_tokens     = isset( $usage['prompt_tokens'] ) ? absint( $usage['prompt_tokens'] ) : 0;
			$completion_tokens = isset( $usage['completion_tokens'] ) ? absint( $usage['completion_tokens'] ) : 0;
			$total_tokens      = isset( $usage['total_tokens'] ) ? absint( $usage['total_tokens'] ) : ( $prompt_tokens + $completion_tokens );

			// If no tokens, return null.
			if ( $total_tokens <= 0 ) {
				return null;
			}

			// Build the usage info array.
			$usage_info = array(
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
			);

			// Include is_estimated flag if present in the original usage data.
			if ( isset( $usage['is_estimated'] ) ) {
				$usage_info['is_estimated'] = (bool) $usage['is_estimated'];
			}

			// Include model if available (from tool result or usage data).
			if ( isset( $tool_result['model'] ) && is_string( $tool_result['model'] ) && '' !== $tool_result['model'] ) {
				$usage_info['model'] = $tool_result['model'];
			} elseif ( isset( $usage['model'] ) && is_string( $usage['model'] ) && '' !== $usage['model'] ) {
				$usage_info['model'] = $usage['model'];
			}

			// Include provider if available (from tool result or usage data).
			if ( isset( $tool_result['provider'] ) && is_string( $tool_result['provider'] ) && '' !== $tool_result['provider'] ) {
				$usage_info['provider'] = $tool_result['provider'];
			} elseif ( isset( $usage['provider'] ) && is_string( $usage['provider'] ) && '' !== $usage['provider'] ) {
				$usage_info['provider'] = $usage['provider'];
			}

			// Include cost data if available in the tool result.
			if ( isset( $tool_result['cost'] ) && is_array( $tool_result['cost'] ) ) {
				$cost = $tool_result['cost'];
				// Validate cost is a non-negative number to prevent erroneous cost tracking.
				if ( isset( $cost['cost_usd'] ) && is_numeric( $cost['cost_usd'] ) && $cost['cost_usd'] >= 0 ) {
					$usage_info['cost_usd'] = (float) $cost['cost_usd'];

					// Include cost is_estimated flag if different from usage is_estimated.
					if ( isset( $cost['is_estimated'] ) ) {
						$usage_info['cost_is_estimated'] = (bool) $cost['is_estimated'];
					}
				}
			}

			// Fallback: compute cost from tokens when the tool result includes
			// provider/model/usage but no explicit cost. This covers tools that
			// call external APIs (e.g. a DeepSeek assistant running a Gemini
			// search tool) where the tool returns usage data but does not compute
			// its own cost.
			if ( ! isset( $usage_info['cost_usd'] ) && ! empty( $usage_info['provider'] ) && ! empty( $usage_info['model'] ) && class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
				$computed_cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
					$usage_info['provider'],
					$usage_info['model'],
					$prompt_tokens,
					$completion_tokens
				);
				if ( $computed_cost > 0.0 ) {
					$usage_info['cost_usd']           = $computed_cost;
					$usage_info['cost_is_calculated'] = true;
				}
			}

			return $usage_info;
		}

		/**
		 * Extract capability flags from a tool instance.
		 *
		 * Returns an array of capability flag strings if the tool implements
		 * the capability flags interface, empty array otherwise.
		 *
		 * @since 1.1.0
		 *
		 * @param WP_MCP_AI_Tool_Interface|null $tool_instance Tool instance.
		 * @return array Array of capability flag strings.
		 */
		protected function extract_capability_flags_from_tool( $tool_instance ) {
			if ( ! $tool_instance || ! ( $tool_instance instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) ) {
				return array();
			}

			$capability_flags = $tool_instance->get_capability_flags();

			if ( ! is_array( $capability_flags ) || empty( $capability_flags ) ) {
				return array();
			}

			return $capability_flags;
		}

		/**
		 * Convert WP_Error to a serializable error format.
		 *
		 * Ensures WP_Error objects can be safely JSON-encoded and sent to chat clients.
		 * Preserves error information in a structured format that can be displayed to users.
		 *
		 * @since 1.1.0
		 *
		 * @param mixed $result Tool execution result, possibly a WP_Error.
		 * @return mixed Original result if not WP_Error, error array if WP_Error.
		 */
		protected function normalize_tool_result( $result ) {
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			// Convert WP_Error to a serializable array format.
			$error_data  = $result->get_error_data();
			$error_array = array(
				'error'   => true,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);

			// Include error data if available (e.g., HTTP status codes).
			if ( ! empty( $error_data ) ) {
				$error_array['data'] = $error_data;
			}

			return $error_array;
		}

		/**
		 * Snapshot the in-flight conversation into the Chat Continuation Store
		 * when the agentic loop is about to exit because a tool returned
		 * `{ async: true, status: 'pending' }`.
		 *
		 * One row is written per pending job_id so that when the async job
		 * later fires `wp_mcp_ai_job_completed`, the dispatcher can correlate
		 * back to the originating chat session and resume the LLM.
		 *
		 * No-op when the continuation subsystem is unavailable (defensive)
		 * or when the caller did not collect any pending jobs.
		 *
		 * @since 1.9.4
		 *
		 * @param array $pending_async_jobs  List of { job_id, tool_call_id, tool_name }.
		 * @param array $messages            Conversation messages at the point of exit.
		 * @param int   $assistant_id        Assistant identifier.
		 * @param int   $user_id             User identifier (0 for guests).
		 * @param array $options             Provider options (model, max_tokens, ...).
		 * @param array $transcript_context  Transcript context (session_key, ...).
		 */
		protected function snapshot_chat_continuation_on_async_pending(
			array $pending_async_jobs,
			array $messages,
			$assistant_id,
			$user_id,
			array $options,
			array $transcript_context
		) {
			if ( empty( $pending_async_jobs ) ) {
				return;
			}
			if ( ! class_exists( 'WP_MCP_AI_Chat_Continuation_Store' ) ) {
				return;
			}

			$session_key = isset( $transcript_context['session_key'] )
				? (string) $transcript_context['session_key']
				: '';

			$context_for_session = array(
				'assistant_id' => (int) $assistant_id,
				'user_id'      => (int) $user_id,
			);
			$chat_session_id     = '' !== $session_key
				? $session_key
				: WP_MCP_AI_Chat_Continuation_Store::generate_session_id( $context_for_session );

			$provider = '';
			if ( isset( $options['provider'] ) && is_string( $options['provider'] ) ) {
				$provider = $options['provider'];
			}
			$model = '';
			if ( isset( $options['model'] ) && is_string( $options['model'] ) ) {
				$model = $options['model'];
			}

			// Strip transient/large keys from options before persisting.
			$persisted_options = $options;
			unset(
				$persisted_options['attachments'],
				$persisted_options['memory_documents'],
				$persisted_options['tools']
			);

			$harness_profile = array();
			if ( isset( $options['harness_profile'] ) && is_array( $options['harness_profile'] ) ) {
				$harness_profile = $options['harness_profile'];
			}

			$now = time();

			foreach ( $pending_async_jobs as $pending ) {
				if ( ! is_array( $pending ) || empty( $pending['job_id'] ) ) {
					continue;
				}

				$payload = array(
					'chat_session_id' => $chat_session_id,
					'assistant_id'    => (int) $assistant_id,
					'user_id'         => (int) $user_id,
					'tool_call_id'    => isset( $pending['tool_call_id'] ) ? (string) $pending['tool_call_id'] : '',
					'tool_name'       => isset( $pending['tool_name'] ) ? (string) $pending['tool_name'] : '',
					'provider'        => $provider,
					'model'           => $model,
					'options'         => is_array( $persisted_options ) ? $persisted_options : array(),
					'harness_profile' => $harness_profile,
					'messages'        => $messages,
					'created_at'      => $now,
				);

				$stored = WP_MCP_AI_Chat_Continuation_Store::store(
					(string) $pending['job_id'],
					$payload
				);

				if ( is_wp_error( $stored ) ) {
					WP_MCP_AI_Logger::log_error(
						'chat_continuation_store_failed',
						array(
							'job_id'       => (string) $pending['job_id'],
							'assistant_id' => $assistant_id,
							'error_code'   => $stored->get_error_code(),
							'error'        => $stored->get_error_message(),
						)
					);
				}
			}
		}

		/**
		 * Recursively normalize data structures to ensure JSON serializability.
		 *
		 * Walks through arrays and objects to convert any WP_Error instances
		 * and WordPress objects (WP_Post, WP_Query, etc.) to serializable array format.
		 * This prevents JSON encoding failures when sending data through SSE streams
		 * or REST API responses.
		 *
		 * @since 1.1.0
		 *
		 * @param mixed $data Data to normalize, can be any type.
		 * @param int   $depth Current recursion depth (internal parameter for preventing infinite loops).
		 * @return mixed Normalized data with all non-serializable objects converted to arrays.
		 */
		protected function normalize_data_recursive( $data, $depth = 0 ) {
			// Prevent infinite recursion - limit depth to 20 levels.
			if ( $depth > 20 ) {
				return '[max recursion depth reached]';
			}

			// Handle WP_Error directly.
			if ( is_wp_error( $data ) ) {
				$normalized_error = $this->normalize_tool_result( $data );
				// Recursively normalize error data in case it contains objects.
				if ( isset( $normalized_error['data'] ) ) {
					$normalized_error['data'] = $this->normalize_data_recursive( $normalized_error['data'], $depth + 1 );
				}
				return $normalized_error;
			}

			// Handle arrays - recursively process each element.
			if ( is_array( $data ) ) {
				$normalized = array();
				foreach ( $data as $key => $value ) {
					$normalized[ $key ] = $this->normalize_data_recursive( $value, $depth + 1 );
				}
				return $normalized;
			}

			// Handle resources (file handles, database connections, etc.).
			// Resources cannot be JSON encoded and should be excluded.
			if ( is_resource( $data ) ) {
				return '[resource]';
			}

			// Handle objects - special handling for common WordPress types.
			if ( is_object( $data ) ) {
				// Handle WP_Post objects - extract only essential data.
				if ( $data instanceof WP_Post ) {
					return array(
						'ID'          => $data->ID,
						'post_title'  => $data->post_title,
						'post_type'   => $data->post_type,
						'post_status' => $data->post_status,
					);
				}

				// Handle WP_Query objects - don't serialize the entire query, just reference it.
				if ( $data instanceof WP_Query ) {
					return array(
						'query_type' => 'WP_Query',
						'post_count' => isset( $data->post_count ) ? $data->post_count : 0,
					);
				}

				// Handle WP_User objects.
				if ( $data instanceof WP_User ) {
					return array(
						'ID'           => $data->ID,
						'user_login'   => $data->user_login,
						'display_name' => $data->display_name,
					);
				}

				// For other objects, use get_object_vars() to avoid exposing private/protected properties.
				// This provides only public properties and avoids mangled property names like '\0ClassName\0propertyName'.
				// that can occur when casting objects with private/protected properties to arrays.
				// For stdClass and simple objects, this works well. For complex objects with magic methods
				// or ArrayAccess, they should be handled in specific cases above.
				$object_vars = get_object_vars( $data );
				return $this->normalize_data_recursive( $object_vars, $depth + 1 );
			}

			// Scalars pass through unchanged (strings, ints, floats, booleans, null).
			return $data;
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

		/**
		 * Handle a chat request using the framework-agnostic OOS engine.
		 *
		 * This is the bridge method that translates WordPress REST request
		 * data into the OOS ChatOrchestrator's expected input format and
		 * converts the response back to WP_REST_Response.
		 *
		 * Activated via ?engine=oos query parameter, X-WP-MCP-AI-Engine header,
		 * or the WP_MCP_AI_OOS_ENGINE constant.
		 *
		 * @param WP_REST_Request $request REST request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function handle_chat_request_oos( WP_REST_Request $request ) {
				// Detect team / profession prefixes before resolving to an assistant ID.
				// The OOS engine delegates multi-agent team orchestration to the
				// existing WordPress-specific workflow; profession-only requests
				// are enriched with profession metadata and then flow through OOS.
				$raw_assistant_id = $request->get_param( 'assistant_id' );
				$team_id          = $this->extract_team_id( $raw_assistant_id );
				$profession_id    = $this->extract_profession_id( $raw_assistant_id );

				// Unified team requests ("unified_team_123") use the full multi-agent
				// orchestration path. The OOS ChatOrchestrator does not yet implement
				// team coordination, so we delegate to the existing handler.
			if ( $team_id ) {
				return $this->handle_unified_team_request( $request, $team_id );
			}

				// Translate WordPress types to OOS domain types.
				$assistant_id = $this->resolve_assistant_id( $raw_assistant_id );

			// Reject requests with no assistant and no profession — matches the guard
			// in the non-OOS handle_chat_request path.
			if ( ! $assistant_id && ! $profession_id ) {
				return new WP_Error(
					'wp_mcp_ai_missing_assistant',
					__( 'No assistant was provided and no default assistant is configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

				$user_id = get_current_user_id();

				// Build assistant config from WordPress post meta.
				$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration(
					$assistant_id
				);

				// Merge profession configuration when testing a profession.
			if ( $profession_id ) {
				$assistant_config = $this->load_profession_configuration( $profession_id, $assistant_config );
			}

				// Validate assistant access.
			if ( $assistant_id ) {
				$assistant_post = $this->validate_assistant_access( $assistant_id );
				if ( is_wp_error( $assistant_post ) ) {
					return $assistant_post;
				}
			}

				// Build transcript context for session-key generation and recording.
				$transcript_context = array(
					'save_transcript' => $this->should_save_transcript( $request ),
					'session_key'     => $this->validator->sanitize_session_key_param(
						$request->get_param( 'session_key' )
					),
				);

				// Sanitize messages.
				$sanitized = $this->validator->sanitize_messages(
					$request->get_param( 'messages' )
				);

			if ( is_wp_error( $sanitized ) ) {
				return $sanitized;
			}

				$messages = $sanitized['messages'];

				// Inject system prompt from assistant config as the first message.
				// The OOS ChatOrchestrator passes messages directly to the provider;
				// it does not auto-inject the system prompt from $assistantConfig.
			if ( ! empty( $assistant_config['system_prompt'] ) ) {
				$has_system = false;
				foreach ( $messages as $msg ) {
					if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
						$has_system = true;
						break;
					}
				}
				if ( ! $has_system ) {
					array_unshift(
						$messages,
						array(
							'role'    => 'system',
							'content' => $assistant_config['system_prompt'],
						)
					);
				}
			}

										// Merge additional_tools from the client request.
												$additional_tools = $request->get_param( 'additional_tools' );
			if ( ! empty( $additional_tools ) && is_array( $additional_tools ) ) {
					$additional_tools = array_filter( array_map( 'sanitize_key', $additional_tools ) );
				if ( ! empty( $additional_tools ) ) {
					if ( ! isset( $assistant_config['tools'] ) || ! is_array( $assistant_config['tools'] ) ) {
						$assistant_config['tools'] = array();
					}
												$assistant_config['tools'] = array_values(
													array_unique( array_merge( $assistant_config['tools'], $additional_tools ) )
												);
				}
			}

												// Build options.
												$options     = array();
												$raw_options = $request->get_param( 'options' );
			if ( is_array( $raw_options ) ) {
					$options = $raw_options;
			}

												// Provider and model from assistant config.
			if ( ! empty( $assistant_config['provider'] ) ) {
				$options['provider'] = $assistant_config['provider'];
			}
			if ( ! empty( $assistant_config['model'] ) ) {
				$options['model'] = $assistant_config['model'];
			}

			// Fall back to global default provider/model when the assistant config
			// does not specify them. Matches the fallback chain in sanitize_options()
			// used by the non-OOS handler.
			if ( empty( $options['provider'] ) || empty( $options['model'] ) ) {
				$global_settings = WP_MCP_AI_Admin_Settings::get_settings();
				if ( empty( $options['provider'] ) && ! empty( $global_settings['default_provider'] ) ) {
					$options['provider'] = sanitize_key( $global_settings['default_provider'] );
				}
				if ( empty( $options['model'] ) && ! empty( $global_settings['default_model'] ) ) {
					$options['model'] = sanitize_text_field( $global_settings['default_model'] );
				}
			}

			// Pass through temperature and max_tokens from assistant config if not
				// already set in request options.
			if ( ! isset( $options['temperature'] ) && isset( $assistant_config['temperature'] ) && null !== $assistant_config['temperature'] ) {
				$options['temperature'] = (float) $assistant_config['temperature'];
			}
			if ( ! isset( $options['max_tokens'] ) && ! empty( $assistant_config['max_tokens'] ) ) {
				$options['max_tokens'] = (int) $assistant_config['max_tokens'];
			}

				// Inject professional prompt into system message when present.
				$professional_prompt = $request->get_param( 'professional_prompt' );
			if ( ! empty( $professional_prompt ) && is_string( $professional_prompt ) ) {
				$has_system = false;
				$system_idx = -1;
				foreach ( $messages as $idx => $msg ) {
					if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
						$has_system = true;
						$system_idx = $idx;
						break;
					}
				}
				if ( $has_system && $system_idx >= 0 ) {
					$messages[ $system_idx ]['content'] = $professional_prompt . "\n\n---\n\n# Additional Instructions\n\n" . $messages[ $system_idx ]['content'];
				} else {
					array_unshift(
						$messages,
						array(
							'role'    => 'system',
							'content' => $professional_prompt,
						)
					);
				}
			}

				WP_MCP_AI_Logger::log_event(
					'oos_engine_chat',
					'OOS engine handling chat request',
					array(
						'assistant_id'  => $assistant_id,
						'message_count' => count( $messages ),
						'provider'      => $options['provider'] ?? 'default',
						'model'         => $options['model'] ?? 'default',
					)
				);

			try {
				// Delegate to the framework-agnostic orchestrator.
				$orchestrator = wp_mcp_ai_oos_orchestrator();

				// Check if the client requests SSE streaming.
				$want_stream = $this->request_wants_event_stream( $request )
					|| $request->get_param( 'stream' );

				if ( $want_stream && method_exists( $orchestrator, 'handleChatStreaming' ) ) {
					// handleChatStreaming() sends SSE headers, status events,
					// tool-execution progress, text chunks, the final message
					// event, and the [DONE] marker.  After it returns, the
					// stream is complete — we only need to record the transcript
					// and exit.
					$result = $orchestrator->handleChatStreaming(
						messages: $messages,
						assistantConfig: $assistant_config,
						userId: $user_id,
						assistantId: $assistant_id,
						options: $options,
					);

					// Record the transcript.
					if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
						WP_MCP_AI_Chat_Transcript_Recorder::record(
							$assistant_id,
							$messages,
							$options,
							$result['response'] ?? array(),
							$request,
							$user_id,
							$transcript_context
						);
					}

					exit;
				}

				// Non-streaming path.
				$result = $orchestrator->handleChat(
					messages: $messages,
					assistantConfig: $assistant_config,
					userId: $user_id,
					assistantId: $assistant_id,
					options: $options,
				);
			} catch ( \Exception $e ) {
				WP_MCP_AI_Logger::log_error(
					'oos_engine_exception',
					'Exception in OOS chat handler: ' . $e->getMessage(),
					array(
						'exception' => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);

				return new WP_Error(
					'oos_engine_error',
					sprintf(
						/* translators: %s: error message */
						__( 'The OOS engine encountered an error: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					),
					array( 'status' => 500 )
				);
			} catch ( \Error $e ) {
				WP_MCP_AI_Logger::log_error(
					'oos_engine_fatal_error',
					'Fatal error in OOS chat handler: ' . $e->getMessage(),
					array(
						'error' => $e->getMessage(),
						'file'  => $e->getFile(),
						'line'  => $e->getLine(),
						'trace' => $e->getTraceAsString(),
					)
				);

				return new WP_Error(
					'oos_engine_fatal_error',
					__( 'A fatal error occurred in the OOS engine. Please check the plugin configuration.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

				// Record the transcript and get the session key.
				$recorded_session_key = null;
			if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
				$recorded_session_key = WP_MCP_AI_Chat_Transcript_Recorder::record(
					$assistant_id,
					$messages,
					$options,
					$result['response'] ?? array(),
					$request,
					$user_id,
					$transcript_context
				);
			}

				// Translate OOS response back to WordPress REST format.
				$payload = array(
					'assistant_id' => $assistant_id,
					'data'         => $result['response'] ?? array(),
				);

				if ( $recorded_session_key ) {
					$payload['sessionKey'] = $recorded_session_key;
				}

				if ( ! empty( $result['tool_results'] ) ) {
					$payload['tool_results'] = $result['tool_results'];
				}

				if ( ! empty( $result['cost'] ) ) {
					$payload['cost'] = $result['cost'];
				}

				if ( ! empty( $result['iterations'] ) ) {
					$payload['iterations'] = $result['iterations'];
				}

				return rest_ensure_response( $payload );
		}
	}
}
