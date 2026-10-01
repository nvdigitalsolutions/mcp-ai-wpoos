<?php
/**
 * Tool for updating or archiving a Google Classroom course.
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
 * Updates a Google Classroom course's fields or course state.
 */
class WP_MCP_AI_Tool_Update_Classroom_Course implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_classroom_course';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Google Classroom Course', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Renames a Google Classroom course, changes its section or room, or moves it to a different course state (for example archiving a finished club term).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Renaming a course after a club rebrands, or archiving a course at the end of a term.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Creating a course; use create_classroom_course. Announcements or coursework; use post_classroom_announcement or create_classroom_coursework.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'create_classroom_course', 'list_classroom_courses', 'link_classroom_course_to_eca' ),
			'notes'           => __( 'Requires the classroom.courses scope. Archiving is reversible in the Classroom UI, not via the API.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Google Classroom course ID to update.', 'mcp-ai-wpoos-pro' ),
				),
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'New course name (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'section'       => array(
					'type'        => 'string',
					'description' => __( 'New course section (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'room'          => array(
					'type'        => 'string',
					'description' => __( 'New room name (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'course_state'  => array(
					'type'        => 'string',
					'description' => __( 'New course state.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ACTIVE', 'ARCHIVED', 'DECLINED', 'PROVISIONED', 'SUSPENDED' ),
				),
			),
			'required'             => array( 'connection_id', 'course_id' ),
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
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$course_id = isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : '';

		if ( '' === $course_id ) {
			return new WP_Error( 'wp_mcp_ai_course_required', __( 'A Google Classroom course ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$body        = array();
		$update_mask = array();

		foreach ( array( 'name', 'section', 'room' ) as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$value = sanitize_text_field( $arguments[ $field ] );

				if ( '' !== $value ) {
					$body[ $field ] = $value;
					$update_mask[]  = $field;
				}
			}
		}

		if ( isset( $arguments['course_state'] ) ) {
			$state = sanitize_key( $arguments['course_state'] );

			if ( in_array( $state, array( 'ACTIVE', 'ARCHIVED', 'DECLINED', 'PROVISIONED', 'SUSPENDED' ), true ) ) {
				$body['courseState'] = $state;
				$update_mask[]       = 'courseState';
			}
		}

		if ( empty( $update_mask ) ) {
			return new WP_Error( 'wp_mcp_ai_classroom_nothing_to_update', __( 'Provide at least one field to update.', 'mcp-ai-wpoos-pro' ) );
		}

		$course = $resolved['client']->update_course( $course_id, $body, $update_mask );

		if ( is_wp_error( $course ) ) {
			return $course;
		}

		return array(
			'success'   => true,
			'course_id' => $course_id,
			'updated'   => $update_mask,
			'message'   => __( 'Google Classroom course updated.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
