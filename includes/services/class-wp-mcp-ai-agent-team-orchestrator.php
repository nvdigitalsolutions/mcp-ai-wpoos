<?php
/**
 * Agent Team Orchestrator
 *
 * Manages team composition and coordinated execution for multi-agent workflows.
 * Inspired by DeepSeek V4's multi-agent coordination patterns.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Team Orchestrator class
 *
 * Handles:
 * - Team composition based on task requirements
 * - Coordinated workflow execution
 * - Team performance tracking
 * - Dynamic team adjustment
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Agent_Team_Orchestrator {

	/**
	 * Communication service instance
	 *
	 * @var WP_MCP_AI_Agent_Communication_Service
	 */
	protected $communication_service;

	/**
	 * Predefined team templates
	 *
	 * @var array
	 */
	protected $team_templates = array();

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Agent_Communication_Service|null $communication_service Communication service.
	 */
	public function __construct( $communication_service = null ) {
		$this->communication_service = $communication_service ?? new WP_MCP_AI_Agent_Communication_Service();
		$this->init_team_templates();
	}

	/**
	 * Compose a team for a specific task
	 *
	 * @param array $task_requirements Task requirements and constraints.
	 * @return array|WP_Error Team composition or error.
	 */
	public function compose_team( $task_requirements ) {
		if ( empty( $task_requirements['task_type'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_task_type',
				__( 'Task type is required for team composition.', 'mcp-ai-wpoos' )
			);
		}

		$task_type = sanitize_key( $task_requirements['task_type'] );

		// Get team template for task type.
		$template = $this->get_team_template( $task_type );
		if ( ! $template ) {
			// Use generic team for unknown types.
			$template = $this->get_team_template( 'generic' );
		}

		// Find available agents for each role.
		$team_members = $this->find_agents_for_roles( $template['roles'] );

		if ( empty( $team_members ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_agents_available',
				__( 'No suitable agents available for team composition.', 'mcp-ai-wpoos' )
			);
		}

		// Create team structure.
		$team = array(
			'team_id'      => $this->generate_team_id(),
			'task_type'    => $task_type,
			'template'     => $template['name'],
			'members'      => $team_members,
			'workflow'     => $template['workflow'],
			'created_at'   => current_time( 'mysql' ),
			'status'       => 'assembled',
			'requirements' => $task_requirements,
		);

		// Store team configuration.
		$this->store_team( $team );

		$this->log_team_action( $team['team_id'], 'composed', array( 'member_count' => count( $team_members ) ) );

		return $team;
	}

	/**
	 * Execute a team workflow
	 *
	 * @param array $team Team configuration.
	 * @param array $task Task to execute.
	 * @param array $context Execution context.
	 * @return array|WP_Error Workflow result or error.
	 */
	public function execute_team_workflow( $team, $task, $context = array() ) {
		if ( empty( $team['team_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_team',
				__( 'Invalid team configuration.', 'mcp-ai-wpoos' )
			);
		}

		$team_id  = $team['team_id'];
		$workflow = isset( $team['workflow'] ) ? $team['workflow'] : array();

		$this->log_team_action( $team_id, 'execution_started' );

		$execution_start = microtime( true );
		$results         = array();

		// Execute workflow steps.
		foreach ( $workflow as $step ) {
			$step_result = $this->execute_workflow_step( $team, $step, $task, $context, $results );

			if ( is_wp_error( $step_result ) ) {
				$this->log_team_action(
					$team_id,
					'step_failed',
					array(
						'step'  => $step['name'],
						'error' => $step_result->get_error_message(),
					)
				);

				// Handle failure based on criticality.
				if ( isset( $step['critical'] ) && $step['critical'] ) {
					return $step_result; // Stop on critical failure.
				}

				// Continue with non-critical failure.
				$results[ $step['name'] ] = array(
					'status' => 'failed',
					'error'  => $step_result,
				);
				continue;
			}

			$results[ $step['name'] ] = $step_result;
		}

		$execution_time = microtime( true ) - $execution_start;

		// Update team status.
		$team['status']         = 'completed';
		$team['execution_time'] = $execution_time;
		$this->store_team( $team );

		$this->log_team_action(
			$team_id,
			'execution_completed',
			array(
				'execution_time' => $execution_time,
				'steps'          => count( $workflow ),
			)
		);

		return array(
			'team_id'        => $team_id,
			'status'         => 'completed',
			'results'        => $results,
			'execution_time' => $execution_time,
			'completed_at'   => current_time( 'mysql' ),
		);
	}

	/**
	 * Track team performance metrics
	 *
	 * @param string $team_id Team ID.
	 * @param array  $execution_data Execution metrics.
	 * @return bool Success status.
	 */
	public function track_team_metrics( $team_id, $execution_data ) {
		$metrics_key = 'wp_mcp_ai_team_metrics_' . sanitize_key( $team_id );

		$metrics = array(
			'team_id'        => $team_id,
			'execution_time' => isset( $execution_data['execution_time'] ) ? $execution_data['execution_time'] : 0,
			'success_rate'   => isset( $execution_data['success_rate'] ) ? $execution_data['success_rate'] : 0,
			'step_count'     => isset( $execution_data['step_count'] ) ? $execution_data['step_count'] : 0,
			'recorded_at'    => current_time( 'mysql' ),
		);

		return set_transient( $metrics_key, $metrics, DAY_IN_SECONDS );
	}

	/**
	 * Execute a single workflow step
	 *
	 * @param array $team Team configuration.
	 * @param array $step Workflow step definition.
	 * @param array $task Original task.
	 * @param array $context Execution context.
	 * @param array $previous_results Results from previous steps.
	 * @return mixed|WP_Error Step result or error.
	 */
	protected function execute_workflow_step( $team, $step, $task, $context, $previous_results ) {
		$step_type = isset( $step['type'] ) ? $step['type'] : 'execute';
		$role      = isset( $step['role'] ) ? $step['role'] : null;

		// Find agent with required role.
		$agent = $this->find_team_member_by_role( $team, $role );
		if ( ! $agent ) {
			return new WP_Error(
				'wp_mcp_ai_no_agent_for_role',
				sprintf(
					/* translators: %s: role name */
					__( 'No agent available with role: %s', 'mcp-ai-wpoos' ),
					$role
				)
			);
		}

		// Execute based on step type.
		switch ( $step_type ) {
			case 'delegate':
				return $this->execute_delegation_step( $agent, $step, $task, $context );

			case 'aggregate':
				return $this->execute_aggregation_step( $previous_results, $step );

			case 'validate':
				return $this->execute_validation_step( $agent, $step, $previous_results );

			default:
				return $this->execute_generic_step( $agent, $step, $task, $context );
		}
	}

	/**
	 * Execute a delegation step
	 *
	 * @param array $agent Agent data.
	 * @param array $step Step definition.
	 * @param array $task Task data.
	 * @param array $context Context data.
	 * @return array|WP_Error Delegation result or error.
	 */
	protected function execute_delegation_step( $agent, $step, $task, $context ) {
		$subtask = isset( $step['subtask'] ) ? $step['subtask'] : $task;

		// In production, this would actually delegate to the agent.
		// For now, return a placeholder result.
		return array(
			'step_type'  => 'delegate',
			'agent_id'   => $agent['id'],
			'agent_role' => $agent['role'],
			'subtask'    => $subtask['description'] ?? 'Subtask',
			'status'     => 'delegated',
		);
	}

	/**
	 * Execute an aggregation step
	 *
	 * @param array $previous_results Previous step results.
	 * @param array $step Step definition.
	 * @return array|WP_Error Aggregation result or error.
	 */
	protected function execute_aggregation_step( $previous_results, $step ) {
		$strategy = isset( $step['strategy'] ) ? $step['strategy'] : 'consensus';

		// Extract results to aggregate.
		$results_to_aggregate = array();
		foreach ( $previous_results as $result ) {
			if ( isset( $result['result'] ) ) {
				$results_to_aggregate[] = $result;
			}
		}

		if ( empty( $results_to_aggregate ) ) {
			return array(
				'step_type' => 'aggregate',
				'strategy'  => $strategy,
				'result'    => null,
				'message'   => __( 'No results to aggregate', 'mcp-ai-wpoos' ),
			);
		}

		return $this->communication_service->aggregate_results( $results_to_aggregate, $strategy );
	}

	/**
	 * Execute a validation step
	 *
	 * @param array $agent Agent data.
	 * @param array $step Step definition.
	 * @param array $previous_results Previous step results.
	 * @return array|WP_Error Validation result or error.
	 */
	protected function execute_validation_step( $agent, $step, $previous_results ) {
		// In production, this would call the critic agent to validate.
		return array(
			'step_type'  => 'validate',
			'agent_id'   => $agent['id'],
			'agent_role' => $agent['role'],
			'validation' => array(
				'passes' => true,
				'score'  => 0.85,
			),
		);
	}

	/**
	 * Execute a generic step
	 *
	 * @param array $agent Agent data.
	 * @param array $step Step definition.
	 * @param array $task Task data.
	 * @param array $context Context data.
	 * @return array Step result.
	 */
	protected function execute_generic_step( $agent, $step, $task, $context ) {
		return array(
			'step_type'  => 'execute',
			'agent_id'   => $agent['id'],
			'agent_role' => $agent['role'],
			'step_name'  => $step['name'] ?? 'unnamed',
			'status'     => 'completed',
		);
	}

	/**
	 * Find team member by role
	 *
	 * @param array  $team Team configuration.
	 * @param string $role Role identifier.
	 * @return array|null Agent data or null if not found.
	 */
	protected function find_team_member_by_role( $team, $role ) {
		if ( empty( $team['members'] ) || ! $role ) {
			return null;
		}

		foreach ( $team['members'] as $member ) {
			if ( isset( $member['role'] ) && $member['role'] === $role ) {
				return $member;
			}
		}

		return null;
	}

	/**
	 * Find available agents for required roles
	 *
	 * @param array $roles Required role identifiers.
	 * @return array Array of agent data.
	 */
	protected function find_agents_for_roles( $roles ) {
		$agents = array();

		foreach ( $roles as $role ) {
			// Query for assistants with this role.
			$query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'meta_query'     => array(
						array(
							'key'     => '_wp_mcp_ai_agent_role',
							'value'   => $role,
							'compare' => '=',
						),
					),
				)
			);

			if ( $query->have_posts() ) {
				$agent_post = $query->posts[0];
				$agents[]   = array(
					'id'    => $agent_post->ID,
					'title' => $agent_post->post_title,
					'role'  => $role,
				);
			} else {
				// Try to find a generic agent if role-specific not found.
				$generic_query = new WP_Query(
					array(
						'post_type'      => 'mcp_ai_assistant',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'orderby'        => 'rand',
					)
				);

				if ( $generic_query->have_posts() ) {
					$agent_post = $generic_query->posts[0];
					$agents[]   = array(
						'id'    => $agent_post->ID,
						'title' => $agent_post->post_title,
						'role'  => 'generalist',
					);
				}
			}

			wp_reset_postdata();
		}

		return $agents;
	}

	/**
	 * Initialize team templates
	 */
	protected function init_team_templates() {
		$this->team_templates = array(
			'research'    => array(
				'name'     => __( 'Research Team', 'mcp-ai-wpoos' ),
				'roles'    => array( 'planner', 'executor', 'critic' ),
				'workflow' => array(
					array(
						'name'     => 'plan',
						'type'     => 'delegate',
						'role'     => 'planner',
						'critical' => true,
					),
					array(
						'name'     => 'execute',
						'type'     => 'delegate',
						'role'     => 'executor',
						'critical' => true,
					),
					array(
						'name'     => 'validate',
						'type'     => 'validate',
						'role'     => 'critic',
						'critical' => false,
					),
				),
			),
			'content'     => array(
				'name'     => __( 'Content Creation Team', 'mcp-ai-wpoos' ),
				'roles'    => array( 'executor', 'critic' ),
				'workflow' => array(
					array(
						'name' => 'create',
						'type' => 'delegate',
						'role' => 'executor',
					),
					array(
						'name' => 'review',
						'type' => 'validate',
						'role' => 'critic',
					),
				),
			),
			'ecommerce'   => array(
				'name'     => __( 'E-commerce Team', 'mcp-ai-wpoos' ),
				'roles'    => array( 'planner', 'executor', 'critic' ),
				'workflow' => array(
					array(
						'name' => 'analyze',
						'type' => 'delegate',
						'role' => 'planner',
					),
					array(
						'name' => 'implement',
						'type' => 'delegate',
						'role' => 'executor',
					),
					array(
						'name' => 'validate',
						'type' => 'validate',
						'role' => 'critic',
					),
				),
			),
			'development' => array(
				'name'     => __( 'Development Team', 'mcp-ai-wpoos' ),
				'roles'    => array( 'planner', 'executor', 'critic' ),
				'workflow' => array(
					array(
						'name' => 'architect',
						'type' => 'delegate',
						'role' => 'planner',
					),
					array(
						'name' => 'code',
						'type' => 'delegate',
						'role' => 'executor',
					),
					array(
						'name' => 'review',
						'type' => 'validate',
						'role' => 'critic',
					),
				),
			),
			'generic'     => array(
				'name'     => __( 'Generic Team', 'mcp-ai-wpoos' ),
				'roles'    => array( 'executor' ),
				'workflow' => array(
					array(
						'name' => 'execute',
						'type' => 'delegate',
						'role' => 'executor',
					),
				),
			),
		);

		/**
		 * Filters team templates.
		 *
		 * @param array $templates Team templates.
		 */
		$this->team_templates = apply_filters( 'wp_mcp_ai_team_templates', $this->team_templates );
	}

	/**
	 * Get team template by type
	 *
	 * @param string $task_type Task type identifier.
	 * @return array|null Template data or null if not found.
	 */
	protected function get_team_template( $task_type ) {
		return isset( $this->team_templates[ $task_type ] ) ? $this->team_templates[ $task_type ] : null;
	}

	/**
	 * Store team configuration
	 *
	 * @param array $team Team data.
	 */
	protected function store_team( $team ) {
		$key = 'wp_mcp_ai_team_' . $team['team_id'];
		set_transient( $key, $team, DAY_IN_SECONDS );
	}

	/**
	 * Generate a unique team ID
	 *
	 * @return string Team ID.
	 */
	protected function generate_team_id() {
		return 'team_' . wp_generate_uuid4();
	}

	/**
	 * Log team action
	 *
	 * @param string $team_id Team ID.
	 * @param string $action Action performed.
	 * @param array  $data Additional data.
	 */
	protected function log_team_action( $team_id, $action, $data = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log(
				sprintf( 'Team %s: %s', $team_id, $action ),
				array_merge(
					array(
						'team_id' => $team_id,
						'action'  => $action,
					),
					$data
				),
				'info'
			);
		}
	}
}
