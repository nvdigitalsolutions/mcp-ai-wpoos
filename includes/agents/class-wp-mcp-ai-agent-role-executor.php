<?php
/**
 * Executor Agent Role
 *
 * Performs specific operations using available tools.
 * Inspired by DeepSeek V4's specialized execution patterns.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executor Agent Role class
 *
 * Responsible for:
 * - Executing assigned tasks and subtasks
 * - Using specialized tools effectively
 * - Returning structured results
 * - Handling errors gracefully
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Role_Executor extends WP_MCP_AI_Agent_Role_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'executor';
		$this->role_name        = __( 'Executor', 'mcp-ai-wpoos' );
		$this->role_description = __( 'Executes specific tasks using available tools and returns structured results.', 'mcp-ai-wpoos' );

		$this->capabilities = array(
			'requires-tools',
			'autonomous',
		);

		// Executor agents benefit from all available tools.
		$this->recommended_tools = array(
			'web_search',
			'crawl4ai',
			'get_recent_posts',
			'create_post',
			'save_post',
		);
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return __(
			'You are an Executor agent responsible for performing specific tasks using the tools available to you. ' .
			'When assigned a task, focus on executing it efficiently and accurately. ' .
			'Use the appropriate tools for the job and return structured, detailed results. ' .
			'If you encounter errors, handle them gracefully and provide clear error information.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Execute an assigned task
	 *
	 * Performs the task using available tools and returns results.
	 *
	 * @param array $task Task data including description, type, and parameters.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Task result or error.
	 */
	public function execute_role_task( $task, $context ) {
		// Validate inputs.
		$task_validation = $this->validate_task( $task );
		if ( is_wp_error( $task_validation ) ) {
			return $task_validation;
		}

		$context_validation = $this->validate_context( $context );
		if ( is_wp_error( $context_validation ) ) {
			return $context_validation;
		}

		$this->log(
			'Executor agent executing task',
			'info',
			array(
				'task_description' => $task['description'],
				'task_type'        => isset( $task['type'] ) ? $task['type'] : 'unknown',
				'assistant_id'     => $context['assistant_id'],
			)
		);

		$start_time = microtime( true );

		// Execute the task.
		$result = $this->execute_task_logic( $task, $context );

		$execution_time = microtime( true ) - $start_time;

		// Wrap result with metadata.
		$execution_result = array(
			'task_id'        => isset( $task['id'] ) ? $task['id'] : uniqid( 'exec_', true ),
			'status'         => is_wp_error( $result ) ? 'failed' : 'completed',
			'result'         => $result,
			'execution_time' => $execution_time,
			'completed_at'   => current_time( 'mysql' ),
		);

		if ( is_wp_error( $result ) ) {
			$this->log(
				'Task execution failed',
				'error',
				array(
					'task_id'   => $execution_result['task_id'],
					'error'     => $result->get_error_message(),
					'exec_time' => $execution_time,
				)
			);
		} else {
			$this->log(
				'Task execution completed',
				'info',
				array(
					'task_id'   => $execution_result['task_id'],
					'exec_time' => $execution_time,
				)
			);
		}

		return $execution_result;
	}

	/**
	 * Execute the core task logic
	 *
	 * Override this method in subclasses for specialized execution.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return mixed|WP_Error Execution result or error.
	 */
	protected function execute_task_logic( $task, $context ) {
		// Default implementation - in production this would intelligently
		// select and execute appropriate tools based on task type.
		
		$task_type = isset( $task['type'] ) ? $task['type'] : 'generic';

		switch ( $task_type ) {
			case 'research':
				return $this->execute_research_task( $task, $context );

			case 'analysis':
				return $this->execute_analysis_task( $task, $context );

			case 'creation':
				return $this->execute_creation_task( $task, $context );

			default:
				return array(
					'message'     => __( 'Task received and acknowledged', 'mcp-ai-wpoos' ),
					'task_type'   => $task_type,
					'description' => $task['description'],
				);
		}
	}

	/**
	 * Execute a research task
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Research results.
	 */
	protected function execute_research_task( $task, $context ) {
		return array(
			'type'        => 'research',
			'description' => $task['description'],
			'findings'    => array(
				'status' => 'ready_for_research',
				'note'   => __( 'Research task prepared for AI model execution with web_search tool', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Execute an analysis task
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Analysis results.
	 */
	protected function execute_analysis_task( $task, $context ) {
		return array(
			'type'        => 'analysis',
			'description' => $task['description'],
			'analysis'    => array(
				'status' => 'ready_for_analysis',
				'note'   => __( 'Analysis task prepared for AI model execution', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Execute a creation task
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Creation results.
	 */
	protected function execute_creation_task( $task, $context ) {
		return array(
			'type'        => 'creation',
			'description' => $task['description'],
			'created'     => array(
				'status' => 'ready_for_creation',
				'note'   => __( 'Creation task prepared for AI model execution with appropriate tools', 'mcp-ai-wpoos' ),
			),
		);
	}
}
