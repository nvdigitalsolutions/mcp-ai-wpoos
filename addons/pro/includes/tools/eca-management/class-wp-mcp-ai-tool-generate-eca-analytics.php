<?php
/**
 * Tool for generating comprehensive ECA program analytics.
 *
 * Generates analytics with Chart.js-compatible data for participation,
 * capacity, financial, and engagement report types.
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
 * Generates comprehensive ECA program analytics with Chart.js-compatible data.
 */
class WP_MCP_AI_Tool_Generate_ECA_Analytics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_eca_analytics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate ECA Analytics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates comprehensive ECA program analytics with Chart.js-compatible data. Supports participation, capacity, financial, and engagement report types with customizable date ranges and filters.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'report_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of analytics report to generate', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'participation', 'capacity', 'financial', 'engagement' ),
				),
				'date_range'  => array(
					'type'        => 'object',
					'description' => __( 'Optional date range filter', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'start_date' => array(
							'type'        => 'string',
							'description' => __( 'Start date in YYYY-MM-DD format', 'mcp-ai-wpoos-pro' ),
						),
						'end_date'   => array(
							'type'        => 'string',
							'description' => __( 'End date in YYYY-MM-DD format', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'filters'     => array(
					'type'        => 'object',
					'description' => __( 'Optional filters to narrow the report', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'eca_type'   => array(
							'type'        => 'string',
							'description' => __( 'Filter by ECA type', 'mcp-ai-wpoos-pro' ),
						),
						'year_group' => array(
							'type'        => 'string',
							'description' => __( 'Filter by year group', 'mcp-ai-wpoos-pro' ),
						),
						'status'     => array(
							'type'        => 'string',
							'description' => __( 'Filter by ECA status', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			),
			'required'             => array( 'report_type' ),
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
			'profession_tags'       => array( 'educator', 'school_admin', 'activities_coordinator' ),
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate ECA analytics.', 'mcp-ai-wpoos-pro' )
			);
		}

		$report_type = isset( $arguments['report_type'] ) ? sanitize_key( $arguments['report_type'] ) : '';
		$valid_types = array( 'participation', 'capacity', 'financial', 'engagement' );

		if ( ! in_array( $report_type, $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_report_type',
				__( 'Invalid report type. Must be one of: participation, capacity, financial, engagement.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Query ECAs matching filters.
		$ecas = $this->query_ecas( $arguments );

		if ( empty( $ecas ) ) {
			return array(
				'success'      => true,
				'report_type'  => $report_type,
				'summary'      => array(),
				'chart_data'   => array(),
				'generated_at' => current_time( 'c' ),
				'message'      => __( 'No ECAs found matching the specified filters.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Generate report based on type.
		switch ( $report_type ) {
			case 'participation':
				$summary    = $this->build_participation_summary( $ecas );
				$chart_data = $this->build_participation_chart( $ecas );
				break;

			case 'capacity':
				$summary    = $this->build_capacity_summary( $ecas );
				$chart_data = $this->build_capacity_chart( $ecas );
				break;

			case 'financial':
				$summary    = $this->build_financial_summary( $ecas );
				$chart_data = $this->build_financial_chart( $ecas );
				break;

			case 'engagement':
				$summary    = $this->build_engagement_summary( $ecas );
				$chart_data = $this->build_engagement_chart( $ecas );
				break;

			default:
				$summary    = array();
				$chart_data = array();
				break;
		}

		return array(
			'success'      => true,
			'report_type'  => $report_type,
			'summary'      => $summary,
			'chart_data'   => $chart_data,
			'generated_at' => current_time( 'c' ),
		);
	}

	/**
	 * Query ECAs matching the provided filters.
	 *
	 * @param array $arguments Tool arguments containing filters.
	 * @return array Array of ECA post objects.
	 */
	private function query_ecas( $arguments ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_analytics', 0, 1000 ) : 1000,
		);

		$meta_query = array( 'relation' => 'AND' );

		$filters = isset( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();

		if ( ! empty( $filters['eca_type'] ) ) {
			$meta_query[] = array(
				'key'   => '_eca_type',
				'value' => sanitize_key( $filters['eca_type'] ),
			);
		}

		if ( ! empty( $filters['year_group'] ) ) {
			$meta_query[] = array(
				'key'     => '_eca_year_groups',
				'value'   => sanitize_text_field( $filters['year_group'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $filters['status'] ) ) {
			$meta_query[] = array(
				'key'   => '_eca_status',
				'value' => sanitize_key( $filters['status'] ),
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Apply date range filter on post creation date.
		$date_range = isset( $arguments['date_range'] ) && is_array( $arguments['date_range'] ) ? $arguments['date_range'] : array();
		if ( ! empty( $date_range['start_date'] ) || ! empty( $date_range['end_date'] ) ) {
			$date_query = array();
			if ( ! empty( $date_range['start_date'] ) ) {
				$date_query['after'] = sanitize_text_field( $date_range['start_date'] );
			}
			if ( ! empty( $date_range['end_date'] ) ) {
				$date_query['before'] = sanitize_text_field( $date_range['end_date'] );
			}
			$date_query['inclusive']  = true;
			$query_args['date_query'] = array( $date_query );
		}

		$query = new WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Build participation summary statistics.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Summary statistics.
	 */
	private function build_participation_summary( $ecas ) {
		$total_ecas      = count( $ecas );
		$total_enrolled  = 0;
		$eca_enrollments = array();
		$all_student_ids = array();

		foreach ( $ecas as $eca ) {
			$enrollment      = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
			$total_enrolled += $enrollment;

			$eca_enrollments[] = array(
				'eca_id'     => $eca->ID,
				'eca_name'   => $eca->post_title,
				'enrollment' => $enrollment,
			);

			$enrolled_students = get_post_meta( $eca->ID, '_eca_enrolled_students', true );
			if ( is_array( $enrolled_students ) ) {
				foreach ( $enrolled_students as $sid ) {
					$all_student_ids[ absint( $sid ) ] = true;
				}
			}
		}

		$unique_students      = count( $all_student_ids );
		$avg_ecas_per_student = $unique_students > 0 ? round( $total_enrolled / $unique_students, 1 ) : 0;

		// Sort to find most and least popular.
		usort(
			$eca_enrollments,
			function ( $a, $b ) {
				return $b['enrollment'] - $a['enrollment'];
			}
		);

		$most_popular  = ! empty( $eca_enrollments ) ? array_slice( $eca_enrollments, 0, 5 ) : array();
		$least_popular = ! empty( $eca_enrollments ) ? array_slice( array_reverse( $eca_enrollments ), 0, 5 ) : array();

		// Query all students to find those with no ECAs.
		$all_students_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_analytics', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		$total_students        = $all_students_query->found_posts;
		$students_with_no_ecas = max( 0, $total_students - $unique_students );

		return array(
			'total_ecas'            => $total_ecas,
			'total_students'        => $total_students,
			'unique_students'       => $unique_students,
			'total_enrolled'        => $total_enrolled,
			'avg_ecas_per_student'  => $avg_ecas_per_student,
			'most_popular_ecas'     => $most_popular,
			'least_popular_ecas'    => $least_popular,
			'students_with_no_ecas' => $students_with_no_ecas,
		);
	}

	/**
	 * Build participation Chart.js-compatible bar chart data.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Chart.js-compatible data.
	 */
	private function build_participation_chart( $ecas ) {
		$labels = array();
		$data   = array();

		foreach ( $ecas as $eca ) {
			$labels[] = $eca->post_title;
			$data[]   = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
		}

		return array(
			'type'     => 'bar',
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label' => __( 'Enrolled', 'mcp-ai-wpoos-pro' ),
					'data'  => $data,
				),
			),
		);
	}

	/**
	 * Build capacity summary statistics.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Summary statistics.
	 */
	private function build_capacity_summary( $ecas ) {
		$total_max        = 0;
		$total_enrolled   = 0;
		$total_waitlisted = 0;
		$over_90_count    = 0;
		$fill_rates       = array();

		foreach ( $ecas as $eca ) {
			$max            = absint( get_post_meta( $eca->ID, '_eca_max_students', true ) );
			$enrollment     = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
			$waitlist       = get_post_meta( $eca->ID, '_eca_waitlist', true );
			$waitlist_count = is_array( $waitlist ) ? count( $waitlist ) : 0;

			$total_max        += $max;
			$total_enrolled   += $enrollment;
			$total_waitlisted += $waitlist_count;

			if ( $max > 0 ) {
				$utilization  = ( $enrollment / $max ) * 100;
				$fill_rates[] = $utilization;

				if ( $utilization >= 90 ) {
					++$over_90_count;
				}
			}
		}

		$avg_utilization  = ! empty( $fill_rates ) ? round( array_sum( $fill_rates ) / count( $fill_rates ), 1 ) : 0;
		$avg_waitlist_len = count( $ecas ) > 0 ? round( $total_waitlisted / count( $ecas ), 1 ) : 0;
		$total_available  = max( 0, $total_max - $total_enrolled );

		return array(
			'total_capacity'       => $total_max,
			'total_enrolled'       => $total_enrolled,
			'total_available'      => $total_available,
			'total_waitlisted'     => $total_waitlisted,
			'avg_utilization_rate' => $avg_utilization,
			'ecas_over_90_percent' => $over_90_count,
			'avg_waitlist_length'  => $avg_waitlist_len,
			'fill_rates'           => $fill_rates,
		);
	}

	/**
	 * Build capacity Chart.js-compatible doughnut chart data.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Chart.js-compatible data.
	 */
	private function build_capacity_chart( $ecas ) {
		$total_available  = 0;
		$total_filled     = 0;
		$total_waitlisted = 0;

		foreach ( $ecas as $eca ) {
			$max        = absint( get_post_meta( $eca->ID, '_eca_max_students', true ) );
			$enrollment = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
			$waitlist   = get_post_meta( $eca->ID, '_eca_waitlist', true );

			$total_filled     += $enrollment;
			$total_available  += max( 0, $max - $enrollment );
			$total_waitlisted += is_array( $waitlist ) ? count( $waitlist ) : 0;
		}

		return array(
			'type'     => 'doughnut',
			'labels'   => array(
				__( 'Available', 'mcp-ai-wpoos-pro' ),
				__( 'Filled', 'mcp-ai-wpoos-pro' ),
				__( 'Waitlisted', 'mcp-ai-wpoos-pro' ),
			),
			'datasets' => array(
				array(
					'data' => array( $total_available, $total_filled, $total_waitlisted ),
				),
			),
		);
	}

	/**
	 * Build financial summary statistics.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Summary statistics.
	 */
	private function build_financial_summary( $ecas ) {
		$total_revenue        = 0;
		$outstanding_payments = 0;
		$paid_ecas            = 0;
		$free_ecas            = 0;
		$all_costs            = array();

		foreach ( $ecas as $eca ) {
			$is_paid    = get_post_meta( $eca->ID, '_eca_is_paid', true ) === 'yes';
			$cost       = floatval( get_post_meta( $eca->ID, '_eca_cost', true ) );
			$enrollment = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );

			if ( $is_paid ) {
				++$paid_ecas;
				$revenue        = $cost * $enrollment;
				$total_revenue += $revenue;
				$all_costs[]    = $cost;

				// Check for outstanding payments via enrolled students meta.
				$enrolled_students = get_post_meta( $eca->ID, '_eca_enrolled_students', true );
				if ( is_array( $enrolled_students ) ) {
					foreach ( $enrolled_students as $sid ) {
						$payment_status = get_post_meta( $eca->ID, '_eca_payment_' . absint( $sid ), true );
						if ( 'paid' !== $payment_status ) {
							$outstanding_payments += $cost;
						}
					}
				}
			} else {
				++$free_ecas;
			}
		}

		$total_students = 0;
		foreach ( $ecas as $eca ) {
			$total_students += absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
		}
		$avg_cost_per_student = $total_students > 0 ? round( $total_revenue / $total_students, 2 ) : 0;

		return array(
			'total_revenue'        => round( $total_revenue, 2 ),
			'outstanding_payments' => round( $outstanding_payments, 2 ),
			'avg_cost_per_student' => $avg_cost_per_student,
			'paid_ecas'            => $paid_ecas,
			'free_ecas'            => $free_ecas,
			'total_ecas'           => count( $ecas ),
		);
	}

	/**
	 * Build financial Chart.js-compatible bar chart data.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Chart.js-compatible data.
	 */
	private function build_financial_chart( $ecas ) {
		$labels = array();
		$data   = array();

		foreach ( $ecas as $eca ) {
			$is_paid = get_post_meta( $eca->ID, '_eca_is_paid', true ) === 'yes';
			if ( ! $is_paid ) {
				continue;
			}

			$cost       = floatval( get_post_meta( $eca->ID, '_eca_cost', true ) );
			$enrollment = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );

			$labels[] = $eca->post_title;
			$data[]   = round( $cost * $enrollment, 2 );
		}

		return array(
			'type'     => 'bar',
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label' => __( 'Revenue', 'mcp-ai-wpoos-pro' ),
					'data'  => $data,
				),
			),
		);
	}

	/**
	 * Build engagement summary statistics.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Summary statistics.
	 */
	private function build_engagement_summary( $ecas ) {
		$total_sessions     = 0;
		$total_present      = 0;
		$total_records      = 0;
		$student_attendance = array();

		foreach ( $ecas as $eca ) {
			$attendance_log = get_post_meta( $eca->ID, '_eca_attendance_log', true );
			if ( ! is_array( $attendance_log ) ) {
				continue;
			}

			$total_sessions += count( $attendance_log );

			foreach ( $attendance_log as $session ) {
				$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

				foreach ( $attendees as $attendee ) {
					++$total_records;
					$status = isset( $attendee['status'] ) ? $attendee['status'] : '';
					$sid    = absint( $attendee['student_id'] );

					if ( 'present' === $status || 'late' === $status ) {
						++$total_present;
					}

					if ( ! isset( $student_attendance[ $sid ] ) ) {
						$student_attendance[ $sid ] = array(
							'student_id'   => $sid,
							'student_name' => isset( $attendee['student_name'] ) ? sanitize_text_field( $attendee['student_name'] ) : '',
							'present'      => 0,
							'total'        => 0,
						);
					}
					++$student_attendance[ $sid ]['total'];
					if ( 'present' === $status || 'late' === $status ) {
						++$student_attendance[ $sid ]['present'];
					}
				}
			}
		}

		$overall_rate = $total_records > 0 ? round( ( $total_present / $total_records ) * 100, 1 ) : 0;

		// Find most engaged students.
		foreach ( $student_attendance as &$sa ) {
			$sa['attendance_rate'] = $sa['total'] > 0
				? round( ( $sa['present'] / $sa['total'] ) * 100, 1 )
				: 0;
		}
		unset( $sa );

		usort(
			$student_attendance,
			function ( $a, $b ) {
				return $b['attendance_rate'] <=> $a['attendance_rate'];
			}
		);

		$most_engaged = array_slice( $student_attendance, 0, 10 );

		return array(
			'total_sessions'           => $total_sessions,
			'total_attendance_records' => $total_records,
			'overall_attendance_rate'  => $overall_rate,
			'most_engaged_students'    => $most_engaged,
		);
	}

	/**
	 * Build engagement Chart.js-compatible line chart data.
	 *
	 * @param array $ecas Array of ECA post objects.
	 * @return array Chart.js-compatible data.
	 */
	private function build_engagement_chart( $ecas ) {
		$dates_data = array();

		foreach ( $ecas as $eca ) {
			$attendance_log = get_post_meta( $eca->ID, '_eca_attendance_log', true );
			if ( ! is_array( $attendance_log ) ) {
				continue;
			}

			foreach ( $attendance_log as $date => $session ) {
				if ( ! isset( $dates_data[ $date ] ) ) {
					$dates_data[ $date ] = array(
						'present' => 0,
						'total'   => 0,
					);
				}

				$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();
				foreach ( $attendees as $attendee ) {
					++$dates_data[ $date ]['total'];
					$status = isset( $attendee['status'] ) ? $attendee['status'] : '';
					if ( 'present' === $status || 'late' === $status ) {
						++$dates_data[ $date ]['present'];
					}
				}
			}
		}

		ksort( $dates_data );

		$labels = array();
		$rates  = array();

		foreach ( $dates_data as $date => $counts ) {
			$labels[] = $date;
			$rates[]  = $counts['total'] > 0
				? round( ( $counts['present'] / $counts['total'] ) * 100, 1 )
				: 0;
		}

		return array(
			'type'     => 'line',
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label' => __( 'Attendance Rate (%)', 'mcp-ai-wpoos-pro' ),
					'data'  => $rates,
				),
			),
		);
	}
}
