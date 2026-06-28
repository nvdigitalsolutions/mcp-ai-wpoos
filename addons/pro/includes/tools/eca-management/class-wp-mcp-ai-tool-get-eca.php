<?php
/**
 * Tool for getting a single ECA's details.
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
 * Get details of a single Extra-Curricular Activity.
 */
class WP_MCP_AI_Tool_Get_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets detailed information about a specific Extra-Curricular Activity including schedule, venue, capacity, enrollment breakdown, and teacher assignments. Optionally returns the list of enrolled students.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'              => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to retrieve (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_enrollments' => array(
					'type'        => 'boolean',
					'description' => __( 'Include the list of enrolled students with their enrollment details', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
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
			'profession_tags'       => array( 'educator', 'school_admin', 'student' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view ECAs.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca = get_post( $eca_id );

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_eca', __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Compute enrollment data.
		$max_students       = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
		$current_enrollment = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
		$is_full            = $max_students > 0 && $current_enrollment >= $max_students;
		$available_spots    = $max_students > 0 ? max( 0, $max_students - $current_enrollment ) : null;
		$is_paid            = get_post_meta( $eca_id, '_eca_is_paid', true ) === 'yes';
		$cost               = $is_paid ? floatval( get_post_meta( $eca_id, '_eca_cost', true ) ) : 0;

		// Enrollment breakdown.
		$enrollment_breakdown = $this->get_enrollment_breakdown( $eca_id );

		// Build ECA data — consistent field names with list_ecas.
		$eca_data = array(
			'eca_id'               => $eca_id,
			'name'                 => $eca->post_title,
			'description'          => $eca->post_content,
			'eca_code'             => get_post_meta( $eca_id, '_eca_code', true ),
			'type'                 => get_post_meta( $eca_id, '_eca_type', true ),
			'day'                  => get_post_meta( $eca_id, '_eca_day', true ),
			'start_time'           => get_post_meta( $eca_id, '_eca_start_time', true ),
			'end_time'             => get_post_meta( $eca_id, '_eca_end_time', true ),
			'venue'                => get_post_meta( $eca_id, '_eca_venue', true ),
			'year_groups'          => get_post_meta( $eca_id, '_eca_year_groups', true ),
			'max_students'         => $max_students,
			'current_enrollment'   => $current_enrollment,
			'available_spots'      => $available_spots,
			'is_full'              => $is_full,
			'enrollment_breakdown' => $enrollment_breakdown,
			'teachers'             => get_post_meta( $eca_id, '_eca_teachers', true ),
			'is_paid'              => $is_paid,
			'cost'                 => $cost,
			'cost_period'          => get_post_meta( $eca_id, '_eca_cost_period', true ),
			'currency'             => get_post_meta( $eca_id, '_eca_currency', true ),
			'term'                 => get_post_meta( $eca_id, '_eca_term', true ),
			'requires_audition'    => get_post_meta( $eca_id, '_eca_requires_audition', true ) === 'yes',
			'booking_type'         => get_post_meta( $eca_id, '_eca_booking_type', true ),
			'status'               => get_post_meta( $eca_id, '_eca_status', true ),
			'isams_sync_id'        => get_post_meta( $eca_id, '_eca_isams_sync_id', true ),
			'sessions'             => get_post_meta( $eca_id, '_eca_sessions', true ),
			'url'                  => get_permalink( $eca_id ),
			'created_at'           => $eca->post_date,
			'modified_at'          => $eca->post_modified,
		);

		// Include enrollments if requested.
		$include_enrollments = isset( $arguments['include_enrollments'] ) && (bool) $arguments['include_enrollments'];
		if ( $include_enrollments ) {
			$eca_data['enrollments'] = $this->get_enrollments( $eca_id );
		}

		return array(
			'success' => true,
			'eca'     => $eca_data,
		);
	}

	/**
	 * Get enrollment breakdown counts by type.
	 *
	 * @param int $eca_id ECA post ID.
	 * @return array Breakdown of enrollment types.
	 */
	private function get_enrollment_breakdown( $eca_id ) {
		$enrollments = get_post_meta( $eca_id, '_eca_enrollments', true );
		$breakdown   = array(
			'confirmed'  => 0,
			'waitlist'   => 0,
			'trial'      => 0,
			'preference' => 0,
		);

		if ( ! is_array( $enrollments ) || empty( $enrollments ) ) {
			return $breakdown;
		}

		foreach ( $enrollments as $enrollment ) {
			$type = isset( $enrollment['enrollment_type'] ) ? $enrollment['enrollment_type'] : 'confirmed';
			if ( isset( $breakdown[ $type ] ) ) {
				++$breakdown[ $type ];
			}
		}

		return $breakdown;
	}

	/**
	 * Get the list of enrolled students for an ECA.
	 *
	 * @param int $eca_id ECA post ID.
	 * @return array List of enrollment records with student details.
	 */
	private function get_enrollments( $eca_id ) {
		$enrollments = get_post_meta( $eca_id, '_eca_enrollments', true );

		if ( ! is_array( $enrollments ) || empty( $enrollments ) ) {
			return array();
		}

		$result = array();
		foreach ( $enrollments as $enrollment ) {
			$student_id = isset( $enrollment['student_id'] ) ? absint( $enrollment['student_id'] ) : 0;
			if ( ! $student_id ) {
				continue;
			}

			$student = get_post( $student_id );
			$entry   = array(
				'student_id'      => $student_id,
				'student_name'    => $student ? sanitize_text_field( $student->post_title ) : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
				'enrollment_type' => isset( $enrollment['enrollment_type'] ) ? $enrollment['enrollment_type'] : 'confirmed',
				'enrollment_date' => isset( $enrollment['enrollment_date'] ) ? $enrollment['enrollment_date'] : '',
				'payment_status'  => isset( $enrollment['payment_status'] ) ? $enrollment['payment_status'] : '',
			);

			$result[] = $entry;
		}

		return $result;
	}
}
