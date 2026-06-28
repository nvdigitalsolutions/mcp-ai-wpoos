<?php
/**
 * Tool for importing ECAs from CSV data.
 *
 * Imports ECAs from a CSV file or CSV text content. Maps columns to ECA fields
 * and creates or updates ECAs in bulk. Supports dry-run mode to preview changes
 * before committing.
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
 * Imports ECAs from CSV file or text content with column mapping and dry-run support.
 */
class WP_MCP_AI_Tool_Import_ECAs_CSV implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_ecas_csv';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import ECAs from CSV', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports ECAs from a CSV file or CSV text content. Maps columns to ECA fields and creates or updates ECAs in bulk. Supports dry-run mode to preview changes before committing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'csv_content'     => array(
					'type'        => 'string',
					'description' => __( 'Raw CSV text content to import', 'mcp-ai-wpoos-pro' ),
				),
				'file_url'        => array(
					'type'        => 'string',
					'description' => __( 'URL to a CSV file to download and import', 'mcp-ai-wpoos-pro' ),
				),
				'column_mapping'  => array(
					'type'        => 'object',
					'description' => __( 'Maps CSV column names to ECA fields. If omitted, headers are auto-detected.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'name'         => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for ECA name', 'mcp-ai-wpoos-pro' ),
						),
						'eca_code'     => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for ECA code', 'mcp-ai-wpoos-pro' ),
						),
						'description'  => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for ECA description', 'mcp-ai-wpoos-pro' ),
						),
						'eca_type'     => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for ECA type', 'mcp-ai-wpoos-pro' ),
						),
						'day'          => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for day of week', 'mcp-ai-wpoos-pro' ),
						),
						'start_time'   => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for start time', 'mcp-ai-wpoos-pro' ),
						),
						'end_time'     => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for end time', 'mcp-ai-wpoos-pro' ),
						),
						'venue'        => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for venue', 'mcp-ai-wpoos-pro' ),
						),
						'max_students' => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for max students', 'mcp-ai-wpoos-pro' ),
						),
						'year_groups'  => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for year groups', 'mcp-ai-wpoos-pro' ),
						),
						'teachers'     => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for teachers', 'mcp-ai-wpoos-pro' ),
						),
						'is_paid'      => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for paid status', 'mcp-ai-wpoos-pro' ),
						),
						'cost'         => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for cost', 'mcp-ai-wpoos-pro' ),
						),
						'cost_period'  => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for cost period', 'mcp-ai-wpoos-pro' ),
						),
						'status'       => array(
							'type'        => 'string',
							'description' => __( 'CSV column name for ECA status', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Update ECAs matched by eca_code instead of creating duplicates', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'description' => __( 'Preview changes without writing to the database', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array(),
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
			'risk_level'            => 'elevated',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to import ECAs.', 'mcp-ai-wpoos-pro' )
			);
		}

		$csv_content     = isset( $arguments['csv_content'] ) ? $arguments['csv_content'] : '';
		$file_url        = isset( $arguments['file_url'] ) ? esc_url_raw( $arguments['file_url'] ) : '';
		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : false;
		$dry_run         = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : false;

		// Get CSV content from either source.
		if ( '' === $csv_content && '' === $file_url ) {
			return new WP_Error(
				'wp_mcp_ai_missing_csv',
				__( 'Either csv_content or file_url must be provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $csv_content && '' !== $file_url ) {
			$response = wp_remote_get(
				$file_url,
				array(
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to download CSV file: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					)
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				return new WP_Error(
					'wp_mcp_ai_download_failed',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'CSV file download returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
						$status_code
					)
				);
			}

			$csv_content = wp_remote_retrieve_body( $response );
		}

		if ( '' === trim( $csv_content ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_csv',
				__( 'CSV content is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse CSV.
		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv_content ) );
		if ( count( $lines ) < 2 ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_csv',
				__( 'CSV must contain at least a header row and one data row.', 'mcp-ai-wpoos-pro' )
			);
		}

		$headers = str_getcsv( array_shift( $lines ) );
		$headers = array_map( 'trim', $headers );

		// Build column mapping.
		$column_mapping = isset( $arguments['column_mapping'] ) && is_array( $arguments['column_mapping'] )
			? array_map( 'sanitize_text_field', $arguments['column_mapping'] )
			: $this->auto_detect_mapping( $headers );

		// Invert mapping: ECA field => CSV column index.
		$field_to_index = array();
		foreach ( $column_mapping as $eca_field => $csv_column ) {
			$csv_column = trim( $csv_column );
			$col_index  = array_search( $csv_column, $headers, true );
			if ( false !== $col_index ) {
				$field_to_index[ $eca_field ] = $col_index;
			}
		}

		if ( ! isset( $field_to_index['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_name_column',
				__( 'CSV must contain a column mapped to the "name" field.', 'mcp-ai-wpoos-pro' )
			);
		}

		$created_count = 0;
		$updated_count = 0;
		$skipped_count = 0;
		$errors        = array();
		$total_rows    = count( $lines );

		foreach ( $lines as $row_num => $line ) {
			$row = str_getcsv( $line );

			if ( empty( array_filter( $row ) ) ) {
				++$skipped_count;
				continue;
			}

			$eca_data = $this->map_row_to_eca( $row, $field_to_index );

			if ( '' === $eca_data['name'] ) {
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: Missing ECA name, skipped.', 'mcp-ai-wpoos-pro' ),
					$row_num + 2
				);
				++$skipped_count;
				continue;
			}

			// Check for existing ECA by eca_code.
			$existing_id = 0;
			if ( $update_existing && ! empty( $eca_data['eca_code'] ) ) {
				$existing_id = $this->find_eca_by_code( $eca_data['eca_code'] );
			}

			if ( $dry_run ) {
				if ( $existing_id ) {
					++$updated_count;
				} else {
					++$created_count;
				}
				continue;
			}

			if ( $existing_id ) {
				$result = $this->update_eca( $existing_id, $eca_data );
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						/* translators: 1: row number, 2: error message */
						__( 'Row %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
						$row_num + 2,
						$result->get_error_message()
					);
					++$skipped_count;
				} else {
					++$updated_count;
				}
			} else {
				$result = $this->create_eca( $eca_data, $current_user_id );
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						/* translators: 1: row number, 2: error message */
						__( 'Row %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
						$row_num + 2,
						$result->get_error_message()
					);
					++$skipped_count;
				} else {
					++$created_count;
				}
			}
		}

		return array(
			'success'       => true,
			'total_rows'    => $total_rows,
			'created_count' => $created_count,
			'updated_count' => $updated_count,
			'skipped_count' => $skipped_count,
			'errors'        => $errors,
			'dry_run'       => $dry_run,
			'message'       => $dry_run
				? sprintf(
					/* translators: 1: created count, 2: updated count, 3: skipped count */
					__( 'Dry run complete. Would create %1$d, update %2$d, skip %3$d ECAs.', 'mcp-ai-wpoos-pro' ),
					$created_count,
					$updated_count,
					$skipped_count
				)
				: sprintf(
					/* translators: 1: created count, 2: updated count, 3: skipped count */
					__( 'Import complete. Created %1$d, updated %2$d, skipped %3$d ECAs.', 'mcp-ai-wpoos-pro' ),
					$created_count,
					$updated_count,
					$skipped_count
				),
		);
	}

	/**
	 * Auto-detect column mapping by matching header names to known ECA fields.
	 *
	 * @param array $headers CSV header row.
	 * @return array Mapping of ECA field names to CSV column names.
	 */
	private function auto_detect_mapping( $headers ) {
		$known_fields = array(
			'name'         => array( 'name', 'eca_name', 'eca name', 'activity', 'activity name', 'title' ),
			'eca_code'     => array( 'eca_code', 'eca code', 'code', 'id', 'eca_id' ),
			'description'  => array( 'description', 'desc', 'details', 'about' ),
			'eca_type'     => array( 'eca_type', 'eca type', 'type', 'category' ),
			'day'          => array( 'day', 'day_of_week', 'day of week', 'weekday' ),
			'start_time'   => array( 'start_time', 'start time', 'start', 'from' ),
			'end_time'     => array( 'end_time', 'end time', 'end', 'to' ),
			'venue'        => array( 'venue', 'location', 'room', 'place' ),
			'max_students' => array( 'max_students', 'max students', 'capacity', 'max', 'limit' ),
			'year_groups'  => array( 'year_groups', 'year groups', 'year group', 'years', 'grades' ),
			'teachers'     => array( 'teachers', 'teacher', 'staff', 'instructor', 'coach' ),
			'is_paid'      => array( 'is_paid', 'is paid', 'paid', 'fee_required' ),
			'cost'         => array( 'cost', 'price', 'fee', 'amount' ),
			'cost_period'  => array( 'cost_period', 'cost period', 'billing_period', 'billing period', 'period' ),
			'status'       => array( 'status', 'eca_status', 'state' ),
		);

		$mapping       = array();
		$headers_lower = array_map( 'strtolower', $headers );

		foreach ( $known_fields as $field => $aliases ) {
			foreach ( $aliases as $alias ) {
				$index = array_search( $alias, $headers_lower, true );
				if ( false !== $index ) {
					$mapping[ $field ] = $headers[ $index ];
					break;
				}
			}
		}

		return $mapping;
	}

	/**
	 * Map a CSV row to ECA field data using the column index mapping.
	 *
	 * @param array $row            CSV row values.
	 * @param array $field_to_index Mapping of ECA field to column index.
	 * @return array Sanitized ECA data.
	 */
	private function map_row_to_eca( $row, $field_to_index ) {
		$data = array(
			'name'         => '',
			'eca_code'     => '',
			'description'  => '',
			'eca_type'     => 'club',
			'day'          => '',
			'start_time'   => '',
			'end_time'     => '',
			'venue'        => '',
			'max_students' => 0,
			'year_groups'  => array(),
			'teachers'     => array(),
			'is_paid'      => false,
			'cost'         => 0,
			'cost_period'  => 'term',
			'status'       => 'active',
		);

		foreach ( $field_to_index as $field => $index ) {
			if ( ! isset( $row[ $index ] ) ) {
				continue;
			}

			$value = trim( $row[ $index ] );

			switch ( $field ) {
				case 'name':
				case 'eca_code':
				case 'day':
				case 'start_time':
				case 'end_time':
				case 'venue':
				case 'eca_type':
				case 'cost_period':
				case 'status':
					$data[ $field ] = sanitize_text_field( $value );
					break;

				case 'description':
					$data[ $field ] = wp_kses_post( $value );
					break;

				case 'max_students':
					$data[ $field ] = absint( $value );
					break;

				case 'cost':
					$data[ $field ] = floatval( $value );
					break;

				case 'is_paid':
					$data[ $field ] = in_array( strtolower( $value ), array( 'yes', 'true', '1' ), true );
					break;

				case 'year_groups':
					$data[ $field ] = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $value ) ) );
					break;

				case 'teachers':
					$data[ $field ] = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $value ) ) );
					break;
			}
		}

		return $data;
	}

	/**
	 * Find an existing ECA post by its eca_code meta value.
	 *
	 * @param string $eca_code ECA code to search for.
	 * @return int Post ID if found, 0 otherwise.
	 */
	private function find_eca_by_code( $eca_code ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_eca_code',
						'value'   => $eca_code,
						'compare' => '=',
					),
				),
			)
		);

		return $query->have_posts() ? $query->posts[0] : 0;
	}

	/**
	 * Create a new ECA post from mapped data.
	 *
	 * @param array $eca_data Sanitized ECA field data.
	 * @param int   $user_id  Author user ID.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_eca( $eca_data, $user_id ) {
		$post_data = array(
			'post_title'   => $eca_data['name'],
			'post_content' => $eca_data['description'],
			'post_status'  => 'publish',
			'post_type'    => 'mcp_ai_eca',
			'post_author'  => $user_id,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_eca_meta( $post_id, $eca_data );

		return $post_id;
	}

	/**
	 * Update an existing ECA post from mapped data.
	 *
	 * @param int   $post_id  Existing ECA post ID.
	 * @param array $eca_data Sanitized ECA field data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function update_eca( $post_id, $eca_data ) {
		$post_data = array(
			'ID'           => $post_id,
			'post_title'   => $eca_data['name'],
			'post_content' => $eca_data['description'],
		);

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_eca_meta( $post_id, $eca_data );

		return $post_id;
	}

	/**
	 * Save ECA meta fields for a post.
	 *
	 * @param int   $post_id  ECA post ID.
	 * @param array $eca_data Sanitized ECA field data.
	 */
	private function save_eca_meta( $post_id, $eca_data ) {
		if ( ! empty( $eca_data['eca_code'] ) ) {
			update_post_meta( $post_id, '_eca_code', $eca_data['eca_code'] );
		}

		$valid_types = array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' );
		$eca_type    = in_array( $eca_data['eca_type'], $valid_types, true ) ? $eca_data['eca_type'] : 'club';
		update_post_meta( $post_id, '_eca_type', $eca_type );

		if ( ! empty( $eca_data['day'] ) ) {
			update_post_meta( $post_id, '_eca_day', $eca_data['day'] );
		}
		if ( ! empty( $eca_data['start_time'] ) ) {
			update_post_meta( $post_id, '_eca_start_time', $eca_data['start_time'] );
		}
		if ( ! empty( $eca_data['end_time'] ) ) {
			update_post_meta( $post_id, '_eca_end_time', $eca_data['end_time'] );
		}
		if ( ! empty( $eca_data['venue'] ) ) {
			update_post_meta( $post_id, '_eca_venue', $eca_data['venue'] );
		}
		if ( $eca_data['max_students'] > 0 ) {
			update_post_meta( $post_id, '_eca_max_students', $eca_data['max_students'] );
		}
		if ( ! empty( $eca_data['year_groups'] ) ) {
			update_post_meta( $post_id, '_eca_year_groups', $eca_data['year_groups'] );
		}
		if ( ! empty( $eca_data['teachers'] ) ) {
			update_post_meta( $post_id, '_eca_teachers', $eca_data['teachers'] );
		}

		update_post_meta( $post_id, '_eca_is_paid', $eca_data['is_paid'] ? 'yes' : 'no' );

		if ( $eca_data['is_paid'] && $eca_data['cost'] > 0 ) {
			update_post_meta( $post_id, '_eca_cost', $eca_data['cost'] );
			$valid_periods = array( 'term', 'month', 'session', 'year' );
			$cost_period   = in_array( $eca_data['cost_period'], $valid_periods, true ) ? $eca_data['cost_period'] : 'term';
			update_post_meta( $post_id, '_eca_cost_period', $cost_period );
		}

		$valid_statuses = array( 'active', 'inactive', 'full', 'cancelled' );
		$status         = in_array( $eca_data['status'], $valid_statuses, true ) ? $eca_data['status'] : 'active';
		update_post_meta( $post_id, '_eca_status', $status );
	}
}
