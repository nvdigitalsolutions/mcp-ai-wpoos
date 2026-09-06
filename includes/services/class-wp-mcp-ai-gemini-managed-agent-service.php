<?php
/**
 * Gemini Managed Agent Service
 *
 * Provides an interface for running long-horizon agentic tasks using Gemini's
 * Managed Agents API (announced at Google I/O 2026). A single API call spins
 * up a full agent inside an isolated Linux container with:
 *
 * - Persistent filesystem (files survive across multi-turn sessions)
 * - Tool use (function calling with native tool execution)
 * - Code execution (Python, JavaScript, shell)
 * - State persistence (session IDs for continuing work)
 *
 * The agent reasons, plans, executes tools, writes code, and iterates toward
 * completing complex multi-step goals — all within a managed environment.
 *
 * This is distinct from the existing run_with_tools() loop which is stateless
 * and does not provide a container or code execution environment.
 *
 * Google I/O 2026 (May 19, 2026):
 * - Managed Agents available via Gemini API in Google AI Studio
 * - Designed for Gemini 3.5 Flash (model defaults to it)
 * - Runs inside isolated Linux container
 * - Files and state persist across follow-up calls
 * - Integrates with Antigravity harness
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Gemini Managed Agent Service.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Gemini_Managed_Agent_Service {

	/**
	 * Default model for managed agents.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'gemini-3.6-flash';

	/**
	 * API endpoint for managed agent operations.
	 *
	 * @var string
	 */
	const AGENT_CREATE_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/agents';

	/**
	 * Default timeout for agent operations in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 300;

	/**
	 * Maximum agent timeout in seconds.
	 *
	 * @var int
	 */
	const MAX_TIMEOUT = 3600;

	/**
	 * Transient prefix for agent session storage.
	 *
	 * @var string
	 */
	const SESSION_PREFIX = 'wp_mcp_ai_agent_session_';

	/**
	 * Maximum session age in seconds (24 hours).
	 *
	 * @var int
	 */
	const MAX_SESSION_AGE = 86400;

	/**
	 * Supported code languages.
	 *
	 * @var array
	 */
	const SUPPORTED_LANGUAGES = array( 'python', 'javascript', 'bash', 'shell' );

	/**
	 * Check if Managed Agents API is available.
	 *
	 * The Managed Agents API was announced at Google I/O 2026.
	 * This gate prevents errors until the endpoint is live.
	 *
	 * @return bool True if Managed Agents API is available.
	 */
	public static function is_managed_agents_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( ! empty( $settings['enable_managed_agents'] ) ) {
			return true;
		}

		/**
		 * Filter: wp_mcp_ai_managed_agents_available
		 *
		 * @since 1.2.0
		 * @param bool $available Whether Managed Agents API is available.
		 */
		return apply_filters( 'wp_mcp_ai_managed_agents_available', false );
	}

	/**
	 * Get the API key.
	 *
	 * @return string API key or empty string.
	 */
	protected function get_api_key() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$key      = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			$key = WP_MCP_AI_Credential_Resolver::get_api_key( 'gemini' ) ?? '';
		}

		return $key;
	}

	/**
	 * Create a new managed agent session.
	 *
	 * Initializes an isolated container with the specified tools, system prompt,
	 * and working directory. Returns a session ID for subsequent operations.
	 *
	 * @param array $args {
	 *     Session configuration.
	 *
	 *     @type string $system_prompt    System instructions for the agent.
	 *     @type array  $tool_slugs       Array of tool slugs to make available.
	 *     @type string $working_dir      Working directory path inside container.
	 *     @type string $model            Model to use (default: gemini-3.6-flash).
	 *     @type int    $max_iterations   Maximum agent loop iterations (default: 10).
	 *     @type int    $timeout          Timeout in seconds (default: 300).
	 * }
	 * @return array|WP_Error Session info or error.
	 */
	public function create_session( array $args ) {
		if ( ! self::is_managed_agents_available() ) {
			return new WP_Error(
				'wp_mcp_ai_managed_agents_unavailable',
				__( 'Managed Agents API is not yet available. It will be accessible in the coming weeks per Google I/O 2026.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$session_id    = $this->generate_session_id();
		$system_prompt = isset( $args['system_prompt'] ) ? sanitize_textarea_field( $args['system_prompt'] ) : '';
		$tool_slugs    = isset( $args['tool_slugs'] ) ? (array) $args['tool_slugs'] : array();
		$working_dir   = isset( $args['working_dir'] ) ? sanitize_text_field( $args['working_dir'] ) : '/workspace';
		$model         = isset( $args['model'] ) ? sanitize_text_field( $args['model'] ) : self::DEFAULT_MODEL;
		$max_iter      = isset( $args['max_iterations'] ) ? absint( $args['max_iterations'] ) : 10;
		$timeout       = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT;

		if ( $timeout > self::MAX_TIMEOUT ) {
			$timeout = self::MAX_TIMEOUT;
		}

		if ( $max_iter > 100 ) {
			$max_iter = 100;
		}

		// Build tool definitions from slugs.
		$tools = $this->resolve_tool_definitions( $tool_slugs );

		// Build the agent creation payload.
		$payload = array(
			'displayName'  => 'NV oOS Agent',
			'systemPrompt' => $system_prompt,
			'model'        => $model,
			'tools'        => $tools,
			'config'       => array(
				'maxIterations'    => $max_iter,
				'timeoutSeconds'   => $timeout,
				'workingDirectory' => $working_dir,
				'codeExecution'    => array(
					'enabled'   => true,
					'languages' => self::SUPPORTED_LANGUAGES,
				),
			),
		);

		/**
		 * Filter: wp_mcp_ai_managed_agent_create_payload
		 *
		 * @since 1.2.0
		 * @param array  $payload Agent creation payload.
		 * @param string $session_id Generated session ID.
		 * @param array  $args     Original arguments.
		 */
		$payload = apply_filters( 'wp_mcp_ai_managed_agent_create_payload', $payload, $session_id, $args );

		// Attempt API call.
		$result = $this->api_request( 'POST', self::AGENT_CREATE_ENDPOINT, $payload );

		if ( is_wp_error( $result ) ) {
			// If API is not yet available, store a local session as fallback.
			if ( 'wp_mcp_ai_managed_agents_unavailable' === $result->get_error_code() ) {
				return $this->create_local_session( $session_id, $args );
			}
			return $result;
		}

		// Store session data linking our session_id to the API's agent ID.
		$session_data = array(
			'session_id'    => $session_id,
			'agent_id'      => isset( $result['name'] ) ? $result['name'] : '',
			'model'         => $model,
			'system_prompt' => $system_prompt,
			'tool_slugs'    => $tool_slugs,
			'working_dir'   => $working_dir,
			'created_at'    => time(),
			'status'        => 'active',
			'is_remote'     => true,
		);

		set_transient( self::SESSION_PREFIX . $session_id, $session_data, self::MAX_SESSION_AGE );

		WP_MCP_AI_Logger::log_event(
			'managed_agent_session_created',
			'Managed agent session created',
			array(
				'session_id' => $session_id,
				'model'      => $model,
				'tool_count' => count( $tool_slugs ),
			)
		);

		return array(
			'session_id'      => $session_id,
			'model'           => $model,
			'max_iterations'  => $max_iter,
			'timeout'         => $timeout,
			'tools_available' => $tool_slugs,
			'created_at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Run a task within an existing agent session.
	 *
	 * Sends a task description to the agent and returns the result.
	 * The agent has access to all tools and its container filesystem.
	 *
	 * @param array $args {
	 *     Task arguments.
	 *
	 *     @type string $session_id   Existing session ID (required).
	 *     @type string $task         Task description (required).
	 *     @type array  $files        Optional files to place in container (attachment IDs).
	 *     @type int    $timeout      Override timeout in seconds.
	 * }
	 * @return array|WP_Error Task result or error.
	 */
	public function run_task( array $args ) {
		if ( ! self::is_managed_agents_available() ) {
			return new WP_Error(
				'wp_mcp_ai_managed_agents_unavailable',
				__( 'Managed Agents API is not yet available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$session_id = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
		$task       = isset( $args['task'] ) ? sanitize_textarea_field( $args['task'] ) : '';

		if ( empty( $session_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_session',
				__( 'A session ID is required. Create a session first with create_managed_agent_session.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $task ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_task',
				__( 'A task description is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$session_data = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! $session_data || ! is_array( $session_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_session_not_found',
				__( 'Agent session not found or expired. Create a new session.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$timeout = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT;

		if ( $timeout > self::MAX_TIMEOUT ) {
			$timeout = self::MAX_TIMEOUT;
		}

		// Build task payload.
		$payload = array(
			'agent'  => $session_data['agent_id'],
			'task'   => $task,
			'config' => array(
				'timeoutSeconds' => $timeout,
			),
		);

		// Add files if provided.
		if ( ! empty( $args['files'] ) && is_array( $args['files'] ) ) {
			$payload['files'] = $this->prepare_files( $args['files'] );
		}

		/**
		 * Filter: wp_mcp_ai_managed_agent_task_payload
		 *
		 * @since 1.2.0
		 * @param array  $payload    Task payload.
		 * @param string $session_id Session ID.
		 * @param array  $args       Original arguments.
		 */
		$payload = apply_filters( 'wp_mcp_ai_managed_agent_task_payload', $payload, $session_id, $args );

		$endpoint = self::AGENT_CREATE_ENDPOINT . '/' . rawurlencode( $session_data['agent_id'] ) . ':runTask';

		WP_MCP_AI_Logger::log_event(
			'managed_agent_task_start',
			'Starting managed agent task',
			array(
				'session_id' => $session_id,
				'task'       => substr( $task, 0, 120 ),
			)
		);

		$result = $this->api_request( 'POST', $endpoint, $payload, $timeout );

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Managed agent task failed',
				array(
					'session_id' => $session_id,
					'error'      => $result->get_error_message(),
				)
			);
			return $result;
		}

		// Update session last activity.
		$session_data['last_activity'] = time();
		set_transient( self::SESSION_PREFIX . $session_id, $session_data, self::MAX_SESSION_AGE );

		WP_MCP_AI_Logger::log_event(
			'managed_agent_task_complete',
			'Managed agent task completed',
			array( 'session_id' => $session_id )
		);

		return $this->normalise_task_result( $result, $session_id );
	}

	/**
	 * Get session status.
	 *
	 * @param string $session_id Session ID.
	 * @return array|WP_Error Session status or error.
	 */
	public function get_session( $session_id ) {
		$session_data = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! $session_data || ! is_array( $session_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_session_not_found',
				__( 'Agent session not found or expired.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'session_id'    => $session_data['session_id'],
			'model'         => $session_data['model'],
			'status'        => $session_data['status'],
			'tools'         => $session_data['tool_slugs'],
			'created_at'    => gmdate( 'c', $session_data['created_at'] ),
			'last_activity' => isset( $session_data['last_activity'] )
				? gmdate( 'c', $session_data['last_activity'] )
				: null,
		);
	}

	/**
	 * List active agent sessions.
	 *
	 * @return array List of active session summaries.
	 */
	public function list_sessions() {
		global $wpdb;

		$prefix     = self::SESSION_PREFIX;
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%'
			)
		);

		$sessions = array();

		foreach ( $transients as $row ) {
			$name = str_replace( '_transient_', '', $row->option_name );
			$data = maybe_unserialize( $row->option_value );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$sessions[] = array(
				'session_id' => $data['session_id'],
				'model'      => $data['model'],
				'status'     => $data['status'],
				'created_at' => gmdate( 'c', $data['created_at'] ),
			);
		}

		return $sessions;
	}

	/**
	 * Terminate an agent session.
	 *
	 * @param string $session_id Session ID to terminate.
	 * @return array|WP_Error Result or error.
	 */
	public function terminate_session( $session_id ) {
		$session_data = get_transient( self::SESSION_PREFIX . $session_id );

		if ( ! $session_data || ! is_array( $session_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_session_not_found',
				__( 'Agent session not found or already terminated.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// If remote session, attempt API termination.
		if ( ! empty( $session_data['is_remote'] ) && ! empty( $session_data['agent_id'] ) ) {
			$endpoint = self::AGENT_CREATE_ENDPOINT . '/' . rawurlencode( $session_data['agent_id'] ) . ':terminate';
			$this->api_request( 'POST', $endpoint, array() );
		}

		delete_transient( self::SESSION_PREFIX . $session_id );

		WP_MCP_AI_Logger::log_event(
			'managed_agent_session_terminated',
			'Agent session terminated',
			array( 'session_id' => $session_id )
		);

		return array(
			'session_id' => $session_id,
			'terminated' => true,
		);
	}

	/**
	 * Resolve tool slugs to Gemini tool definitions.
	 *
	 * @param array $tool_slugs Array of tool slugs.
	 * @return array Tool definitions in Gemini format.
	 */
	protected function resolve_tool_definitions( array $tool_slugs ) {
		$definitions = array();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $definitions;
		}

		$registry  = WP_MCP_AI_Tool_Registry::instance();
		$all_tools = $registry->get_tools();

		foreach ( $tool_slugs as $slug ) {
			$slug = sanitize_key( $slug );

			if ( ! isset( $all_tools[ $slug ] ) ) {
				continue;
			}

			$tool = $all_tools[ $slug ];

			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$schema = $tool->get_parameters_schema();

			if ( ! is_array( $schema ) ) {
				$schema = array(
					'type'       => 'object',
					// Empty stdClass encodes as `{}`; an empty PHP array would
					// encode as `[]`, which strict providers reject.
					'properties' => new stdClass(),
				);
			}

			$definitions[] = array(
				'name'        => $slug,
				'description' => $tool->get_description(),
				'parameters'  => $schema,
			);
		}

		return $definitions;
	}

	/**
	 * Prepare attachment files for inclusion in the agent container.
	 *
	 * @param array $attachment_ids Array of WordPress attachment IDs.
	 * @return array File objects.
	 */
	protected function prepare_files( array $attachment_ids ) {
		$files = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$file_path = get_attached_file( absint( $attachment_id ) );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			$mime_type = get_post_mime_type( $attachment_id );
			$file_data = base64_encode( file_get_contents( $file_path ) ); // phpcs:ignore

			$files[] = array(
				'name'     => basename( $file_path ),
				'mimeType' => $mime_type ? $mime_type : 'application/octet-stream',
				'data'     => $file_data,
			);
		}

		return $files;
	}

	/**
	 * Make an API request.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint URL.
	 * @param array  $payload  Request payload.
	 * @param int    $timeout  Request timeout.
	 * @return array|WP_Error Response data or error.
	 */
	protected function api_request( $method, $endpoint, $payload = array(), $timeout = 60 ) {
		$api_key = $this->get_api_key();

		$request_args = array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'timeout' => $timeout,
		);

		if ( ! empty( $payload ) ) {
			$request_args['body'] = wp_json_encode( $payload );
		}

		$response = 'GET' === $method
			? wp_remote_get( $endpoint, $request_args )
			: wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$error_message = __( 'Managed agent request failed.', 'mcp-ai-wpoos' );
			$error_code    = 'wp_mcp_ai_agent_request_failed';

			if ( isset( $data['error']['message'] ) ) {
				$api_error     = $data['error']['message'];
				$error_message = $api_error;

				if ( false !== stripos( $api_error, 'not found' ) ) {
					$error_code    = 'wp_mcp_ai_managed_agents_unavailable';
					$error_message = __( 'Managed Agents API is not yet available. It will be accessible in the coming weeks per Google I/O 2026.', 'mcp-ai-wpoos' );
				} elseif ( false !== stripos( $api_error, 'quota' ) ) {
					$error_code = 'wp_mcp_ai_quota_exceeded';
				}
			}

			return new WP_Error( $error_code, $error_message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Normalise task result into a consistent format.
	 *
	 * @param array  $result     Raw API response.
	 * @param string $session_id Session ID.
	 * @return array Normalised result.
	 */
	protected function normalise_task_result( $result, $session_id ) {
		$output = array(
			'session_id' => $session_id,
			'success'    => true,
			'message'    => '',
			'artifacts'  => array(),
			'iterations' => 0,
		);

		// Extract the agent's final message.
		if ( isset( $result['response']['message'] ) ) {
			$output['message'] = wp_kses_post( $result['response']['message'] );
		} elseif ( isset( $result['response']['text'] ) ) {
			$output['message'] = wp_kses_post( $result['response']['text'] );
		}

		// Extract artifacts (files, code outputs, etc.).
		if ( isset( $result['response']['artifacts'] ) && is_array( $result['response']['artifacts'] ) ) {
			$output['artifacts'] = $result['response']['artifacts'];
		}

		// Extract iteration count.
		if ( isset( $result['metadata']['iterations'] ) ) {
			$output['iterations'] = absint( $result['metadata']['iterations'] );
		}

		// Extract code execution outputs.
		if ( isset( $result['response']['codeExecutionResults'] ) ) {
			$output['code_outputs'] = $result['response']['codeExecutionResults'];
		}

		// Extract tool call history.
		if ( isset( $result['response']['toolCalls'] ) ) {
			$output['tool_calls'] = array();
			foreach ( $result['response']['toolCalls'] as $call ) {
				$output['tool_calls'][] = array(
					'tool'      => isset( $call['name'] ) ? sanitize_text_field( $call['name'] ) : '',
					'arguments' => isset( $call['args'] ) ? $call['args'] : array(),
					'result'    => isset( $call['result'] ) ? $call['result'] : null,
				);
			}
		}

		return $output;
	}

	/**
	 * Create a local session for when the API is not yet available.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $args       Original creation arguments.
	 * @return array Session info.
	 */
	protected function create_local_session( $session_id, $args ) {
		$session_data = array(
			'session_id'    => $session_id,
			'agent_id'      => '',
			'model'         => isset( $args['model'] ) ? sanitize_text_field( $args['model'] ) : self::DEFAULT_MODEL,
			'system_prompt' => isset( $args['system_prompt'] ) ? sanitize_textarea_field( $args['system_prompt'] ) : '',
			'tool_slugs'    => isset( $args['tool_slugs'] ) ? (array) $args['tool_slugs'] : array(),
			'working_dir'   => isset( $args['working_dir'] ) ? sanitize_text_field( $args['working_dir'] ) : '/workspace',
			'created_at'    => time(),
			'status'        => 'active',
			'is_remote'     => false,
		);

		set_transient( self::SESSION_PREFIX . $session_id, $session_data, self::MAX_SESSION_AGE );

		WP_MCP_AI_Logger::log_event(
			'managed_agent_local_session',
			'Created local managed agent session (API not yet available)',
			array( 'session_id' => $session_id )
		);

		return array(
			'session_id'      => $session_id,
			'model'           => $session_data['model'],
			'max_iterations'  => isset( $args['max_iterations'] ) ? absint( $args['max_iterations'] ) : 10,
			'timeout'         => isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT,
			'tools_available' => $session_data['tool_slugs'],
			'created_at'      => gmdate( 'c' ),
			'status'          => 'local_fallback',
			'note'            => __( 'Managed Agents API is not yet available. The session is stored locally and will be usable when the API is live.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Generate a unique session ID.
	 *
	 * @return string Session ID.
	 */
	protected function generate_session_id() {
		return 'agent_' . wp_generate_password( 24, false, false );
	}
}
