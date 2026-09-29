<?php
/**
 * Tool for creating a Google Classroom course.
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
 * Creates a Google Classroom course, optionally linking it to an ECA.
 */
class WP_MCP_AI_Tool_Create_Classroom_Course implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_classroom_course';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Google Classroom Course', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Provisions a Google Classroom course under the connected teacher account, optionally linking the new course to an existing ECA record.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Provisioning a Classroom course when a new ECA is approved, or standing up a course shell before roster import.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Editing an existing course; use update_classroom_course. Linking an existing course; use link_classroom_course_to_eca.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'update_classroom_course', 'link_classroom_course_to_eca', 'list_classroom_courses' ),
			'notes'           => __( 'Requires the classroom.courses scope. The new course is owned by the connected account.', 'mcp-ai-wpoos-pro' ),
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
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'Course name (required by Google).', 'mcp-ai-wpoos-pro' ),
				),
				'section'       => array(
					'type'        => 'string',
					'description' => __( 'Optional course section.', 'mcp-ai-wpoos-pro' ),
				),
				'room'          => array(
					'type'        => 'string',
					'description' => __( 'Optional room name.', 'mcp-ai-wpoos-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Optional course description.', 'mcp-ai-wpoos-pro' ),
				),
				'link_eca_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Optional ECA post ID to link to the newly created course.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'connection_id', 'name' ),
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
		return array( 'pro', 'external-api', 'external-write', 'database-write' );
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
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$name = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_classroom_name_required', __( 'A course name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$body = array(
			'name' => $name,
		);

		if ( isset( $arguments['section'] ) && '' !== sanitize_text_field( $arguments['section'] ) ) {
			$body['section'] = sanitize_text_field( $arguments['section'] );
		}

		if ( isset( $arguments['room'] ) && '' !== sanitize_text_field( $arguments['room'] ) ) {
			$body['room'] = sanitize_text_field( $arguments['room'] );
		}

		if ( isset( $arguments['description'] ) && '' !== sanitize_text_field( $arguments['description'] ) ) {
			$body['description'] = sanitize_text_field( $arguments['description'] );
		}

		$course = $resolved['client']->create_course( $body );

		if ( is_wp_error( $course ) ) {
			return $course;
		}

		$course_id = isset( $course['id'] ) ? sanitize_text_field( $course['id'] ) : '';

		$link_eca_id = isset( $arguments['link_eca_id'] ) ? absint( $arguments['link_eca_id'] ) : 0;

		if ( $link_eca_id > 0 && '' !== $course_id ) {
			$eca = get_post( $link_eca_id );

			if ( $eca && 'mcp_ai_eca' === $eca->post_type ) {
				update_post_meta( $link_eca_id, '_eca_google_course_id', $course_id );
				update_post_meta( $link_eca_id, '_eca_classroom_sync', 'roster-in' );
			}
		}

		return array(
			'success'    => true,
			'course_id'  => $course_id,
			'name'       => $name,
			'linked_eca' => $link_eca_id,
			'message'    => __( 'Google Classroom course created.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
