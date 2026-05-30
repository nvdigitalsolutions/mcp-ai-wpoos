<?php
/**
 * Tool for generating ECA timetable views.
 *
 * Allows AI assistants to generate timetable views for students, teachers,
 * venues, or year groups across Extra-Curricular Activities.
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
 * Generates a timetable view for a student, teacher, venue, or year group.
 */
class WP_MCP_AI_Tool_Get_ECA_Timetable implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_eca_timetable';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get ECA Timetable', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a timetable view for a student, teacher, venue, or year group showing all scheduled Extra-Curricular Activities organized by day with optional conflict detection.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'view_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of timetable view to generate (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'student', 'teacher', 'venue', 'year_group' ),
				),
				'view_id'           => array(
					'type'        => 'string',
					'description' => __( 'Identifier for the view: student post ID, teacher name, venue name, or year group (required)', 'mcp-ai-wpoos-pro' ),
				),
				'week_of'           => array(
					'type'        => 'string',
					'description' => __( 'Date in YYYY-MM-DD format to determine the week. Defaults to current week.', 'mcp-ai-wpoos-pro' ),
				),
				'include_conflicts' => array(
					'type'        => 'boolean',
					'description' => __( 'Include conflict detection for overlapping sessions', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'view_type', 'view_id' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view ECA timetables.', 'mcp-ai-wpoos-pro' )
			);
		}

		$view_type = isset( $arguments['view_type'] ) ? sanitize_key( $arguments['view_type'] ) : '';
		$view_id   = isset( $arguments['view_id'] ) ? sanitize_text_field( $arguments['view_id'] ) : '';

		if ( ! $view_type || ! $view_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'view_type and view_id are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_view_types = array( 'student', 'teacher', 'venue', 'year_group' );
		if ( ! in_array( $view_type, $valid_view_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_view_type',
				__( 'view_type must be one of: student, teacher, venue, year_group.', 'mcp-ai-wpoos-pro' )
			);
		}

		$include_conflicts = isset( $arguments['include_conflicts'] ) ? (bool) $arguments['include_conflicts'] : false;
		$week_of           = isset( $arguments['week_of'] ) ? sanitize_text_field( $arguments['week_of'] ) : wp_date( 'Y-m-d' );

		// Fetch ECAs based on view type.
		$ecas = $this->query_ecas_for_view( $view_type, $view_id );

		if ( is_wp_error( $ecas ) ) {
			return $ecas;
		}

		// Organize by day of week.
		$days      = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$timetable = array();

		foreach ( $days as $day ) {
			$timetable[ $day ] = array();
		}

		foreach ( $ecas as $eca_data ) {
			$day = $eca_data['day'];
			if ( isset( $timetable[ $day ] ) ) {
				$timetable[ $day ][] = $eca_data;
			}
		}

		// Sort each day's sessions by start_time.
		foreach ( $days as $day ) {
			usort(
				$timetable[ $day ],
				function ( $a, $b ) {
					return strtotime( $a['start_time'] ) - strtotime( $b['start_time'] );
				}
			);
		}

		// Detect conflicts if requested.
		$conflicts = array();
		if ( $include_conflicts ) {
			$conflicts = $this->detect_conflicts( $timetable );
		}

		// Remove empty days for cleaner output.
		$total_sessions = 0;
		foreach ( $days as $day ) {
			$total_sessions += count( $timetable[ $day ] );
			if ( empty( $timetable[ $day ] ) ) {
				unset( $timetable[ $day ] );
			}
		}

		$result = array(
			'success'        => true,
			'view_type'      => $view_type,
			'view_id'        => $view_id,
			'week_of'        => $week_of,
			'total_sessions' => $total_sessions,
			'timetable'      => $timetable,
		);

		if ( $include_conflicts ) {
			$result['has_conflicts'] = ! empty( $conflicts );
			$result['conflicts']     = $conflicts;
		}

		return $result;
	}

	/**
	 * Query ECAs based on view type.
	 *
	 * @param string $view_type The type of view (student, teacher, venue, year_group).
	 * @param string $view_id   The identifier for the view.
	 * @return array|WP_Error Array of ECA session data or error.
	 */
	private function query_ecas_for_view( $view_type, $view_id ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_eca_timetable', 0, 1000 ) : 1000,
		);

		if ( 'teacher' === $view_type ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_eca_teachers',
					'value'   => $view_id,
					'compare' => 'LIKE',
				),
			);
		} elseif ( 'venue' === $view_type ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_eca_venue',
					'value' => $view_id,
				),
			);
		} elseif ( 'year_group' === $view_type ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_eca_year_groups',
					'value'   => $view_id,
					'compare' => 'LIKE',
				),
			);
		}

		// For student view, we query all ECAs and filter by enrollment.
		$query = new WP_Query( $query_args );
		$ecas  = array();

		foreach ( $query->posts as $eca_post ) {
			$eca_id = $eca_post->ID;

			// For student view, check enrollment.
			if ( 'student' === $view_type ) {
				$student_id  = absint( $view_id );
				$enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
				if ( ! is_array( $enrollments ) || ! isset( $enrollments[ $student_id ] ) ) {
					continue;
				}
			}

			$day = get_post_meta( $eca_id, '_eca_day', true );
			if ( empty( $day ) ) {
				continue;
			}

			$ecas[] = array(
				'eca_id'     => $eca_id,
				'eca_name'   => $eca_post->post_title,
				'day'        => $day,
				'start_time' => get_post_meta( $eca_id, '_eca_start_time', true ),
				'end_time'   => get_post_meta( $eca_id, '_eca_end_time', true ),
				'venue'      => get_post_meta( $eca_id, '_eca_venue', true ),
				'type'       => get_post_meta( $eca_id, '_eca_type', true ),
				'teachers'   => get_post_meta( $eca_id, '_eca_teachers', true ),
			);
		}

		return $ecas;
	}

	/**
	 * Detect overlapping sessions within the timetable.
	 *
	 * @param array $timetable Timetable organized by day.
	 * @return array Array of detected conflicts.
	 */
	private function detect_conflicts( $timetable ) {
		$conflicts = array();

		foreach ( $timetable as $day => $sessions ) {
			$count = count( $sessions );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a_start = strtotime( $sessions[ $i ]['start_time'] );
					$a_end   = strtotime( $sessions[ $i ]['end_time'] );
					$b_start = strtotime( $sessions[ $j ]['start_time'] );
					$b_end   = strtotime( $sessions[ $j ]['end_time'] );

					if ( false === $a_start || false === $a_end || false === $b_start || false === $b_end ) {
						continue;
					}

					// Check for time overlap.
					if ( $a_start < $b_end && $a_end > $b_start ) {
						$conflicts[] = array(
							'day'     => $day,
							'eca_a'   => array(
								'eca_id'     => $sessions[ $i ]['eca_id'],
								'eca_name'   => $sessions[ $i ]['eca_name'],
								'start_time' => $sessions[ $i ]['start_time'],
								'end_time'   => $sessions[ $i ]['end_time'],
							),
							'eca_b'   => array(
								'eca_id'     => $sessions[ $j ]['eca_id'],
								'eca_name'   => $sessions[ $j ]['eca_name'],
								'start_time' => $sessions[ $j ]['start_time'],
								'end_time'   => $sessions[ $j ]['end_time'],
							),
							'message' => sprintf(
								/* translators: 1: first ECA name, 2: second ECA name, 3: day */
								__( '"%1$s" and "%2$s" overlap on %3$s.', 'mcp-ai-wpoos-pro' ),
								$sessions[ $i ]['eca_name'],
								$sessions[ $j ]['eca_name'],
								$day
							),
						);
					}
				}
			}
		}

		return $conflicts;
	}
}
