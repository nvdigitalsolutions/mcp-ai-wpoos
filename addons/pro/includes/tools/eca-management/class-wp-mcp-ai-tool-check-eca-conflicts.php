<?php
/**
 * Tool for detecting scheduling conflicts across ECAs.
 *
 * Allows AI assistants to check for student, teacher, or venue scheduling
 * conflicts in Extra-Curricular Activities.
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
 * Detects scheduling conflicts for students, teachers, or venues across ECAs.
 */
class WP_MCP_AI_Tool_Check_ECA_Conflicts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_eca_conflicts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check ECA Conflicts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detect scheduling conflicts for students, teachers, or venues across Extra-Curricular Activities. Checks for time overlaps on a given day and returns detailed conflict information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'check_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of conflict check to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'student', 'teacher', 'venue' ),
				),
				'student_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Student post ID (required for student check)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'teacher_name'   => array(
					'type'        => 'string',
					'description' => __( 'Teacher name (required for teacher check)', 'mcp-ai-wpoos-pro' ),
				),
				'venue'          => array(
					'type'        => 'string',
					'description' => __( 'Venue name (required for venue check)', 'mcp-ai-wpoos-pro' ),
				),
				'day'            => array(
					'type'        => 'string',
					'description' => __( 'Day of the week to check (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'start_time'     => array(
					'type'        => 'string',
					'description' => __( 'Start time in HH:MM format (required)', 'mcp-ai-wpoos-pro' ),
				),
				'end_time'       => array(
					'type'        => 'string',
					'description' => __( 'End time in HH:MM format (required)', 'mcp-ai-wpoos-pro' ),
				),
				'exclude_eca_id' => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to exclude from conflict check (e.g. when updating an existing ECA)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'check_type', 'day', 'start_time', 'end_time' ),
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
			'profession_tags'       => array( 'educator', 'school_admin' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check ECA conflicts.', 'mcp-ai-wpoos-pro' )
			);
		}

		$check_type = isset( $arguments['check_type'] ) ? sanitize_key( $arguments['check_type'] ) : '';
		$day        = isset( $arguments['day'] ) ? sanitize_text_field( $arguments['day'] ) : '';
		$start_time = isset( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '';
		$end_time   = isset( $arguments['end_time'] ) ? sanitize_text_field( $arguments['end_time'] ) : '';

		if ( ! $check_type || ! $day || ! $start_time || ! $end_time ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'check_type, day, start_time, and end_time are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_types = array( 'student', 'teacher', 'venue' );
		if ( ! in_array( $check_type, $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_check_type',
				__( 'check_type must be one of: student, teacher, venue.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate type-specific required parameters.
		if ( 'student' === $check_type && empty( $arguments['student_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_student_id',
				__( 'student_id is required for student conflict check.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 'teacher' === $check_type && empty( $arguments['teacher_name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_teacher_name',
				__( 'teacher_name is required for teacher conflict check.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 'venue' === $check_type && empty( $arguments['venue'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_venue',
				__( 'venue is required for venue conflict check.', 'mcp-ai-wpoos-pro' )
			);
		}

		$exclude_eca_id = isset( $arguments['exclude_eca_id'] ) ? absint( $arguments['exclude_eca_id'] ) : 0;

		// Convert times to timestamps for comparison.
		$check_start = strtotime( $start_time );
		$check_end   = strtotime( $end_time );

		if ( false === $check_start || false === $check_end ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_time',
				__( 'Invalid time format. Use HH:MM format.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Query all active ECAs on the specified day.
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'check_eca_conflicts', 0, 1000 ) : 1000,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_eca_day',
					'value' => $day,
				),
			),
		);

		$query     = new WP_Query( $query_args );
		$conflicts = array();

		foreach ( $query->posts as $eca_post ) {
			$eca_id = $eca_post->ID;

			// Skip the excluded ECA.
			if ( $exclude_eca_id && $eca_id === $exclude_eca_id ) {
				continue;
			}

			$eca_start = strtotime( get_post_meta( $eca_id, '_eca_start_time', true ) );
			$eca_end   = strtotime( get_post_meta( $eca_id, '_eca_end_time', true ) );

			if ( false === $eca_start || false === $eca_end ) {
				continue;
			}

			// Check for time overlap.
			if ( $check_start >= $eca_end || $check_end <= $eca_start ) {
				continue;
			}

			// Time overlaps — now check type-specific conditions.
			$is_conflict = false;

			if ( 'student' === $check_type ) {
				$student_id  = absint( $arguments['student_id'] );
				$enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
				if ( is_array( $enrollments ) && isset( $enrollments[ $student_id ] ) ) {
					$is_conflict = true;
				}
			} elseif ( 'teacher' === $check_type ) {
				$teacher_name = sanitize_text_field( $arguments['teacher_name'] );
				$teachers     = get_post_meta( $eca_id, '_eca_teachers', true );
				if ( is_array( $teachers ) && in_array( $teacher_name, $teachers, true ) ) {
					$is_conflict = true;
				}
			} elseif ( 'venue' === $check_type ) {
				$venue_name = sanitize_text_field( $arguments['venue'] );
				$eca_venue  = get_post_meta( $eca_id, '_eca_venue', true );
				if ( $eca_venue === $venue_name ) {
					$is_conflict = true;
				}
			}

			if ( $is_conflict ) {
				$conflicts[] = array(
					'eca_id'     => $eca_id,
					'eca_name'   => $eca_post->post_title,
					'day'        => $day,
					'start_time' => get_post_meta( $eca_id, '_eca_start_time', true ),
					'end_time'   => get_post_meta( $eca_id, '_eca_end_time', true ),
					'venue'      => get_post_meta( $eca_id, '_eca_venue', true ),
					'teachers'   => get_post_meta( $eca_id, '_eca_teachers', true ),
					'type'       => get_post_meta( $eca_id, '_eca_type', true ),
				);
			}
		}

		return array(
			'success'        => true,
			'check_type'     => $check_type,
			'day'            => $day,
			'start_time'     => $start_time,
			'end_time'       => $end_time,
			'has_conflicts'  => ! empty( $conflicts ),
			'conflict_count' => count( $conflicts ),
			'conflicts'      => $conflicts,
		);
	}
}
