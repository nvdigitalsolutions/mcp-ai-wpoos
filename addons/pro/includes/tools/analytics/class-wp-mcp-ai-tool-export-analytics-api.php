<?php
/**
 * Export Analytics API Tool
 *
 * Exports analytics data via REST API with various formats
 * and filtering options.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for exporting analytics via API.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Export_Analytics_API implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_analytics_toolkit'] );
	}

	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_analytics_toolkit'] ) ) {
			return __( 'Advanced Analytics toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Export analytics API tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	public function get_slug() {
		return 'export_analytics_api';
	}

	public function get_name() {
		return __( 'Export Analytics API', 'mcp-ai-wpoos-pro' );
	}

	public function get_description() {
		return __( 'Export analytics data via REST API in JSON, CSV, or XML format with filtering and pagination.', 'mcp-ai-wpoos-pro' );
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'data_type'   => array(
					'type'        => 'string',
					'description' => 'Type of analytics: sales, customers, products, traffic',
					'enum'        => array( 'sales', 'customers', 'products', 'traffic' ),
					'default'     => 'sales',
				),
				'format'      => array(
					'type'        => 'string',
					'description' => 'Export format: json, csv, xml',
					'enum'        => array( 'json', 'csv', 'xml' ),
					'default'     => 'json',
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => 'Start date (YYYY-MM-DD)',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => 'End date (YYYY-MM-DD)',
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => 'Maximum records to export',
					'minimum'     => 1,
					'maximum'     => 10000,
					'default'     => 1000,
				),
			),
			'required'   => array( 'data_type' ),
		);
	}

	public function get_required_capability() {
		return 'manage_options';
	}

	public function get_capability_flags() {
		return array(
			'analytics'  => true,
			'export'     => true,
			'read_only'  => true,
		);
	}

	public function execute( $arguments, $context ) {
		$data_type  = sanitize_text_field( $arguments['data_type'] );
		$format     = ! empty( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'json';
		$start_date = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : date( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date   = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : current_time( 'Y-m-d' );
		$limit      = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 1000;

		// Collect data based on type.
		$data = $this->collect_analytics_data( $data_type, $start_date, $end_date, $limit );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Format data.
		$formatted_data = $this->format_data( $data, $format );

		// Generate API endpoint URL.
		$api_url = rest_url( 'mcp-ai-pro/v1/analytics/export' );

		return array(
			'success'     => true,
			'data'        => $formatted_data,
			'metadata'    => array(
				'data_type'   => $data_type,
				'format'      => $format,
				'start_date'  => $start_date,
				'end_date'    => $end_date,
				'record_count' => is_array( $data ) ? count( $data ) : 0,
				'api_url'     => $api_url,
			),
			'exported_at' => current_time( 'mysql' ),
			'message'     => __( 'Analytics data exported successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	private function collect_analytics_data( $data_type, $start_date, $end_date, $limit ) {
		switch ( $data_type ) {
			case 'sales':
				return $this->get_sales_data( $start_date, $end_date, $limit );
			case 'customers':
				return $this->get_customers_data( $start_date, $end_date, $limit );
			case 'products':
				return $this->get_products_data( $start_date, $end_date, $limit );
			case 'traffic':
				return $this->get_traffic_data( $start_date, $end_date, $limit );
			default:
				return new WP_Error( 'invalid_data_type', __( 'Invalid data type.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	private function get_sales_data( $start_date, $end_date, $limit ) {
		global $wpdb;

		$query = "
			SELECT 
				p.ID as order_id,
				p.post_date as order_date,
				pm_total.meta_value as total,
				pm_status.meta_value as status
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
			LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_order_status'
			WHERE p.post_type = 'shop_order'
				AND p.post_date BETWEEN %s AND %s
			ORDER BY p.post_date DESC
			LIMIT %d
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date, $limit ), ARRAY_A );
	}

	private function get_customers_data( $start_date, $end_date, $limit ) {
		global $wpdb;

		$query = "
			SELECT DISTINCT
				u.ID as customer_id,
				u.user_email,
				u.display_name,
				u.user_registered,
				COUNT(DISTINCT p.ID) as order_count,
				SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as total_spent
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->posts} p ON u.ID = p.post_author
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND pm.meta_key = '_order_total'
				AND p.post_date BETWEEN %s AND %s
			GROUP BY u.ID
			ORDER BY total_spent DESC
			LIMIT %d
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date, $limit ), ARRAY_A );
	}

	private function get_products_data( $start_date, $end_date, $limit ) {
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array(
				array(
					'after'     => $start_date,
					'before'    => $end_date,
					'inclusive' => true,
				),
			),
		);

		$products = get_posts( $args );
		$data     = array();

		foreach ( $products as $product ) {
			$product_obj = wc_get_product( $product->ID );
			if ( ! $product_obj ) {
				continue;
			}

			$data[] = array(
				'product_id'    => $product->ID,
				'name'          => $product->post_title,
				'sku'           => $product_obj->get_sku(),
				'price'         => $product_obj->get_price(),
				'stock_status'  => $product_obj->get_stock_status(),
				'stock_quantity' => $product_obj->get_stock_quantity(),
			);
		}

		return $data;
	}

	private function get_traffic_data( $start_date, $end_date, $limit ) {
		// Placeholder for traffic data - would integrate with analytics plugin.
		return array(
			array(
				'date'       => $start_date,
				'pageviews'  => 0,
				'visits'     => 0,
				'bounce_rate' => 0,
				'note'       => 'Traffic data requires Google Analytics or similar integration',
			),
		);
	}

	private function format_data( $data, $format ) {
		switch ( $format ) {
			case 'csv':
				return $this->convert_to_csv( $data );
			case 'xml':
				return $this->convert_to_xml( $data );
			case 'json':
			default:
				return $data;
		}
	}

	private function convert_to_csv( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		$output = fopen( 'php://temp', 'r+' );
		
		// Headers.
		fputcsv( $output, array_keys( $data[0] ) );

		// Rows.
		foreach ( $data as $row ) {
			fputcsv( $output, $row );
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		return $csv;
	}

	private function convert_to_xml( $data ) {
		$xml = new SimpleXMLElement( '<analytics/>' );

		foreach ( $data as $item ) {
			$record = $xml->addChild( 'record' );
			foreach ( $item as $key => $value ) {
				$record->addChild( $key, htmlspecialchars( $value ) );
			}
		}

		return $xml->asXML();
	}
}
