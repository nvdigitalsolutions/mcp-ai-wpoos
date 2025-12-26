<?php
/**
 * Tool for deleting projects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a project.
 */
class WP_MCP_AI_Tool_Delete_Project implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'delete_project';
	}

	public function get_name() {
		return __( 'Delete Project', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Deletes a project. Note: This does not delete associated tasks or events.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'Project ID to delete (required)', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'project_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'database-write', 'destructive' );
	}

	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete projects.', 'wp-mcp-ai' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		if ( ! $project_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Project ID is required.', 'wp-mcp-ai' ) );
		}

		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'wp-mcp-ai' ) );
		}

		$result = wp_delete_post( $project_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete project.', 'wp-mcp-ai' ) );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Project deleted successfully.', 'wp-mcp-ai' ),
			'project_id' => $project_id,
		);
	}
}
