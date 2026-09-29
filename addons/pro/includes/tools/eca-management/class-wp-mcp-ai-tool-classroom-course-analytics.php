<?php
/**
 * Tool for Google Classroom course analytics.
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
 * Aggregates enrolment and submission completion metrics for linked ECAs.
 */
class WP_MCP_AI_Tool_Classroom_Course_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'classroom_course_analytics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Classroom Course Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Aggregates Google Classroom enrolment, coursework, and submission-completion metrics for one course or every linked ECA, feeding the ECA participation reports.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Summarising club engagement across Classroom coursework, or comparing completion across linked ECAs for a coordinator report.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Raw submission rows; use list_classroom_submissions. ECA-internal attendance analytics; use generate_eca_analytics.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'list_classroom_submissions', 'generate_eca_analytics', 'generate_eca_participation_report' ),
			'notes'           => __( 'Read-only. Bounded: submission metrics scan at most the 20 latest coursework items per course.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'       => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'course_id'           => array(
					'type'        => 'string',
					'description' => __( 'Google Classroom course ID. When omitted, all ECAs linked to this connection\'s courses are summarised.', 'mcp-ai-wpoos-pro' ),
				),
				'include_submissions' => array(
					'type'        => 'boolean',
					'description' => __( 'Include coursework completion metrics (default: true).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
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
		return 'edit_posts';
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
			'risk_level'            => 'standard',
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
		$resolved      = WP_MCP_AI_ECA_Classroom_Helper::get_client( $connection_id, 'edit_posts', $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$scope_check = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
			$resolved['credentials'],
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$include_submissions = isset( $arguments['include_submissions'] ) ? (bool) $arguments['include_submissions'] : true;
		$course_id           = isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : '';

		$pages = WP_MCP_AI_Google_Classroom_Client::paginate(
			static function ( $params ) use ( $resolved ) {
				return $resolved['client']->list_courses( $params );
			},
			array(
				'pageSize'     => 100,
				'courseStates' => array( 'ACTIVE' ),
			)
		);

		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$courses = isset( $pages['items'] ) ? $pages['items'] : array();
		$rows    = array();

		foreach ( $courses as $course ) {
			if ( ! is_array( $course ) ) {
				continue;
			}

			$cid = isset( $course['id'] ) ? sanitize_text_field( $course['id'] ) : '';

			if ( '' !== $course_id && $cid !== $course_id ) {
				continue;
			}

			$row = array(
				'course_id'  => $cid,
				'name'       => isset( $course['name'] ) ? sanitize_text_field( $course['name'] ) : '',
				'eca_id'     => WP_MCP_AI_ECA_Classroom_Helper::find_eca_by_google_course( $cid ),
				'students'   => 0,
				'coursework' => 0,
				'turned_in'  => 0,
			);

			$students = $resolved['client']->list_students( $cid, array( 'pageSize' => 1 ) );

			if ( ! is_wp_error( $students ) ) {
				$row['students'] = isset( $students['students'] ) && is_array( $students['students'] ) ? count( $students['students'] ) : 0;
			}

			if ( $include_submissions ) {
				$work = $resolved['client']->list_coursework( $cid, array( 'pageSize' => 20 ) );

				if ( ! is_wp_error( $work ) ) {
					$items             = isset( $work['courseWork'] ) && is_array( $work['courseWork'] ) ? $work['courseWork'] : array();
					$row['coursework'] = count( $items );

					foreach ( array_slice( $items, 0, 20 ) as $item ) {
						if ( ! is_array( $item ) || empty( $item['id'] ) ) {
							continue;
						}

						$submissions = $resolved['client']->list_student_submissions(
							$cid,
							(string) $item['id'],
							array(
								'pageSize' => 100,
								'states'   => array( 'TURNED_IN', 'RETURNED' ),
							)
						);

						if ( is_wp_error( $submissions ) ) {
							continue;
						}

						$list              = isset( $submissions['studentSubmissions'] ) && is_array( $submissions['studentSubmissions'] ) ? $submissions['studentSubmissions'] : array();
						$row['turned_in'] += count( $list );
					}
				}
			}

			$rows[] = $row;
		}

		return array(
			'success' => true,
			'courses' => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: %d: number of courses summarised */
				__( 'Analytics summarised for %d Google Classroom courses.', 'mcp-ai-wpoos-pro' ),
				count( $rows )
			),
		);
	}
}
