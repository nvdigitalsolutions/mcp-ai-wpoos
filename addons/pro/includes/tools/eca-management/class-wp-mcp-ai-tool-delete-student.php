<?php
/**
 * Tool for deleting students.
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
 * Deletes a student.
 */
class WP_MCP_AI_Tool_Delete_Student implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_student';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Student', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a student. Note: This does not automatically unenroll the student from ECAs. This action cannot be undone.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id' => array(
					'type'        => 'integer',
					'description' => __( 'Student ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'student_id' ),
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
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_student',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'school_admin', 'registrar' ),
			'risk_level'            => 'high',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
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
		return ! empty( $settings['enable_eca_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete students.', 'mcp-ai-wpoos-pro' ) );
		}

		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;

		if ( ! $student_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Student ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$student = get_post( $student_id );

		if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_student', __( 'Invalid student ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $student_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete student.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Student deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'student_id' => $student_id,
		);
	}
}
