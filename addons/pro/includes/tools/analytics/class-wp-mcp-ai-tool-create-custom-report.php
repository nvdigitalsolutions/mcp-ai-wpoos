<?php
/**
 * Create Custom Report Tool
 *
 * Builds custom analytics reports with templates and scheduling.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for creating custom analytics reports.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Create_Custom_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Custom report tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'create_custom_report';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Create Custom Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Build custom analytics reports with templates. Supports scheduled delivery via email with charts and visualizations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_name'    => array(
					'type'        => 'string',
					'description' => 'Name for the custom report',
				),
				'template'       => array(
					'type'        => 'string',
					'description' => 'Report template: executive, sales, marketing, operations',
					'enum'        => array( 'executive', 'sales', 'marketing', 'operations', 'custom' ),
					'default'     => 'executive',
				),
				'metrics'        => array(
					'type'        => 'array',
					'description' => 'Metrics to include',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'revenue', 'orders', 'customers', 'conversion_rate', 'avg_order_value', 'traffic' ),
					),
				),
				'period'         => array(
					'type'        => 'string',
					'description' => 'Reporting period: daily, weekly, monthly, quarterly',
					'enum'        => array( 'daily', 'weekly', 'monthly', 'quarterly' ),
					'default'     => 'monthly',
				),
				'include_charts' => array(
					'type'        => 'boolean',
					'description' => 'Include charts and visualizations',
					'default'     => true,
				),
				'schedule'       => array(
					'type'        => 'string',
					'description' => 'Delivery schedule: none, daily, weekly, monthly',
					'enum'        => array( 'none', 'daily', 'weekly', 'monthly' ),
					'default'     => 'none',
				),
				'recipients'     => array(
					'type'        => 'array',
					'description' => 'Email addresses for scheduled delivery',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'report_name' ),
		);
	}

	/**
	 * Get the required capability.
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
			'analytics' => true,
			'reporting' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Report data or error.
	 */
	public function execute( $arguments, $context ) {
		$report_name    = sanitize_text_field( $arguments['report_name'] );
		$template       = ! empty( $arguments['template'] ) ? sanitize_text_field( $arguments['template'] ) : 'executive';
		$metrics        = ! empty( $arguments['metrics'] ) ? array_map( 'sanitize_text_field', $arguments['metrics'] ) : array( 'revenue', 'orders' );
		$period         = ! empty( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : 'monthly';
		$include_charts = ! isset( $arguments['include_charts'] ) || $arguments['include_charts'];
		$schedule       = ! empty( $arguments['schedule'] ) ? sanitize_text_field( $arguments['schedule'] ) : 'none';
		$recipients     = ! empty( $arguments['recipients'] ) ? array_map( 'sanitize_email', $arguments['recipients'] ) : array();

		// Validate report name.
		if ( empty( $report_name ) ) {
			return new WP_Error( 'missing_report_name', __( 'Report name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get template configuration.
		$report_config = $this->get_template_config( $template, $metrics );

		// Collect report data.
		$report_data = $this->collect_report_data( $report_config, $period );

		if ( is_wp_error( $report_data ) ) {
			return $report_data;
		}

		// Generate report ID.
		$report_id = 'report_' . wp_generate_password( 12, false );

		// Save report configuration.
		$report = array(
			'id'             => $report_id,
			'name'           => $report_name,
			'template'       => $template,
			'metrics'        => $metrics,
			'period'         => $period,
			'include_charts' => $include_charts,
			'schedule'       => $schedule,
			'recipients'     => $recipients,
			'created_at'     => current_time( 'mysql' ),
			'data'           => $report_data,
		);

		// Save to database.
		$this->save_report( $report_id, $report );

		// Schedule if requested.
		if ( 'none' !== $schedule && ! empty( $recipients ) ) {
			$this->schedule_report( $report_id, $schedule );
		}

		return array(
			'success'    => true,
			'report_id'  => $report_id,
			'report'     => array(
				'name'     => $report_name,
				'template' => $template,
				'metrics'  => $metrics,
				'period'   => $period,
				'data'     => $report_data,
			),
			'scheduled'  => 'none' !== $schedule,
			'created_at' => current_time( 'mysql' ),
			'message'    => __( 'Custom report created successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get template configuration with metrics and charts.
	 *
	 * @since 1.1.0
	 *
	 * @param string $template       Template name.
	 * @param array  $custom_metrics Custom metrics array.
	 * @return array Template configuration.
	 */
	private function get_template_config( $template, $custom_metrics = array() ) {
		$templates = array(
			'executive'  => array(
				'metrics' => array( 'revenue', 'orders', 'customers', 'conversion_rate' ),
				'charts'  => array( 'revenue_trend', 'top_products' ),
			),
			'sales'      => array(
				'metrics' => array( 'revenue', 'orders', 'avg_order_value', 'top_products' ),
				'charts'  => array( 'sales_by_day', 'product_performance' ),
			),
			'marketing'  => array(
				'metrics' => array( 'traffic', 'conversion_rate', 'customers', 'campaigns' ),
				'charts'  => array( 'traffic_sources', 'conversion_funnel' ),
			),
			'operations' => array(
				'metrics' => array( 'orders', 'fulfillment_time', 'inventory', 'returns' ),
				'charts'  => array( 'order_status', 'inventory_levels' ),
			),
			'custom'     => array(
				'metrics' => $custom_metrics,
				'charts'  => array(),
			),
		);

		return isset( $templates[ $template ] ) ? $templates[ $template ] : $templates['executive'];
	}

	/**
	 * Collect report data based on configuration.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $config Report configuration.
	 * @param string $period Reporting period.
	 * @return array Report data.
	 */
	private function collect_report_data( $config, $period ) {
		$data = array();

		// Date range based on period.
		$date_ranges = $this->get_date_ranges( $period );

		foreach ( $config['metrics'] as $metric ) {
			$data[ $metric ] = $this->get_metric_data( $metric, $date_ranges );
		}

		return $data;
	}

	/**
	 * Get date ranges based on period.
	 *
	 * @since 1.1.0
	 *
	 * @param string $period Period type (daily, weekly, monthly, quarterly).
	 * @return array Start and end dates.
	 */
	private function get_date_ranges( $period ) {
		$end_date = current_time( 'Y-m-d' );

		switch ( $period ) {
			case 'daily':
				$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
				break;
			case 'weekly':
				$start_date = gmdate( 'Y-m-d', strtotime( '-12 weeks' ) );
				break;
			case 'quarterly':
				$start_date = gmdate( 'Y-m-d', strtotime( '-1 year' ) );
				break;
			case 'monthly':
			default:
				$start_date = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
				break;
		}

		return array(
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);
	}

	/**
	 * Get data for specific metric.
	 *
	 * @since 1.1.0
	 *
	 * @param string $metric      Metric name.
	 * @param array  $date_ranges Date ranges array.
	 * @return array Metric data.
	 */
	private function get_metric_data( $metric, $date_ranges ) {
		global $wpdb;

		switch ( $metric ) {
			case 'revenue':
				$query = $wpdb->prepare(
					"SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as value
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'shop_order'
						AND p.post_status IN ('wc-completed', 'wc-processing')
						AND pm.meta_key = '_order_total'
						AND p.post_date BETWEEN %s AND %s",
					$date_ranges['start_date'],
					$date_ranges['end_date']
				);
				break;

			case 'orders':
				$query = $wpdb->prepare(
					"SELECT COUNT(*) as value
					FROM {$wpdb->posts}
					WHERE post_type = 'shop_order'
						AND post_status IN ('wc-completed', 'wc-processing')
						AND post_date BETWEEN %s AND %s",
					$date_ranges['start_date'],
					$date_ranges['end_date']
				);
				break;

			case 'customers':
				$query = $wpdb->prepare(
					"SELECT COUNT(DISTINCT post_author) as value
					FROM {$wpdb->posts}
					WHERE post_type = 'shop_order'
						AND post_status IN ('wc-completed', 'wc-processing')
						AND post_date BETWEEN %s AND %s
						AND post_author > 0",
					$date_ranges['start_date'],
					$date_ranges['end_date']
				);
				break;

			case 'avg_order_value':
				$query = $wpdb->prepare(
					"SELECT AVG(CAST(pm.meta_value AS DECIMAL(10,2))) as value
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'shop_order'
						AND p.post_status IN ('wc-completed', 'wc-processing')
						AND pm.meta_key = '_order_total'
						AND p.post_date BETWEEN %s AND %s",
					$date_ranges['start_date'],
					$date_ranges['end_date']
				);
				break;

			default:
				return array(
					'value' => 0,
					'note'  => 'Metric not available',
				);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_var( $query );
		return array( 'value' => $result ? floatval( $result ) : 0 );
	}

	/**
	 * Save report to database.
	 *
	 * @since 1.1.0
	 *
	 * @param string $report_id Report ID.
	 * @param array  $report    Report data.
	 * @return void
	 */
	private function save_report( $report_id, $report ) {
		$reports               = get_option( 'wp_mcp_ai_custom_reports', array() );
		$reports[ $report_id ] = $report;
		update_option( 'wp_mcp_ai_custom_reports', $reports );
	}

	/**
	 * Schedule report for automatic delivery.
	 *
	 * @since 1.1.0
	 *
	 * @param string $report_id Report ID.
	 * @param string $schedule  Schedule frequency.
	 * @return void
	 */
	private function schedule_report( $report_id, $schedule ) {
		$intervals = array(
			'daily'   => DAY_IN_SECONDS,
			'weekly'  => WEEK_IN_SECONDS,
			'monthly' => MONTH_IN_SECONDS,
		);

		$interval = isset( $intervals[ $schedule ] ) ? $intervals[ $schedule ] : WEEK_IN_SECONDS;

		wp_schedule_event( time() + $interval, $schedule, 'wp_mcp_ai_send_report', array( $report_id ) );
	}
}
