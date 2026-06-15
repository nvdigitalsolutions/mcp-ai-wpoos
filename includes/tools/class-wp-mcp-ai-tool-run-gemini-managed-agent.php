<?php
/**
 * Tool for running tasks with Gemini Managed Agents.
 *
 * Creates and manages agent sessions with isolated Linux containers,
 * persistent filesystems, code execution, and multi-turn state.
 * Agents reason, plan, call tools, and iterate toward complex goals.
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

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-managed-agent-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Run Gemini Managed Agent Tool.
 */
class WP_MCP_AI_Tool_Run_Gemini_Managed_Agent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'run_gemini_managed_agent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Run Gemini Managed Agent', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates and runs tasks with a managed AI agent powered by Gemini 3.5 Flash. The agent operates in an isolated Linux container with persistent files, code execution (Python, JavaScript, and shell), and access to all NV oOS tools. It can plan, iterate, write code, call tools, and complete complex multi-step workflows. Sessions persist for 24 hours — continue work by passing the session_id. Use the "create" operation first to set up a session, then "run" to execute tasks, "status" to check, or "terminate" to clean up.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation'      => array(
					'type'        => 'string',
					'description' => __( 'Operation to perform: "create" a new agent session, "run" a task in an existing session, "status" to check session state, "list" active sessions, or "terminate" a session.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'create', 'run', 'status', 'list', 'terminate' ),
				),
				'session_id'     => array(
					'type'        => 'string',
					'description' => __( 'Session ID from a previous create operation. Required for "run", "status", and "terminate" operations.', 'mcp-ai-wpoos' ),
				),
				'task'           => array(
					'type'        => 'string',
					'description' => __( 'The task to execute. Be specific about goals, constraints, and expected outputs. Required for "create" and "run" operations. Examples: "Analyze the sales data and create a summary report", "Refactor the Python code to use async/await", "Research the top 5 competitors and compare their pricing".', 'mcp-ai-wpoos' ),
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'System instructions defining the agent\'s role, personality, and constraints. Optional for "create" operation. Example: "You are a data analyst. Always cite sources and show your reasoning."', 'mcp-ai-wpoos' ),
				),
				'tool_slugs'     => array(
					'type'        => 'array',
					'description' => __( 'List of tool slugs the agent can use. If empty, all available tools are accessible. Example: ["get_post", "create_post", "search_posts"].', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'max_iterations' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum agent loop iterations (1-100). Default: 10. Higher values allow more complex multi-step workflows but take longer.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
				),
				'timeout'        => array(
					'type'        => 'integer',
					'description' => __( 'Timeout in seconds (30-3600). Default: 300 (5 minutes). Increase for complex tasks that may take longer.', 'mcp-ai-wpoos' ),
					'minimum'     => 30,
					'maximum'     => 3600,
					'default'     => 300,
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Model to use. Defaults to gemini-3.5-flash which is optimized for agentic workflows.', 'mcp-ai-wpoos' ),
					'default'     => 'gemini-3.5-flash',
				),
			),
			'required'   => array( 'operation' ),
		);
	}

	/**
	 * Execute the Gemini Managed Agent tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( $arguments, $context ) {
		$operation     = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$session_id    = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$task          = isset( $arguments['task'] ) ? sanitize_textarea_field( $arguments['task'] ) : '';
		$system_prompt = isset( $arguments['system_prompt'] ) ? sanitize_textarea_field( $arguments['system_prompt'] ) : '';
		$tool_slugs    = isset( $arguments['tool_slugs'] ) ? array_map( 'sanitize_key', (array) $arguments['tool_slugs'] ) : array();
		$max_iter      = isset( $arguments['max_iterations'] ) ? absint( $arguments['max_iterations'] ) : 10;
		$timeout       = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 300;
		$model         = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'gemini-3.5-flash';

		$service = new WP_MCP_AI_Gemini_Managed_Agent_Service();

		switch ( $operation ) {
			case 'create':
				return $this->handle_create( $service, $task, $system_prompt, $tool_slugs, $max_iter, $timeout, $model );

			case 'run':
				return $this->handle_run( $service, $session_id, $task, $timeout );

			case 'status':
				return $this->handle_status( $service, $session_id );

			case 'list':
				return $this->handle_list( $service );

			case 'terminate':
				return $this->handle_terminate( $service, $session_id );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_operation',
					sprintf(
						/* translators: %s: operation name */
						__( 'Invalid operation: %s. Valid operations: create, run, status, list, terminate.', 'mcp-ai-wpoos' ),
						esc_html( $operation )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Handle "create" operation — new agent session.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service      Service instance.
	 * @param string                                 $task         Task description.
	 * @param string                                 $system_prompt System instructions.
	 * @param array                                  $tool_slugs   Allowed tool slugs.
	 * @param int                                    $max_iter     Max iterations.
	 * @param int                                    $timeout      Timeout seconds.
	 * @param string                                 $model        Model ID.
	 * @return array|WP_Error
	 */
	protected function handle_create( $service, $task, $system_prompt, $tool_slugs, $max_iter, $timeout, $model ) {
		$create_args = array(
			'system_prompt'  => $system_prompt,
			'tool_slugs'     => $tool_slugs,
			'model'          => $model,
			'max_iterations' => $max_iter,
			'timeout'        => $timeout,
		);

		$result = $service->create_session( $create_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If a task was provided, run it immediately.
		if ( ! empty( $task ) ) {
			$task_result = $service->run_task(
				array(
					'session_id' => $result['session_id'],
					'task'       => $task,
					'timeout'    => $timeout,
				)
			);

			if ( is_wp_error( $task_result ) ) {
				// Session was created but task failed — return both.
				return $this->build_chat_response(
					sprintf(
						/* translators: 1: session ID, 2: error message */
						__( 'Session created (ID: %1$s) but task failed: %2$s. The session is still active.', 'mcp-ai-wpoos' ),
						esc_html( $result['session_id'] ),
						esc_html( $task_result->get_error_message() )
					),
					array_merge(
						$result,
						array( 'task_error' => $task_result->get_error_message() )
					)
				);
			}

			return $this->build_chat_response(
				isset( $task_result['message'] ) ? $task_result['message'] : __( 'Task completed.', 'mcp-ai-wpoos' ),
				array_merge( $result, $task_result )
			);
		}

		return $this->build_chat_response(
			sprintf(
				/* translators: %s: session ID */
				__( 'Agent session created. Session ID: %s. Use this ID with the "run" operation to execute tasks.', 'mcp-ai-wpoos' ),
				esc_html( $result['session_id'] )
			),
			$result
		);
	}

	/**
	 * Handle "run" operation — execute task in existing session.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service   Service instance.
	 * @param string                                 $session_id Session ID.
	 * @param string                                 $task      Task description.
	 * @param int                                    $timeout   Timeout seconds.
	 * @return array|WP_Error
	 */
	protected function handle_run( $service, $session_id, $task, $timeout ) {
		if ( empty( $session_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_session',
				__( 'Session ID is required for the "run" operation.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $task ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_task',
				__( 'A task description is required for the "run" operation.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$result = $service->run_task(
			array(
				'session_id' => $session_id,
				'task'       => $task,
				'timeout'    => $timeout,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Check if it's an availability error and offer fallback.
			if ( 'wp_mcp_ai_managed_agents_unavailable' === $result->get_error_code() ) {
				return $this->build_chat_response(
					$result->get_error_message(),
					array(
						'session_id' => $session_id,
						'status'     => 'unavailable',
						'suggestion' => __( 'The Managed Agents API will be available in the coming weeks per Google I/O 2026. In the meantime, use individual tools directly.', 'mcp-ai-wpoos' ),
					)
				);
			}

			return $result;
		}

		return $this->build_chat_response(
			isset( $result['message'] ) ? $result['message'] : __( 'Task completed.', 'mcp-ai-wpoos' ),
			$result
		);
	}

	/**
	 * Handle "status" operation.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service   Service instance.
	 * @param string                                 $session_id Session ID.
	 * @return array|WP_Error
	 */
	protected function handle_status( $service, $session_id ) {
		if ( empty( $session_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_session',
				__( 'Session ID is required for the "status" operation.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$result = $service->get_session( $session_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->build_chat_response(
			sprintf(
				/* translators: %s: session status */
				__( 'Session status: %s', 'mcp-ai-wpoos' ),
				esc_html( $result['status'] )
			),
			$result
		);
	}

	/**
	 * Handle "list" operation.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service Service instance.
	 * @return array
	 */
	protected function handle_list( $service ) {
		$sessions = $service->list_sessions();

		if ( empty( $sessions ) ) {
			return $this->build_chat_response(
				__( 'No active agent sessions.', 'mcp-ai-wpoos' ),
				array( 'sessions' => array() )
			);
		}

		return $this->build_chat_response(
			sprintf(
				/* translators: %d: number of sessions */
				_n(
					'%d active agent session.',
					'%d active agent sessions.',
					count( $sessions ),
					'mcp-ai-wpoos'
				),
				count( $sessions )
			),
			array( 'sessions' => $sessions )
		);
	}

	/**
	 * Handle "terminate" operation.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service   Service instance.
	 * @param string                                 $session_id Session ID.
	 * @return array|WP_Error
	 */
	protected function handle_terminate( $service, $session_id ) {
		if ( empty( $session_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_session',
				__( 'Session ID is required for the "terminate" operation.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$result = $service->terminate_session( $session_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->build_chat_response(
			__( 'Agent session terminated.', 'mcp-ai-wpoos' ),
			$result
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'background-only'  => true,
			'token_multiplier' => 10.0,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'providers'    => array( 'gemini' ),
			'capabilities' => array( 'function-calling' ),
			'required'     => true,
		);
	}
}
