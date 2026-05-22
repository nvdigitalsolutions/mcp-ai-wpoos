<?php
/**
 * Tool for deleting quizzes.
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
 * Deletes a quiz.
 */
class WP_MCP_AI_Tool_Delete_Quiz implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_quiz';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Quiz', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a quiz. Note: This does not automatically delete associated submissions. This action cannot be undone.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'quiz_id' => array(
					'type'        => 'integer',
					'description' => __( 'Quiz ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'quiz_id' ),
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
			'post_type'             => 'mcp_ai_quiz',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin' ),
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
		return ! empty( $settings['enable_quiz_system'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete quizzes.', 'mcp-ai-wpoos-pro' ) );
		}

		$quiz_id = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;

		if ( ! $quiz_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Quiz ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$quiz = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_quiz', __( 'Invalid quiz ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $quiz_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete quiz.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Quiz deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'quiz_id' => $quiz_id,
		);
	}
}
