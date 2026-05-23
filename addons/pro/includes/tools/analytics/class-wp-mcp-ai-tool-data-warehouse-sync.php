<?php
/**
 * Data Warehouse Sync Tool
 *
 * Synchronize analytics data to external data warehouses.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for syncing data to external warehouses.
 *
 * Supports:
 * - BigQuery sync
 * - Snowflake sync
 * - Redshift sync
 * - Generic webhook destinations
 * - Batch and incremental sync
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Data_Warehouse_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if analytics toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_analytics_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_analytics_toolkit'] ) ) {
			return __( 'Advanced Analytics toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Data warehouse sync tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'data_warehouse_sync';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Sync to Data Warehouse', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Synchronize analytics data to external data warehouses like BigQuery, Snowflake, or Redshift. Supports batch and incremental sync.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'destination' => array(
					'type'        => 'string',
					'description' => 'Data warehouse destination',
					'enum'        => array( 'bigquery', 'snowflake', 'redshift', 'webhook' ),
				),
				'data_type'   => array(
					'type'        => 'string',
					'description' => 'Type of data to sync',
					'enum'        => array( 'orders', 'customers', 'products', 'custom_metrics', 'all' ),
				),
				'sync_mode'   => array(
					'type'        => 'string',
					'description' => 'Sync mode: full or incremental',
					'enum'        => array( 'full', 'incremental' ),
					'default'     => 'incremental',
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => 'Start date for incremental sync (YYYY-MM-DD)',
					'format'      => 'date',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => 'End date for incremental sync (YYYY-MM-DD)',
					'format'      => 'date',
				),
				'credentials' => array(
					'type'        => 'object',
					'description' => 'Warehouse credentials (stored securely)',
				),
			),
			'required'   => array( 'destination', 'data_type' ),
		);
	}

	/**
	 * Get required capability.
	 *
	 * @since 1.1.0
	 *
	 * @return string Required capability.
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array Capability flags.
	 */
	public function get_capability_flags() {
		return array(
			'analytics'    => true,
			'external_api' => true,
			'long_running' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$destination = ! empty( $arguments['destination'] ) ? sanitize_text_field( $arguments['destination'] ) : '';
		$data_type   = ! empty( $arguments['data_type'] ) ? sanitize_text_field( $arguments['data_type'] ) : '';
		$sync_mode   = ! empty( $arguments['sync_mode'] ) ? sanitize_text_field( $arguments['sync_mode'] ) : 'incremental';
		$start_date  = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date    = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : gmdate( 'Y-m-d' );

		if ( empty( $destination ) || empty( $data_type ) ) {
			return new WP_Error(
				'missing_parameters',
				__( 'Destination and data_type are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get data to sync.
		$data = $this->get_data_for_sync( $data_type, $start_date, $end_date, $sync_mode );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Perform sync based on destination.
		$sync_result = $this->sync_to_destination( $destination, $data, $arguments );

		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}

		return array(
			'success'        => true,
			'destination'    => $destination,
			'data_type'      => $data_type,
			'sync_mode'      => $sync_mode,
			'records_synced' => count( $data ),
			'started_at'     => $start_date,
			'ended_at'       => $end_date,
			'message'        => sprintf(
				/* translators: 1: records count, 2: destination */
				__( 'Successfully synced %1$d records to %2$s.', 'mcp-ai-wpoos-pro' ),
				count( $data ),
				$destination
			),
		);
	}

	/**
	 * Get data for sync.
	 *
	 * @since 1.1.0
	 *
	 * @param string $data_type  Data type.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param string $sync_mode  Sync mode.
	 * @return array|WP_Error Data array or error.
	 */
	private function get_data_for_sync( $data_type, $start_date, $end_date, $sync_mode ) {
		global $wpdb;

		$data = array();

		switch ( $data_type ) {
			case 'orders':
				if ( class_exists( 'WooCommerce' ) ) {
					$data = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->posts} 
							WHERE post_type = 'shop_order' 
							AND post_date >= %s AND post_date <= %s",
							$start_date,
							$end_date
						),
						ARRAY_A
					);
				}
				break;

			case 'custom_metrics':
				$table_name = $wpdb->prefix . 'mcp_ai_custom_metrics';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name ) {
					$data = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$table_name} // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table name
							WHERE recorded_at >= %s AND recorded_at <= %s",
							$start_date,
							$end_date
						),
						ARRAY_A
					);
				}
				break;

			default:
				return new WP_Error(
					'unsupported_data_type',
					__( 'Unsupported data type for sync.', 'mcp-ai-wpoos-pro' )
				);
		}

		return $data;
	}

	/**
	 * Sync data to destination.
	 *
	 * @since 1.1.0
	 *
	 * @param string $destination Destination type.
	 * @param array  $data        Data to sync.
	 * @param array  $arguments   Full arguments.
	 * @return true|WP_Error True on success, error otherwise.
	 */
	private function sync_to_destination( $destination, $data, $arguments ) {
		// Apply filter for custom destinations.
		$result = apply_filters( "wp_mcp_ai_sync_to_{$destination}", null, $data, $arguments );

		if ( null !== $result ) {
			return $result;
		}

		// Default webhook implementation.
		if ( 'webhook' === $destination && ! empty( $arguments['credentials']['webhook_url'] ) ) {
			$response = wp_remote_post(
				$arguments['credentials']['webhook_url'],
				array(
					'body'    => wp_json_encode( $data ),
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return true;
		}

		return new WP_Error(
			'destination_not_configured',
			__( 'Destination is not configured. Please use the filter hook or provide webhook URL.', 'mcp-ai-wpoos-pro' )
		);
	}
}
