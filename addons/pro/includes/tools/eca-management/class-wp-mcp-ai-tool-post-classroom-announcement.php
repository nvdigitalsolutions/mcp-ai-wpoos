<?php
/**
 * Tool for posting announcements to a Google Classroom stream.
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
 * Posts an ECA notice to a Google Classroom course stream.
 */
class WP_MCP_AI_Tool_Post_Classroom_Announcement implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'post_classroom_announcement';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Post Classroom Announcement', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Posts an announcement (for example session changes or sign-up deadlines) to a Google Classroom course stream.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Broadcasting an ECA notice where students already look, instead of only emailing.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Graded work; use create_classroom_coursework. Private parent communication; use send_eca_notification or send_eca_parent_report.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'create_classroom_coursework', 'send_eca_notification', 'list_classroom_courses' ),
			'notes'           => __( 'Requires the classroom.announcements scope. Announcements cannot be edited or deleted via the API after posting.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'course_id'     => array(
					'type'        => 'string',
					'description' => __( 'Google Classroom course ID. Defaults to the connection\'s default course.', 'mcp-ai-wpoos-pro' ),
				),
				'text'          => array(
					'type'        => 'string',
					'description' => __( 'Announcement text (required).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'connection_id', 'text' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'external-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_ECA_Classroom_Helper::feature_enabled();
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return WP_MCP_AI_ECA_Classroom_Helper::unavailable_reason();
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
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'school_admin', 'it_admin' ),
			'risk_level'            => 'elevated',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$resolved      = WP_MCP_AI_ECA_Classroom_Helper::get_client( $connection_id, 'manage_options', $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$scope_check = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
			$resolved['credentials'],
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ANNOUNCEMENTS
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$text = isset( $arguments['text'] ) ? sanitize_textarea_field( $arguments['text'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_classroom_text_required', __( 'Announcement text is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$course_id = WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id(
			$resolved['credentials'],
			isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : ''
		);

		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		$announcement = $resolved['client']->create_announcement(
			$course_id,
			array( 'text' => $text )
		);

		if ( is_wp_error( $announcement ) ) {
			return $announcement;
		}

		return array(
			'success'         => true,
			'course_id'       => $course_id,
			'announcement_id' => isset( $announcement['id'] ) ? sanitize_text_field( $announcement['id'] ) : '',
			'message'         => __( 'Announcement posted to the Google Classroom stream.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
