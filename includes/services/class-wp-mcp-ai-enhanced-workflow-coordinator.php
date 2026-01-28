<?php
/**
 * Enhanced Multi-Agent Workflow Coordinator
 *
 * Provides advanced coordination capabilities for multi-agent workflows including:
 * - Parallel task execution
 * - Dependency management
 * - State persistence
 * - Error recovery
 * - Performance optimization
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced workflow coordinator for multi-agent systems
 */
class WP_MCP_AI_Enhanced_Workflow_Coordinator {

	/**
	 * Workflow state storage key
	 */
	const STATE_OPTION_KEY = 'wp_mcp_ai_workflow_states';

	/**
	 * Maximum parallel executions
	 */
	const MAX_PARALLEL_TASKS = 3;

	/**
	 * Orchestrator instance
	 *
	 * @var WP_MCP_AI_Agent_Team_Orchestrator
	 */
	protected $orchestrator;

	/**
	 * Active workflows
	 *
	 * @var array
	 */
	protected $active_workflows = array();

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Agent_Team_Orchestrator|null $orchestrator Optional orchestrator instance.
	 */
	public function __construct( $orchestrator = null ) {
		$this->orchestrator = $orchestrator ?? new WP_MCP_AI_Agent_Team_Orchestrator();
		$this->load_active_workflows();
	}

	/**
	 * Create an enhanced workflow with advanced features
	 *
	 * @param array $config Workflow configuration.
	 * @return array|WP_Error Workflow data or error.
	 */
	public function create_workflow( $config ) {
		// Validate configuration.
		$validation = $this->validate_workflow_config( $config );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Create team.
		$team = $this->orchestrator->compose_team( $config['task_requirements'] );
		if ( is_wp_error( $team ) ) {
			return $team;
		}

		// Build workflow structure.
		$workflow = array(
			'workflow_id'        => $this->generate_workflow_id(),
			'team_id'            => $team['team_id'],
			'team'               => $team,
			'config'             => $config,
			'state'              => 'initialized',
			'tasks'              => array(),
			'task_results'       => array(),
			'dependencies'       => isset( $config['dependencies'] ) ? $config['dependencies'] : array(),
			'parallel_execution' => isset( $config['parallel'] ) ? $config['parallel'] : false,
			'retry_policy'       => isset( $config['retry_policy'] ) ? $config['retry_policy'] : $this->get_default_retry_policy(),
			'timeout'            => isset( $config['timeout'] ) ? $config['timeout'] : 600,
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
			'metadata'           => isset( $config['metadata'] ) ? $config['metadata'] : array(),
		);

		// Decompose tasks if planner is available.
		if ( $this->has_planner( $team ) ) {
			$workflow['tasks'] = $this->decompose_workflow_tasks( $config, $team );
		} else {
			// Create single task.
			$workflow['tasks'] = array(
				array(
					'task_id'      => $this->generate_task_id(),
					'description'  => $config['description'] ?? 'Execute workflow',
					'assigned_to'  => $team['members'][0]['id'] ?? null,
					'status'       => 'pending',
					'dependencies' => array(),
				),
			);
		}

		// Store workflow state.
		$this->save_workflow_state( $workflow );

		WP_MCP_AI_Logger::log_event(
			'enhanced_workflow_created',
			'Enhanced multi-agent workflow created',
			array(
				'workflow_id' => $workflow['workflow_id'],
				'team_id'     => $workflow['team_id'],
				'task_count'  => count( $workflow['tasks'] ),
				'parallel'    => $workflow['parallel_execution'],
			)
		);

		return $workflow;
	}

	/**
	 * Execute workflow with advanced coordination
	 *
	 * @param string $workflow_id Workflow ID.
	 * @return array|WP_Error Execution result or error.
	 */
	public function execute_workflow( $workflow_id ) {
		$workflow = $this->load_workflow_state( $workflow_id );
		if ( ! $workflow ) {
			return new WP_Error(
				'workflow_not_found',
				sprintf(
					/* translators: %s: workflow ID */
					__( 'Workflow not found: %s', 'mcp-ai-wpoos' ),
					$workflow_id
				)
			);
		}

		// Check if already running.
		if ( 'running' === $workflow['state'] ) {
			return new WP_Error(
				'workflow_already_running',
				__( 'Workflow is already running', 'mcp-ai-wpoos' )
			);
		}

		// Update state to running.
		$workflow['state']      = 'running';
		$workflow['started_at'] = current_time( 'mysql' );
		$this->save_workflow_state( $workflow );

		try {
			// Execute based on mode.
			if ( $workflow['parallel_execution'] ) {
				$result = $this->execute_parallel_workflow( $workflow );
			} else {
				$result = $this->execute_sequential_workflow( $workflow );
			}

			// Update workflow state.
			$workflow['state']        = 'completed';
			$workflow['completed_at'] = current_time( 'mysql' );
			$workflow['result']       = $result;
			$this->save_workflow_state( $workflow );

			return $result;

		} catch ( Exception $e ) {
			$workflow['state']         = 'failed';
			$workflow['error']         = $e->getMessage();
			$workflow['error_details'] = array(
				'message' => $e->getMessage(),
				'trace'   => $e->getTraceAsString(),
			);
			$this->save_workflow_state( $workflow );

			WP_MCP_AI_Logger::log_error(
				'workflow_execution_failed',
				'Enhanced workflow execution failed',
				array(
					'workflow_id' => $workflow_id,
					'error'       => $e->getMessage(),
				)
			);

			return new WP_Error(
				'workflow_execution_failed',
				$e->getMessage()
			);
		}
	}

	/**
	 * Execute workflow with parallel task execution
	 *
	 * @param array $workflow Workflow data.
	 * @return array Execution results.
	 */
	protected function execute_parallel_workflow( $workflow ) {
		$tasks       = $workflow['tasks'];
		$completed   = array();
		$pending     = $tasks;
		$results     = array();
		$max_cycles  = 100; // Prevent infinite loops.
		$cycle_count = 0;

		while ( ! empty( $pending ) && $cycle_count < $max_cycles ) {
			++$cycle_count;

			// Find tasks that can be executed (no pending dependencies).
			$executable = array();
			foreach ( $pending as $key => $task ) {
				if ( $this->can_execute_task( $task, $completed ) ) {
					$executable[ $key ] = $task;
				}
			}

			if ( empty( $executable ) ) {
				// Deadlock detection - no tasks can proceed.
				if ( ! empty( $pending ) ) {
					WP_MCP_AI_Logger::log_error(
						'workflow_deadlock',
						'Workflow deadlock detected',
						array(
							'workflow_id'   => $workflow['workflow_id'],
							'pending_tasks' => array_column( $pending, 'task_id' ),
						)
					);
					break;
				}
			}

			// Execute up to MAX_PARALLEL_TASKS at once.
			$batch = array_slice( $executable, 0, self::MAX_PARALLEL_TASKS, true );

			foreach ( $batch as $key => $task ) {
				$result = $this->execute_single_task( $task, $workflow, $results );

				if ( ! is_wp_error( $result ) ) {
					$results[ $task['task_id'] ]   = $result;
					$completed[ $task['task_id'] ] = $task;
					unset( $pending[ $key ] );
				} else {
					// Handle retry logic.
					$retry_result = $this->handle_task_retry( $task, $result, $workflow );
					if ( is_wp_error( $retry_result ) ) {
						// Task failed after retries.
						$results[ $task['task_id'] ] = $retry_result;
						unset( $pending[ $key ] );
					}
				}
			}

			// Small delay between cycles.
			usleep( 100000 ); // 0.1 second.
		}

		return array(
			'workflow_id' => $workflow['workflow_id'],
			'status'      => empty( $pending ) ? 'completed' : 'partial',
			'results'     => $results,
			'completed'   => count( $completed ),
			'total'       => count( $tasks ),
			'cycles'      => $cycle_count,
		);
	}

	/**
	 * Execute workflow sequentially
	 *
	 * @param array $workflow Workflow data.
	 * @return array Execution results.
	 */
	protected function execute_sequential_workflow( $workflow ) {
		$tasks   = $workflow['tasks'];
		$results = array();

		// Sort tasks by order/dependencies.
		usort(
			$tasks,
			function ( $a, $b ) {
				$order_a = isset( $a['order'] ) ? $a['order'] : 0;
				$order_b = isset( $b['order'] ) ? $b['order'] : 0;
				return $order_a - $order_b;
			}
		);

		foreach ( $tasks as $task ) {
			$result = $this->execute_single_task( $task, $workflow, $results );

			if ( is_wp_error( $result ) ) {
				// Handle retry logic.
				$retry_result = $this->handle_task_retry( $task, $result, $workflow );
				if ( is_wp_error( $retry_result ) ) {
					// Critical task failed - stop execution.
					$results[ $task['task_id'] ] = $retry_result;
					break;
				}
				$result = $retry_result;
			}

			$results[ $task['task_id'] ] = $result;
		}

		return array(
			'workflow_id' => $workflow['workflow_id'],
			'status'      => 'completed',
			'results'     => $results,
			'total'       => count( $tasks ),
		);
	}

	/**
	 * Execute a single task
	 *
	 * @param array $task Task data.
	 * @param array $workflow Workflow context.
	 * @param array $previous_results Previous task results.
	 * @return mixed|WP_Error Task result or error.
	 */
	protected function execute_single_task( $task, $workflow, $previous_results ) {
		$team  = $workflow['team'];
		$agent = $this->find_agent_by_id( $team, $task['assigned_to'] );

		if ( ! $agent ) {
			return new WP_Error(
				'agent_not_found',
				sprintf(
					/* translators: %s: agent ID */
					__( 'Agent not found: %s', 'mcp-ai-wpoos' ),
					$task['assigned_to']
				)
			);
		}

		// Prepare task context with previous results.
		$context = array(
			'workflow_id'      => $workflow['workflow_id'],
			'task_id'          => $task['task_id'],
			'previous_results' => $previous_results,
			'assistant_id'     => $agent['id'],
		);

		// Execute via orchestrator.
		$step = array(
			'name'        => $task['task_id'],
			'type'        => 'delegate',
			'role'        => $agent['role'],
			'description' => $task['description'],
		);

		return $this->orchestrator->execute_workflow_step(
			$team,
			$step,
			array( 'description' => $task['description'] ),
			$context,
			$previous_results
		);
	}

	/**
	 * Check if task can be executed (dependencies satisfied)
	 *
	 * @param array $task Task data.
	 * @param array $completed Completed tasks.
	 * @return bool Whether task can execute.
	 */
	protected function can_execute_task( $task, $completed ) {
		if ( empty( $task['dependencies'] ) ) {
			return true;
		}

		foreach ( $task['dependencies'] as $dependency_id ) {
			if ( ! isset( $completed[ $dependency_id ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Handle task retry logic
	 *
	 * @param array    $task Task data.
	 * @param WP_Error $error Task error.
	 * @param array    $workflow Workflow data.
	 * @return mixed|WP_Error Retry result or final error.
	 */
	protected function handle_task_retry( $task, $error, $workflow ) {
		$retry_policy = $workflow['retry_policy'];
		$max_retries  = $retry_policy['max_retries'];

		$retry_count = isset( $task['retry_count'] ) ? $task['retry_count'] : 0;

		if ( $retry_count >= $max_retries ) {
			WP_MCP_AI_Logger::log_error(
				'task_failed_after_retries',
				'Task failed after maximum retries',
				array(
					'task_id'     => $task['task_id'],
					'retry_count' => $retry_count,
					'error'       => $error->get_error_message(),
				)
			);
			return $error;
		}

		// Exponential backoff.
		$delay = $retry_policy['base_delay'] * pow( 2, $retry_count );
		sleep( min( $delay, $retry_policy['max_delay'] ) );

		$task['retry_count'] = $retry_count + 1;

		WP_MCP_AI_Logger::log_event(
			'task_retry_attempt',
			'Retrying failed task',
			array(
				'task_id'     => $task['task_id'],
				'retry_count' => $task['retry_count'],
				'max_retries' => $max_retries,
			)
		);

		return $this->execute_single_task( $task, $workflow, array() );
	}

	/**
	 * Get default retry policy
	 *
	 * @return array Retry policy configuration.
	 */
	protected function get_default_retry_policy() {
		return array(
			'max_retries' => 2,
			'base_delay'  => 1, // seconds.
			'max_delay'   => 10, // seconds.
		);
	}

	/**
	 * Decompose workflow into tasks
	 *
	 * @param array $config Workflow configuration.
	 * @param array $team Team data.
	 * @return array Task list.
	 */
	protected function decompose_workflow_tasks( $config, $team ) {
		// Find planner agent.
		$planner = null;
		foreach ( $team['members'] as $member ) {
			if ( 'planner' === $member['role'] ) {
				$planner = $member;
				break;
			}
		}

		if ( ! $planner ) {
			return array();
		}

		// Use planner to decompose.
		$planner_role = wp_mcp_ai_get_assistant_role( $planner['id'] );
		if ( ! $planner_role ) {
			return array();
		}

		$plan = $planner_role->execute_role_task(
			array(
				'description' => $config['description'] ?? 'Complex workflow task',
				'type'        => $config['task_requirements']['task_type'] ?? 'generic',
			),
			array( 'assistant_id' => $planner['id'] )
		);

		if ( is_wp_error( $plan ) || empty( $plan['subtasks'] ) ) {
			return array();
		}

		// Assign subtasks to executor agents.
		$executors = array_filter(
			$team['members'],
			function ( $member ) {
				return 'executor' === $member['role'] || 'generalist' === $member['role'];
			}
		);

		$tasks = array();
		foreach ( $plan['subtasks'] as $index => $subtask ) {
			$executor = $executors[ $index % count( $executors ) ];
			$tasks[]  = array(
				'task_id'      => $subtask['id'],
				'description'  => $subtask['description'],
				'type'         => $subtask['type'],
				'order'        => $subtask['order'],
				'assigned_to'  => $executor['id'],
				'status'       => 'pending',
				'dependencies' => $subtask['dependencies'] ?? array(),
			);
		}

		return $tasks;
	}

	/**
	 * Validate workflow configuration
	 *
	 * @param array $config Workflow configuration.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	protected function validate_workflow_config( $config ) {
		if ( empty( $config['task_requirements'] ) ) {
			return new WP_Error(
				'invalid_config',
				__( 'Workflow configuration must include task_requirements', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $config['task_requirements']['task_type'] ) ) {
			return new WP_Error(
				'invalid_config',
				__( 'Task requirements must include task_type', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Check if team has a planner
	 *
	 * @param array $team Team data.
	 * @return bool Whether team has planner.
	 */
	protected function has_planner( $team ) {
		foreach ( $team['members'] as $member ) {
			if ( 'planner' === $member['role'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find agent by ID
	 *
	 * @param array  $team Team data.
	 * @param string $agent_id Agent ID.
	 * @return array|null Agent data or null.
	 */
	protected function find_agent_by_id( $team, $agent_id ) {
		foreach ( $team['members'] as $member ) {
			if ( $member['id'] === $agent_id ) {
				return $member;
			}
		}
		return null;
	}

	/**
	 * Save workflow state
	 *
	 * @param array $workflow Workflow data.
	 */
	protected function save_workflow_state( $workflow ) {
		$workflow['updated_at']                             = current_time( 'mysql' );
		$this->active_workflows[ $workflow['workflow_id'] ] = $workflow;

		// Persist to transient.
		set_transient(
			'wp_mcp_ai_workflow_' . $workflow['workflow_id'],
			$workflow,
			DAY_IN_SECONDS
		);
	}

	/**
	 * Load workflow state
	 *
	 * @param string $workflow_id Workflow ID.
	 * @return array|null Workflow data or null.
	 */
	protected function load_workflow_state( $workflow_id ) {
		if ( isset( $this->active_workflows[ $workflow_id ] ) ) {
			return $this->active_workflows[ $workflow_id ];
		}

		$workflow = get_transient( 'wp_mcp_ai_workflow_' . $workflow_id );
		if ( $workflow ) {
			$this->active_workflows[ $workflow_id ] = $workflow;
			return $workflow;
		}

		return null;
	}

	/**
	 * Load active workflows
	 */
	protected function load_active_workflows() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$workflows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_name LIKE %s",
				'%transient%',
				'%wp_mcp_ai_workflow_%'
			)
		);

		foreach ( $workflows as $row ) {
			$workflow = maybe_unserialize( $row->option_value );
			if ( is_array( $workflow ) && isset( $workflow['workflow_id'] ) ) {
				$this->active_workflows[ $workflow['workflow_id'] ] = $workflow;
			}
		}
	}

	/**
	 * Generate workflow ID
	 *
	 * @return string Workflow ID.
	 */
	protected function generate_workflow_id() {
		return 'workflow_' . wp_generate_uuid4();
	}

	/**
	 * Generate task ID
	 *
	 * @return string Task ID.
	 */
	protected function generate_task_id() {
		return 'task_' . wp_generate_uuid4();
	}

	/**
	 * Get workflow status
	 *
	 * @param string $workflow_id Workflow ID.
	 * @return array|WP_Error Status data or error.
	 */
	public function get_workflow_status( $workflow_id ) {
		$workflow = $this->load_workflow_state( $workflow_id );
		if ( ! $workflow ) {
			return new WP_Error(
				'workflow_not_found',
				__( 'Workflow not found', 'mcp-ai-wpoos' )
			);
		}

		return array(
			'workflow_id'  => $workflow['workflow_id'],
			'state'        => $workflow['state'],
			'tasks_total'  => count( $workflow['tasks'] ),
			'tasks_done'   => count(
				array_filter(
					$workflow['tasks'],
					function ( $t ) {
						return 'completed' === ( $t['status'] ?? 'pending' );
					}
				)
			),
			'created_at'   => $workflow['created_at'],
			'updated_at'   => $workflow['updated_at'],
			'started_at'   => $workflow['started_at'] ?? null,
			'completed_at' => $workflow['completed_at'] ?? null,
		);
	}
}
