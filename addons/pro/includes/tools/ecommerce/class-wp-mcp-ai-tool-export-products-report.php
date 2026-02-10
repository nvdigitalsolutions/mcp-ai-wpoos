<?php
/**
 * Export Products Report Tool
 *
 * Exports WooCommerce product catalog to Excel/CSV with analytics data
 * including sales performance, stock status, and pricing information.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for exporting WooCommerce product reports.
 *
 * Supports:
 * - Excel and CSV export formats
 * - Comprehensive product data
 * - Sales analytics
 * - Stock and inventory information
 * - Custom field selection
 * - Filtered exports
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Export_Products_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Product export requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Product export tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'export_products_report';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Export Products Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Export WooCommerce product catalog to Excel or CSV with comprehensive analytics including sales data, stock levels, pricing, and product attributes. Supports filtered exports and custom field selection.', 'mcp-ai-wpoos-pro' );
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
				'format'            => array(
					'type'        => 'string',
					'description' => __( 'Export format', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'excel', 'csv' ),
					'default'     => 'excel',
				),
				'fields'            => array(
					'type'        => 'array',
					'description' => __( 'Fields to include in export (default: all standard fields)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'id', 'sku', 'name', 'type', 'status', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'categories', 'tags', 'sales_count', 'total_sales', 'average_rating', 'review_count', 'date_created', 'date_modified' ),
					),
				),
				'include_analytics' => array(
					'type'        => 'boolean',
					'description' => __( 'Include sales analytics data', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'filter'            => array(
					'type'        => 'object',
					'description' => __( 'Filter criteria to select products', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'category'     => array(
							'type'        => 'string',
							'description' => 'Filter by category slug',
						),
						'status'       => array(
							'type'        => 'string',
							'description' => 'Filter by status: publish, draft, private',
						),
						'stock_status' => array(
							'type'        => 'string',
							'description' => 'Filter by stock status: instock, outofstock, onbackorder',
						),
						'limit'        => array(
							'type'        => 'integer',
							'description' => 'Maximum number of products to export',
							'default'     => 1000,
						),
						'offset'       => array(
							'type'        => 'integer',
							'description' => 'Offset for pagination',
							'default'     => 0,
						),
					),
				),
				'upload'            => array(
					'type'        => 'boolean',
					'description' => __( 'Upload file to WordPress media library', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'filename'          => array(
					'type'        => 'string',
					'description' => __( 'Custom filename (without extension)', 'mcp-ai-wpoos-pro' ),
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
			'database-read',
			'requires-plugin',
			'file-write',
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
				__( 'You do not have permission to export product reports.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Get parameters.
		$format            = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'excel';
		$fields            = isset( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : $this->get_default_fields();
		$include_analytics = isset( $arguments['include_analytics'] ) ? (bool) $arguments['include_analytics'] : true;
		$upload            = isset( $arguments['upload'] ) ? (bool) $arguments['upload'] : true;
		$filename          = isset( $arguments['filename'] ) ? sanitize_file_name( $arguments['filename'] ) : 'products-export-' . gmdate( 'Y-m-d-His' );

		// Get products.
		$products = $this->get_products( $arguments );

		if ( is_wp_error( $products ) ) {
			return $products;
		}

		if ( empty( $products ) ) {
			return new WP_Error(
				'no_products_found',
				__( 'No products found matching the criteria.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare export data.
		$export_data = $this->prepare_export_data( $products, $fields, $include_analytics );

		// Generate file.
		$file_path = $this->generate_export_file( $export_data, $format, $filename );

		if ( is_wp_error( $file_path ) ) {
			return $file_path;
		}

		// Upload to media library if requested.
		$attachment_id = 0;
		$file_url      = '';

		if ( $upload ) {
			$upload_result = $this->upload_to_media_library( $file_path, $filename, $format );

			if ( is_wp_error( $upload_result ) ) {
				// Clean up temp file.
				wp_delete_file( $file_path );
				return $upload_result;
			}

			$attachment_id = $upload_result['attachment_id'];
			$file_url      = $upload_result['url'];

			// Clean up temp file after upload.
			wp_delete_file( $file_path );
		} else {
			$file_url = $file_path;
		}

		return array(
			'success'        => true,
			'file_path'      => $upload ? '' : $file_path,
			'file_url'       => $file_url,
			'attachment_id'  => $attachment_id,
			'format'         => $format,
			'total_products' => count( $products ),
			'filename'       => basename( $file_url ),
			'message'        => sprintf(
				/* translators: 1: Number of products, 2: Format */
				__( 'Exported %1$d products to %2$s format successfully.', 'mcp-ai-wpoos-pro' ),
				count( $products ),
				strtoupper( $format )
			),
		);
	}

	/**
	 * Get default export fields.
	 *
	 * @return array Default fields.
	 */
	protected function get_default_fields() {
		return array( 'id', 'sku', 'name', 'type', 'status', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'categories', 'tags' );
	}

	/**
	 * Get products based on filter criteria.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Array of product IDs or error.
	 */
	protected function get_products( $arguments ) {
		$filter = isset( $arguments['filter'] ) && is_array( $arguments['filter'] ) ? $arguments['filter'] : array();

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => isset( $filter['limit'] ) ? absint( $filter['limit'] ) : 1000,
			'offset'         => isset( $filter['offset'] ) ? absint( $filter['offset'] ) : 0,
			'post_status'    => isset( $filter['status'] ) ? sanitize_text_field( $filter['status'] ) : 'publish',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		// Add category filter.
		if ( ! empty( $filter['category'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $filter['category'] ),
				),
			);
		}

		// Add stock status filter.
		if ( ! empty( $filter['stock_status'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_stock_status',
					'value' => sanitize_text_field( $filter['stock_status'] ),
				),
			);
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Prepare export data from products.
	 *
	 * @param array $products          Product posts.
	 * @param array $fields            Fields to include.
	 * @param bool  $include_analytics Include analytics.
	 * @return array Export data with headers and rows.
	 */
	protected function prepare_export_data( $products, $fields, $include_analytics ) {
		// Prepare headers.
		$headers = array();
		foreach ( $fields as $field ) {
			$headers[] = $this->get_field_label( $field );
		}

		if ( $include_analytics ) {
			$headers = array_merge( $headers, array( 'Total Sales', 'Sales Count', 'Average Rating', 'Review Count' ) );
		}

		// Prepare rows.
		$rows = array();

		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );

			if ( ! $product ) {
				continue;
			}

			$row = array();

			foreach ( $fields as $field ) {
				$row[] = $this->get_field_value( $product, $field );
			}

			if ( $include_analytics ) {
				$row[] = wc_format_decimal( $product->get_total_sales(), 2 );
				$row[] = absint( $product->get_total_sales() );
				$row[] = wc_format_decimal( $product->get_average_rating(), 2 );
				$row[] = absint( $product->get_review_count() );
			}

			$rows[] = $row;
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Get field label.
	 *
	 * @param string $field Field name.
	 * @return string Field label.
	 */
	protected function get_field_label( $field ) {
		$labels = array(
			'id'             => 'ID',
			'sku'            => 'SKU',
			'name'           => 'Name',
			'type'           => 'Type',
			'status'         => 'Status',
			'regular_price'  => 'Regular Price',
			'sale_price'     => 'Sale Price',
			'stock_quantity' => 'Stock Quantity',
			'stock_status'   => 'Stock Status',
			'categories'     => 'Categories',
			'tags'           => 'Tags',
			'date_created'   => 'Date Created',
			'date_modified'  => 'Date Modified',
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : ucwords( str_replace( '_', ' ', $field ) );
	}

	/**
	 * Get field value from product.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $field   Field name.
	 * @return string Field value.
	 */
	protected function get_field_value( $product, $field ) {
		switch ( $field ) {
			case 'id':
				return $product->get_id();
			case 'sku':
				return $product->get_sku();
			case 'name':
				return $product->get_name();
			case 'type':
				return $product->get_type();
			case 'status':
				return $product->get_status();
			case 'regular_price':
				return $product->get_regular_price();
			case 'sale_price':
				return $product->get_sale_price();
			case 'stock_quantity':
				return $product->get_stock_quantity();
			case 'stock_status':
				return $product->get_stock_status();
			case 'categories':
				return implode( ', ', wp_list_pluck( wc_get_product_terms( $product->get_id(), 'product_cat' ), 'name' ) );
			case 'tags':
				return implode( ', ', wp_list_pluck( wc_get_product_terms( $product->get_id(), 'product_tag' ), 'name' ) );
			case 'date_created':
				return $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : '';
			case 'date_modified':
				return $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : '';
			default:
				return '';
		}
	}

	/**
	 * Generate export file.
	 *
	 * @param array  $data     Export data.
	 * @param string $format   Format (excel or csv).
	 * @param string $filename Filename without extension.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_export_file( $data, $format, $filename ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/wp-mcp-ai-temp';

		// Create temp directory if it doesn't exist.
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		if ( 'csv' === $format ) {
			return $this->generate_csv_file( $data, $temp_dir, $filename );
		} else {
			return $this->generate_excel_file( $data, $temp_dir, $filename );
		}
	}

	/**
	 * Generate CSV file.
	 *
	 * @param array  $data     Export data.
	 * @param string $temp_dir Temp directory.
	 * @param string $filename Filename.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_csv_file( $data, $temp_dir, $filename ) {
		$file_path = $temp_dir . '/' . $filename . '.csv';

		$fp = fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fp ) {
			return new WP_Error( 'file_creation_failed', __( 'Failed to create CSV file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Write headers.
		fputcsv( $fp, $data['headers'] );

		// Write rows.
		foreach ( $data['rows'] as $row ) {
			fputcsv( $fp, $row );
		}

		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $file_path;
	}

	/**
	 * Generate Excel file using Node.js microservice.
	 *
	 * @param array  $data     Export data.
	 * @param string $temp_dir Temp directory.
	 * @param string $filename Filename.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_excel_file( $data, $temp_dir, $filename ) {
		$file_path = $temp_dir . '/' . $filename . '.xlsx';

		// Prepare data for Excel generation.
		$sheet_data = array(
			'name'    => 'Products',
			'columns' => array_map(
				function ( $header ) {
					return array(
						'header' => $header,
						'key'    => sanitize_title( $header ),
						'width'  => 20,
					);
				},
				$data['headers']
			),
			'data'    => array(),
		);

		// Convert rows to associative arrays.
		foreach ( $data['rows'] as $row ) {
			$row_data = array();
			foreach ( $data['headers'] as $index => $header ) {
				$key              = sanitize_title( $header );
				$row_data[ $key ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
			}
			$sheet_data['data'][] = $row_data;
		}

		// Use Node.js script to generate Excel file.
		$script_path = WP_MCP_AI_PRO_PATH . 'addons/pro/scripts/generate-excel.js';
		$input_data  = wp_json_encode(
			array(
				'sheets'     => array( $sheet_data ),
				'outputFile' => $file_path,
				'creator'    => 'WP MCP AI Pro',
			)
		);

		// Execute Node.js script.
		$node_path = 'node'; // Assume node is in PATH.
		$command   = sprintf(
			'%s %s %s 2>&1',
			escapeshellcmd( $node_path ),
			escapeshellarg( $script_path ),
			escapeshellarg( $input_data )
		);

		$output     = array();
		$return_var = 0;
		exec( $command, $output, $return_var ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 !== $return_var || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'excel_generation_failed',
				sprintf(
					/* translators: %s: error output */
					__( 'Failed to generate Excel file: %s', 'mcp-ai-wpoos-pro' ),
					implode( "\n", $output )
				)
			);
		}

		return $file_path;
	}

	/**
	 * Upload file to WordPress media library.
	 *
	 * @param string $file_path File path.
	 * @param string $filename  Filename.
	 * @param string $format    Format.
	 * @return array|WP_Error Upload result or error.
	 */
	protected function upload_to_media_library( $file_path, $filename, $format ) {
		$extension = 'csv' === $format ? 'csv' : 'xlsx';
		$mime_type = 'csv' === $format ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

		$file = array(
			'name'     => $filename . '.' . $extension,
			'type'     => $mime_type,
			'tmp_name' => $file_path,
			'error'    => 0,
			'size'     => filesize( $file_path ),
		);

		$attachment_id = media_handle_sideload( $file, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
		);
	}
}
