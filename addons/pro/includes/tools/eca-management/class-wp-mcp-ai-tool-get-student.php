<?php
/**
 * Tool for getting student details.
 *
 * Allows AI assistants to retrieve student information including enrolled ECAs.
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
 * Gets student details with enrollment information.
 */
class WP_MCP_AI_Tool_Get_Student implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_student';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Student', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a student including personal details, year group, house, and all enrolled Extra-Curricular Activities with schedules.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'       => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the student (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_schedule' => array(
					'type'        => 'boolean',
					'description' => __( 'Include weekly ECA schedule (default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
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
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view student details.', 'mcp-ai-wpoos-pro' )
			);
		}

		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
		if ( ! $student_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_id',
				__( 'Student ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$student = get_post( $student_id );
		if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_student',
				__( 'Invalid student ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		$include_schedule = isset( $arguments['include_schedule'] ) ? (bool) $arguments['include_schedule'] : true;

		// Get student basic info.
		$first_name   = get_post_meta( $student_id, '_student_first_name', true );
		$last_name    = get_post_meta( $student_id, '_student_last_name', true );
		$year_group   = get_post_meta( $student_id, '_student_year_group', true );
		$house        = get_post_meta( $student_id, '_student_house', true );
		$email        = get_post_meta( $student_id, '_student_email', true );
		$isams_id     = get_post_meta( $student_id, '_student_isams_id', true );
		$isams_synced = get_post_meta( $student_id, '_student_isams_synced', true ) === 'yes';

		// Get enrollments.
		$enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( ! is_array( $enrollments ) ) {
			$enrollments = array();
		}

		$enrolled_ecas   = array();
		$weekly_schedule = array();

		foreach ( $enrollments as $eca_id => $enrollment_data ) {
			$eca = get_post( $eca_id );
			if ( ! $eca ) {
				continue;
			}

			$eca_info = array(
				'eca_id'          => $eca_id,
				'name'            => $eca->post_title,
				'type'            => get_post_meta( $eca_id, '_eca_type', true ),
				'day'             => get_post_meta( $eca_id, '_eca_day', true ),
				'start_time'      => get_post_meta( $eca_id, '_eca_start_time', true ),
				'end_time'        => get_post_meta( $eca_id, '_eca_end_time', true ),
				'venue'           => get_post_meta( $eca_id, '_eca_venue', true ),
				'teachers'        => get_post_meta( $eca_id, '_eca_teachers', true ),
				'enrollment_type' => isset( $enrollment_data['enrollment_type'] ) ? $enrollment_data['enrollment_type'] : 'confirmed',
				'enrollment_date' => isset( $enrollment_data['enrollment_date'] ) ? $enrollment_data['enrollment_date'] : '',
				'payment_status'  => isset( $enrollment_data['payment_status'] ) ? $enrollment_data['payment_status'] : 'n/a',
			);

			$enrolled_ecas[] = $eca_info;

			// Build schedule if requested.
			if ( $include_schedule ) {
				$day = get_post_meta( $eca_id, '_eca_day', true );
				if ( $day ) {
					if ( ! isset( $weekly_schedule[ $day ] ) ) {
						$weekly_schedule[ $day ] = array();
					}
					$weekly_schedule[ $day ][] = array(
						'eca_id'     => $eca_id,
						'name'       => $eca->post_title,
						'start_time' => get_post_meta( $eca_id, '_eca_start_time', true ),
						'end_time'   => get_post_meta( $eca_id, '_eca_end_time', true ),
						'venue'      => get_post_meta( $eca_id, '_eca_venue', true ),
					);
				}
			}
		}

		// Sort schedule by day.
		if ( $include_schedule ) {
			$day_order       = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
			$sorted_schedule = array();
			foreach ( $day_order as $day ) {
				if ( isset( $weekly_schedule[ $day ] ) ) {
					$sorted_schedule[ $day ] = $weekly_schedule[ $day ];
				}
			}
			$weekly_schedule = $sorted_schedule;
		}

		$result = array(
			'success'           => true,
			'student_id'        => $student_id,
			'name'              => $student->post_title,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'year_group'        => $year_group,
			'house'             => $house,
			'email'             => $email,
			'total_enrollments' => count( $enrolled_ecas ),
			'enrolled_ecas'     => $enrolled_ecas,
			'isams_id'          => $isams_id,
			'isams_synced'      => $isams_synced,
			'url'               => get_permalink( $student_id ),
		);

		if ( $include_schedule ) {
			$result['weekly_schedule'] = $weekly_schedule;
		}

		return $result;
	}
}
