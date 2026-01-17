<?php
/**
 * Tool for delegating tasks to agents.
 *
 * Allows AI assistants to delegate subtasks to specialized agents within a team.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates a subtask to a specialized agent.
 *
 * This tool enables AI models to assign specific subtasks to appropriate agent roles
 * (planner, executor, critic) by profession ID. The agent will execute the task
 * using its specialized tools and expertise.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Delegate_To_Agent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delegate_to_agent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delegate to Agent', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Delegates a subtask to a specialized agent. The agent will use its expertise and tools to complete the task. Use this for complex workflows where different specialists handle different aspects of the work.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'        => array(
					'type'        => 'integer',
					'description' => __( 'ID of the agent (profession or assistant) to delegate to', 'mcp-ai-wpoos' ),
				),
				'task'            => array(
					'type'        => 'string',
					'description' => __( 'Clear description of the subtask to be completed', 'mcp-ai-wpoos' ),
				),
				'context'         => array(
					'type'        => 'object',
					'description' => __( 'Shared context from parent task', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'parent_task_id' => array(
							'type'        => 'string',
							'description' => __( 'ID of the parent task', 'mcp-ai-wpoos' ),
						),
						'dependencies'   => array(
							'type'        => 'array',
							'description' => __( 'IDs of subtasks that must complete first', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'shared_data'    => array(
							'type'        => 'object',
							'description' => __( 'Data to share with the agent', 'mcp-ai-wpoos' ),
						),
					),
				),
				'expected_output' => array(
					'type'        => 'object',
					'description' => __( 'Description of expected output format', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'format' => array(
							'type'        => 'string',
							'description' => __( 'Expected format: text, json, html, markdown', 'mcp-ai-wpoos' ),
							'enum'        => array( 'text', 'json', 'html', 'markdown' ),
						),
						'fields' => array(
							'type'        => 'array',
							'description' => __( 'Required fields in the output', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
			),
			'required'             => array( 'agent_id', 'task' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['agent_id'] ) || empty( $arguments['task'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent ID and task description are required.', 'mcp-ai-wpoos' ),
			);
		}

		$agent_id        = absint( $arguments['agent_id'] );
		$task            = sanitize_textarea_field( $arguments['task'] );
		$task_context    = isset( $arguments['context'] ) ? $arguments['context'] : array();
		$expected_output = isset( $arguments['expected_output'] ) ? $arguments['expected_output'] : array();

		// Get communication service.
		if ( ! class_exists( 'WP_MCP_AI_Agent_Communication_Service' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent communication system not available.', 'mcp-ai-wpoos' ),
			);
		}

		$communication_service = new WP_MCP_AI_Agent_Communication_Service();

		// Prepare task data.
		$task_data = array(
			'description'     => $task,
			'context'         => $task_context,
			'expected_output' => $expected_output,
			'delegated_by'    => isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
			'delegated_at'    => current_time( 'mysql' ),
		);

		// Delegate task.
		$result = $communication_service->delegate_task(
			isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
			$agent_id,
			$task_data,
			$task_context
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			);
		}

		// Format delegation result.
		return array(
			'success'    => true,
			'message'    => __( 'Task successfully delegated to agent.', 'mcp-ai-wpoos' ),
			'delegation' => array(
				'delegation_id' => $result['delegation_id'],
				'agent_id'      => $agent_id,
				'agent_name'    => $result['agent_name'],
				'agent_role'    => $result['agent_role'],
				'task'          => $task,
				'status'        => $result['status'],
				'delegated_at'  => $result['delegated_at'],
			),
			'next_steps' => array(
				__( 'Wait for agent to complete the task', 'mcp-ai-wpoos' ),
				__( 'Check delegation status if needed', 'mcp-ai-wpoos' ),
				__( 'Use aggregate_agent_results to combine with other agent outputs', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => false, // Creates delegation records
			'local-only'        => true,  // No external API calls
			'read-only'         => false, // Writes delegation data
			'idempotent'        => false, // Each delegation is unique
			'cacheable'         => false, // Delegation is dynamic
			'requires-auth'     => true,  // Needs user authentication
			'blocking'          => false, // Async delegation
			'uses-network'      => false, // No network calls
			'modifies-wp'       => true,  // Stores delegation in transients
			'expensive'         => false, // Low cost operation
			'requires-approval' => false, // Auto-approved
		);
	}
}
