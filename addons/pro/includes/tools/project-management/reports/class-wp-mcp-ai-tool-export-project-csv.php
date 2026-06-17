<?php
/**
 * Tool for exporting project tasks to CSV.
 *
 * Exports all tasks belonging to a project as a CSV string with headers
 * for Task ID, Title, Status, Priority, Assignee, Due Date, Project,
 * Dependencies, and Tags.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports project tasks to CSV.
 */
class WP_MCP_AI_Tool_Export_Project_Csv implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_project_csv';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Project CSV', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export all tasks for a project as a CSV-formatted string. Includes Task ID, Title, Status, Priority, Assignee, Due Date, Project name, Dependencies, and Tags.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the project whose tasks to export (required).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'project_id' ),
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
			'post_type'             => 'mcp_ai_task',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'executive', 'data_analyst' ),
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
			'database-read',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export project data.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		if ( ! $project_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_project_id', __( 'Project ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$project = get_post( $project_id );

		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_name = $project->post_title;

		// Fetch all tasks for the project.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_task_project_id',
						'value' => $project_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		// Build CSV.
		$csv = $this->build_csv( $tasks, $project_name );

		$task_count = count( $tasks );

		return array(
			'success'     => true,
			'project_id'  => $project_id,
			'project_name' => $project_name,
			'task_count'  => $task_count,
			'csv'         => $csv,
			'generated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Build CSV string from task posts.
	 *
	 * @param WP_Post[] $tasks        Tasks to export.
	 * @param string    $project_name Project name for the Project column.
	 * @return string
	 */
	private function build_csv( $tasks, $project_name ) {
		// CSV headers.
		$headers = array(
			__( 'Task ID', 'mcp-ai-wpoos-pro' ),
			__( 'Title', 'mcp-ai-wpoos-pro' ),
			__( 'Status', 'mcp-ai-wpoos-pro' ),
			__( 'Priority', 'mcp-ai-wpoos-pro' ),
			__( 'Assignee', 'mcp-ai-wpoos-pro' ),
			__( 'Due Date', 'mcp-ai-wpoos-pro' ),
			__( 'Project', 'mcp-ai-wpoos-pro' ),
			__( 'Dependencies', 'mcp-ai-wpoos-pro' ),
			__( 'Tags', 'mcp-ai-wpoos-pro' ),
		);

		$rows = array();
		$rows[] = $this->csv_row( $headers );

		foreach ( $tasks as $task ) {
			$task_id      = $task->ID;
			$title        = $task->post_title;
			$status   = get_post_meta( $task_id, '_task_status', true );
			$status   = $status ? $status : 'pending';
			$priority = get_post_meta( $task_id, '_task_priority', true );
			$priority = $priority ? $priority : 'medium';
			$due_date = get_post_meta( $task_id, '_task_due_date', true );
			$due_date = $due_date ? $due_date : '';

			// Assignee.
			$assignee_id   = get_post_meta( $task_id, '_task_assigned_to', true );
			$assignee_name = '';

			if ( $assignee_id ) {
				$user = get_user_by( 'id', (int) $assignee_id );
				if ( $user ) {
					$assignee_name = $user->display_name;
				}
			}

			// Dependencies.
			$dependency_ids = get_post_meta( $task_id, '_task_dependencies', true );
			$dependency_str = '';

			if ( is_array( $dependency_ids ) && ! empty( $dependency_ids ) ) {
				$dep_titles = array();

				foreach ( $dependency_ids as $dep_id ) {
					$dep_post = get_post( (int) $dep_id );
					if ( $dep_post && 'mcp_ai_task' === $dep_post->post_type ) {
						$dep_titles[] = '#' . $dep_id . ' ' . $dep_post->post_title;
					}
				}

				$dependency_str = implode( '; ', $dep_titles );
			}

			// Tags from taxonomy.
			$tags       = wp_get_post_terms( $task_id, 'mcp_ai_task_category', array( 'fields' => 'names' ) );
			$tags_str   = '';

			if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
				$tags_str = implode( '; ', $tags );
			}

			$row = array(
				(string) $task_id,
				$title,
				$status,
				$priority,
				$assignee_name,
				$due_date,
				$project_name,
				$dependency_str,
				$tags_str,
			);

			$rows[] = $this->csv_row( $row );
		}

		return implode( "\n", $rows );
	}

	/**
	 * Format an array of values as a single CSV row.
	 *
	 * @param array $values Row values.
	 * @return string
	 */
	private function csv_row( $values ) {
		$escaped = array();

		foreach ( $values as $value ) {
			$val = (string) $value;

			// Escape double quotes and wrap in quotes if the value contains
			// commas, double quotes, or newlines.
			if (
				false !== strpos( $val, ',' ) ||
				false !== strpos( $val, '"' ) ||
				false !== strpos( $val, "\n" ) ||
				false !== strpos( $val, "\r" )
			) {
				$val = '"' . str_replace( '"', '""', $val ) . '"';
			}

			$escaped[] = $val;
		}

		return implode( ',', $escaped );
	}
}
