<?php
/**
 * Tool for bulk importing products from Excel with field mapping.
 *
 * Allows AI assistants to import regulatory products in bulk
 * from Excel files with customizable field mapping.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Imports products from Excel files.
 */
class WP_MCP_AI_Tool_Import_Products_From_Excel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_products_from_excel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Products from Excel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk imports regulatory products from Excel file (XLSX format) with comprehensive field mapping, validation, and support for multiple worksheet formats including L\'OCCITANE, Puig, and other regulatory tracking sheets.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_path'             => array(
					'type'        => 'string',
					'description' => __( 'Path to Excel file (.xlsx format, required)', 'mcp-ai-wpoos-pro' ),
				),
				'field_mapping'         => array(
					'type'        => 'object',
					'description' => __( 'Map Excel columns to product fields. Use column letters (A, B, C) or names. Supports all regulatory tracking fields.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'supplier_reference'                   => array(
							'type'        => 'string',
							'description' => 'Column for supplier reference code',
						),
						'item_name'                            => array(
							'type'        => 'string',
							'description' => 'Column for product/item name',
						),
						'item_group'                           => array(
							'type'        => 'string',
							'description' => 'Column for item group/range',
						),
						'brand'                                => array(
							'type'        => 'string',
							'description' => 'Column for brand name',
						),
						'product_status'                       => array(
							'type'        => 'string',
							'description' => 'Column for product status',
						),
						'loa'                                  => array(
							'type'        => 'string',
							'description' => 'Column for LOA (Letter of Authorization)',
						),
						'manufacturer_declaration'             => array(
							'type'        => 'string',
							'description' => 'Column for Manufacturer Declaration',
						),
						'art_works'                            => array(
							'type'        => 'string',
							'description' => 'Column for Art Works',
						),
						'date_of_apply_sample_import_license'  => array(
							'type'        => 'string',
							'description' => 'Column for Sample Import License application date',
						),
						'payments'                             => array(
							'type'        => 'string',
							'description' => 'Column for payment status',
						),
						'sample_import_license'                => array(
							'type'        => 'string',
							'description' => 'Column for Sample Import License status',
						),
						'sample_import_license_received_date'  => array(
							'type'        => 'string',
							'description' => 'Column for license received date',
						),
						'license_exp_date'                     => array(
							'type'        => 'string',
							'description' => 'Column for license expiry date',
						),
						'sample'                               => array(
							'type'        => 'string',
							'description' => 'Column for sample status',
						),
						'formula_certificate'                  => array(
							'type'        => 'string',
							'description' => 'Column for Formula Certificate',
						),
						'certificate_of_analysis'              => array(
							'type'        => 'string',
							'description' => 'Column for Certificate of Analysis',
						),
						'free_sale_certificate'                => array(
							'type'        => 'string',
							'description' => 'Column for Free Sale Certificate',
						),
						'registration_payment_status'          => array(
							'type'        => 'string',
							'description' => 'Column for registration payment status',
						),
						'payment_date'                         => array(
							'type'        => 'string',
							'description' => 'Column for payment date',
						),
						'file_handover_date'                   => array(
							'type'        => 'string',
							'description' => 'Column for file handover date',
						),
						'cos_no'                               => array(
							'type'        => 'string',
							'description' => 'Column for COS registration number',
						),
						'evaluation_payment'                   => array(
							'type'        => 'string',
							'description' => 'Column for evaluation payment status',
						),
						'registration_certificate_status'      => array(
							'type'        => 'string',
							'description' => 'Column for registration certificate status',
						),
					),
				),
				'skip_duplicates'       => array(
					'type'        => 'boolean',
					'description' => __( 'Skip products with duplicate supplier references (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'start_row'             => array(
					'type'        => 'integer',
					'description' => __( 'Starting row number (optional, default: 2 for header row)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 2,
				),
				'end_row'               => array(
					'type'        => 'integer',
					'description' => __( 'Ending row number to process (optional, processes all rows if not specified)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'exclude_section_markers' => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically exclude section marker rows like "These items are not in the final list" (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'sheet_index'           => array(
					'type'        => 'integer',
					'description' => __( 'Worksheet index to import from (optional, default: 0 for first sheet)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
			'required'             => array( 'file_path', 'field_mapping' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Creates products.
			'file-upload',          // Handles file uploads.
			'destructive',          // Can create many records.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import products.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['file_path'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'File path is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['field_mapping'] ) || ! is_array( $arguments['field_mapping'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Field mapping is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$file_path               = sanitize_text_field( $arguments['file_path'] );
		$field_mapping           = $arguments['field_mapping'];
		$skip_duplicates         = isset( $arguments['skip_duplicates'] ) ? (bool) $arguments['skip_duplicates'] : true;
		$start_row               = ! empty( $arguments['start_row'] ) ? absint( $arguments['start_row'] ) : 2;
		$end_row                 = ! empty( $arguments['end_row'] ) ? absint( $arguments['end_row'] ) : null;
		$exclude_section_markers = isset( $arguments['exclude_section_markers'] ) ? (bool) $arguments['exclude_section_markers'] : true;
		$sheet_index             = ! empty( $arguments['sheet_index'] ) ? absint( $arguments['sheet_index'] ) : 0;

		// Resolve URL to local path if needed.
		$file_path = $this->resolve_file_path( $file_path );

		// Verify file exists.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'Excel file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify file extension.
		$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $file_extension, array( 'xlsx', 'xls' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_file', __( 'File must be an Excel file (.xlsx or .xls).', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if PhpSpreadsheet is available.
		if ( ! class_exists( 'PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_dependency', __( 'PhpSpreadsheet library is not installed. Please run "composer install".', 'mcp-ai-wpoos-pro' ) );
		}

		try {
			// Load the spreadsheet.
			$spreadsheet = IOFactory::load( $file_path );
			$worksheet   = $spreadsheet->getSheet( $sheet_index );
			$highest_row = $worksheet->getHighestRow();

			// Normalize field mapping (convert column names to letters if needed).
			$normalized_mapping = $this->normalize_field_mapping( $field_mapping, $worksheet, $start_row - 1 );

			$imported        = 0;
			$skipped         = 0;
			$errors          = array();
			$section_skipped = 0;

			// Determine end row.
			$actual_end_row = $end_row ? min( $end_row, $highest_row ) : $highest_row;

			// Process each row.
			for ( $row_number = $start_row; $row_number <= $actual_end_row; $row_number++ ) {
				// Extract row data based on field mapping.
				$row_data = $this->extract_row_data( $worksheet, $row_number, $normalized_mapping );

				// Check if this is a section marker row.
				if ( $exclude_section_markers && $this->is_section_marker( $row_data ) ) {
					++$section_skipped;
					continue;
				}

				// Check if row is completely empty.
				if ( $this->is_empty_row( $row_data ) ) {
					continue;
				}

				// Validate required fields.
				$validation_errors = $this->validate_row( $row_data, $row_number );
				if ( ! empty( $validation_errors ) ) {
					$errors = array_merge( $errors, $validation_errors );
					continue;
				}

				// Check for duplicates by supplier reference.
				if ( $skip_duplicates && ! empty( $row_data['supplier_reference'] ) ) {
					$existing = $this->find_product_by_supplier_reference( $row_data['supplier_reference'] );
					if ( $existing ) {
						++$skipped;
						continue;
					}
				}

				// Create the product.
				$result = $this->create_product( $row_data, $current_user_id );
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						/* translators: 1: row number, 2: error message */
						__( 'Row %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
						$row_number,
						$result->get_error_message()
					);
					continue;
				}

				++$imported;
			}

			return array(
				'success'         => true,
				'imported'        => $imported,
				'skipped'         => $skipped,
				'section_skipped' => $section_skipped,
				'errors'          => $errors,
				'total_processed' => $imported + $skipped + count( $errors ),
				'rows_scanned'    => $actual_end_row - $start_row + 1,
				'message'         => sprintf(
					/* translators: 1: imported count, 2: skipped count, 3: section markers skipped */
					__( 'Import complete: %1$d imported, %2$d skipped (duplicates), %3$d section markers skipped.', 'mcp-ai-wpoos-pro' ),
					$imported,
					$skipped,
					$section_skipped
				),
			);

		} catch ( \Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_import_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to import Excel file: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Normalize field mapping to use column letters.
	 *
	 * @param array                                        $field_mapping User-provided field mapping.
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet     The worksheet.
	 * @param int                                          $header_row    Header row number (0-indexed).
	 * @return array Normalized mapping with column letters.
	 */
	private function normalize_field_mapping( $field_mapping, $worksheet, $header_row ) {
		$normalized = array();

		foreach ( $field_mapping as $field_name => $column_reference ) {
			if ( empty( $column_reference ) ) {
				continue;
			}

			// If it's already a column letter (A, B, C, etc.), use it directly.
			if ( preg_match( '/^[A-Z]+$/i', $column_reference ) ) {
				$normalized[ $field_name ] = strtoupper( $column_reference );
			} else {
				// Try to find the column by header name.
				$column_letter = $this->find_column_by_header( $worksheet, $column_reference, $header_row );
				if ( $column_letter ) {
					$normalized[ $field_name ] = $column_letter;
				}
			}
		}

		return $normalized;
	}

	/**
	 * Find column letter by header name.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet   The worksheet.
	 * @param string                                        $header_name Header name to find.
	 * @param int                                           $header_row  Header row number (0-indexed).
	 * @return string|null Column letter or null if not found.
	 */
	private function find_column_by_header( $worksheet, $header_name, $header_row ) {
		$highest_column = $worksheet->getHighestColumn();
		$header_name    = strtolower( trim( $header_name ) );

		// Use PhpSpreadsheet's column index methods for proper iteration.
		$highest_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest_column );

		// Iterate through columns using numeric index.
		for ( $col_index = 1; $col_index <= $highest_col_index; ++$col_index ) {
			$col        = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col_index );
			$cell_value = $worksheet->getCell( $col . ( $header_row + 1 ) )->getValue();
			if ( strtolower( trim( (string) $cell_value ) ) === $header_name ) {
				return $col;
			}
		}

		return null;
	}

	/**
	 * Extract row data based on field mapping.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet    The worksheet.
	 * @param int                                           $row_number   Row number (1-indexed).
	 * @param array                                         $field_mapping Normalized field mapping.
	 * @return array Extracted row data.
	 */
	private function extract_row_data( $worksheet, $row_number, $field_mapping ) {
		$row_data = array();

		foreach ( $field_mapping as $field_name => $column_letter ) {
			$cell_value              = $worksheet->getCell( $column_letter . $row_number )->getValue();
			$row_data[ $field_name ] = $this->sanitize_cell_value( $cell_value );
		}

		return $row_data;
	}

	/**
	 * Sanitize cell value.
	 *
	 * @param mixed $value Cell value.
	 * @return string Sanitized value.
	 */
	private function sanitize_cell_value( $value ) {
		if ( is_null( $value ) ) {
			return '';
		}

		// Handle dates.
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}

		// Convert to string and sanitize.
		return sanitize_text_field( trim( (string) $value ) );
	}

	/**
	 * Check if row is a section marker.
	 *
	 * @param array $row_data Row data.
	 * @return bool True if section marker.
	 */
	private function is_section_marker( $row_data ) {
		// Common section marker patterns.
		$marker_patterns = array(
			'these items are not in the final list',
			'these items are not in the in the final list',
			'remove list',
			'parfumes',
			'light orange colour item',
			'yellow are the ones',
		);

		// Check all values in the row.
		foreach ( $row_data as $value ) {
			$value_lower = strtolower( (string) $value );
			foreach ( $marker_patterns as $pattern ) {
				if ( strpos( $value_lower, $pattern ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if row is completely empty.
	 *
	 * @param array $row_data Row data.
	 * @return bool True if empty.
	 */
	private function is_empty_row( $row_data ) {
		foreach ( $row_data as $value ) {
			if ( ! empty( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate row data.
	 *
	 * @param array $row_data   Row data.
	 * @param int   $row_number Row number.
	 * @return array Array of error messages.
	 */
	private function validate_row( $row_data, $row_number ) {
		$errors = array();

		// Check for required fields.
		if ( empty( $row_data['item_name'] ) && empty( $row_data['supplier_reference'] ) ) {
			$errors[] = sprintf(
				/* translators: %d: row number */
				__( 'Row %d: Either Item Name or Supplier Reference is required.', 'mcp-ai-wpoos-pro' ),
				$row_number
			);
		}

		return $errors;
	}

	/**
	 * Find product by supplier reference.
	 *
	 * @param string $supplier_reference Supplier reference.
	 * @return int|null Product ID or null if not found.
	 */
	private function find_product_by_supplier_reference( $supplier_reference ) {
		$args = array(
			'post_type'      => 'mcp_ai_reg_product',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => 'supplier_reference',
					'value'   => $supplier_reference,
					'compare' => '=',
				),
			),
			'fields'         => 'ids',
		);

		$posts = get_posts( $args );
		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Create product from row data.
	 *
	 * @param array $row_data Row data.
	 * @param int   $user_id  User ID.
	 * @return int|WP_Error Product ID or error.
	 */
	private function create_product( $row_data, $user_id ) {
		// Determine product name.
		$product_name = ! empty( $row_data['item_name'] ) ? $row_data['item_name'] : $row_data['supplier_reference'];

		// Create the product post.
		$post_id = wp_insert_post(
			array(
				'post_title'  => $product_name,
				'post_type'   => 'mcp_ai_reg_product',
				'post_status' => 'publish',
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Define all regulatory meta fields.
		$meta_fields = array(
			'supplier_reference',
			'item_group',
			'brand',
			'product_status',
			'loa',
			'manufacturer_declaration',
			'art_works',
			'date_of_apply_sample_import_license',
			'payments',
			'sample_import_license',
			'sample_import_license_received_date',
			'license_exp_date',
			'sample',
			'formula_certificate',
			'certificate_of_analysis',
			'free_sale_certificate',
			'registration_payment_status',
			'payment_date',
			'file_handover_date',
			'cos_no',
			'evaluation_payment',
			'registration_certificate_status',
		);

		// Save all meta fields.
		foreach ( $meta_fields as $meta_key ) {
			if ( ! empty( $row_data[ $meta_key ] ) ) {
				update_post_meta( $post_id, $meta_key, $row_data[ $meta_key ] );
			}
		}

		// Handle brand taxonomy if it exists.
		if ( ! empty( $row_data['brand'] ) && taxonomy_exists( 'mcp_ai_reg_brand' ) ) {
			wp_set_object_terms( $post_id, $row_data['brand'], 'mcp_ai_reg_brand' );
		}

		return $post_id;
	}
}
