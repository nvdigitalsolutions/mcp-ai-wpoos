<?php
/**
 * Tool: Update Task Plan
 *
 * Updates task completion status in a task plan.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update Task Plan Tool
 */
class WP_MCP_AI_Pro_Tool_Update_Task_Plan {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'update_task_plan';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'update_task_plan',
			'description'         => 'Update task completion status in a task plan. Use this after completing tasks to track progress in autonomous workflows.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'plan_id'      => array(
						'type'        => 'integer',
						'description' => 'Task plan ID',
					),
					'task_updates' => array(
						'type'        => 'array',
						'description' => 'Array of task status updates',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'task_index' => array(
									'type'        => 'integer',
									'description' => 'Zero-based index of the task in the list',
								),
								'completed'  => array(
									'type'        => 'boolean',
									'description' => 'Whether the task is completed',
								),
							),
							'required'   => array( 'task_index', 'completed' ),
						),
					),
					'new_tasks'    => array(
						'type'        => 'array',
						'description' => 'Optional new tasks to add to the plan',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'text'     => array(
									'type'        => 'string',
									'description' => 'Task description',
								),
								'priority' => array(
									'type'        => 'string',
									'enum'        => array( 'high', 'medium', 'low' ),
									'description' => 'Task priority',
								),
							),
							'required'   => array( 'text' ),
						),
					),
				),
				'required'   => array( 'plan_id' ),
			),
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate arguments.
		if ( empty( $arguments['plan_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: plan_id',
			);
		}

		$plan_id = intval( $arguments['plan_id'] );

		// Get current plan.
		$plan = $this->get_plan( $plan_id );

		if ( ! $plan ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Task plan #%d not found', $plan_id ),
			);
		}

		// Parse current markdown.
		$markdown = $plan['markdown_content'];
		$parsed   = $this->parse_markdown_tasks( $markdown );

		// Apply task updates.
		if ( ! empty( $arguments['task_updates'] ) ) {
			foreach ( $arguments['task_updates'] as $update ) {
				$task_index = intval( $update['task_index'] );
				$completed  = ! empty( $update['completed'] );

				$markdown = $this->update_task_status( $markdown, $task_index, $completed );
			}

			// Reparse to get updated stats.
			$parsed = $this->parse_markdown_tasks( $markdown );
		}

		// Add new tasks if provided.
		if ( ! empty( $arguments['new_tasks'] ) ) {
			foreach ( $arguments['new_tasks'] as $task ) {
				$priority = ! empty( $task['priority'] ) ? ' (Priority: ' . ucfirst( $task['priority'] ) . ')' : '';
				$markdown = preg_replace(
					'/(## Tasks\n(?:- \[[ x]\] .+\n)+)/',
					'$1- [ ] ' . $task['text'] . $priority . "\n",
					$markdown
				);
			}

			// Reparse to get final stats.
			$parsed = $this->parse_markdown_tasks( $markdown );
		}

		// Update storage.
		$success = $this->update_plan_storage( $plan_id, $markdown, $parsed );

		if ( ! $success ) {
			return array(
				'success' => false,
				'error'   => 'Failed to update task plan',
			);
		}

		return array(
			'success'          => true,
			'plan_id'          => $plan_id,
			'task_count'       => $parsed['total'],
			'completed_count'  => $parsed['completed'],
			'progress'         => $parsed['progress'],
			'remaining_tasks'  => $parsed['total'] - $parsed['completed'],
			'updated_markdown' => $markdown,
			'message'          => sprintf(
				'Updated task plan #%d: %d of %d tasks complete (%.1f%%)',
				$plan_id,
				$parsed['completed'],
				$parsed['total'],
				$parsed['progress']
			),
		);
	}

	/**
	 * Get task plan
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function get_plan( $plan_id ) {
		// Try CCT first.
		if ( $this->should_use_cct() ) {
			$handler = $this->get_cct_handler();
			if ( $handler ) {
				$item = $handler->get_item( $plan_id );
				if ( $item ) {
					return $item;
				}
			}
		}

		// Fallback to CPT.
		$post = get_post( $plan_id );
		if ( ! $post || 'mcp_task_plan' !== $post->post_type ) {
			return null;
		}

		return array(
			'plan_name'        => $post->post_title,
			'goal'             => get_post_meta( $post->ID, '_goal', true ),
			'markdown_content' => $post->post_content,
			'task_count'       => get_post_meta( $post->ID, '_task_count', true ),
			'completed_count'  => get_post_meta( $post->ID, '_completed_count', true ),
			'progress'         => get_post_meta( $post->ID, '_progress', true ),
			'status'           => get_post_meta( $post->ID, '_status', true ),
		);
	}

	/**
	 * Parse markdown tasks
	 *
	 * @param string $markdown Markdown content.
	 * @return array
	 */
	private function parse_markdown_tasks( $markdown ) {
		$tasks = array();
		$lines = explode( "\n", $markdown );

		foreach ( $lines as $index => $line ) {
			if ( preg_match( '/^- \[([ x])\] (.+)$/', $line, $matches ) ) {
				$tasks[] = array(
					'completed' => 'x' === $matches[1],
					'text'      => $matches[2],
					'line'      => $index,
				);
			}
		}

		$total     = count( $tasks );
		$completed = count(
			array_filter(
				$tasks,
				function ( $task ) {
					return $task['completed'];
				}
			)
		);
		$progress  = $total > 0 ? ( $completed / $total ) * 100 : 0;

		return array(
			'tasks'     => $tasks,
			'total'     => $total,
			'completed' => $completed,
			'progress'  => round( $progress, 1 ),
		);
	}

	/**
	 * Update task status in markdown
	 *
	 * @param string $markdown   Markdown content.
	 * @param int    $task_index Task index.
	 * @param bool   $completed  Completion status.
	 * @return string
	 */
	private function update_task_status( $markdown, $task_index, $completed ) {
		$lines      = explode( "\n", $markdown );
		$task_count = -1;

		foreach ( $lines as $index => $line ) {
			if ( preg_match( '/^- \[([ x])\] (.+)$/', $line, $matches ) ) {
				++$task_count;
				if ( $task_count === $task_index ) {
					$checkbox        = $completed ? 'x' : ' ';
					$lines[ $index ] = sprintf( '- [%s] %s', $checkbox, $matches[2] );
					break;
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Update plan storage
	 *
	 * @param int    $plan_id  Plan ID.
	 * @param string $markdown Markdown content.
	 * @param array  $parsed   Parsed task data.
	 * @return bool
	 */
	private function update_plan_storage( $plan_id, $markdown, $parsed ) {
		// Try CCT first.
		if ( $this->should_use_cct() ) {
			$handler = $this->get_cct_handler();
			if ( $handler ) {
				return $handler->update_item(
					$plan_id,
					array(
						'markdown_content' => $markdown,
						'task_count'       => $parsed['total'],
						'completed_count'  => $parsed['completed'],
						'progress'         => $parsed['progress'],
						'status'           => $parsed['progress'] >= 100 ? 'completed' : 'active',
						'updated_at'       => current_time( 'mysql' ),
						'completed_at'     => $parsed['progress'] >= 100 ? current_time( 'mysql' ) : null,
					)
				);
			}
		}

		// Fallback to CPT.
		$updated = wp_update_post(
			array(
				'ID'           => $plan_id,
				'post_content' => $markdown,
			)
		);

		if ( $updated ) {
			update_post_meta( $plan_id, '_task_count', $parsed['total'] );
			update_post_meta( $plan_id, '_completed_count', $parsed['completed'] );
			update_post_meta( $plan_id, '_progress', $parsed['progress'] );
			update_post_meta( $plan_id, '_status', $parsed['progress'] >= 100 ? 'completed' : 'active' );
		}

		return (bool) $updated;
	}

	/**
	 * Check if we should use CCT
	 *
	 * @return bool
	 */
	private function should_use_cct() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}

	/**
	 * Get CCT handler
	 *
	 * @return object|null
	 */
	private function get_cct_handler() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return null;
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );
		if ( ! $module ) {
			return null;
		}

		$instance = $module->manager->get_content_types( 'mcp_task_plans' );
		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}
}
