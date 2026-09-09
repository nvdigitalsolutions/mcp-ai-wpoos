<?php
/**
 * WP_MCP_AI_Tool_Export_Products_To_Excel (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Export Products to Excel', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports regulatory products to Excel file with custom filters, field selection, and formatting options.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Filter products to export (optional)', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'brand'        => array( 'type' => 'string' ),
						'manufacturer' => array( 'type' => 'string' ),
						'category'     => array( 'type' => 'string' ),
					),
				),
				'fields'          => array(
					'type'        => 'array',
					'description' => __( 'Fields to include (optional, all if not specified)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'id', 'name', 'brand', 'manufacturer', 'category', 'created_date' ),
					),
				),
				'include_headers' => array(
					'type'        => 'boolean',
					'description' => __( 'Include column headers (optional, default: true)', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export products.', 'nvoos-content-graph-pro' ) );
		}

		$filters         = ! empty( $arguments['filters'] ) && is_array( $arguments['filters'] ) ? $arguments['filters'] : array();
		$fields          = ! empty( $arguments['fields'] ) && is_array( $arguments['fields'] ) ? $arguments['fields'] : array( 'id', 'name', 'brand', 'manufacturer', 'category', 'created_date' );
		$include_headers = isset( $arguments['include_headers'] ) ? (bool) $arguments['include_headers'] : true;

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_product',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_products_to_excel', 0, 1000 ) : 1000,
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
