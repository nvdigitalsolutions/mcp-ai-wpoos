<?php
/**
 * Tool for creating Google Classroom coursework.
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
 * Creates a coursework item (assignment or question) in a Classroom course.
 */
class WP_MCP_AI_Tool_Create_Classroom_Coursework implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_classroom_coursework';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Classroom Coursework', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a Google Classroom coursework item — an assignment (for example a permission slip or rehearsal check-in) or a short-answer question — with optional due date and topic.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Turning an ECA requirement into an assignable Classroom item so turn-in state can be tracked.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'A plain notice; use post_classroom_announcement. Grading submissions; not supported by this toolkit yet.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'post_classroom_announcement', 'list_classroom_submissions', 'classroom_course_analytics' ),
			'notes'           => __( 'Requires the classroom.coursework.students scope. Only work types ASSIGNMENT and SHORT_ANSWER_QUESTION are supported.', 'mcp-ai-wpoos-pro' ),
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
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Coursework title (required).', 'mcp-ai-wpoos-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Optional instructions.', 'mcp-ai-wpoos-pro' ),
				),
				'work_type'     => array(
					'type'        => 'string',
					'description' => __( 'Coursework type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ASSIGNMENT', 'SHORT_ANSWER_QUESTION' ),
					'default'     => 'ASSIGNMENT',
				),
				'max_points'    => array(
					'type'        => 'number',
					'description' => __( 'Optional maximum points for assignments.', 'mcp-ai-wpoos-pro' ),
				),
				'due_date'      => array(
					'type'        => 'string',
					'description' => __( 'Optional due date in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ),
				),
				'topic_id'      => array(
					'type'        => 'string',
					'description' => __( 'Optional Classroom topic ID to file the item under.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'connection_id', 'title' ),
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
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSEWORK_STUDENTS
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$title = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_classroom_title_required', __( 'A coursework title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$course_id = WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id(
			$resolved['credentials'],
			isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : ''
		);

		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		$work_type = isset( $arguments['work_type'] ) ? sanitize_key( $arguments['work_type'] ) : 'ASSIGNMENT';

		if ( ! in_array( $work_type, array( 'ASSIGNMENT', 'SHORT_ANSWER_QUESTION' ), true ) ) {
			$work_type = 'ASSIGNMENT';
		}

		$body = array(
			'title'    => $title,
			'workType' => $work_type,
			'state'    => 'PUBLISHED',
		);

		if ( isset( $arguments['description'] ) && '' !== sanitize_text_field( $arguments['description'] ) ) {
			$body['description'] = sanitize_text_field( $arguments['description'] );
		}

		if ( isset( $arguments['topic_id'] ) && '' !== sanitize_text_field( $arguments['topic_id'] ) ) {
			$body['topicId'] = sanitize_text_field( $arguments['topic_id'] );
		}

		if ( isset( $arguments['due_date'] ) && '' !== sanitize_text_field( $arguments['due_date'] ) ) {
			$due = strtotime( sanitize_text_field( $arguments['due_date'] ) );

			if ( false !== $due ) {
				$body['dueDate'] = array(
					'year'  => absint( gmdate( 'Y', $due ) ),
					'month' => absint( gmdate( 'n', $due ) ),
					'day'   => absint( gmdate( 'j', $due ) ),
				);
			}
		}

		if ( isset( $arguments['max_points'] ) && is_numeric( $arguments['max_points'] ) ) {
			$body['maxPoints'] = (float) $arguments['max_points'];
		}

		$coursework = $resolved['client']->create_coursework( $course_id, $body );

		if ( is_wp_error( $coursework ) ) {
			return $coursework;
		}

		return array(
			'success'       => true,
			'course_id'     => $course_id,
			'coursework_id' => isset( $coursework['id'] ) ? sanitize_text_field( $coursework['id'] ) : '',
			'work_type'     => $work_type,
			'message'       => __( 'Google Classroom coursework created and published.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
