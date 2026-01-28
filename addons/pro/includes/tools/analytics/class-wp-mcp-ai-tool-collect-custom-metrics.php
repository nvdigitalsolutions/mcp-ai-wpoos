<?php
/**
 * Collect Custom Metrics Tool
 *
 * Track custom business metrics and KPIs with flexible event tracking.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for collecting and storing custom business metrics.
 *
 * Supports:
 * - Custom event tracking
 * - KPI measurement
 * - Time-series data collection
 * - Metadata attachment
 * - Aggregation support
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Collect_Custom_Metrics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if analytics toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if analytics toolkit is enabled.
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
			return __( 'Advanced Analytics toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Custom metrics collection tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'collect_custom_metrics';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Collect Custom Metrics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Track custom business metrics and KPIs. Record events with values, metadata, and timestamps for analysis and reporting.', 'mcp-ai-wpoos-pro' );
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
				'metric_name'  => array(
					'type'        => 'string',
					'description' => 'Name of the metric to track (e.g., user_signup, form_submission)',
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'metric_value' => array(
					'type'        => 'number',
					'description' => 'Numeric value of the metric',
				),
				'unit'         => array(
					'type'        => 'string',
					'description' => 'Unit of measurement (e.g., count, dollars, seconds)',
					'maxLength'   => 50,
				),
				'metadata'     => array(
					'type'        => 'object',
					'description' => 'Additional metadata as key-value pairs',
				),
				'timestamp'    => array(
					'type'        => 'string',
					'description' => 'ISO 8601 timestamp (defaults to current time)',
					'format'      => 'date-time',
				),
				'user_id'      => array(
					'type'        => 'integer',
					'description' => 'User ID associated with this metric',
					'minimum'     => 0,
				),
			),
			'required'   => array( 'metric_name', 'metric_value' ),
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
		return 'edit_posts';
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
			'analytics'       => true,
			'data_collection' => true,
			'write'           => true,
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
	public function execute( $arguments, $context ) {
		global $wpdb;

		// Parse arguments.
		$metric_name  = ! empty( $arguments['metric_name'] ) ? sanitize_text_field( $arguments['metric_name'] ) : '';
		$metric_value = isset( $arguments['metric_value'] ) ? floatval( $arguments['metric_value'] ) : 0;
		$unit         = ! empty( $arguments['unit'] ) ? sanitize_text_field( $arguments['unit'] ) : 'count';
		$metadata     = ! empty( $arguments['metadata'] ) && is_array( $arguments['metadata'] ) ? $arguments['metadata'] : array();
		$timestamp    = ! empty( $arguments['timestamp'] ) ? sanitize_text_field( $arguments['timestamp'] ) : current_time( 'mysql', true );
		$user_id      = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : get_current_user_id();

		// Validate metric name.
		if ( empty( $metric_name ) ) {
			return new WP_Error(
				'missing_metric_name',
				__( 'Metric name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure custom metrics table exists.
		$table_name = $wpdb->prefix . 'mcp_ai_custom_metrics';
		$this->ensure_table_exists( $table_name );

		// Insert metric.
		$result = $wpdb->insert(
			$table_name,
			array(
				'metric_name'  => $metric_name,
				'metric_value' => $metric_value,
				'unit'         => $unit,
				'metadata'     => wp_json_encode( $metadata ),
				'user_id'      => $user_id,
				'recorded_at'  => $timestamp,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%f', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'insert_failed',
				__( 'Failed to record custom metric.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $wpdb->last_error )
			);
		}

		$metric_id = $wpdb->insert_id;

		return array(
			'success'      => true,
			'metric_id'    => $metric_id,
			'metric_name'  => $metric_name,
			'metric_value' => $metric_value,
			'unit'         => $unit,
			'user_id'      => $user_id,
			'recorded_at'  => $timestamp,
			'message'      => sprintf(
				/* translators: 1: metric name, 2: metric value */
				__( 'Recorded metric "%1$s" with value %2$s.', 'mcp-ai-wpoos-pro' ),
				$metric_name,
				$metric_value
			),
		);
	}

	/**
	 * Ensure custom metrics table exists.
	 *
	 * @since 1.1.0
	 *
	 * @param string $table_name Table name.
	 * @return void
	 */
	private function ensure_table_exists( $table_name ) {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			metric_name varchar(255) NOT NULL,
			metric_value decimal(20,4) NOT NULL,
			unit varchar(50) DEFAULT 'count',
			metadata longtext,
			user_id bigint(20) unsigned DEFAULT 0,
			recorded_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY metric_name (metric_name),
			KEY user_id (user_id),
			KEY recorded_at (recorded_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
