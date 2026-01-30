<?php
/**
 * Tool: Create Task Plan
 *
 * Creates a markdown-based task plan with checkboxes for autonomous orchestration.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Task Plan Tool
 */
class WP_MCP_AI_Pro_Tool_Create_Task_Plan {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_task_plan';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'create_task_plan',
			'description'         => 'Create a new task plan or update an existing task plan. If task_plan_id is provided, updates the existing task plan instead of creating a new one. Create a markdown-based task plan with checkboxes for autonomous orchestration. Use this to break down complex projects into manageable tasks with progress tracking. Use this tool for both creating new task plans and updating existing ones.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'task_plan_id' => array(
						'type'        => 'integer',
						'description' => 'Optional task plan ID. If provided, updates the existing task plan instead of creating a new one.',
					),
					'plan_name'    => array(
						'type'        => 'string',
						'description' => 'Name of the task plan (e.g., "Market Research Q1 2026")',
					),
					'goal'         => array(
						'type'        => 'string',
						'description' => 'Overall objective of the task plan',
					),
					'tasks'        => array(
						'type'        => 'array',
						'description' => 'List of tasks with priorities',
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
									'description' => 'Task priority level',
								),
							),
							'required'   => array( 'text' ),
						),
					),
					'project_id'   => array(
						'type'        => 'integer',
						'description' => 'Optional project ID to link this task plan to',
					),
					'template_id'  => array(
						'type'        => 'integer',
						'description' => 'Optional template ID if using a pre-built template',
					),
				),
				'required'   => array( 'plan_name', 'goal', 'tasks' ),
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
		if ( empty( $arguments['plan_name'] ) || empty( $arguments['goal'] ) || empty( $arguments['tasks'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required arguments: plan_name, goal, and tasks are required',
			);
		}

		// Check if this is an update operation.
		$task_plan_id       = isset( $arguments['task_plan_id'] ) ? absint( $arguments['task_plan_id'] ) : 0;
		$is_update          = false;
		$existing_task_plan = null;

		if ( $task_plan_id ) {
			$existing_task_plan = get_post( $task_plan_id );

			if ( ! $existing_task_plan || 'mcp_task_plan' !== $existing_task_plan->post_type ) {
				return array(
					'success' => false,
					'error'   => 'Task plan not found.',
				);
			}

			// Check permissions.
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$is_author       = absint( $existing_task_plan->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return array(
					'success' => false,
					'error'   => 'You do not have permission to update this task plan.',
				);
			}

			$is_update = true;
		}

		// Generate markdown content.
		$markdown = $this->generate_markdown( $arguments );

		// Count tasks.
		$task_count = count( $arguments['tasks'] );

		// Use CCT if available, otherwise CPT.
		$plan_id = $this->create_plan_storage( $arguments, $markdown, $task_count, $task_plan_id, $is_update );

		if ( is_wp_error( $plan_id ) ) {
			return array(
				'success' => false,
				'error'   => $plan_id->get_error_message(),
			);
		}

		return array(
			'success'         => true,
			'plan_id'         => $plan_id,
			'plan_name'       => $arguments['plan_name'],
			'task_count'      => $task_count,
			'completed_count' => 0,
			'progress'        => 0,
			'markdown'        => $markdown,
			'updated'         => $is_update,
			'message'         => sprintf(
				'%s task plan "%s" with %d tasks (ID: %d)',
				$is_update ? 'Updated' : 'Created',
				$arguments['plan_name'],
				$task_count,
				$plan_id
			),
		);
	}

	/**
	 * Generate markdown content from task list
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	private function generate_markdown( $arguments ) {
		$markdown  = "# {$arguments['plan_name']}\n\n";
		$markdown .= "## Goal\n{$arguments['goal']}\n\n";
		$markdown .= "## Tasks\n";

		foreach ( $arguments['tasks'] as $task ) {
			$priority  = ! empty( $task['priority'] ) ? ' (Priority: ' . ucfirst( $task['priority'] ) . ')' : '';
			$markdown .= "- [ ] {$task['text']}{$priority}\n";
		}

		$markdown .= "\n## Status\n";
		$markdown .= "Progress: 0%\n";
		$markdown .= sprintf( "Created: %s\n", current_time( 'Y-m-d H:i:s' ) );

		return $markdown;
	}

	/**
	 * Create plan storage (CCT or CPT)
	 *
	 * @param array  $arguments    Tool arguments.
	 * @param string $markdown     Markdown content.
	 * @param int    $task_count   Task count.
	 * @param int    $task_plan_id Task plan ID for updates.
	 * @param bool   $is_update    Whether this is an update operation.
	 * @return int|WP_Error Plan ID or error.
	 */
	private function create_plan_storage( $arguments, $markdown, $task_count, $task_plan_id = 0, $is_update = false ) {
		// Try CCT first if JetEngine is active.
		if ( $this->should_use_cct() ) {
			return $this->create_cct( $arguments, $markdown, $task_count, $task_plan_id, $is_update );
		}

		// Fallback to CPT.
		return $this->create_cpt( $arguments, $markdown, $task_count, $task_plan_id, $is_update );
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

		// Check if Pro addon has CCT storage enabled.
		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}

	/**
	 * Create task plan in CCT
	 *
	 * @param array  $arguments    Tool arguments.
	 * @param string $markdown     Markdown content.
	 * @param int    $task_count   Task count.
	 * @param int    $task_plan_id Task plan ID for updates.
	 * @param bool   $is_update    Whether this is an update operation.
	 * @return int|WP_Error CCT item ID or error.
	 */
	private function create_cct( $arguments, $markdown, $task_count, $task_plan_id = 0, $is_update = false ) {
		$handler = $this->get_cct_handler();

		if ( ! $handler ) {
			return new WP_Error( 'cct_unavailable', 'CCT handler not available' );
		}

		$item_data = array(
			'plan_name'        => $arguments['plan_name'],
			'goal'             => $arguments['goal'],
			'markdown_content' => $markdown,
			'task_count'       => $task_count,
			'completed_count'  => 0,
			'progress'         => 0,
			'status'           => 'draft',
			'owner_id'         => get_current_user_id(),
			'project_id'       => ! empty( $arguments['project_id'] ) ? $arguments['project_id'] : 0,
			'template_id'      => ! empty( $arguments['template_id'] ) ? $arguments['template_id'] : 0,
			'updated_at'       => current_time( 'mysql' ),
		);

		if ( $is_update ) {
			$item_id = $handler->update_item( $task_plan_id, $item_data );
		} else {
			$item_data['created_at'] = current_time( 'mysql' );
			$item_id                 = $handler->add_item( $item_data );
		}

		return $item_id;
	}

	/**
	 * Create task plan as CPT
	 *
	 * @param array  $arguments    Tool arguments.
	 * @param string $markdown     Markdown content.
	 * @param int    $task_count   Task count.
	 * @param int    $task_plan_id Task plan ID for updates.
	 * @param bool   $is_update    Whether this is an update operation.
	 * @return int|WP_Error Post ID or error.
	 */
	private function create_cpt( $arguments, $markdown, $task_count, $task_plan_id = 0, $is_update = false ) {
		if ( $is_update ) {
			$post_id = wp_update_post(
				array(
					'ID'           => $task_plan_id,
					'post_title'   => $arguments['plan_name'],
					'post_content' => $markdown,
				)
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			// Update meta data.
			update_post_meta( $task_plan_id, '_goal', $arguments['goal'] );
			update_post_meta( $task_plan_id, '_task_count', $task_count );
			update_post_meta( $task_plan_id, '_project_id', ! empty( $arguments['project_id'] ) ? $arguments['project_id'] : 0 );
			update_post_meta( $task_plan_id, '_template_id', ! empty( $arguments['template_id'] ) ? $arguments['template_id'] : 0 );

			return $task_plan_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $arguments['plan_name'],
				'post_content' => $markdown,
				'post_type'    => 'mcp_task_plan',
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
				'meta_input'   => array(
					'_goal'            => $arguments['goal'],
					'_task_count'      => $task_count,
					'_completed_count' => 0,
					'_progress'        => 0,
					'_status'          => 'draft',
					'_project_id'      => ! empty( $arguments['project_id'] ) ? $arguments['project_id'] : 0,
					'_template_id'     => ! empty( $arguments['template_id'] ) ? $arguments['template_id'] : 0,
				),
			)
		);

		return $post_id;
	}

	/**
	 * Get CCT handler for task plans
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
