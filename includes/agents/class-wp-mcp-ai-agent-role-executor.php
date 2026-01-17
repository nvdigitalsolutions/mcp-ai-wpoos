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
	 * Provides execution plan for research tasks using available tools.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Research execution plan with tool recommendations.
	 */
	protected function execute_research_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();

		// Build execution plan with recommended tools and steps.
		$execution_plan = array(
			'type'        => 'research',
			'description' => $description,
			'plan'        => array(
				'steps'                => array(
					array(
						'step'        => 1,
						'action'      => 'search_and_gather',
						'tools'       => array( 'web_search', 'crawl4ai' ),
						'description' => __( 'Search for information and gather relevant data', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 2,
						'action'      => 'analyze_sources',
						'tools'       => array(),
						'description' => __( 'Analyze gathered information for relevance and quality', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 3,
						'action'      => 'synthesize',
						'tools'       => array( 'save_post' ),
						'description' => __( 'Synthesize findings into structured results', 'mcp-ai-wpoos' ),
					),
				),
				'estimated_tool_calls' => 3,
				'parallel_execution'   => false,
			),
		);

		// Add task-specific parameters.
		if ( ! empty( $parameters['query'] ) ) {
			$execution_plan['query'] = $parameters['query'];
		}
		if ( ! empty( $parameters['sources'] ) ) {
			$execution_plan['sources'] = $parameters['sources'];
		}

		return $execution_plan;
	}

	/**
	 * Execute an analysis task
	 *
	 * Provides execution plan for analysis tasks using available tools.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Analysis execution plan with tool recommendations.
	 */
	protected function execute_analysis_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();

		// Build execution plan for analysis.
		$execution_plan = array(
			'type'        => 'analysis',
			'description' => $description,
			'plan'        => array(
				'steps'                => array(
					array(
						'step'        => 1,
						'action'      => 'retrieve_data',
						'tools'       => array( 'get_recent_posts', 'search_content' ),
						'description' => __( 'Retrieve data to be analyzed', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 2,
						'action'      => 'perform_analysis',
						'tools'       => array( 'create_chart' ),
						'description' => __( 'Analyze data and identify patterns or insights', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 3,
						'action'      => 'generate_report',
						'tools'       => array( 'save_post' ),
						'description' => __( 'Generate analysis report with findings', 'mcp-ai-wpoos' ),
					),
				),
				'estimated_tool_calls' => 3,
				'parallel_execution'   => false,
			),
		);

		// Add task-specific parameters.
		if ( ! empty( $parameters['dataset'] ) ) {
			$execution_plan['dataset'] = $parameters['dataset'];
		}
		if ( ! empty( $parameters['metrics'] ) ) {
			$execution_plan['metrics'] = $parameters['metrics'];
		}

		return $execution_plan;
	}

	/**
	 * Execute a creation task
	 *
	 * Provides execution plan for content/resource creation tasks.
	 *
	 * @param array $task Task data.
	 * @param array $context Execution context.
	 * @return array Creation execution plan with tool recommendations.
	 */
	protected function execute_creation_task( $task, $context ) {
		$description = isset( $task['description'] ) ? $task['description'] : '';
		$parameters  = isset( $task['parameters'] ) ? $task['parameters'] : array();

		// Build execution plan for creation.
		$execution_plan = array(
			'type'        => 'creation',
			'description' => $description,
			'plan'        => array(
				'steps'                => array(
					array(
						'step'        => 1,
						'action'      => 'research_content',
						'tools'       => array( 'web_search', 'get_recent_posts' ),
						'description' => __( 'Research and gather information for creation', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 2,
						'action'      => 'create_draft',
						'tools'       => array( 'create_post' ),
						'description' => __( 'Create initial draft or prototype', 'mcp-ai-wpoos' ),
					),
					array(
						'step'        => 3,
						'action'      => 'refine_and_publish',
						'tools'       => array( 'save_post' ),
						'description' => __( 'Refine and finalize the created content', 'mcp-ai-wpoos' ),
					),
				),
				'estimated_tool_calls' => 3,
				'parallel_execution'   => false,
			),
		);

		// Add task-specific parameters.
		if ( ! empty( $parameters['content_type'] ) ) {
			$execution_plan['content_type'] = $parameters['content_type'];
		}
		if ( ! empty( $parameters['requirements'] ) ) {
			$execution_plan['requirements'] = $parameters['requirements'];
		}

		return $execution_plan;
	}
}
