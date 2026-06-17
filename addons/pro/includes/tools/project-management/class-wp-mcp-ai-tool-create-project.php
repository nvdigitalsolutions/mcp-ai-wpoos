<?php
/**
 * Tool for creating projects.
 *
 * Allows AI assistants to create new projects.
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
		return __( 'Create Project', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new project or update an existing project. If project_id is provided, updates the existing project instead of creating a new one. Projects can have a name, description, start/end dates, status, and assigned members. Use this tool for both creating new projects and updating existing ones.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Optional project ID. If provided, updates the existing project instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Project name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Project description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Project status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
					'default'     => 'planning',
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Project start date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'Project end date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'array',
					'description' => __( 'Array of user IDs assigned to this project (optional)', 'mcp-ai-wpoos-pro' ),
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
			'post_type'             => 'mcp_ai_project',
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
		);
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create projects.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if this is an update operation.
		$project_id       = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$is_update        = false;
		$existing_project = null;

		if ( $project_id ) {
			// Verify project exists and user has permission to update it.
			$existing_project = get_post( $project_id );

			if ( ! $existing_project || 'mcp_ai_project' !== $existing_project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_project->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this project.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate and sanitize inputs.
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$status      = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'planning';
		$start_date  = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date    = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$assigned_to = isset( $arguments['assigned_to'] ) && is_array( $arguments['assigned_to'] ) ? array_map( 'absint', $arguments['assigned_to'] ) : array();

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Project name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate status.
		$valid_statuses = array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'planning';
		}

		// Validate dates.
		if ( $start_date && ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate assigned users.
		foreach ( $assigned_to as $user_id ) {
			if ( ! get_user_by( 'id', $user_id ) ) {
				/* translators: %d: user ID */
				return new WP_Error( 'wp_mcp_ai_invalid_user', sprintf( __( 'User ID %d does not exist.', 'mcp-ai-wpoos-pro' ), $user_id ) );
			}
		}

		if ( $is_update ) {
			// Update existing project.
			$post_data = array(
				'ID'           => $project_id,
				'post_title'   => $name,
				'post_content' => $description,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update project metadata.
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

			$project = get_post( $project_id );

			return array(
				'success'    => true,
				'message'    => sprintf(
					/* translators: %s: project name */
					__( 'Project updated: %s', 'mcp-ai-wpoos-pro' ),
					$name
				),
				'project_id' => $project_id,
				'project'    => array(
					'id'          => $project_id,
					'name'        => $name,
					'description' => $description,
					'status'      => $status,
					'start_date'  => $start_date,
					'end_date'    => $end_date,
					'assigned_to' => $assigned_to,
					'updated_at'  => $project->post_modified,
				),
				'updated'    => true,
			);
		} else {
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
				'message'    => __( 'Project created successfully.', 'mcp-ai-wpoos-pro' ),
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
				'updated'    => false,
			);
		}
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
