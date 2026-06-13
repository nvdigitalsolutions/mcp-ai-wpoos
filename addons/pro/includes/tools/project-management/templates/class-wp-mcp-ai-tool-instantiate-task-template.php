<?php
/**
 * Tool for instantiating a task template.
 *
 * Allows AI assistants to create multiple tasks from a task template
 * by parsing its markdown content and creating individual task posts.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantiates a task template into individual tasks.
 *
 * Reads mcp_task_template CPT content (markdown with checkboxes),
 * parses `- [ ] Task name` lines, and creates individual mcp_ai_task
 * posts for each checkbox item under the target project.
 */
class WP_MCP_AI_Tool_Instantiate_Task_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'instantiate_task_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Instantiate Task Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create tasks from a task template. Parses the template markdown content for checkbox items and creates individual tasks under the specified project. Useful for setting up project task boards from reusable templates.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the task template to instantiate (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the project to create tasks under (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'assignee_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional user ID to assign all created tasks to', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'template_id', 'project_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'project_management',
			'post_type'             => 'mcp_task_template',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'developer', 'team_lead' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'state-changing',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to instantiate task templates.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$template_id = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$project_id  = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$assignee_id = isset( $arguments['assignee_id'] ) ? absint( $arguments['assignee_id'] ) : 0;

		if ( $template_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_template', __( 'A valid template ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $project_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_project', __( 'A valid project ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify template exists.
		$template = get_post( $template_id );
		if ( ! $template || 'mcp_task_template' !== $template->post_type ) {
			return new WP_Error( 'wp_mcp_ai_template_not_found', __( 'Task template not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify project exists.
		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify assignee if provided.
		if ( $assignee_id > 0 && ! get_user_by( 'id', $assignee_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_assignee', __( 'Assigned user does not exist.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get template priority from meta.
		$template_priority = get_post_meta( $template_id, '_template_priority', true );
		if ( ! in_array( $template_priority, array( 'low', 'medium', 'high', 'urgent' ), true ) ) {
			$template_priority = 'medium';
		}

		// Parse template content for checkbox items.
		$content  = $template->post_content;
		$task_ids = array();
		$tasks    = array();
		$errors   = array();

		// Match lines like "- [ ] Task Name" or "- [x] Task Name" (both executed).
		preg_match_all( '/^\s*-\s*\[([ xX])\]\s*(.+)$/m', $content, $matches, PREG_SET_ORDER );

		if ( empty( $matches ) ) {
			// Fallback: treat each non-empty line as a task.
			$lines = array_filter(
				array_map( 'trim', explode( "\n", $content ) ),
				function ( $line ) {
					return '' !== $line && ! preg_match( '/^\s*#/', $line );
				}
			);

			foreach ( $lines as $line ) {
				$task_id = $this->create_task_from_line( $line, $project_id, $assignee_id, $template_priority, $current_user_id );

				if ( is_wp_error( $task_id ) ) {
					$errors[] = $task_id->get_error_message();
					continue;
				}

				$task_ids[] = $task_id;
				$tasks[]    = array(
					'id'    => $task_id,
					'title' => $line,
				);
			}
		} else {
			foreach ( $matches as $match ) {
				$task_title = trim( $match[2] );

				if ( '' === $task_title ) {
					continue;
				}

				$task_id = $this->create_task_from_line( $task_title, $project_id, $assignee_id, $template_priority, $current_user_id );

				if ( is_wp_error( $task_id ) ) {
					$errors[] = $task_id->get_error_message();
					continue;
				}

				$task_ids[] = $task_id;
				$tasks[]    = array(
					'id'    => $task_id,
					'title' => $task_title,
				);
			}
		}

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: 1: count of created tasks, 2: template title */
				__( 'Created %1$d task(s) from template: %2$s', 'mcp-ai-wpoos-pro' ),
				count( $task_ids ),
				$template->post_title
			),
			'template_id'   => $template_id,
			'template_title' => $template->post_title,
			'project_id'    => $project_id,
			'created_count' => count( $task_ids ),
			'task_ids'      => $task_ids,
			'tasks'         => $tasks,
			'errors'        => $errors,
		);
	}

	/**
	 * Create a single task from a parsed template line.
	 *
	 * @param string $title       Task title.
	 * @param int    $project_id  Project ID to assign to.
	 * @param int    $assignee_id User ID to assign to (0 for none).
	 * @param string $priority    Task priority.
	 * @param int    $author_id   Post author ID.
	 * @return int|WP_Error Task ID on success, WP_Error on failure.
	 */
	private function create_task_from_line( $title, $project_id, $assignee_id, $priority, $author_id ) {
		$post_data = array(
			'post_type'   => 'mcp_ai_task',
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => 'publish',
			'post_author' => $author_id,
		);

		$task_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		update_post_meta( $task_id, '_task_project_id', $project_id );
		update_post_meta( $task_id, '_task_status', 'todo' );
		update_post_meta( $task_id, '_task_priority', $priority );

		if ( $assignee_id > 0 ) {
			update_post_meta( $task_id, '_task_assigned_to', $assignee_id );
		}

		return $task_id;
	}
}
