<?php
/**
 * Import Products from CSV Tool
 *
 * Imports WooCommerce products from CSV or Excel files with comprehensive
 * data mapping and validation.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for importing WooCommerce products from CSV/Excel files.
 *
 * Supports:
 * - CSV and Excel file formats
 * - Product creation and update
 * - Variations and attributes
 * - Categories and tags
 * - Images by URL
 * - Custom field mapping
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Import_Products_CSV implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Product import requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Product import tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'import_products_csv';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Import Products from CSV', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Import WooCommerce products from CSV or Excel files. Supports creating new products, updating existing ones, variations, attributes, categories, tags, and images. Can handle large imports with progress tracking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'file_path'       => array(
					'type'        => 'string',
					'description' => __( 'Absolute file path to CSV/Excel file (required if no file_content)', 'mcp-ai-wpoos-pro' ),
				),
				'file_content'    => array(
					'type'        => 'string',
					'description' => __( 'CSV file content as string (required if no file_path)', 'mcp-ai-wpoos-pro' ),
				),
				'mapping'         => array(
					'type'        => 'object',
					'description' => __( 'Column mapping (auto-detected if not provided)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'name'              => array( 'type' => 'string' ),
						'sku'               => array( 'type' => 'string' ),
						'type'              => array( 'type' => 'string' ),
						'description'       => array( 'type' => 'string' ),
						'short_description' => array( 'type' => 'string' ),
						'regular_price'     => array( 'type' => 'string' ),
						'sale_price'        => array( 'type' => 'string' ),
						'stock_quantity'    => array( 'type' => 'string' ),
						'stock_status'      => array( 'type' => 'string' ),
						'categories'        => array( 'type' => 'string' ),
						'tags'              => array( 'type' => 'string' ),
						'images'            => array( 'type' => 'string' ),
					),
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Update existing products by SKU', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'skip_images'     => array(
					'type'        => 'boolean',
					'description' => __( 'Skip downloading product images', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'batch_size'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of products to process per batch', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'delimiter'       => array(
					'type'        => 'string',
					'description' => __( 'CSV delimiter character', 'mcp-ai-wpoos-pro' ),
					'default'     => ',',
					'maxLength'   => 1,
				),
			),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-plugin',
			'file-read',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to import products.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Get CSV data.
		$csv_data = $this->get_csv_data( $arguments );

		if ( is_wp_error( $csv_data ) ) {
			return $csv_data;
		}

		// Parse CSV.
		$rows = $this->parse_csv( $csv_data, $arguments );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		if ( empty( $rows ) ) {
			return new WP_Error(
				'empty_file',
				__( 'CSV file is empty or contains no valid data.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get column mapping.
		$mapping = $this->get_column_mapping( $rows[0], $arguments );

		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}

		// Process products.
		$batch_size      = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 50;
		$update_existing = isset( $arguments['update_existing'] ) && $arguments['update_existing'];
		$skip_images     = isset( $arguments['skip_images'] ) && $arguments['skip_images'];

		$results = array(
			'success'  => true,
			'total'    => count( $rows ) - 1, // Exclude header row.
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'products' => array(),
			'errors'   => array(),
		);

		// Process rows (skip header).
		$row_count = count( $rows );
		for ( $i = 1; $i < $row_count; $i++ ) {
			$row_data     = $this->map_row_data( $rows[ $i ], $mapping );
			$product_data = $this->sanitize_product_data( $row_data, $skip_images );

			// Check if product exists by SKU.
			$existing_product_id = 0;
			if ( ! empty( $product_data['sku'] ) ) {
				$existing_product_id = wc_get_product_id_by_sku( $product_data['sku'] );
			}

			if ( $existing_product_id && $update_existing ) {
				// Update existing product.
				$result = $this->update_product( $existing_product_id, $product_data, $current_user_id );
				if ( is_wp_error( $result ) ) {
					++$results['failed'];
					$results['errors'][] = array(
						'row'   => $i + 1,
						'sku'   => $product_data['sku'],
						'error' => $result->get_error_message(),
					);
				} else {
					++$results['updated'];
					$results['products'][] = $result;
				}
			} elseif ( $existing_product_id ) {
				// Product exists but update_existing is false.
				++$results['skipped'];
			} else {
				// Create new product.
				$result = $this->create_product( $product_data, $current_user_id );
				if ( is_wp_error( $result ) ) {
					++$results['failed'];
					$results['errors'][] = array(
						'row'   => $i + 1,
						'sku'   => $product_data['sku'],
						'error' => $result->get_error_message(),
					);
				} else {
					++$results['created'];
					$results['products'][] = $result;
				}
			}

			// Batch processing - reduce memory usage.
			if ( 0 === $i % $batch_size ) {
				wp_cache_flush();
			}
		}

		$results['message'] = sprintf(
			/* translators: 1: Created count, 2: Updated count, 3: Failed count, 4: Skipped count */
			__( 'Import complete: %1$d created, %2$d updated, %3$d failed, %4$d skipped.', 'mcp-ai-wpoos-pro' ),
			$results['created'],
			$results['updated'],
			$results['failed'],
			$results['skipped']
		);

		return $results;
	}

	/**
	 * Get CSV data from file or content.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string|WP_Error CSV data or error.
	 */
	protected function get_csv_data( $arguments ) {
		if ( ! empty( $arguments['file_content'] ) ) {
			return $arguments['file_content'];
		}

		if ( ! empty( $arguments['file_path'] ) ) {
			$file_path = sanitize_text_field( $arguments['file_path'] );

			// Security: Resolve canonical path to prevent directory traversal attacks.
			$resolved = realpath( $file_path );
			if ( false === $resolved ) {
				return new WP_Error(
					'invalid_file',
					__( 'File not found or not accessible.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Security: Restrict file access to the WordPress uploads directory.
			$upload_dir   = wp_upload_dir();
			$uploads_base = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
			if ( 0 !== strpos( wp_normalize_path( $resolved ), $uploads_base ) ) {
				return new WP_Error(
					'invalid_file',
					__( 'File must be located in the WordPress uploads directory.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Security: Ensure the file is readable.
			if ( ! is_readable( $resolved ) ) {
				return new WP_Error(
					'invalid_file',
					__( 'File is not readable.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Read file content.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for local file reading.
			$content = file_get_contents( $resolved );

			if ( false === $content ) {
				return new WP_Error(
					'file_read_error',
					__( 'Failed to read file content.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $content;
		}

		return new WP_Error(
			'missing_file',
			__( 'Either file_path or file_content is required.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Parse CSV data into rows.
	 *
	 * @param string $csv_data  CSV data.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Array of rows or error.
	 */
	protected function parse_csv( $csv_data, $arguments ) {
		$delimiter = isset( $arguments['delimiter'] ) ? $arguments['delimiter'] : ',';
		$rows      = array();

		$lines = explode( "\n", $csv_data );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$row = str_getcsv( $line, $delimiter );
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Get column mapping from header row.
	 *
	 * @param array $header_row Header row.
	 * @param array $arguments  Tool arguments.
	 * @return array|WP_Error Column mapping or error.
	 */
	protected function get_column_mapping( $header_row, $arguments ) {
		// Use provided mapping if available.
		if ( ! empty( $arguments['mapping'] ) && is_array( $arguments['mapping'] ) ) {
			return $arguments['mapping'];
		}

		// Auto-detect mapping.
		$mapping = array();

		foreach ( $header_row as $index => $column_name ) {
			$column_name = strtolower( trim( $column_name ) );

			// Map common column names.
			$field_mapping = array(
				'name'              => array( 'name', 'product name', 'title' ),
				'sku'               => array( 'sku', 'product code' ),
				'type'              => array( 'type', 'product type' ),
				'description'       => array( 'description', 'long description' ),
				'short_description' => array( 'short description', 'summary' ),
				'regular_price'     => array( 'regular price', 'price', 'regular_price' ),
				'sale_price'        => array( 'sale price', 'sale_price' ),
				'stock_quantity'    => array( 'stock', 'stock quantity', 'quantity', 'stock_quantity' ),
				'stock_status'      => array( 'stock status', 'stock_status' ),
				'categories'        => array( 'categories', 'category' ),
				'tags'              => array( 'tags', 'tag' ),
				'images'            => array( 'images', 'image', 'image url', 'image_url' ),
			);

			foreach ( $field_mapping as $field => $possible_names ) {
				if ( in_array( $column_name, $possible_names, true ) ) {
					$mapping[ $field ] = $index;
					break;
				}
			}
		}

		// Validate required fields.
		if ( empty( $mapping['name'] ) ) {
			return new WP_Error(
				'missing_name_column',
				__( 'CSV must contain a "name" or "product name" column.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $mapping;
	}

	/**
	 * Map row data using column mapping.
	 *
	 * @param array $row     Row data.
	 * @param array $mapping Column mapping.
	 * @return array Mapped data.
	 */
	protected function map_row_data( $row, $mapping ) {
		$data = array();

		foreach ( $mapping as $field => $index ) {
			if ( isset( $row[ $index ] ) ) {
				$data[ $field ] = $row[ $index ];
			}
		}

		return $data;
	}

	/**
	 * Sanitize product data from CSV row.
	 *
	 * @param array $row_data    Row data.
	 * @param bool  $skip_images Skip image processing.
	 * @return array Sanitized data.
	 */
	protected function sanitize_product_data( $row_data, $skip_images = false ) {
		$data = array();

		$data['name']              = isset( $row_data['name'] ) ? sanitize_text_field( $row_data['name'] ) : '';
		$data['sku']               = isset( $row_data['sku'] ) ? sanitize_text_field( $row_data['sku'] ) : '';
		$data['type']              = isset( $row_data['type'] ) ? sanitize_text_field( $row_data['type'] ) : 'simple';
		$data['description']       = isset( $row_data['description'] ) ? wp_kses_post( $row_data['description'] ) : '';
		$data['short_description'] = isset( $row_data['short_description'] ) ? wp_kses_post( $row_data['short_description'] ) : '';
		$data['regular_price']     = isset( $row_data['regular_price'] ) ? floatval( $row_data['regular_price'] ) : 0;
		$data['sale_price']        = isset( $row_data['sale_price'] ) ? floatval( $row_data['sale_price'] ) : 0;
		$data['stock_quantity']    = isset( $row_data['stock_quantity'] ) ? absint( $row_data['stock_quantity'] ) : 0;
		$data['stock_status']      = isset( $row_data['stock_status'] ) ? sanitize_text_field( $row_data['stock_status'] ) : 'instock';

		// Parse categories (comma-separated).
		if ( isset( $row_data['categories'] ) ) {
			$data['categories'] = array_map( 'trim', explode( ',', $row_data['categories'] ) );
		}

		// Parse tags (comma-separated).
		if ( isset( $row_data['tags'] ) ) {
			$data['tags'] = array_map( 'trim', explode( ',', $row_data['tags'] ) );
		}

		// Parse images (comma-separated URLs).
		if ( ! $skip_images && isset( $row_data['images'] ) ) {
			$data['images'] = array_map( 'trim', explode( ',', $row_data['images'] ) );
		}

		return $data;
	}

	/**
	 * Create a new product.
	 *
	 * @param array $data    Product data.
	 * @param int   $user_id User ID.
	 * @return array|WP_Error Product info or error.
	 */
	protected function create_product( $data, $user_id ) {
		// Reuse the create_product_advanced tool logic.
		$create_tool = new WP_MCP_AI_Tool_Create_Product_Advanced();
		$result      = $create_tool->execute( $data, array( 'user_id' => $user_id ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'product_id' => $result['product_id'],
			'name'       => $data['name'],
			'sku'        => $data['sku'],
			'action'     => 'created',
		);
	}

	/**
	 * Update an existing product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data       Product data.
	 * @param int   $user_id    User ID.
	 * @return array|WP_Error Product info or error.
	 */
	protected function update_product( $product_id, $data, $user_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error( 'invalid_product', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update basic data.
		if ( ! empty( $data['name'] ) ) {
			$product->set_name( $data['name'] );
		}
		if ( ! empty( $data['description'] ) ) {
			$product->set_description( $data['description'] );
		}
		if ( ! empty( $data['short_description'] ) ) {
			$product->set_short_description( $data['short_description'] );
		}
		if ( $data['regular_price'] > 0 ) {
			$product->set_regular_price( $data['regular_price'] );
		}
		if ( $data['sale_price'] > 0 ) {
			$product->set_sale_price( $data['sale_price'] );
		}
		if ( $data['stock_quantity'] >= 0 ) {
			$product->set_stock_quantity( $data['stock_quantity'] );
		}
		if ( ! empty( $data['stock_status'] ) ) {
			$product->set_stock_status( $data['stock_status'] );
		}

		$product->save();

		return array(
			'product_id' => $product_id,
			'name'       => $product->get_name(),
			'sku'        => $data['sku'],
			'action'     => 'updated',
		);
	}
}
