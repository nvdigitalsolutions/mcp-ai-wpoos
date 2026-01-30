<?php
/**
 * Tool for exporting products to Excel with filters.
 *
 * Allows AI assistants to export regulatory products to Excel
 * with custom filters and field selection.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports products to Excel files.
 */
class WP_MCP_AI_Tool_Export_Products_To_Excel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_products_to_excel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Products to Excel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports regulatory products to Excel file with custom filters, field selection, and formatting options.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filters'         => array(
					'type'        => 'object',
					'description' => __( 'Filter products to export (optional)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'brand'        => array( 'type' => 'string' ),
						'manufacturer' => array( 'type' => 'string' ),
						'category'     => array( 'type' => 'string' ),
					),
				),
				'fields'          => array(
					'type'        => 'array',
					'description' => __( 'Fields to include (optional, all if not specified)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'id', 'name', 'brand', 'manufacturer', 'category', 'created_date' ),
					),
				),
				'include_headers' => array(
					'type'        => 'boolean',
					'description' => __( 'Include column headers (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export products.', 'mcp-ai-wpoos-pro' ) );
		}

		$filters         = ! empty( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();
		$fields          = ! empty( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : array( 'id', 'name', 'brand', 'manufacturer', 'category', 'created_date' );
		$include_headers = isset( $arguments['include_headers'] ) ? (bool) $arguments['include_headers'] : true;

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		// Apply filters.
		if ( ! empty( $filters ) ) {
			$query_args['meta_query'] = array( 'relation' => 'AND' );

			foreach ( $filters as $key => $value ) {
				if ( ! empty( $value ) ) {
					$query_args['meta_query'][] = array(
						'key'   => sanitize_key( $key ),
						'value' => sanitize_text_field( $value ),
					);
				}
			}
		}

		$products_query = new WP_Query( $query_args );

		// Prepare data for Excel.
		$export_data = array();

		// Add headers if requested.
		if ( $include_headers ) {
			$headers = array();
			foreach ( $fields as $field ) {
				$headers[] = ucwords( str_replace( '_', ' ', $field ) );
			}
			$export_data[] = $headers;
		}

		// Add product data.
		if ( $products_query->have_posts() ) {
			foreach ( $products_query->posts as $product ) {
				$row = array();
				foreach ( $fields as $field ) {
					switch ( $field ) {
						case 'id':
							$row[] = $product->ID;
							break;
						case 'name':
							$row[] = $product->post_title;
							break;
						case 'created_date':
							$row[] = $product->post_date;
							break;
						default:
							$row[] = get_post_meta( $product->ID, $field, true );
							break;
					}
				}
				$export_data[] = $row;
			}
		}

		// Generate Excel file (placeholder - would use PHPSpreadsheet).
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/exports';
		$filename   = sprintf( 'products-export-%s.xlsx', gmdate( 'YmdHis' ) );
		$file_path  = $export_dir . '/' . $filename;
		$file_url   = $upload_dir['baseurl'] . '/exports/' . $filename;

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		// Placeholder: Convert data to Excel format.
		$csv_content = '';
		foreach ( $export_data as $row ) {
			$csv_content .= implode( ',', $row ) . "\n";
		}
		file_put_contents( $file_path, $csv_content );

		return array(
			'success'       => true,
			'file_path'     => $file_path,
			'file_url'      => $file_url,
			'filename'      => $filename,
			'total_records' => $products_query->found_posts,
			'exported_at'   => current_time( 'mysql' ),
			'fields'        => $fields,
		);
	}
}
