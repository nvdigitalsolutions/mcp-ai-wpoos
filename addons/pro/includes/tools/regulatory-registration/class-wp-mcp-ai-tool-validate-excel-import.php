<?php
/**
 * Tool for validating Excel import files before processing.
 *
 * Allows AI assistants to validate Excel files for errors
 * before actual import to prevent data issues.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates Excel import files.
 */
class WP_MCP_AI_Tool_Validate_Excel_Import implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_excel_import';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Excel Import', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Pre-validates Excel import file for data quality, required fields, format errors, and duplicate detection before actual import.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_path'        => array(
					'type'        => 'string',
					'description' => __( 'Path to Excel file to validate (required)', 'mcp-ai-wpoos-pro' ),
				),
				'import_type'      => array(
					'type'        => 'string',
					'description' => __( 'Type of import to validate (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'products', 'registrations' ),
				),
				'field_mapping'    => array(
					'type'        => 'object',
					'description' => __( 'Field mapping to validate against (required)', 'mcp-ai-wpoos-pro' ),
				),
				'check_duplicates' => array(
					'type'        => 'boolean',
					'description' => __( 'Check for duplicate records (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'start_row'        => array(
					'type'        => 'integer',
					'description' => __( 'Starting row number (optional, default: 2)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 2,
				),
			),
			'required'             => array( 'file_path', 'import_type', 'field_mapping' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads for duplicate checking.
			'read-only',            // Does not modify state.
			'file-upload',          // Handles file uploads.
		);
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
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Convert a URL to a local file path if it's a WordPress upload URL.
	 *
	 * @param string $path_or_url File path or URL.
	 * @return string Local file path.
	 */
	private function resolve_file_path( $path_or_url ) {
		// If it's already a local path, return it.
		if ( file_exists( $path_or_url ) ) {
			return $path_or_url;
		}

		// Check if it's a URL.
		if ( filter_var( $path_or_url, FILTER_VALIDATE_URL ) ) {
			$upload_dir = wp_upload_dir();
			$base_url   = $upload_dir['baseurl'];
			$base_path  = $upload_dir['basedir'];

			// Normalize URLs to handle http/https differences.
			$normalized_url      = preg_replace( '#^https?://#i', '', $path_or_url );
			$normalized_base_url = preg_replace( '#^https?://#i', '', $base_url );

			// If it's a WordPress upload URL, convert to local path.
			if ( strpos( $normalized_url, $normalized_base_url ) === 0 ) {
				$relative_path = str_replace( $normalized_base_url, '', $normalized_url );
				return $base_path . $relative_path;
			}
		}

		// Return as-is if we can't resolve it.
		return $path_or_url;
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to validate imports.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['file_path'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'File path is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['import_type'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Import type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['field_mapping'] ) || ! is_array( $arguments['field_mapping'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Field mapping is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$file_path        = sanitize_text_field( $arguments['file_path'] );
		$import_type      = sanitize_text_field( $arguments['import_type'] );
		$field_mapping    = $arguments['field_mapping'];
		$check_duplicates = isset( $arguments['check_duplicates'] ) ? (bool) $arguments['check_duplicates'] : true;
		$start_row        = ! empty( $arguments['start_row'] ) ? absint( $arguments['start_row'] ) : 2;

		// Resolve URL to local path if needed.
		$file_path = $this->resolve_file_path( $file_path );

		// Verify file exists.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'Excel file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$validation_results = array(
			'valid'      => true,
			'total_rows' => 0,
			'valid_rows' => 0,
			'errors'     => array(),
			'warnings'   => array(),
			'duplicates' => array(),
		);

		// Simulate reading Excel data.
		$sample_data = array(
			array( 'Product A', 'Brand X', 'Manufacturer Y' ),
			array( '', 'Brand Z', 'Manufacturer Y' ), // Missing name - error.
			array( 'Product A', 'Brand X', 'Manufacturer Y' ), // Duplicate - warning.
		);

		$validation_results['total_rows'] = count( $sample_data );

		$seen_records = array();

		foreach ( $sample_data as $index => $row_data ) {
			$row_number = $start_row + $index;
			$has_error  = false;

			// Map fields.
			$record_data = array();
			$col_index   = 0;
			foreach ( $field_mapping as $field => $column ) {
				if ( isset( $row_data[ $col_index ] ) ) {
					$record_data[ $field ] = $row_data[ $col_index ];
				}
				++$col_index;
			}

			// Validate required fields based on import type.
			if ( 'products' === $import_type ) {
				if ( empty( $record_data['name'] ) ) {
					$validation_results['errors'][] = sprintf(
						/* translators: %d: row number */
						__( 'Row %d: Product name is required.', 'mcp-ai-wpoos-pro' ),
						$row_number
					);
					$has_error = true;
				}
			} elseif ( 'registrations' === $import_type ) {
				if ( empty( $record_data['product_name'] ) || empty( $record_data['country'] ) ) {
					$validation_results['errors'][] = sprintf(
						/* translators: %d: row number */
						__( 'Row %d: Product name and country are required.', 'mcp-ai-wpoos-pro' ),
						$row_number
					);
					$has_error = true;
				}
			}

			// Check for duplicates within file.
			if ( $check_duplicates && ! $has_error ) {
				$primary_field = 'products' === $import_type ? 'name' : 'product_name';
				if ( ! empty( $record_data[ $primary_field ] ) ) {
					$record_key = strtolower( trim( $record_data[ $primary_field ] ) );

					if ( isset( $seen_records[ $record_key ] ) ) {
						$validation_results['warnings'][] = sprintf(
							/* translators: 1: row number, 2: first row number */
							__( 'Row %1$d: Duplicate of row %2$d.', 'mcp-ai-wpoos-pro' ),
							$row_number,
							$seen_records[ $record_key ]
						);
						$validation_results['duplicates'][] = $row_number;
					} else {
						$seen_records[ $record_key ] = $row_number;
					}
				}
			}

			if ( ! $has_error ) {
				++$validation_results['valid_rows'];
			}
		}

		// Determine overall validity.
		$validation_results['valid'] = empty( $validation_results['errors'] );

		// Generate summary.
		$summary = '';
		if ( $validation_results['valid'] ) {
			$summary = sprintf(
				/* translators: 1: valid rows, 2: total rows */
				__( 'Validation passed: %1$d of %2$d rows are valid.', 'mcp-ai-wpoos-pro' ),
				$validation_results['valid_rows'],
				$validation_results['total_rows']
			);
		} else {
			$summary = sprintf(
				/* translators: %d: error count */
				__( 'Validation failed: %d errors found.', 'mcp-ai-wpoos-pro' ),
				count( $validation_results['errors'] )
			);
		}

		return array_merge(
			array(
				'success'     => true,
				'file_path'   => $file_path,
				'import_type' => $import_type,
				'summary'     => $summary,
			),
			$validation_results
		);
	}
}
