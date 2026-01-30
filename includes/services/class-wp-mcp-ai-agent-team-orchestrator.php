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
	 * Load monitor instance
	 *
	 * @var WP_MCP_AI_Tool_Load_Monitor|null
	 */
	protected $load_monitor;

	/**
	 * Tool execution orchestrator instance
	 *
	 * @var WP_MCP_AI_Tool_Execution_Orchestrator|null
	 */
	protected $tool_orchestrator;

	/**
	 * Predefined team templates
	 *
	 * @var array
	 */
	protected $team_templates = array();

	/**
	 * Workflow state for context propagation
	 *
	 * @var array
	 */
	protected $workflow_state = array();

	/**
	 * Completed workflow steps
	 *
	 * @var array
	 */
	protected $completed_steps = array();

	/**
	 * Execution history tracking
	 *
	 * @var array
	 */
	protected $execution_history = array();

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Agent_Communication_Service|null $communication_service Communication service.
	 * @param WP_MCP_AI_Tool_Load_Monitor|null           $load_monitor Load monitor instance.
	 * @param WP_MCP_AI_Tool_Execution_Orchestrator|null $tool_orchestrator Tool orchestrator instance.
	 */
	public function __construct( $communication_service = null, $load_monitor = null, $tool_orchestrator = null ) {
		$this->communication_service = $communication_service ?? new WP_MCP_AI_Agent_Communication_Service();
		$this->load_monitor          = $load_monitor;
		$this->tool_orchestrator     = $tool_orchestrator;
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

		// Check system capacity before composing team (Phase 2.3).
		$capacity_check = $this->check_system_capacity_for_team( $task_requirements );
		if ( is_wp_error( $capacity_check ) ) {
			return $capacity_check;
		}

		// Get team template for task type.
		$template = $this->get_team_template( $task_type );
		if ( ! $template ) {
			// Use generic team for unknown types.
			$template = $this->get_team_template( 'generic' );
		}

		// Find available agents for each role.
		$team_members = $this->find_agents_for_roles( $template['roles'], $task_requirements );

		if ( empty( $team_members ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_agents_available',
				sprintf(
					/* translators: %s: task type */
					__( 'No suitable agents available for %s team composition. Please ensure you have at least one published assistant, or the system will automatically create virtual agents. Check the plugin logs for more details.', 'mcp-ai-wpoos' ),
					$task_type
				),
				array(
					'task_type'      => $task_type,
					'required_roles' => $template['roles'],
					'suggestion'     => __( 'Create at least one assistant in the WordPress admin, or the system will use virtual agents.', 'mcp-ai-wpoos' ),
				)
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

		// Also store as workflow for orchestration dashboard tracking.
		$this->save_team_as_workflow( $team );

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

		// Generate workflow ID for tracking.
		$workflow_id = 'wf_' . $team_id . '_' . time();
		$trace_id    = isset( $context['trace_id'] ) ? $context['trace_id'] : uniqid( 'trace_', true );

		// Add workflow tracking data to context for tool execution.
		$context['workflow_id'] = $workflow_id;
		$context['team_id']     = $team_id;
		$context['trace_id']    = $trace_id;

		// Initialize workflow tracking data for dashboard.
		$workflow_data = array(
			'workflow_id'  => $workflow_id,
			'team_id'      => $team_id,
			'state'        => 'running',
			'task'         => isset( $task['description'] ) ? $task['description'] : 'Unnamed task',
			'tasks'        => array(),
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
			'started_at'   => current_time( 'mysql' ),
			'completed_at' => null,
			'trace_id'     => $trace_id,
		);

		// Save initial workflow state to dashboard.
		$this->save_workflow_to_dashboard( $workflow_id, $workflow_data );

		$this->log_team_action( $team_id, 'execution_started', array( 'workflow_id' => $workflow_id, 'trace_id' => $trace_id ) );

		$execution_start = microtime( true );
		$results         = array();
		$tasks_completed = 0;
		$tasks_failed    = 0;

		// Execute workflow steps.
		foreach ( $workflow as $step ) {
			// Add current step name to context for workflow task tracking.
			$step_name            = isset( $step['name'] ) ? $step['name'] : 'unnamed_step';
			$context['task_name'] = $step_name;
			
			$step_start  = microtime( true );
			$step_result = $this->execute_workflow_step( $team, $step, $task, $context, $results );
			$step_time   = microtime( true ) - $step_start;

			// Track step execution.
			$step_data = array(
				'name'           => $step_name,
				'type'           => isset( $step['type'] ) ? $step['type'] : 'execute',
				'status'         => is_wp_error( $step_result ) ? 'failed' : 'completed',
				'execution_time' => $step_time,
				'completed_at'   => current_time( 'mysql' ),
			);

			if ( is_wp_error( $step_result ) ) {
				$step_data['error'] = $step_result->get_error_message();
				++$tasks_failed;

				$this->log_team_action(
					$team_id,
					'step_failed',
					array(
						'step'        => $step['name'],
						'error'       => $step_result->get_error_message(),
						'workflow_id' => $workflow_id,
						'trace_id'    => $trace_id,
					)
				);

				// Add failed step to workflow tracking.
				$workflow_data['tasks'][]    = $step_data;
				$workflow_data['updated_at'] = current_time( 'mysql' );

				// Handle failure based on criticality.
				if ( isset( $step['critical'] ) && $step['critical'] ) {
					// Update workflow as failed.
					$workflow_data['state']        = 'failed';
					$workflow_data['error']        = $step_result->get_error_message();
					$workflow_data['completed_at'] = current_time( 'mysql' );
					$this->save_workflow_to_dashboard( $workflow_id, $workflow_data );
					return $step_result; // Stop on critical failure.
				}

				// Continue with non-critical failure.
				$results[ $step['name'] ] = array(
					'status' => 'failed',
					'error'  => $step_result,
				);

				// Update dashboard with failed step.
				$this->save_workflow_to_dashboard( $workflow_id, $workflow_data );
				continue;
			}

			// Success - track it.
			++$tasks_completed;
			$results[ $step['name'] ] = $step_result;

			// Add successful step to workflow tracking.
			$workflow_data['tasks'][]    = $step_data;
			$workflow_data['updated_at'] = current_time( 'mysql' );
			$this->save_workflow_to_dashboard( $workflow_id, $workflow_data );
		}

		$execution_time = microtime( true ) - $execution_start;

		// Update final workflow state for dashboard.
		$workflow_data['state']          = $tasks_failed > 0 ? 'completed_with_errors' : 'completed';
		$workflow_data['execution_time'] = $execution_time;
		$workflow_data['completed_at']   = current_time( 'mysql' );
		$workflow_data['updated_at']     = current_time( 'mysql' );
		$workflow_data['summary']        = array(
			'total_tasks'     => count( $workflow ),
			'tasks_completed' => $tasks_completed,
			'tasks_failed'    => $tasks_failed,
			'execution_time'  => round( $execution_time, 2 ),
		);
		$this->save_workflow_to_dashboard( $workflow_id, $workflow_data );

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
				'workflow_id'    => $workflow_id,
				'trace_id'       => $trace_id,
			)
		);

		return array(
			'workflow_id'    => $workflow_id,
			'team_id'        => $team_id,
			'status'         => 'completed',
			'results'        => $results,
			'execution_time' => $execution_time,
			'completed_at'   => current_time( 'mysql' ),
			'trace_id'       => $trace_id,
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
	 * Delegates a subtask to a specialized agent and executes it.
	 * Industry best practices (2026):
	 * - Full conversation context propagation
	 * - Workflow state management
	 * - Execution history tracking
	 * - Idempotency through task IDs
	 * - Trace ID for debugging
	 *
	 * @param array $agent Agent data.
	 * @param array $step Step definition.
	 * @param array $task Task data.
	 * @param array $context Context data.
	 * @return array|WP_Error Delegation result or error.
	 */
	protected function execute_delegation_step( $agent, $step, $task, $context ) {
		$subtask = isset( $step['subtask'] ) ? $step['subtask'] : $task;

		// Get agent role instance.
		$agent_role = null;
		if ( isset( $agent['id'] ) ) {
			$agent_role = wp_mcp_ai_get_assistant_role( $agent['id'] );
		}

		// Fallback to role type if assistant doesn't have a role set.
		if ( ! $agent_role && isset( $agent['role'] ) ) {
			$agent_role = wp_mcp_ai_get_agent_role( $agent['role'] );
		}

		if ( ! $agent_role ) {
			return new WP_Error(
				'wp_mcp_ai_no_agent_role',
				sprintf(
					/* translators: %d: agent ID */
					__( 'Agent %d does not have a valid role assigned.', 'mcp-ai-wpoos' ),
					$agent['id']
				)
			);
		}

		// Generate task ID for idempotency.
		$task_id = isset( $step['name'] ) ? $step['name'] : uniqid( 'subtask_', true );

		// Check if task already completed (idempotency).
		if ( $this->is_task_completed( $task_id ) ) {
			return $this->get_cached_task_result( $task_id );
		}

		// Prepare task data for agent execution.
		$agent_task = array(
			'description' => isset( $subtask['description'] ) ? $subtask['description'] : ( isset( $task['description'] ) ? $task['description'] : '' ),
			'type'        => isset( $subtask['type'] ) ? $subtask['type'] : ( isset( $task['type'] ) ? $task['type'] : 'generic' ),
			'parameters'  => isset( $subtask['parameters'] ) ? $subtask['parameters'] : array(),
			'id'          => $task_id,
		);

		// Prepare execution context with full workflow state.
		$trace_id      = isset( $context['trace_id'] ) ? $context['trace_id'] : uniqid( 'trace_', true );
		$agent_context = array_merge(
			$context,
			array(
				'assistant_id'   => $agent['id'],
				'delegated_by'   => isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
				'parent_task'    => isset( $task['id'] ) ? $task['id'] : null,
				'workflow_state' => $this->get_workflow_state(),
				'previous_steps' => $this->get_completed_steps(),
				'trace_id'       => $trace_id,
			)
		);

		// Log delegation start.
		$this->log_execution(
			'delegation_started',
			array(
				'agent_id'   => $agent['id'],
				'agent_role' => $agent['role'],
				'task_id'    => $task_id,
				'trace_id'   => $trace_id,
			)
		);

		// Execute the task using the agent's role.
		$result = $agent_role->execute_role_task( $agent_task, $agent_context );

		// Cache result for idempotency.
		$this->cache_task_result( $task_id, $result );

		// Update workflow state.
		$this->update_workflow_state( $task_id, $result );
		$this->add_completed_step( $task_id, array(
			'agent_id'   => $agent['id'],
			'agent_role' => $agent['role'],
			'task'       => $agent_task['description'],
			'result'     => $result,
		) );

		// Log delegation completion.
		$this->log_execution(
			'delegation_completed',
			array(
				'agent_id'   => $agent['id'],
				'agent_role' => $agent['role'],
				'task_id'    => $task_id,
				'status'     => is_wp_error( $result ) ? 'failed' : 'completed',
				'trace_id'   => $trace_id,
			)
		);

		// Wrap result with delegation metadata.
		return array(
			'step_type'    => 'delegate',
			'agent_id'     => $agent['id'],
			'agent_role'   => $agent['role'],
			'subtask_id'   => $agent_task['id'],
			'subtask'      => $agent_task['description'],
			'status'       => is_wp_error( $result ) ? 'failed' : 'completed',
			'result'       => $result,
			'delegated_at' => current_time( 'mysql' ),
			'trace_id'     => $trace_id,
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
	 * Invokes a critic agent to validate previous results.
	 *
	 * @param array $agent Agent data.
	 * @param array $step Step definition.
	 * @param array $previous_results Previous step results.
	 * @return array|WP_Error Validation result or error.
	 */
	protected function execute_validation_step( $agent, $step, $previous_results ) {
		// Get critic agent role instance.
		$agent_role = null;
		if ( isset( $agent['id'] ) ) {
			$agent_role = wp_mcp_ai_get_assistant_role( $agent['id'] );
		}

		// Fallback to generic critic role.
		if ( ! $agent_role || $agent_role->get_role_type() !== 'critic' ) {
			$agent_role = wp_mcp_ai_get_agent_role( 'critic' );
		}

		if ( ! $agent_role ) {
			return new WP_Error(
				'wp_mcp_ai_no_critic_role',
				__( 'Critic agent role not available for validation.', 'mcp-ai-wpoos' )
			);
		}

		// Prepare validation task data.
		$validation_task = array(
			'description' => __( 'Validate the results from previous workflow steps', 'mcp-ai-wpoos' ),
			'type'        => 'validation',
			'parameters'  => array(
				'results_to_validate' => $previous_results,
				'validation_criteria' => isset( $step['criteria'] ) ? $step['criteria'] : array(),
			),
		);

		// Prepare context.
		$validation_context = array(
			'assistant_id'    => $agent['id'],
			'validation_step' => true,
		);

		// Execute validation.
		$result = $agent_role->execute_role_task( $validation_task, $validation_context );

		// Extract validation status.
		$validation_passes = true;
		$validation_score  = 0.85; // Default.

		if ( ! is_wp_error( $result ) ) {
			if ( isset( $result['validation'] ) && isset( $result['validation']['passes'] ) ) {
				$validation_passes = (bool) $result['validation']['passes'];
			}
			if ( isset( $result['overall_score'] ) ) {
				$validation_score = (float) $result['overall_score'];
			}
		}

		return array(
			'step_type'    => 'validate',
			'agent_id'     => $agent['id'],
			'agent_role'   => $agent['role'],
			'validation'   => array(
				'passes' => $validation_passes,
				'score'  => $validation_score,
				'result' => $result,
			),
			'validated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Execute a generic step
	 *
	 * Executes a generic workflow step using the assigned agent.
	 *
	 * @param array $agent Agent data.
	 * @param array $step Step definition.
	 * @param array $task Task data.
	 * @param array $context Context data.
	 * @return array|WP_Error Step result or error.
	 */
	protected function execute_generic_step( $agent, $step, $task, $context ) {
		// Get agent role instance.
		$agent_role = null;
		if ( isset( $agent['id'] ) ) {
			$agent_role = wp_mcp_ai_get_assistant_role( $agent['id'] );
		}

		// Fallback to role type.
		if ( ! $agent_role && isset( $agent['role'] ) ) {
			$agent_role = wp_mcp_ai_get_agent_role( $agent['role'] );
		}

		// If no agent role, return placeholder.
		if ( ! $agent_role ) {
			return array(
				'step_type'  => 'execute',
				'agent_id'   => $agent['id'],
				'agent_role' => $agent['role'],
				'step_name'  => $step['name'] ?? 'unnamed',
				'status'     => 'no_role_assigned',
				'message'    => __( 'Agent does not have a role assigned', 'mcp-ai-wpoos' ),
			);
		}

		// Prepare task for agent.
		$agent_task = array(
			'description' => isset( $step['description'] ) ? $step['description'] : ( isset( $task['description'] ) ? $task['description'] : '' ),
			'type'        => isset( $step['type'] ) ? $step['type'] : 'generic',
			'parameters'  => isset( $step['parameters'] ) ? $step['parameters'] : array(),
		);

		// Prepare context.
		$agent_context = array_merge(
			$context,
			array(
				'assistant_id' => $agent['id'],
				'step_name'    => $step['name'] ?? 'unnamed',
			)
		);

		// Execute task with agent role.
		$result = $agent_role->execute_role_task( $agent_task, $agent_context );

		return array(
			'step_type'   => 'execute',
			'agent_id'    => $agent['id'],
			'agent_role'  => $agent['role'],
			'step_name'   => $step['name'] ?? 'unnamed',
			'status'      => is_wp_error( $result ) ? 'failed' : 'completed',
			'result'      => $result,
			'executed_at' => current_time( 'mysql' ),
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
	 * Searches in the following order:
	 * 1. Assistants with specific agent role metadata (best match)
	 * 2. Profession-based agents (have relevant expertise)
	 * 3. Virtual agents (role-specific with defined expertise)
	 * 4. Generic assistants (last resort - no specific configuration)
	 *
	 * @param array $roles Required role identifiers.
	 * @param array $task_requirements Task requirements.
	 * @return array Array of agent data.
	 */
	protected function find_agents_for_roles( $roles, $task_requirements = array() ) {
		$agents        = array();
		$missing_roles = array();

		foreach ( $roles as $role ) {
			$agent_found = false;

			// Step 1: Query for assistants with this specific role.
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
				$agent_post  = $query->posts[0];
				$professions = get_post_meta( $agent_post->ID, '_wp_mcp_ai_professions', true );
				$expertise   = array();

				// Extract expertise from professions if available.
				if ( is_array( $professions ) && ! empty( $professions ) ) {
					foreach ( $professions as $profession_slug ) {
						$profession_data = $this->get_profession_data( $profession_slug );
						if ( $profession_data && ! empty( $profession_data['expertise'] ) ) {
							$expertise = array_merge( $expertise, $profession_data['expertise'] );
						}
					}
				}

				$agents[]    = array(
					'id'          => $agent_post->ID,
					'title'       => $agent_post->post_title,
					'name'        => $agent_post->post_title,
					'role'        => $role,
					'professions' => is_array( $professions ) ? $professions : array(),
					'expertise'   => array_unique( $expertise ),
				);
				$agent_found = true;
			} else {
				// Step 2: Try to find a profession-based agent with relevant expertise.
				$profession_agent = $this->find_profession_agent_for_role( $role, $task_requirements );
				if ( $profession_agent ) {
					$agents[]    = $profession_agent;
					$agent_found = true;

					WP_MCP_AI_Logger::log_event(
						'team_composition_fallback_profession',
						sprintf( 'No assistant with role "%s" found, using profession-based agent with relevant expertise', $role ),
						array(
							'required_role' => $role,
							'profession_id' => $profession_agent['id'],
							'profession'    => $profession_agent['profession'] ?? 'unknown',
						)
					);
				} else {
					// Step 3: Create a virtual agent with role-specific expertise.
					$virtual_agent = $this->create_virtual_agent_for_role( $role );
					if ( $virtual_agent ) {
						$agents[]    = $virtual_agent;
						$agent_found = true;

						WP_MCP_AI_Logger::log_event(
							'team_composition_virtual_agent',
							sprintf( 'No role-specific assistant or profession found for "%s", creating virtual agent with role-appropriate expertise', $role ),
							array(
								'required_role'    => $role,
								'virtual_agent_id' => $virtual_agent['id'],
								'expertise'        => $virtual_agent['expertise'],
							)
						);
					} else {
						// Step 4: Last resort - try any generic published assistant.
						$generic_query = new WP_Query(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'post_status'    => 'publish',
								'posts_per_page' => 1,
								'orderby'        => 'rand',
							)
						);

						if ( $generic_query->have_posts() ) {
							$agent_post  = $generic_query->posts[0];
							$agents[]    = array(
								'id'        => $agent_post->ID,
								'title'     => $agent_post->post_title,
								'name'      => $agent_post->post_title,
								'role'      => 'generalist',
								'expertise' => array(),
							);
							$agent_found = true;

							WP_MCP_AI_Logger::log_event(
								'team_composition_fallback_generic',
								sprintf( 'No suitable agents found for "%s", using random generic assistant as absolute last resort', $role ),
								array(
									'required_role'       => $role,
									'fallback_agent_id'   => $agent_post->ID,
									'fallback_agent_name' => $agent_post->post_title,
									'warning'             => 'Generic assistant has no specific configuration for this role',
								)
							);
						}
					}
				}
			}

			if ( ! $agent_found ) {
				$missing_roles[] = $role;
			}

			wp_reset_postdata();
		}

		// Log missing roles for debugging.
		if ( ! empty( $missing_roles ) ) {
			WP_MCP_AI_Logger::log_error(
				'team_composition_missing_agents',
				'Could not find agents for required roles after all fallbacks',
				array(
					'missing_roles'  => $missing_roles,
					'required_roles' => $roles,
					'task_type'      => isset( $task_requirements['task_type'] ) ? $task_requirements['task_type'] : 'unknown',
					'agents_found'   => count( $agents ),
				)
			);
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
			WP_MCP_AI_Logger::log_event(
				'info',
				sprintf( 'Team %s: %s', $team_id, $action ),
				array_merge(
					array(
						'team_id' => $team_id,
						'action'  => $action,
					),
					$data
				)
			);
		}
	}

	/**
	 * Check system capacity before composing team
	 *
	 * Ensures sufficient capacity available for multi-agent workflow.
	 * Phase 2.3: Multi-Agent Integration with capacity awareness.
	 *
	 * @param array $task_requirements Task requirements.
	 * @return true|WP_Error True if capacity sufficient, error otherwise.
	 */
	protected function check_system_capacity_for_team( $task_requirements ) {
		$monitor = $this->get_load_monitor();
		if ( ! $monitor ) {
			// Load monitor not available - allow team composition.
			return true;
		}

		// Get system capacity metrics.
		$system_metrics = $monitor->get_system_load_metrics();

		// Check if system is in critical state.
		if ( 'critical' === $system_metrics['health_status'] ) {
			// Check if this is a critical priority request.
			$is_critical = isset( $task_requirements['priority'] ) && 'critical' === $task_requirements['priority'];

			if ( ! $is_critical ) {
				return new WP_Error(
					'wp_mcp_ai_insufficient_capacity',
					sprintf(
						/* translators: %s: health status */
						__( 'System capacity is %s. Team workflow deferred to prevent overload.', 'mcp-ai-wpoos' ),
						$system_metrics['health_status']
					),
					array(
						'health_status'       => $system_metrics['health_status'],
						'available_capacity'  => $system_metrics['available_capacity'],
						'overall_utilization' => $system_metrics['overall_utilization'],
					)
				);
			}
		}

		// Log capacity check.
		$this->log_team_action(
			'capacity_check',
			'Team composition capacity check',
			array(
				'task_type'          => isset( $task_requirements['task_type'] ) ? $task_requirements['task_type'] : 'unknown',
				'health_status'      => $system_metrics['health_status'],
				'available_capacity' => $system_metrics['available_capacity'],
			)
		);

		return true;
	}

	/**
	 * Find profession-based agent for role
	 *
	 * Queries profession CPT with agent_role metadata.
	 * Phase 2.3: Multi-Agent Integration.
	 *
	 * @param string $role Agent role (planner, executor, critic, etc.).
	 * @param array  $task_requirements Task requirements.
	 * @return array|null Agent data or null.
	 */
	protected function find_profession_agent_for_role( $role, $task_requirements = array() ) {
		// Query professions with agent_role meta.
		$args = array(
			'post_type'      => 'mcp_ai_profession',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'meta_query'     => array(
				array(
					'key'     => '_agent_role',
					'value'   => $role,
					'compare' => '=',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return null;
		}

		// Get first matching profession.
		$query->the_post();
		$post_id = get_the_ID();

		$profession_data = array(
			'id'         => $post_id,
			'role'       => $role,
			'type'       => 'profession',
			'profession' => get_post_field( 'post_name', $post_id ),
			'name'       => get_the_title(),
			'tools'      => get_post_meta( $post_id, '_tool_slugs', true ),
			'config'     => get_post_meta( $post_id, '_orchestration_config', true ),
		);

		wp_reset_postdata();

		return $profession_data;
	}

	/**
	 * Find generic agent for role (fallback)
	 *
	 * @param string $role Agent role.
	 * @return array|null Agent data or null.
	 */
	protected function find_generic_agent_for_role( $role ) {
		// Get generic agent by role.
		$agent_role = wp_mcp_ai_get_agent_role( $role );

		if ( ! $agent_role ) {
			return null;
		}

		return array(
			'id'   => 'generic_' . $role,
			'role' => $role,
			'type' => 'generic',
			'name' => ucfirst( $role ) . ' Agent',
		);
	}

	/**
	 * Create a virtual agent for a role as last resort
	 *
	 * Creates a virtual agent that can execute basic role functions
	 * even when no assistants or professions are available.
	 *
	 * @param string $role Agent role (planner, executor, critic).
	 * @return array Virtual agent data.
	 */
	protected function create_virtual_agent_for_role( $role ) {
		$role_definitions = array(
			'planner'  => array(
				'name'      => __( 'Virtual Planner', 'mcp-ai-wpoos' ),
				'expertise' => array( 'task decomposition', 'strategic planning', 'workflow design' ),
			),
			'executor' => array(
				'name'      => __( 'Virtual Executor', 'mcp-ai-wpoos' ),
				'expertise' => array( 'task execution', 'content creation', 'problem solving' ),
			),
			'critic'   => array(
				'name'      => __( 'Virtual Critic', 'mcp-ai-wpoos' ),
				'expertise' => array( 'quality assurance', 'validation', 'feedback' ),
			),
		);

		$definition = isset( $role_definitions[ $role ] ) ? $role_definitions[ $role ] : array(
			/* translators: %s: Role name */
			'name'      => sprintf( __( 'Virtual %s', 'mcp-ai-wpoos' ), ucfirst( $role ) ),
			'expertise' => array(),
		);

		return array(
			'id'        => 'virtual_' . $role . '_' . wp_generate_uuid4(),
			'title'     => $definition['name'],
			'name'      => $definition['name'],
			'role'      => $role,
			'type'      => 'virtual',
			'expertise' => $definition['expertise'],
		);
	}

	/**
	 * Get profession data by slug
	 *
	 * Retrieves profession information including expertise areas.
	 *
	 * @param string $profession_slug Profession slug.
	 * @return array|null Profession data or null.
	 */
	protected function get_profession_data( $profession_slug ) {
		// Check if professions are stored as posts.
		$profession_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'name'           => $profession_slug,
				'posts_per_page' => 1,
			)
		);

		if ( $profession_query->have_posts() ) {
			$profession_post = $profession_query->posts[0];
			$expertise       = get_post_meta( $profession_post->ID, '_expertise', true );

			wp_reset_postdata();

			return array(
				'id'        => $profession_post->ID,
				'slug'      => $profession_slug,
				'name'      => $profession_post->post_title,
				'expertise' => is_array( $expertise ) ? $expertise : array(),
			);
		}

		wp_reset_postdata();

		// Fallback: Check if there's a profession class/registry.
		if ( function_exists( 'wp_mcp_ai_get_profession' ) ) {
			return wp_mcp_ai_get_profession( $profession_slug );
		}

		return null;
	}

	/**
	 * Get load monitor instance
	 *
	 * @return WP_MCP_AI_Tool_Load_Monitor|null
	 */
	protected function get_load_monitor() {
		if ( null === $this->load_monitor && class_exists( 'WP_MCP_AI_Tool_Load_Monitor' ) ) {
			$this->load_monitor = new WP_MCP_AI_Tool_Load_Monitor();
		}
		return $this->load_monitor;
	}

	/**
	 * Get tool orchestrator instance
	 *
	 * @return WP_MCP_AI_Tool_Execution_Orchestrator|null
	 */
	protected function get_tool_orchestrator() {
		if ( null === $this->tool_orchestrator && class_exists( 'WP_MCP_AI_Tool_Execution_Orchestrator' ) ) {
			$this->tool_orchestrator = new WP_MCP_AI_Tool_Execution_Orchestrator();
		}
		return $this->tool_orchestrator;
	}

	/**
	 * Get system capacity metrics for team
	 *
	 * Public method for external capacity queries.
	 * Phase 2.3: Multi-Agent Integration.
	 *
	 * @return array|null Capacity metrics or null.
	 */
	public function get_team_capacity_metrics() {
		$monitor = $this->get_load_monitor();
		if ( ! $monitor ) {
			return null;
		}

		return $monitor->get_system_load_metrics();
	}

	/**
	 * Get workflow state
	 *
	 * @return array Current workflow state.
	 */
	protected function get_workflow_state() {
		return $this->workflow_state;
	}

	/**
	 * Update workflow state
	 *
	 * @param string $task_id Task identifier.
	 * @param mixed  $result Task result.
	 */
	protected function update_workflow_state( $task_id, $result ) {
		$this->workflow_state[ $task_id ] = array(
			'result'       => $result,
			'completed_at' => current_time( 'mysql' ),
			'status'       => is_wp_error( $result ) ? 'failed' : 'completed',
		);
	}

	/**
	 * Get completed steps
	 *
	 * @return array Completed workflow steps.
	 */
	protected function get_completed_steps() {
		return $this->completed_steps;
	}

	/**
	 * Add completed step
	 *
	 * @param string $task_id Task identifier.
	 * @param array  $step_data Step data.
	 */
	protected function add_completed_step( $task_id, $step_data ) {
		$this->completed_steps[ $task_id ] = $step_data;
	}

	/**
	 * Check if task is completed (idempotency)
	 *
	 * @param string $task_id Task identifier.
	 * @return bool True if task is completed, false otherwise.
	 */
	protected function is_task_completed( $task_id ) {
		return isset( $this->workflow_state[ $task_id ] );
	}

	/**
	 * Get cached task result (idempotency)
	 *
	 * @param string $task_id Task identifier.
	 * @return mixed|null Cached result or null.
	 */
	protected function get_cached_task_result( $task_id ) {
		if ( isset( $this->workflow_state[ $task_id ] ) ) {
			return $this->workflow_state[ $task_id ]['result'];
		}
		return null;
	}

	/**
	 * Cache task result (idempotency)
	 *
	 * @param string $task_id Task identifier.
	 * @param mixed  $result Task result.
	 */
	protected function cache_task_result( $task_id, $result ) {
		$this->update_workflow_state( $task_id, $result );
	}

	/**
	 * Log execution event
	 *
	 * @param string $event_type Event type.
	 * @param array  $data Event data.
	 */
	protected function log_execution( $event_type, $data = array() ) {
		$this->execution_history[] = array_merge(
			array(
				'event'     => $event_type,
				'timestamp' => current_time( 'mysql' ),
			),
			$data
		);

		// Also log to WordPress logger if available.
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			wp_mcp_ai_log(
				sprintf( 'Orchestrator: %s', $event_type ),
				'debug',
				$data
			);
		}
	}

	/**
	 * Get execution history
	 *
	 * @return array Execution history.
	 */
	public function get_execution_history() {
		return $this->execution_history;
	}

	/**
	 * Save workflow data to dashboard transient
	 *
	 * Stores workflow execution data so it appears on the orchestration dashboard.
	 *
	 * @param string $workflow_id Unique workflow identifier.
	 * @param array  $workflow_data Workflow execution data.
	 * @return bool True on success, false on failure.
	 */
	protected function save_workflow_to_dashboard( $workflow_id, $workflow_data ) {
		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		
		// Store workflow data for 7 days.
		return set_transient( $transient_key, $workflow_data, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Save team as workflow for dashboard tracking
	 *
	 * Converts a team composition into a workflow record so it appears
	 * on the orchestration dashboard even before workflow execution begins.
	 *
	 * @param array $team Team configuration.
	 * @return bool True on success, false on failure.
	 */
	protected function save_team_as_workflow( $team ) {
		if ( empty( $team['team_id'] ) ) {
			return false;
		}

		// Create workflow tasks from team members and workflow steps.
		$tasks = array();
		
		// Add team composition as initial task.
		$tasks[] = array(
			'task_id'      => 'compose_' . $team['team_id'],
			'name'         => __( 'Team Composition', 'mcp-ai-wpoos' ),
			'type'         => 'composition',
			'status'       => 'completed',
			'completed_at' => $team['created_at'],
		);

		// Add workflow steps as pending tasks.
		if ( isset( $team['workflow'] ) && is_array( $team['workflow'] ) ) {
			foreach ( $team['workflow'] as $index => $step ) {
				$tasks[] = array(
					'task_id' => 'step_' . $index . '_' . $team['team_id'],
					'name'    => $step['name'],
					'type'    => $step['type'],
					'role'    => isset( $step['role'] ) ? $step['role'] : null,
					'status'  => 'pending',
				);
			}
		}

		// Build workflow data structure.
		$workflow_data = array(
			'workflow_id'  => 'wf_' . $team['team_id'],
			'team_id'      => $team['team_id'],
			'task_type'    => $team['task_type'],
			'state'        => 'initialized',
			'tasks'        => $tasks,
			'members'      => $team['members'],
			'created_at'   => $team['created_at'],
			'updated_at'   => $team['created_at'],
			'started_at'   => null,
			'completed_at' => null,
		);

		return $this->save_workflow_to_dashboard( $workflow_data['workflow_id'], $workflow_data );
	}

	/**
	 * Get workflow by ID
	 *
	 * Retrieves workflow data from transient storage.
	 *
	 * @param string $workflow_id Workflow ID.
	 * @return array|null Workflow data or null if not found.
	 */
	public function get_workflow( $workflow_id ) {
		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		$workflow_data = get_transient( $transient_key );

		return $workflow_data ? $workflow_data : null;
	}

	/**
	 * Check workflow health status
	 *
	 * Checks if workflow is stale (stuck in initialized state for too long).
	 * Important for WordPress plugins where workflows may wait for cron/async processing.
	 *
	 * @param string $workflow_id Workflow ID.
	 * @return array Health status with recommendations.
	 */
	public function check_workflow_health( $workflow_id ) {
		$workflow = $this->get_workflow( $workflow_id );

		if ( ! $workflow ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ),
			);
		}

		$current_time = time();
		$created_time = strtotime( $workflow['created_at'] );
		$age_seconds  = $current_time - $created_time;
		$age_minutes  = round( $age_seconds / 60, 1 );

		$health = array(
			'workflow_id'  => $workflow_id,
			'state'        => $workflow['state'],
			'age_seconds'  => $age_seconds,
			'age_minutes'  => $age_minutes,
			'created_at'   => $workflow['created_at'],
			'started_at'   => $workflow['started_at'] ?? null,
			'completed_at' => $workflow['completed_at'] ?? null,
			'status'       => 'healthy',
			'warnings'     => array(),
			'recommendations' => array(),
		);

		// Check for workflows stuck in initialized state.
		// WordPress plugins need time for cron setup, so we allow 5 minutes.
		$initialized_timeout = 300; // 5 minutes.

		if ( 'initialized' === $workflow['state'] ) {
			if ( $age_seconds > $initialized_timeout ) {
				$health['status']     = 'warning';
				$health['warnings'][] = sprintf(
					/* translators: %s: age in minutes */
					__( 'Workflow has been initialized for %s minutes without starting.', 'mcp-ai-wpoos' ),
					$age_minutes
				);
				$health['recommendations'][] = __( 'Call execute_workflow() to start the workflow, or use delegate_to_agent to assign tasks to team members.', 'mcp-ai-wpoos' );
				$health['recommendations'][] = __( 'For WordPress plugins, ensure wp-cron is running: wp cron event run --due-now', 'mcp-ai-wpoos' );
			} else {
				$health['status']  = 'pending';
				$health['message'] = sprintf(
					/* translators: 1: age in minutes, 2: timeout in minutes */
					__( 'Workflow is waiting to start (%1$s minutes old, timeout at %2$s minutes).', 'mcp-ai-wpoos' ),
					$age_minutes,
					round( $initialized_timeout / 60, 1 )
				);
			}
		}

		return $health;
	}

	/**
	 * Update workflow task status
	 *
	 * Updates a specific task's status within a workflow and recalculates overall workflow state.
	 * Used when tasks are completed via delegation or other async mechanisms.
	 *
	 * @param string $workflow_id Workflow ID.
	 * @param string $task_name   Task name or identifier.
	 * @param string $status      New status: 'pending', 'completed', 'failed'.
	 * @param array  $task_data   Optional additional task data (error, result, etc.).
	 * @return bool True on success, false if workflow not found.
	 */
	public function update_workflow_task_status( $workflow_id, $task_name, $status, $task_data = array() ) {
		$workflow = $this->get_workflow( $workflow_id );
		
		if ( ! $workflow ) {
			return false;
		}

		// Update task status in workflow tasks array.
		if ( isset( $workflow['tasks'] ) && is_array( $workflow['tasks'] ) ) {
			foreach ( $workflow['tasks'] as $key => $task ) {
				if ( isset( $task['name'] ) && $task['name'] === $task_name ) {
					$workflow['tasks'][ $key ]['status']       = $status;
					$workflow['tasks'][ $key ]['updated_at']   = current_time( 'mysql' );
					
					// Add completion timestamp for completed/failed tasks.
					if ( in_array( $status, array( 'completed', 'failed' ), true ) ) {
						$workflow['tasks'][ $key ]['completed_at'] = current_time( 'mysql' );
					}
					
					// Merge additional task data.
					if ( ! empty( $task_data ) ) {
						$workflow['tasks'][ $key ] = array_merge( $workflow['tasks'][ $key ], $task_data );
					}
					
					break;
				}
			}
		}

		// Recalculate workflow state based on task statuses.
		$total_tasks      = count( $workflow['tasks'] );
		$completed_tasks  = 0;
		$failed_tasks     = 0;
		
		foreach ( $workflow['tasks'] as $task ) {
			if ( isset( $task['status'] ) ) {
				if ( 'completed' === $task['status'] ) {
					++$completed_tasks;
				} elseif ( 'failed' === $task['status'] ) {
					++$failed_tasks;
				}
			}
		}

		// Update workflow state.
		if ( $completed_tasks === $total_tasks ) {
			$workflow['state']        = 'completed';
			$workflow['completed_at'] = current_time( 'mysql' );
		} elseif ( $failed_tasks > 0 && ( $completed_tasks + $failed_tasks ) === $total_tasks ) {
			$workflow['state']        = 'completed_with_errors';
			$workflow['completed_at'] = current_time( 'mysql' );
		} elseif ( $completed_tasks > 0 || $failed_tasks > 0 ) {
			// Some tasks done, update state to running if still initialized.
			if ( 'initialized' === $workflow['state'] ) {
				$workflow['state']      = 'running';
				$workflow['started_at'] = current_time( 'mysql' );
			}
		}

		// Update timestamp and save.
		$workflow['updated_at'] = current_time( 'mysql' );
		$this->save_workflow_to_dashboard( $workflow_id, $workflow );

		return true;
	}
}

