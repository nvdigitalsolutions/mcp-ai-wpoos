<?php
/**
 * Tool for updating projects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing project.
 */
class WP_MCP_AI_Tool_Update_Project implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'update_project';
	}

	public function get_name() {
		return __( 'Update Project', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Updates an existing project. Provide only the fields you want to update.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Project ID to update (required)', 'wp-mcp-ai' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'New project name (optional)', 'wp-mcp-ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New project description (optional)', 'wp-mcp-ai' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'New project status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'New start date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'New end date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'array',
					'description' => __( 'New array of assigned user IDs (optional)', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'integer' ),
				),
			),
			'required'             => array( 'project_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'database-write' );
	}

	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return (bool) get_option( 'wp_mcp_ai_enable_project_management', false );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update projects.', 'wp-mcp-ai' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		
		if ( ! $project_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Project ID is required.', 'wp-mcp-ai' ) );
		}

		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'wp-mcp-ai' ) );
		}

		$post_data = array( 'ID' => $project_id );

		if ( isset( $arguments['name'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			wp_update_post( $post_data );
		}

		if ( isset( $arguments['status'] ) ) {
			update_post_meta( $project_id, '_project_status', sanitize_key( $arguments['status'] ) );
		}

		if ( isset( $arguments['start_date'] ) ) {
			update_post_meta( $project_id, '_project_start_date', sanitize_text_field( $arguments['start_date'] ) );
		}

		if ( isset( $arguments['end_date'] ) ) {
			update_post_meta( $project_id, '_project_end_date', sanitize_text_field( $arguments['end_date'] ) );
		}

		if ( isset( $arguments['assigned_to'] ) ) {
			update_post_meta( $project_id, '_project_assigned_to', array_map( 'absint', $arguments['assigned_to'] ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Project updated successfully.', 'wp-mcp-ai' ),
			'project_id' => $project_id,
		);
	}
}
