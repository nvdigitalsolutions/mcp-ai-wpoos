<?php
/**
 * Tool for creating task templates.
 *
 * Allows AI assistants to create reusable task plan templates with
 * structured task definitions that can be instantiated into projects.
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
 * Creates a new task template.
 *
 * Saves as mcp_task_template CPT with markdown content generated
 * from the tasks array. Stores category and task count as post meta.
 */
class WP_MCP_AI_Tool_Create_Task_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Valid priority levels.
	 *
	 * @var string[]
	 */
	const VALID_PRIORITIES = array( 'low', 'medium', 'high', 'urgent' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_task_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Task Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a reusable task template with structured task definitions. Templates contain task checklists that can be instantiated into projects to quickly set up task boards.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Template name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Template description or notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'tasks'       => array(
					'type'        => 'array',
					'description' => __( 'Array of task definitions. Each task has: title (required), description (optional), priority (optional, one of low/medium/high/urgent), estimated_hours (optional).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'           => array(
								'type'        => 'string',
								'description' => __( 'Task title (required)', 'mcp-ai-wpoos-pro' ),
								'minLength'   => 1,
								'maxLength'   => 200,
							),
							'description'     => array(
								'type'        => 'string',
								'description' => __( 'Task description (optional)', 'mcp-ai-wpoos-pro' ),
							),
							'priority'        => array(
								'type'        => 'string',
								'description' => __( 'Task priority (default: medium)', 'mcp-ai-wpoos-pro' ),
								'enum'        => self::VALID_PRIORITIES,
							),
							'estimated_hours' => array(
								'type'        => 'number',
								'description' => __( 'Estimated effort in hours (optional)', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
						),
						'required'   => array( 'title' ),
					),
				),
				'category'    => array(
					'type'        => 'string',
					'description' => __( 'Template category for organization (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'title' ),
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
			'profession_tags'       => array( 'project_manager', 'team_lead' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create task templates.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$category    = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Template title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize and validate tasks.
		$sanitized_tasks = array();
		if ( isset( $arguments['tasks'] ) && is_array( $arguments['tasks'] ) ) {
			foreach ( $arguments['tasks'] as $task ) {
				if ( ! isset( $task['title'] ) || '' === trim( (string) $task['title'] ) ) {
					continue;
				}

				$task_title       = sanitize_text_field( $task['title'] );
				$task_description = isset( $task['description'] ) ? sanitize_text_field( $task['description'] ) : '';
				$task_priority    = isset( $task['priority'] ) ? sanitize_key( $task['priority'] ) : 'medium';
				$task_hours       = isset( $task['estimated_hours'] ) ? floatval( $task['estimated_hours'] ) : 0;

				if ( ! in_array( $task_priority, self::VALID_PRIORITIES, true ) ) {
					$task_priority = 'medium';
				}

				$sanitized_tasks[] = array(
					'title'           => $task_title,
					'description'     => $task_description,
					'priority'        => $task_priority,
					'estimated_hours' => $task_hours,
				);
			}
		}

		// Generate markdown content from tasks.
		$markdown_lines = array();
		if ( ! empty( $description ) ) {
			$markdown_lines[] = $description;
			$markdown_lines[] = '';
		}

		foreach ( $sanitized_tasks as $task ) {
			$line = '- [ ] ' . $task['title'];

			if ( ! empty( $task['description'] ) ) {
				$line .= ' — ' . $task['description'];
			}

			if ( $task['estimated_hours'] > 0 ) {
				$line .= sprintf( ' (%.1fh)', $task['estimated_hours'] );
			}

			$markdown_lines[] = $line;
		}

		$markdown_content = implode( "\n", $markdown_lines );

		// Create template post.
		$post_data = array(
			'post_type'    => 'mcp_task_template',
			'post_title'   => $title,
			'post_content' => $markdown_content,
			'post_excerpt' => ! empty( $description ) ? wp_trim_words( $description, 30 ) : '',
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$template_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Save template metadata.
		update_post_meta( $template_id, '_template_task_count', count( $sanitized_tasks ) );

		if ( ! empty( $category ) ) {
			update_post_meta( $template_id, '_template_category', $category );
		}

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: 1: template title, 2: task count */
				__( 'Task template created: %1$s with %2$d task(s).', 'mcp-ai-wpoos-pro' ),
				$title,
				count( $sanitized_tasks )
			),
			'template_id' => $template_id,
			'template'    => array(
				'id'          => $template_id,
				'title'       => $title,
				'description' => $description,
				'category'    => $category,
				'task_count'  => count( $sanitized_tasks ),
				'tasks'       => $sanitized_tasks,
				'created_at'  => current_time( 'mysql' ),
			),
		);
	}
}
