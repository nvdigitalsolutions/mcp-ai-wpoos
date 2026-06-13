<?php
/**
 * Tool for creating sprints.
 *
 * Allows AI assistants to create sprints within projects for iterative
 * planning and execution tracking.
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
 * Creates a new sprint within a project.
 *
 * Saves as mcp_ai_sprint CPT (inserted via wp_insert_post) with goal,
 * dates, velocity target, and status stored as post meta.
 */
class WP_MCP_AI_Tool_Create_Sprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Valid sprint statuses.
	 *
	 * @var string[]
	 */
	const VALID_STATUSES = array( 'planning', 'active', 'completed', 'cancelled' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_sprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Sprint', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new sprint within a project. Sprints are time-boxed iterations with a goal, date range, and velocity target. Useful for agile project management and iterative delivery planning.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Sprint name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'goal'            => array(
					'type'        => 'string',
					'description' => __( 'Sprint goal - describes what the sprint aims to achieve (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'project_id'      => array(
					'type'        => 'integer',
					'description' => __( 'ID of the project this sprint belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'start_date'      => array(
					'type'        => 'string',
					'description' => __( 'Sprint start date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'        => array(
					'type'        => 'string',
					'description' => __( 'Sprint end date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'velocity_target' => array(
					'type'        => 'integer',
					'description' => __( 'Target number of tasks/story points the sprint aims to complete (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'             => array( 'title', 'project_id' ),
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
			'post_type'             => 'mcp_ai_sprint',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'scrum_master' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create sprints.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$title           = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$goal            = isset( $arguments['goal'] ) ? sanitize_textarea_field( $arguments['goal'] ) : '';
		$project_id      = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$start_date      = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date        = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$velocity_target = isset( $arguments['velocity_target'] ) ? absint( $arguments['velocity_target'] ) : 0;

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Sprint title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $project_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_project', __( 'A valid project ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify project exists.
		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate dates.
		if ( $start_date && ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $start_date && $end_date && $start_date > $end_date ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date_range', __( 'Start date must be before end date.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create sprint post.
		$post_data = array(
			'post_type'    => 'mcp_ai_sprint',
			'post_title'   => $title,
			'post_content' => $goal,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$sprint_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $sprint_id ) ) {
			return $sprint_id;
		}

		// Save sprint metadata.
		update_post_meta( $sprint_id, '_sprint_goal', $goal );
		update_post_meta( $sprint_id, '_sprint_project_id', $project_id );
		update_post_meta( $sprint_id, '_sprint_status', 'planning' );

		if ( $start_date ) {
			update_post_meta( $sprint_id, '_sprint_start_date', $start_date );
		}

		if ( $end_date ) {
			update_post_meta( $sprint_id, '_sprint_end_date', $end_date );
		}

		if ( $velocity_target > 0 ) {
			update_post_meta( $sprint_id, '_sprint_velocity_target', $velocity_target );
		}

		return array(
			'success'   => true,
			'message'   => sprintf(
				/* translators: %s: sprint title */
				__( 'Sprint created: %s', 'mcp-ai-wpoos-pro' ),
				$title
			),
			'sprint_id' => $sprint_id,
			'sprint'    => array(
				'id'              => $sprint_id,
				'title'           => $title,
				'goal'            => $goal,
				'project_id'      => $project_id,
				'status'          => 'planning',
				'start_date'      => $start_date,
				'end_date'        => $end_date,
				'velocity_target' => $velocity_target > 0 ? $velocity_target : null,
				'created_at'      => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
