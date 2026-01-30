<?php
/**
 * Tool for creating agent teams.
 *
 * Allows AI assistants to compose multi-agent teams dynamically from available professions.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a specialized agent team for complex tasks.
 *
 * This tool enables AI models to autonomously compose teams of specialized agents
 * by selecting professions with appropriate agent roles (planner, executor, critic).
 * The team can then be deployed for coordinated multi-agent workflows.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Create_Agent_Team implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_agent_team';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Agent Team', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a specialized multi-agent team for complex tasks. Teams consist of a planner (task decomposition), executors (specialized work), and optionally a critic (validation). The system selects appropriate professions based on task requirements.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_type'              => array(
					'type'        => 'string',
					'description' => __( 'Type of task the team will handle', 'mcp-ai-wpoos' ),
					'enum'        => array( 'research', 'content', 'ecommerce', 'development', 'analysis', 'creative', 'technical', 'generic' ),
				),
				'requirements'           => array(
					'type'        => 'object',
					'description' => __( 'Task requirements and constraints', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'expertise_needed' => array(
							'type'        => 'array',
							'description' => __( 'Required expertise areas (e.g., ["machine learning", "data visualization"])', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'tools_needed'     => array(
							'type'        => 'array',
							'description' => __( 'Required tools (e.g., ["web_search", "create_chart"])', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'quality_level'    => array(
							'type'        => 'string',
							'description' => __( 'Required quality level: "standard" or "validated" (includes critic)', 'mcp-ai-wpoos' ),
							'enum'        => array( 'standard', 'validated' ),
							'default'     => 'standard',
						),
					),
				),
				'profession_preferences' => array(
					'type'        => 'object',
					'description' => __( 'Optional profession preferences for team roles', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'planner_slug'   => array(
							'type'        => 'string',
							'description' => __( 'Preferred planner profession slug', 'mcp-ai-wpoos' ),
						),
						'executor_slugs' => array(
							'type'        => 'array',
							'description' => __( 'Preferred executor profession slugs', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'critic_slug'    => array(
							'type'        => 'string',
							'description' => __( 'Preferred critic profession slug', 'mcp-ai-wpoos' ),
						),
					),
				),
			),
			'required'             => array( 'task_type' ),
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
		// Validate task_type.
		if ( empty( $arguments['task_type'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Task type is required.', 'mcp-ai-wpoos' ),
			);
		}

		$task_type    = sanitize_key( $arguments['task_type'] );
		$requirements = isset( $arguments['requirements'] ) ? $arguments['requirements'] : array();
		$preferences  = isset( $arguments['profession_preferences'] ) ? $arguments['profession_preferences'] : array();

		// Get orchestrator service.
		if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent orchestration system not available.', 'mcp-ai-wpoos' ),
			);
		}

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		// Compose team.
		$task_requirements = array_merge(
			array( 'task_type' => $task_type ),
			$requirements,
			array( 'preferences' => $preferences )
		);

		$team = $orchestrator->compose_team( $task_requirements );

		if ( is_wp_error( $team ) ) {
			return array(
				'success' => false,
				'message' => $team->get_error_message(),
				'code'    => $team->get_error_code(),
			);
		}

		// Format team members for response.
		$formatted_members = array();
		if ( isset( $team['members'] ) && is_array( $team['members'] ) ) {
			foreach ( $team['members'] as $member ) {
				$formatted_members[] = array(
					'agent_id'   => $member['id'],
					'role'       => $member['role'],
					'profession' => $member['name'],
					'expertise'  => isset( $member['expertise'] ) ? $member['expertise'] : array(),
				);
			}
		}

		// Format workflow steps.
		$workflow_steps = array();
		if ( isset( $team['workflow'] ) && is_array( $team['workflow'] ) ) {
			foreach ( $team['workflow'] as $step ) {
				$workflow_steps[] = array(
					'name' => $step['name'],
					'type' => $step['type'],
					'role' => isset( $step['role'] ) ? $step['role'] : null,
				);
			}
		}

		// Build delegation examples for clarity.
		$delegation_examples = array();
		foreach ( $formatted_members as $member ) {
			$delegation_examples[] = sprintf(
				/* translators: 1: agent role, 2: agent_id value */
				__( 'Delegate to %1$s using agent_id: "%2$s"', 'mcp-ai-wpoos' ),
				$member['role'],
				$member['agent_id']
			);
		}

		return array(
			'success'    => true,
			'message'    => __( 'Agent team created successfully.', 'mcp-ai-wpoos' ),
			'team'       => array(
				'team_id'      => $team['team_id'],
				'task_type'    => $team['task_type'],
				'template'     => $team['template'],
				'member_count' => count( $formatted_members ),
				'members'      => $formatted_members,
				'workflow'     => $workflow_steps,
				'status'       => $team['status'],
				'created_at'   => $team['created_at'],
			),
			'next_steps' => array(
				__( 'IMPORTANT: Use the agent_id field (not profession) when calling delegate_to_agent', 'mcp-ai-wpoos' ),
				__( 'Example: delegate_to_agent with agent_id from the members array above', 'mcp-ai-wpoos' ),
				__( 'When delegating, include team_id in the context parameter for virtual agents', 'mcp-ai-wpoos' ),
				__( 'Use aggregate_agent_results to combine outputs from multiple agents', 'mcp-ai-wpoos' ),
			),
			'delegation_examples' => $delegation_examples,
		);
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

			'profession_tags'       => array( 'project_manager' ),

			'risk_level'            => 'standard',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => false, // Creates team records.
			'local-only'        => true,  // No external API calls.
			'read-only'         => false, // Writes team data.
			'idempotent'        => false, // Creates new team each time.
			'cacheable'         => false, // Team composition is dynamic.
			'requires-auth'     => true,  // Needs user authentication.
			'blocking'          => false, // Fast operation.
			'uses-network'      => false, // No network calls.
			'modifies-wp'       => true,  // Stores team in transients.
			'expensive'         => false, // Low cost operation.
			'requires-approval' => false, // Auto-approved.
		);
	}
}
