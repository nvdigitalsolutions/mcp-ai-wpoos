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
class WP_MCP_AI_Tool_Create_Task_Plan {

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
			'description'         => 'Create a markdown-based task plan with checkboxes for autonomous orchestration. Use this to break down complex projects into manageable tasks with progress tracking.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'plan_name'   => array(
						'type'        => 'string',
						'description' => 'Name of the task plan (e.g., "Market Research Q1 2026")',
					),
					'goal'        => array(
						'type'        => 'string',
						'description' => 'Overall objective of the task plan',
					),
					'tasks'       => array(
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
					'project_id'  => array(
						'type'        => 'integer',
						'description' => 'Optional project ID to link this task plan to',
					),
					'template_id' => array(
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
	public function execute( array $arguments = array(), array $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_MCP_AI_Tool_Interface.
		// Validate arguments.
		if ( empty( $arguments['plan_name'] ) || empty( $arguments['goal'] ) || empty( $arguments['tasks'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required arguments: plan_name, goal, and tasks are required',
			);
		}

		// Generate markdown content.
		$markdown = $this->generate_markdown( $arguments );

		// Count tasks.
		$task_count = count( $arguments['tasks'] );

		// Use CCT if available, otherwise CPT.
		$plan_id = $this->create_plan_storage( $arguments, $markdown, $task_count );

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
			'message'         => sprintf(
				'Created task plan "%s" with %d tasks (ID: %d)',
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
	 * @param array  $arguments  Tool arguments.
	 * @param string $markdown   Markdown content.
	 * @param int    $task_count Task count.
	 * @return int|WP_Error Plan ID or error.
	 */
	private function create_plan_storage( $arguments, $markdown, $task_count ) {
		// Try CCT first if JetEngine is active.
		if ( $this->should_use_cct() ) {
			return $this->create_cct( $arguments, $markdown, $task_count );
		}

		// Fallback to CPT.
		return $this->create_cpt( $arguments, $markdown, $task_count );
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
	 * @param array  $arguments  Tool arguments.
	 * @param string $markdown   Markdown content.
	 * @param int    $task_count Task count.
	 * @return int|WP_Error CCT item ID or error.
	 */
	private function create_cct( $arguments, $markdown, $task_count ) {
		$handler = $this->get_cct_handler();

		if ( ! $handler ) {
			return new WP_Error( 'cct_unavailable', 'CCT handler not available' );
		}

		$item_id = $handler->add_item(
			array(
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
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		return $item_id;
	}

	/**
	 * Create task plan as CPT
	 *
	 * @param array  $arguments  Tool arguments.
	 * @param string $markdown   Markdown content.
	 * @param int    $task_count Task count.
	 * @return int|WP_Error Post ID or error.
	 */
	private function create_cpt( $arguments, $markdown, $task_count ) {
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
