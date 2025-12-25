<?php
/**
 * Tool for creating projects.
 *
 * Allows AI assistants to create new projects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new project.
 */
class WP_MCP_AI_Tool_Create_Project implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_project';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Project', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new project for managing tasks and events. Projects can have a name, description, start/end dates, status, and assigned members.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Project name (required)', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Project description (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 5000,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Project status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
					'default'     => 'planning',
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Project start date in ISO 8601 format (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'Project end date in ISO 8601 format (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'array',
					'description' => __( 'Array of user IDs assigned to this project (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create projects.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$status      = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'planning';
		$start_date  = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date    = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$assigned_to = isset( $arguments['assigned_to'] ) && is_array( $arguments['assigned_to'] ) ? array_map( 'absint', $arguments['assigned_to'] ) : array();

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Project name is required.', 'wp-mcp-ai' ) );
		}

		// Validate status.
		$valid_statuses = array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'planning';
		}

		// Validate dates.
		if ( $start_date && ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ) );
		}

		// Validate assigned users.
		foreach ( $assigned_to as $user_id ) {
			if ( ! get_user_by( 'id', $user_id ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_user', sprintf( __( 'User ID %d does not exist.', 'wp-mcp-ai' ), $user_id ) );
			}
		}

		// Create project post.
		$post_data = array(
			'post_type'    => 'mcp_ai_project',
			'post_title'   => $name,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$project_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $project_id ) ) {
			return $project_id;
		}

		// Save project metadata.
		update_post_meta( $project_id, '_project_status', $status );

		if ( $start_date ) {
			update_post_meta( $project_id, '_project_start_date', $start_date );
		}

		if ( $end_date ) {
			update_post_meta( $project_id, '_project_end_date', $end_date );
		}

		if ( ! empty( $assigned_to ) ) {
			update_post_meta( $project_id, '_project_assigned_to', $assigned_to );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Project created successfully.', 'wp-mcp-ai' ),
			'project_id' => $project_id,
			'project'    => array(
				'id'          => $project_id,
				'name'        => $name,
				'description' => $description,
				'status'      => $status,
				'start_date'  => $start_date,
				'end_date'    => $end_date,
				'assigned_to' => $assigned_to,
				'created_at'  => current_time( 'mysql' ),
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
