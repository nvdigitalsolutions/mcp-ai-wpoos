<?php
/**
 * Tool for creating and executing enhanced multi-agent workflows.
 *
 * Provides advanced workflow capabilities including parallel execution,
 * dependency management, state persistence, and error recovery.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute enhanced multi-agent workflows
 */
class WP_MCP_AI_Tool_Execute_Workflow implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'execute_workflow';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Execute Multi-Agent Workflow', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates and executes an enhanced multi-agent workflow with advanced features like parallel execution, dependency management, automatic retries, and state persistence. Use this for complex tasks that benefit from coordinated multi-agent execution.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'description'        => array(
					'type'        => 'string',
					'description' => __( 'Description of the workflow and what should be accomplished', 'mcp-ai-wpoos' ),
				),
				'task_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of workflow', 'mcp-ai-wpoos' ),
					'enum'        => array( 'research', 'content', 'ecommerce', 'development', 'analysis', 'creative', 'technical', 'generic' ),
				),
				'parallel'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to execute tasks in parallel where possible (default: false)', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'requirements'       => array(
					'type'        => 'object',
					'description' => __( 'Task requirements and constraints', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'expertise_needed' => array(
							'type'        => 'array',
							'description' => __( 'Required expertise areas', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'tools_needed'     => array(
							'type'        => 'array',
							'description' => __( 'Required tools', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'quality_level'    => array(
							'type'        => 'string',
							'description' => __( 'Required quality level', 'mcp-ai-wpoos' ),
							'enum'        => array( 'standard', 'validated' ),
							'default'     => 'standard',
						),
					),
				),
				'max_retries'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum retry attempts per task (default: 2)', 'mcp-ai-wpoos' ),
					'default'     => 2,
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'timeout'            => array(
					'type'        => 'integer',
					'description' => __( 'Workflow timeout in seconds (default: 600)', 'mcp-ai-wpoos' ),
					'default'     => 600,
					'minimum'     => 60,
					'maximum'     => 3600,
				),
				'return_status_only' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, returns workflow ID for async execution. If false, executes synchronously (default: false)', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'description', 'task_type' ),
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
		if ( empty( $arguments['description'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Workflow description is required.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['task_type'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Task type is required.', 'mcp-ai-wpoos' ),
			);
		}

		// Check if enhanced coordinator is available.
		if ( ! class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Enhanced workflow coordinator not available.', 'mcp-ai-wpoos' ),
			);
		}

		try {
			$coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();

			// Build workflow configuration.
			$config = array(
				'description'       => sanitize_text_field( $arguments['description'] ),
				'task_requirements' => array(
					'task_type' => sanitize_key( $arguments['task_type'] ),
				),
				'parallel'          => isset( $arguments['parallel'] ) ? (bool) $arguments['parallel'] : false,
				'timeout'           => isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 600,
				'metadata'          => array(
					'user_id'      => isset( $context['user_id'] ) ? $context['user_id'] : 0,
					'assistant_id' => isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
					'created_via'  => 'execute_workflow_tool',
				),
			);

			// Add requirements if provided.
			if ( ! empty( $arguments['requirements'] ) ) {
				$config['task_requirements'] = array_merge(
					$config['task_requirements'],
					$arguments['requirements']
				);
			}

			// Add retry policy if specified.
			if ( isset( $arguments['max_retries'] ) ) {
				$config['retry_policy'] = array(
					'max_retries' => absint( $arguments['max_retries'] ),
					'base_delay'  => 1,
					'max_delay'   => 10,
				);
			}

			// Create workflow.
			$workflow = $coordinator->create_workflow( $config );

			if ( is_wp_error( $workflow ) ) {
				return array(
					'success' => false,
					'message' => $workflow->get_error_message(),
					'code'    => $workflow->get_error_code(),
				);
			}

			// Return early if async execution requested.
			if ( ! empty( $arguments['return_status_only'] ) ) {
				return array(
					'success'     => true,
					'workflow_id' => $workflow['workflow_id'],
					'team_id'     => $workflow['team_id'],
					'task_count'  => count( $workflow['tasks'] ),
					'state'       => $workflow['state'],
					'message'     => __( 'Workflow created. Use get_workflow_status to check progress.', 'mcp-ai-wpoos' ),
					'actions'     => array(
						'execute' => sprintf( 'Call execute_workflow with workflow_id: %s', $workflow['workflow_id'] ),
						'status'  => sprintf( 'Call get_workflow_status with workflow_id: %s', $workflow['workflow_id'] ),
					),
				);
			}

			// Execute workflow synchronously.
			$result = $coordinator->execute_workflow( $workflow['workflow_id'] );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				);
			}

			return array(
				'success'     => true,
				'workflow_id' => $workflow['workflow_id'],
				'team'        => array(
					'team_id'      => $workflow['team_id'],
					'member_count' => count( $workflow['team']['members'] ),
					'members'      => array_map(
						function ( $m ) {
							return array(
								'agent_id' => $m['id'],
								'role'     => $m['role'],
								'name'     => $m['name'] ?? $m['title'],
							);
						},
						$workflow['team']['members']
					),
				),
				'execution'   => array(
					'status'          => $result['status'],
					'tasks_total'     => $result['total'],
					'tasks_completed' => $result['completed'] ?? $result['total'],
					'parallel'        => $config['parallel'],
				),
				'results'     => $result['results'],
				'message'     => __( 'Workflow executed successfully.', 'mcp-ai-wpoos' ),
			);

		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'workflow_tool_exception',
				'Exception during workflow execution',
				array(
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);

			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: exception message */
					__( 'Workflow execution failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				'code'    => 'workflow_exception',
			);
		}
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'workflow_automation',

			'pattern_compatibility' => array( 'hierarchical', 'orchestrator' ),

			'profession_tags'       => array( 'project_manager', 'operations_manager' ),

			'risk_level'            => 'standard',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => false, // Creates workflow state and executes agents.
			'local-only'        => true,  // No external API calls directly.
			'read-only'         => false, // Creates and modifies data.
			'idempotent'        => false, // Each execution creates new workflow.
			'cacheable'         => false, // Workflow execution is dynamic.
			'requires-auth'     => true,  // Needs user authentication.
			'blocking'          => true,  // Can take time to execute.
			'uses-network'      => false, // No direct network calls.
			'modifies-wp'       => true,  // Stores workflow state.
			'expensive'         => true,  // Resource-intensive operation.
			'requires-approval' => false, // Auto-approved.
		);
	}
}
