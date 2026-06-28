<?php
/**
 * Tool for exporting ECA data to CSV format.
 *
 * Exports ECAs, students, enrollments, attendance, or financial data
 * with optional filters and date ranges.
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
 * Exports ECA data to CSV format with filtering and date range support.
 */
class WP_MCP_AI_Tool_Export_ECA_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_eca_data';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export ECA Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports ECA data to CSV format. Supports exporting ECAs, students, enrollments, attendance, or financial data with optional filters and date ranges.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'export_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of data to export', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ecas', 'students', 'enrollments', 'attendance', 'financial' ),
				),
				'filters'     => array(
					'type'        => 'object',
					'description' => __( 'Optional filters to narrow the export', 'mcp-ai-wpoos-pro' ),
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
						'eca_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Filter by specific ECA post ID', 'mcp-ai-wpoos-pro' ),
							'minimum'     => 1,
						),
					),
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
			),
			'required'             => array( 'export_type' ),
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
				__( 'You do not have permission to export ECA data.', 'mcp-ai-wpoos-pro' )
			);
		}

		$export_type = isset( $arguments['export_type'] ) ? sanitize_key( $arguments['export_type'] ) : '';
		$valid_types = array( 'ecas', 'students', 'enrollments', 'attendance', 'financial' );

		if ( ! in_array( $export_type, $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_export_type',
				__( 'Invalid export type. Must be one of: ecas, students, enrollments, attendance, financial.', 'mcp-ai-wpoos-pro' )
			);
		}

		$filters    = isset( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();
		$date_range = isset( $arguments['date_range'] ) && is_array( $arguments['date_range'] ) ? $arguments['date_range'] : array();

		// Build data rows based on export type.
		switch ( $export_type ) {
			case 'ecas':
				$result = $this->export_ecas( $filters );
				break;

			case 'students':
				$result = $this->export_students( $filters );
				break;

			case 'enrollments':
				$result = $this->export_enrollments( $filters );
				break;

			case 'attendance':
				$result = $this->export_attendance( $filters, $date_range );
				break;

			case 'financial':
				$result = $this->export_financial( $filters );
				break;

			default:
				$result = array(
					'headers' => array(),
					'rows'    => array(),
				);
				break;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Generate CSV file.
		$csv_result = $this->write_csv( $export_type, $result['headers'], $result['rows'] );

		if ( is_wp_error( $csv_result ) ) {
			return $csv_result;
		}

		return array(
			'success'     => true,
			'file_url'    => $csv_result['file_url'],
			'file_path'   => $csv_result['file_path'],
			'filename'    => $csv_result['filename'],
			'row_count'   => count( $result['rows'] ),
			'export_type' => $export_type,
		);
	}

	/**
	 * Query ECAs with optional filters applied.
	 *
	 * @param array $filters Filter parameters.
	 * @return WP_Post[] Array of ECA posts.
	 */
	private function query_filtered_ecas( $filters ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_eca_data', 0, 1000 ) : 1000,
		);

		$meta_query = array( 'relation' => 'AND' );

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

		if ( ! empty( $filters['eca_id'] ) ) {
			$query_args['p'] = absint( $filters['eca_id'] );
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Export ECAs data.
	 *
	 * @param array $filters Filter parameters.
	 * @return array Headers and rows for CSV.
	 */
	private function export_ecas( $filters ) {
		$ecas = $this->query_filtered_ecas( $filters );

		$headers = array( 'Name', 'Code', 'Type', 'Day', 'Start Time', 'End Time', 'Venue', 'Enrollment', 'Max Students', 'Status', 'Cost' );
		$rows    = array();

		foreach ( $ecas as $eca ) {
			$is_paid = get_post_meta( $eca->ID, '_eca_is_paid', true ) === 'yes';
			$cost    = $is_paid ? floatval( get_post_meta( $eca->ID, '_eca_cost', true ) ) : 0;

			$rows[] = array(
				sanitize_text_field( $eca->post_title ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_code', true ) ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_type', true ) ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_day', true ) ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_start_time', true ) ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_end_time', true ) ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_venue', true ) ),
				absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) ),
				absint( get_post_meta( $eca->ID, '_eca_max_students', true ) ),
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_status', true ) ),
				$cost,
			);
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Export students data with enrollment info.
	 *
	 * @param array $filters Filter parameters.
	 * @return array Headers and rows for CSV.
	 */
	private function export_students( $filters ) {
		$student_args = array(
			'post_type'      => 'mcp_ai_student',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_eca_data', 0, 1000 ) : 1000,
		);

		if ( ! empty( $filters['year_group'] ) ) {
			$student_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_student_year_group',
					'value' => sanitize_text_field( $filters['year_group'] ),
				),
			);
		}

		$students = new WP_Query( $student_args );

		$headers = array( 'Student Name', 'Student ID', 'Year Group', 'ECAs Enrolled', 'ECA Names' );
		$rows    = array();

		foreach ( $students->posts as $student ) {
			$year_group = sanitize_text_field( get_post_meta( $student->ID, '_student_year_group', true ) );

			// Find ECAs this student is in.
			$eca_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_eca',
					'post_status'    => 'publish',
					'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_eca_data', 0, 1000 ) : 1000,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_eca_enrolled_students',
							'value'   => (string) $student->ID,
							'compare' => 'LIKE',
						),
					),
				)
			);

			$eca_names = wp_list_pluck( $eca_query->posts, 'post_title' );

			$rows[] = array(
				sanitize_text_field( $student->post_title ),
				$student->ID,
				$year_group,
				count( $eca_names ),
				implode( '; ', array_map( 'sanitize_text_field', $eca_names ) ),
			);
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Export enrollment records.
	 *
	 * @param array $filters Filter parameters.
	 * @return array Headers and rows for CSV.
	 */
	private function export_enrollments( $filters ) {
		$ecas = $this->query_filtered_ecas( $filters );

		$headers = array( 'Student Name', 'ECA Name', 'ECA Type', 'Enrollment Date', 'Payment Status' );
		$rows    = array();

		foreach ( $ecas as $eca ) {
			$eca_type          = sanitize_text_field( get_post_meta( $eca->ID, '_eca_type', true ) );
			$enrolled_students = get_post_meta( $eca->ID, '_eca_enrolled_students', true );

			if ( ! is_array( $enrolled_students ) ) {
				continue;
			}

			foreach ( $enrolled_students as $sid ) {
				$student = get_post( absint( $sid ) );
				if ( ! $student ) {
					continue;
				}

				$enrollment_date = get_post_meta( $eca->ID, '_eca_enrollment_date_' . absint( $sid ), true );
				$payment_status  = get_post_meta( $eca->ID, '_eca_payment_' . absint( $sid ), true );

				$rows[] = array(
					sanitize_text_field( $student->post_title ),
					sanitize_text_field( $eca->post_title ),
					$eca_type,
					sanitize_text_field( $enrollment_date ),
					sanitize_text_field( $payment_status ? $payment_status : 'N/A' ),
				);
			}
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Export attendance records.
	 *
	 * @param array $filters    Filter parameters.
	 * @param array $date_range Date range filter.
	 * @return array Headers and rows for CSV.
	 */
	private function export_attendance( $filters, $date_range ) {
		$ecas = $this->query_filtered_ecas( $filters );

		$start_date = ! empty( $date_range['start_date'] ) ? sanitize_text_field( $date_range['start_date'] ) : '';
		$end_date   = ! empty( $date_range['end_date'] ) ? sanitize_text_field( $date_range['end_date'] ) : '';

		$headers = array( 'ECA Name', 'Date', 'Student Name', 'Status' );
		$rows    = array();

		foreach ( $ecas as $eca ) {
			$attendance_log = get_post_meta( $eca->ID, '_eca_attendance_log', true );
			if ( ! is_array( $attendance_log ) ) {
				continue;
			}

			foreach ( $attendance_log as $date => $session ) {
				// Apply date range filter.
				if ( '' !== $start_date && $date < $start_date ) {
					continue;
				}
				if ( '' !== $end_date && $date > $end_date ) {
					continue;
				}

				$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

				foreach ( $attendees as $attendee ) {
					$rows[] = array(
						sanitize_text_field( $eca->post_title ),
						sanitize_text_field( $date ),
						isset( $attendee['student_name'] ) ? sanitize_text_field( $attendee['student_name'] ) : '',
						isset( $attendee['status'] ) ? sanitize_text_field( $attendee['status'] ) : '',
					);
				}
			}
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Export financial data.
	 *
	 * @param array $filters Filter parameters.
	 * @return array Headers and rows for CSV.
	 */
	private function export_financial( $filters ) {
		$ecas = $this->query_filtered_ecas( $filters );

		$headers = array( 'ECA Name', 'Cost', 'Enrollment', 'Revenue', 'Cost Period', 'Paid Status' );
		$rows    = array();

		foreach ( $ecas as $eca ) {
			$is_paid    = get_post_meta( $eca->ID, '_eca_is_paid', true ) === 'yes';
			$cost       = floatval( get_post_meta( $eca->ID, '_eca_cost', true ) );
			$enrollment = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
			$revenue    = $is_paid ? round( $cost * $enrollment, 2 ) : 0;

			$rows[] = array(
				sanitize_text_field( $eca->post_title ),
				$is_paid ? $cost : 0,
				$enrollment,
				$revenue,
				sanitize_text_field( get_post_meta( $eca->ID, '_eca_cost_period', true ) ),
				$is_paid ? 'Paid' : 'Free',
			);
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Write CSV data to a file in the uploads directory.
	 *
	 * @param string $export_type Export type for filename.
	 * @param array  $headers     Column headers.
	 * @param array  $rows        Data rows.
	 * @return array|WP_Error File info or error.
	 */
	private function write_csv( $export_type, $headers, $rows ) {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_error',
				/* translators: %s: Upload directory error message. */
				sprintf( __( 'Upload directory error: %s', 'mcp-ai-wpoos-pro' ), $upload_dir['error'] )
			);
		}

		$subdir   = $upload_dir['basedir'] . '/mcp-ai-exports';
		$suburl   = $upload_dir['baseurl'] . '/mcp-ai-exports';
		$filename = 'eca-export-' . sanitize_file_name( $export_type ) . '-' . gmdate( 'Y-m-d-His' ) . '.csv';
		$filepath = $subdir . '/' . $filename;

		// Ensure the export directory exists.
		if ( ! file_exists( $subdir ) ) {
			wp_mkdir_p( $subdir );
		}

		// Write CSV content.
		$handle = fopen( $filepath, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error(
				'wp_mcp_ai_file_error',
				__( 'Unable to create the export file.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Write UTF-8 BOM for Excel compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Write header row.
		fputcsv( $handle, $headers );

		// Write data rows.
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Set secure file permissions.
		chmod( $filepath, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return array(
			'file_url'  => $suburl . '/' . $filename,
			'file_path' => $filepath,
			'filename'  => $filename,
		);
	}
}
