<?php
/**
 * Planner Agent Role
 *
 * Decomposes complex tasks into subtasks and coordinates execution.
 * Inspired by DeepSeek V4's task planning capabilities.
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Planner Agent Role class
 *
 * Responsible for:
 * - Analyzing complex tasks
 * - Breaking down into manageable subtasks
 * - Assigning subtasks to executor agents
 * - Defining success criteria
 * - Coordinating overall workflow
 *
 * @since 1.1.0
 */
class AgentRolePlanner extends AgentRoleBase {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->role_type        = 'planner';
		$this->role_name        = __( 'Planner', 'nvoos-content-graph-ai-platform' );
		$this->role_description = __( 'Analyzes complex tasks and breaks them down into coordinated subtasks for other agents to execute.', 'nvoos-content-graph-ai-platform' );

		$this->capabilities = array(
			'can-delegate',
			'can-coordinate',
			'autonomous',
		);

		$this->recommended_tools = array(
			'create_agent_team',
			'delegate_to_agent',
			'aggregate_agent_results',
			'execute_workflow',
		);
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return __(
			'You are a Planner agent responsible for analyzing complex tasks and breaking them down into manageable subtasks. When given a complex task, analyze it carefully and create a structured plan with clear subtasks, dependencies, and success criteria. Delegate subtasks to appropriate executor agents and coordinate their efforts. Think step-by-step and ensure each subtask is well-defined and achievable.',
			'nvoos-content-graph-ai-platform'
		);
	}

	/**
	 * Execute a planning task
	 *
	 * Analyzes the task and decomposes it into subtasks.
	 *
	 * @param array $task Task data including description, context, requirements.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Task result with decomposed subtasks or error.
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
			'Planner agent executing task decomposition',
			'info',
			array(
				'task_description' => $task['description'],
				'assistant_id'     => $context['assistant_id'],
			)
		);

		// Analyze task complexity.
		$complexity = $this->analyze_task_complexity( $task );

		// Decompose into subtasks.
		$subtasks = $this->decompose_task( $task, $complexity );

		// Define success criteria.
		$success_criteria = $this->define_success_criteria( $task, $subtasks );

		// Build execution plan.
		$plan = array(
			'task_id'          => $this->generate_task_id(),
			'original_task'    => $task['description'],
			'complexity'       => $complexity,
			'subtasks'         => $subtasks,
			'success_criteria' => $success_criteria,
			'created_at'       => current_time( 'mysql' ),
			'status'           => 'planned',
		);

		$this->log(
			'Task decomposition complete',
			'info',
			array(
				'task_id'       => $plan['task_id'],
				'subtask_count' => count( $subtasks ),
				'complexity'    => $complexity,
			)
		);

		return $plan;
	}

	/**
	 * Analyze task complexity
	 *
	 * @param array $task Task data.
	 * @return string Complexity level: 'simple', 'moderate', 'complex', 'very_complex'.
	 */
	protected function analyze_task_complexity( $task ) {
		$description = $task['description'];
		$word_count  = str_word_count( $description );

		// Simple heuristic based on description length and keywords.
		$complex_keywords = array( 'analyze', 'research', 'comprehensive', 'detailed', 'multiple', 'various', 'all', 'compare' );
		$keyword_count    = 0;

		foreach ( $complex_keywords as $keyword ) {
			if ( stripos( $description, $keyword ) !== false ) {
				++$keyword_count;
			}
		}

		if ( $word_count > 50 || $keyword_count >= 3 ) {
			return 'very_complex';
		} elseif ( $word_count > 30 || $keyword_count >= 2 ) {
			return 'complex';
		} elseif ( $word_count > 15 || $keyword_count >= 1 ) {
			return 'moderate';
		}

		return 'simple';
	}

	/**
	 * Decompose task into subtasks
	 *
	 * @param array  $task Task data.
	 * @param string $complexity Task complexity level.
	 * @return array Array of subtasks.
	 */
	protected function decompose_task( $task, $complexity ) {
		$subtasks = array();

		// For now, create a simple decomposition structure.
		// In production, this would use AI to intelligently decompose.
		switch ( $complexity ) {
			case 'very_complex':
				$subtasks[] = $this->create_subtask( 'research', __( 'Gather and analyze required information', 'nvoos-content-graph-ai-platform' ), 1 );
				$subtasks[] = $this->create_subtask( 'process', __( 'Process and organize findings', 'nvoos-content-graph-ai-platform' ), 2 );
				$subtasks[] = $this->create_subtask( 'synthesis', __( 'Synthesize comprehensive results', 'nvoos-content-graph-ai-platform' ), 3 );
				$subtasks[] = $this->create_subtask( 'validation', __( 'Validate and refine output', 'nvoos-content-graph-ai-platform' ), 4 );
				break;

			case 'complex':
				$subtasks[] = $this->create_subtask( 'analysis', __( 'Analyze requirements', 'nvoos-content-graph-ai-platform' ), 1 );
				$subtasks[] = $this->create_subtask( 'execution', __( 'Execute main task', 'nvoos-content-graph-ai-platform' ), 2 );
				$subtasks[] = $this->create_subtask( 'review', __( 'Review and finalize', 'nvoos-content-graph-ai-platform' ), 3 );
				break;

			case 'moderate':
				$subtasks[] = $this->create_subtask( 'prepare', __( 'Prepare and gather context', 'nvoos-content-graph-ai-platform' ), 1 );
				$subtasks[] = $this->create_subtask( 'execute', __( 'Execute task', 'nvoos-content-graph-ai-platform' ), 2 );
				break;

			default: // simple.
				$subtasks[] = $this->create_subtask( 'execute', $task['description'], 1 );
				break;
		}

		return $subtasks;
	}

	/**
	 * Create a subtask structure
	 *
	 * @param string $type Subtask type identifier.
	 * @param string $description Subtask description.
	 * @param int    $order Execution order.
	 * @return array Subtask data.
	 */
	protected function create_subtask( $type, $description, $order ) {
		return array(
			'id'           => uniqid( 'subtask_', true ),
			'type'         => $type,
			'description'  => $description,
			'order'        => $order,
			'status'       => 'pending',
			'dependencies' => array(),
			'assigned_to'  => null,
		);
	}

	/**
	 * Define success criteria for the task
	 *
	 * @param array $task Task data.
	 * @param array $subtasks Decomposed subtasks.
	 * @return array Success criteria.
	 */
	protected function define_success_criteria( $task, $subtasks ) {
		return array(
			'all_subtasks_complete' => true,
			'quality_threshold'     => 0.8,
			'max_retries'           => 2,
			'timeout_seconds'       => 300,
		);
	}

	/**
	 * Generate a unique task ID
	 *
	 * @return string Task ID.
	 */
	protected function generate_task_id() {
		return 'task_' . wp_generate_uuid4();
	}
}
