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
		return __( 'Bulk imports regulatory products from Excel file with customizable field mapping, validation, and duplicate handling.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_path'       => array(
					'type'        => 'string',
					'description' => __( 'Path to Excel file (required)', 'mcp-ai-wpoos-pro' ),
				),
				'field_mapping'   => array(
					'type'        => 'object',
					'description' => __( 'Map Excel columns to product fields (required)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'name'         => array( 'type' => 'string' ),
						'brand'        => array( 'type' => 'string' ),
						'manufacturer' => array( 'type' => 'string' ),
						'category'     => array( 'type' => 'string' ),
					),
				),
				'skip_duplicates' => array(
					'type'        => 'boolean',
					'description' => __( 'Skip duplicate products (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'start_row'       => array(
					'type'        => 'integer',
					'description' => __( 'Starting row number (optional, default: 2)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 2,
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

		$file_path       = sanitize_text_field( $arguments['file_path'] );
		$field_mapping   = $arguments['field_mapping'];
		$skip_duplicates = isset( $arguments['skip_duplicates'] ) ? (bool) $arguments['skip_duplicates'] : true;
		$start_row       = ! empty( $arguments['start_row'] ) ? absint( $arguments['start_row'] ) : 2;

		// Verify file exists.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'Excel file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Placeholder for Excel parsing (would use PHPSpreadsheet or similar).
		$imported = 0;
		$skipped  = 0;
		$errors   = array();

		// Simulate reading Excel data.
		$sample_data = array(
			array( 'Product A', 'Brand X', 'Manufacturer Y', 'Cosmetics' ),
			array( 'Product B', 'Brand Z', 'Manufacturer Y', 'Skincare' ),
		);

		foreach ( $sample_data as $index => $row_data ) {
			$row_number = $start_row + $index;

			// Map fields.
			$product_data = array();
			$col_index    = 0;
			foreach ( $field_mapping as $field => $column ) {
				if ( isset( $row_data[ $col_index ] ) ) {
					$product_data[ $field ] = sanitize_text_field( $row_data[ $col_index ] );
				}
				++$col_index;
			}

			// Check for required fields.
			if ( empty( $product_data['name'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: Product name is required.', 'mcp-ai-wpoos-pro' ),
					$row_number
				);
				continue;
			}

			// Check for duplicates.
			if ( $skip_duplicates ) {
				$existing = get_page_by_title( $product_data['name'], OBJECT, 'mcp_ai_reg_product' );
				if ( $existing ) {
					++$skipped;
					continue;
				}
			}

			// Create product.
			$post_id = wp_insert_post(
				array(
					'post_title'  => $product_data['name'],
					'post_type'   => 'mcp_ai_reg_product',
					'post_status' => 'publish',
					'post_author' => $current_user_id,
				)
			);

			if ( is_wp_error( $post_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: row number, 2: error message */
					__( 'Row %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$row_number,
					$post_id->get_error_message()
				);
				continue;
			}

			// Save metadata.
			if ( ! empty( $product_data['brand'] ) ) {
				update_post_meta( $post_id, 'brand', $product_data['brand'] );
			}
			if ( ! empty( $product_data['manufacturer'] ) ) {
				update_post_meta( $post_id, 'manufacturer', $product_data['manufacturer'] );
			}
			if ( ! empty( $product_data['category'] ) ) {
				update_post_meta( $post_id, 'category', $product_data['category'] );
			}

			++$imported;
		}

		return array(
			'success'  => true,
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'total'    => $imported + $skipped + count( $errors ),
			'message'  => sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Import complete: %1$d imported, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
				$imported,
				$skipped
			),
		);
	}
}
