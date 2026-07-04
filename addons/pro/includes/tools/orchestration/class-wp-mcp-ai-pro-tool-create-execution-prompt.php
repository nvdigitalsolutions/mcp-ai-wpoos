<?php
/**
 * Tool: Create Execution Prompt
 *
 * Generates a structured, self-contained execution prompt for each iteration
 * of an autonomous orchestration loop. Prevents context-window degradation
 * by providing a fresh prompt with explicit constraints, success criteria,
 * and exit-signal instructions.
 *
 * This implements the Ralph Loop pattern's core innovation: per-iteration
 * context reset via file-based prompt delivery.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Execution Prompt Tool
 */
class WP_MCP_AI_Pro_Tool_Create_Execution_Prompt {

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_execution_prompt';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'create_execution_prompt',
			'description'         => 'Generate a structured execution prompt for the current iteration of an autonomous orchestration loop. Includes the plan objective, current task, available tools, remaining budget/iterations, explicit success criteria, and EXIT_SIGNAL instructions. Use this at the start of each loop iteration to provide a fresh, scoped prompt that prevents context-window degradation.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'plan_id'                => array(
						'type'        => 'integer',
						'description' => 'Task plan ID to generate the prompt from',
					),
					'iteration_number'       => array(
						'type'        => 'integer',
						'description' => 'Current iteration number (used in prompt header)',
						'default'     => 1,
					),
					'previous_result'        => array(
						'type'        => 'string',
						'description' => 'Summary of the previous iteration result to provide continuity',
					),
					'current_task'           => array(
						'type'        => 'string',
						'description' => 'Override: specific task to focus on (if empty, uses next incomplete task from plan)',
					),
					'constraints'            => array(
						'type'        => 'object',
						'description' => 'Execution constraints to include in the prompt',
						'properties'  => array(
							'remaining_iterations' => array(
								'type'        => 'integer',
								'description' => 'Remaining iterations in session budget',
							),
							'remaining_tokens'     => array(
								'type'        => 'integer',
								'description' => 'Remaining tokens in session budget',
							),
							'time_remaining_hours' => array(
								'type'        => 'number',
								'description' => 'Hours remaining before session expiry',
							),
							'max_tool_calls'       => array(
								'type'        => 'integer',
								'description' => 'Maximum tool calls allowed for this iteration',
							),
						),
					),
					'assistant_capabilities' => array(
						'type'        => 'array',
						'description' => 'List of tools/capabilities available to the assistant',
						'items'       => array(
							'type' => 'string',
						),
					),
				),
				'required'   => array( 'plan_id' ),
			),
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by tool interface.
		$plan_id         = isset( $arguments['plan_id'] ) ? absint( $arguments['plan_id'] ) : 0;
		$iteration       = isset( $arguments['iteration_number'] ) ? absint( $arguments['iteration_number'] ) : 1;
		$previous_result = isset( $arguments['previous_result'] ) ? sanitize_textarea_field( $arguments['previous_result'] ) : '';
		$current_task    = isset( $arguments['current_task'] ) ? sanitize_text_field( $arguments['current_task'] ) : '';
		$constraints     = isset( $arguments['constraints'] ) && is_array( $arguments['constraints'] ) ? $arguments['constraints'] : array();
		$capabilities    = isset( $arguments['assistant_capabilities'] ) && is_array( $arguments['assistant_capabilities'] ) ? $arguments['assistant_capabilities'] : array();

		// Load the task plan data.
		$plan_data = $this->get_plan_data( $plan_id );

		if ( ! $plan_data ) {
			return new \WP_Error(
				'plan_not_found',
				sprintf(
					/* translators: %d: plan ID */
					__( 'Task plan #%d not found.', 'mcp-ai-wpoos' ),
					$plan_id
				)
			);
		}

		// Build the prompt sections.
		$prompt = $this->build_prompt(
			$plan_data,
			$iteration,
			$previous_result,
			$current_task,
			$constraints,
			$capabilities
		);

		return array(
			'success'         => true,
			'plan_id'         => $plan_id,
			'iteration'       => $iteration,
			'prompt_content'  => $prompt,
			'prompt_length'   => strlen( $prompt ),
			'next_incomplete' => $this->get_next_incomplete_task( $plan_data ),
			'message'         => sprintf(
				/* translators: 1: plan name, 2: iteration number */
				__( 'Generated execution prompt for "%1$s", iteration %2$d', 'mcp-ai-wpoos' ),
				$plan_data['plan_name'],
				$iteration
			),
		);
	}

	/**
	 * Build the full markdown prompt from sections.
	 *
	 * @param array  $plan            Plan data.
	 * @param int    $iteration       Iteration number.
	 * @param string $previous_result Previous result summary.
	 * @param string $current_task    Current task override.
	 * @param array  $constraints     Budget/time constraints.
	 * @param array  $capabilities    Available tools.
	 * @return string Markdown prompt.
	 */
	private function build_prompt(
		array $plan,
		$iteration,
		$previous_result,
		$current_task,
		array $constraints,
		array $capabilities
	) {
		$markdown  = '# Execution Prompt: ' . esc_html( $plan['plan_name'] ) . ' — Iteration ' . absint( $iteration ) . "\n\n";
		$markdown .= "## Objective\n";
		$markdown .= esc_html( $plan['goal'] ) . "\n\n";

		// Current task.
		$markdown .= "## Current Task\n";
		if ( ! empty( $current_task ) ) {
			$markdown .= esc_html( $current_task ) . "\n\n";
		} else {
			$next = $this->get_next_incomplete_task( $plan );
			if ( $next ) {
				$markdown .= esc_html( $next ) . "\n\n";
			} else {
				$markdown .= "All tasks are complete. Verify work and emit EXIT_SIGNAL.\n\n";
			}
		}

		// Progress.
		$markdown .= "## Progress\n";
		$markdown .= sprintf(
			"Completed: %d / %d tasks (%.1f%%)\n",
			absint( $plan['completed_count'] ),
			absint( $plan['task_count'] ),
			(float) $plan['progress']
		);
		$markdown .= 'Status: ' . esc_html( $plan['status'] ) . "\n\n";

		// Previous result (continuity).
		if ( ! empty( $previous_result ) ) {
			$markdown .= "## Previous Iteration Result\n";
			$markdown .= esc_html( $previous_result ) . "\n\n";
		}

		// Available tools.
		if ( ! empty( $capabilities ) ) {
			$markdown .= "## Available Tools\n";
			foreach ( $capabilities as $tool ) {
				$markdown .= '- ' . esc_html( $tool ) . "\n";
			}
			$markdown .= "\n";
		}

		// Constraints.
		$markdown       .= "## Constraints\n";
		$has_constraints = false;

		if ( ! empty( $constraints['remaining_iterations'] ) ) {
			$markdown       .= sprintf(
				"- Remaining iterations: %d\n",
				absint( $constraints['remaining_iterations'] )
			);
			$has_constraints = true;
		}
		if ( ! empty( $constraints['remaining_tokens'] ) ) {
			$markdown       .= sprintf(
				"- Remaining tokens: %s\n",
				number_format( absint( $constraints['remaining_tokens'] ) )
			);
			$has_constraints = true;
		}
		if ( ! empty( $constraints['time_remaining_hours'] ) ) {
			$markdown       .= sprintf(
				"- Time remaining: %.1f hours\n",
				(float) $constraints['time_remaining_hours']
			);
			$has_constraints = true;
		}
		if ( ! empty( $constraints['max_tool_calls'] ) ) {
			$markdown       .= sprintf(
				"- Max tool calls this iteration: %d\n",
				absint( $constraints['max_tool_calls'] )
			);
			$has_constraints = true;
		}

		if ( ! $has_constraints ) {
			$markdown .= "- No specific constraints. Proceed efficiently.\n";
		}
		$markdown .= "\n";

		// Success criteria.
		$markdown .= "## Success Criteria\n";
		if ( ! empty( $plan['success_criteria'] ) ) {
			$markdown .= esc_html( $plan['success_criteria'] ) . "\n\n";
		} else {
			$markdown .= "- Current task is completed\n";
			$markdown .= "- All work is verified and tested\n";
			$markdown .= "- No errors or blockers remain\n\n";
		}

		// Exit signal instructions.
		$markdown .= "## Exit Signal\n";
		$markdown .= "Report **EXIT_SIGNAL: true** when:\n";
		$markdown .= "1. All tasks in the plan are complete\n";
		$markdown .= "2. All success criteria are met\n";
		$markdown .= "3. All work has been verified\n";
		$markdown .= "4. No errors, warnings, or blockers remain\n\n";
		$markdown .= "Do NOT emit EXIT_SIGNAL: true until ALL conditions above are satisfied.\n";

		return $markdown;
	}

	/**
	 * Get task plan data from storage.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function get_plan_data( $plan_id ) {
		// Try CCT first if JetEngine is active.
		$cct_data = $this->get_plan_from_cct( $plan_id );
		if ( $cct_data ) {
			return $cct_data;
		}

		// Fallback to CPT.
		return $this->get_plan_from_cpt( $plan_id );
	}

	/**
	 * Get plan from CCT.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function get_plan_from_cct( $plan_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Task_Plans_CCT' ) ) {
			return null;
		}

		$handler = WP_MCP_AI_Task_Plans_CCT::get_item_handler();
		if ( ! $handler ) {
			return null;
		}

		$factory = $handler->get_factory();
		if ( ! $factory || empty( $factory->db ) ) {
			return null;
		}

		$items = $factory->db->query(
			array(
				'_ID'   => $plan_id,
				'limit' => 1,
			)
		);

		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}

		$item = reset( $items );

		return array(
			'plan_name'        => isset( $item['plan_name'] ) ? $item['plan_name'] : '',
			'goal'             => isset( $item['goal'] ) ? $item['goal'] : '',
			'markdown_content' => isset( $item['markdown_content'] ) ? $item['markdown_content'] : '',
			'task_count'       => isset( $item['task_count'] ) ? (int) $item['task_count'] : 0,
			'completed_count'  => isset( $item['completed_count'] ) ? (int) $item['completed_count'] : 0,
			'progress'         => isset( $item['progress'] ) ? (float) $item['progress'] : 0,
			'status'           => isset( $item['status'] ) ? $item['status'] : 'unknown',
			'tasks_parsed'     => isset( $item['tasks_parsed'] ) ? $item['tasks_parsed'] : '[]',
			'success_criteria' => isset( $item['success_criteria'] ) ? $item['success_criteria'] : '',
		);
	}

	/**
	 * Get plan from CPT.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function get_plan_from_cpt( $plan_id ) {
		$post = get_post( $plan_id );

		if ( ! $post || 'mcp_task_plan' !== $post->post_type ) {
			return null;
		}

		return array(
			'plan_name'        => $post->post_title,
			'goal'             => get_post_meta( $plan_id, '_goal', true ),
			'markdown_content' => $post->post_content,
			'task_count'       => (int) get_post_meta( $plan_id, '_task_count', true ),
			'completed_count'  => (int) get_post_meta( $plan_id, '_completed_count', true ),
			'progress'         => (float) get_post_meta( $plan_id, '_progress', true ),
			'status'           => get_post_meta( $plan_id, '_status', true ),
			'tasks_parsed'     => get_post_meta( $plan_id, '_tasks_parsed', true ),
			'success_criteria' => get_post_meta( $plan_id, '_success_criteria', true ),
		);
	}

	/**
	 * Extract the next incomplete task from plan markdown or parsed tasks.
	 *
	 * @param array $plan Plan data.
	 * @return string|null
	 */
	private function get_next_incomplete_task( array $plan ) {
		// Try parsed tasks JSON first.
		if ( ! empty( $plan['tasks_parsed'] ) ) {
			$tasks = json_decode( $plan['tasks_parsed'], true );
			if ( is_array( $tasks ) ) {
				foreach ( $tasks as $task ) {
					if ( empty( $task['completed'] ) ) {
						return isset( $task['text'] ) ? $task['text'] : ( isset( $task['title'] ) ? $task['title'] : '' );
					}
				}
			}
		}

		// Fallback: parse markdown for unchecked tasks.
		if ( ! empty( $plan['markdown_content'] ) ) {
			$lines = explode( "\n", $plan['markdown_content'] );
			foreach ( $lines as $line ) {
				if ( preg_match( '/^\s*-\s*\[ \]\s*(.+)$/', $line, $matches ) ) {
					return trim( $matches[1] );
				}
			}
		}

		return null;
	}
}
