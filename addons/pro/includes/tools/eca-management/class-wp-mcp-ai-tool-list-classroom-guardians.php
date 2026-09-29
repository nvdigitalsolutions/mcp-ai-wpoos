<?php
/**
 * Tool for listing Google Classroom guardians.
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
 * Lists guardian records for Classroom students, for parent-report addressing.
 */
class WP_MCP_AI_Tool_List_Classroom_Guardians implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_classroom_guardians';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Classroom Guardians', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists Google Classroom guardian records (names and email addresses) for students in a course, for addressing parent reports. Guardian data is only returned when the connection was granted the guardian scope.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Resolving guardian email addresses for send_eca_parent_report, or building a course parent distribution list.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Contacting students directly; use send_eca_notification. Guardian data is sensitive — only request it when actually sending to guardians.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'send_eca_parent_report', 'sync_classroom_roster_to_students', 'list_classroom_courses' ),
			'notes'           => __( 'Read-only, but the guardian scope is sensitive and opt-in: a connection without it gets an actionable error.', 'mcp-ai-wpoos-pro' ),
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
				'student_id'    => array(
					'type'        => 'string',
					'description' => __( 'Optional Google Classroom student user ID; returns guardians for just that student.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'connection_id' ),
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
		return array( 'pro', 'external-api' );
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
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_GUARDIANLINKS_STUDENTS_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$single_student = isset( $arguments['student_id'] ) ? sanitize_text_field( $arguments['student_id'] ) : '';
		$course_id      = WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id(
			$resolved['credentials'],
			isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : ''
		);

		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		$student_ids = array();

		if ( '' !== $single_student ) {
			$student_ids[] = $single_student;
		} else {
			$pages = WP_MCP_AI_Google_Classroom_Client::paginate(
				static function ( $params ) use ( $resolved, $course_id ) {
					return $resolved['client']->list_students( $course_id, $params );
				},
				array( 'pageSize' => 100 )
			);

			if ( is_wp_error( $pages ) ) {
				return $pages;
			}

			foreach ( isset( $pages['items'] ) ? $pages['items'] : array() as $member ) {
				if ( is_array( $member ) && ! empty( $member['userId'] ) ) {
					$student_ids[] = sanitize_text_field( (string) $member['userId'] );
				}
			}
		}

		$rows = array();

		foreach ( $student_ids as $student_id ) {
			$guardians = $resolved['client']->list_guardians( $student_id, array( 'pageSize' => 50 ) );

			if ( is_wp_error( $guardians ) ) {
				$rows[] = array(
					'student_id' => $student_id,
					'error'      => $guardians->get_error_message(),
				);
				continue;
			}

			$list = isset( $guardians['guardians'] ) && is_array( $guardians['guardians'] ) ? $guardians['guardians'] : array();

			foreach ( $list as $guardian ) {
				if ( ! is_array( $guardian ) ) {
					continue;
				}

				$rows[] = array(
					'student_id'  => $student_id,
					'guardian_id' => isset( $guardian['guardianId'] ) ? sanitize_text_field( $guardian['guardianId'] ) : '',
					'name'        => isset( $guardian['guardianProfile']['name']['fullName'] ) ? sanitize_text_field( $guardian['guardianProfile']['name']['fullName'] ) : '',
					'email'       => isset( $guardian['guardianProfile']['emailAddress'] ) ? sanitize_email( $guardian['guardianProfile']['emailAddress'] ) : '',
				);
			}
		}

		return array(
			'success'   => true,
			'course_id' => $course_id,
			'guardians' => $rows,
			'count'     => count( $rows ),
			'message'   => sprintf(
				/* translators: %d: number of guardian records */
				__( '%d guardian records returned.', 'mcp-ai-wpoos-pro' ),
				count( $rows )
			),
		);
	}
}
