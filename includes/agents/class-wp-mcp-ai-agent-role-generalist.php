<?php
/**
 * Generalist Agent Role
 *
 * A flexible, general-purpose agent role capable of handling a broad
 * range of tasks without requiring deep specialization.
 * Serves as the default role for agents that have not been assigned
 * a more specific role.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generalist Agent Role class
 *
 * Responsible for:
 * - Handling diverse tasks that do not require specialist knowledge
 * - Acting as a capable default role for any assistant
 * - Balancing breadth of knowledge with practical execution
 * - Routing tasks to appropriate specialists when needed
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Role_Generalist extends WP_MCP_AI_Agent_Role_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'generalist';
		$this->role_name        = __( 'Generalist', 'mcp-ai-wpoos' );
		$this->role_description = __( 'A flexible, general-purpose agent that can handle a wide variety of tasks. Serves as the default role when no specialist role is required.', 'mcp-ai-wpoos' );

		$this->capabilities = array(
			'autonomous',
			'requires-tools',
		);

		$this->recommended_tools = array(
			'web_search',
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
			'You are a Generalist agent capable of handling a wide range of tasks. Approach each task thoughtfully, leveraging your broad knowledge and available tools. When a task requires deep domain expertise, acknowledge that and suggest engaging a Specialist agent. Otherwise, deliver thorough, well-rounded results that address all aspects of the request.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Execute a general-purpose task
	 *
	 * Handles the task using a balanced approach and available tools.
	 *
	 * @param array $task    Task data including description, context, requirements.
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
			'Generalist agent executing task',
			'info',
			array(
				'task_description' => $task['description'],
				'assistant_id'     => $context['assistant_id'],
			)
		);

		$start_time = microtime( true );

		// Assess whether a specialist is more appropriate.
		$specialist_hint = $this->assess_specialist_need( $task );

		// Execute the task.
		$task_result = $this->perform_general_task( $task, $context );

		$execution_time = microtime( true ) - $start_time;

		$result = array(
			'task_id'         => isset( $task['id'] ) ? $task['id'] : uniqid( 'generalist_task_', true ),
			'status'          => 'completed',
			'result'          => $task_result,
			'execution_time'  => round( $execution_time, 4 ),
			'completed_at'    => current_time( 'mysql' ),
		);

		if ( ! empty( $specialist_hint ) ) {
			$result['specialist_recommendation'] = $specialist_hint;
		}

		$this->log(
			'Generalist task complete',
			'info',
			array(
				'task_id'        => $result['task_id'],
				'execution_time' => $result['execution_time'],
			)
		);

		return $result;
	}

	/**
	 * Assess whether a specialist role would be more appropriate
	 *
	 * Returns a recommendation string when the task description contains
	 * strongly domain-specific keywords, or an empty string otherwise.
	 *
	 * @param array $task Task data.
	 * @return string Recommendation to use a specialist, or empty string.
	 */
	protected function assess_specialist_need( $task ) {
		$specialist_keywords = array(
			'security', 'seo', 'medical', 'legal', 'financial',
			'accounting', 'engineering', 'scientific', 'compliance',
		);

		$description = strtolower( $task['description'] );

		foreach ( $specialist_keywords as $keyword ) {
			if ( str_contains( $description, $keyword ) ) {
				return sprintf(
					/* translators: %s: domain keyword */
					__( 'This task involves %s topics. Consider using a Specialist agent for better results.', 'mcp-ai-wpoos' ),
					$keyword
				);
			}
		}

		return '';
	}

	/**
	 * Perform the general task execution
	 *
	 * @param array $task    Task data.
	 * @param array $context Execution context.
	 * @return array Task output.
	 */
	protected function perform_general_task( $task, $context ) {
		return array(
			'description' => $task['description'],
			'response'    => sprintf(
				/* translators: %s: task description */
				__( 'Generalist task executed: %s', 'mcp-ai-wpoos' ),
				$task['description']
			),
		);
	}
}
