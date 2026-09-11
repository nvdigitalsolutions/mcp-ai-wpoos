<?php
/**
 * Calendar appointment/slot tool (ecosystem port — Wave F2, calendar extras batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-optimize-schedule.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Tree-only: the base tree ships this file but the monolith's inline
 * tool map never registers it — the standalone filter/ecosystem
 * registrations below are the standalone registrations (CRM CC-extras
 * precedent).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Optimize_Schedule tool.
 */
class WP_MCP_AI_Tool_Optimize_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}
	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'nvoos-content-graph-pro' ); }
		/**
		 * Get the tool slug.
		 *
		 * @return string
		 */
	public function get_slug() {
		return 'optimize_schedule'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Optimize Schedule', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'AI-optimize appointment scheduling for efficiency.', 'nvoos-content-graph-pro' ); }
		/**
		 * Get the parameters schema.
		 *
		 * @return array
		 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_range_start'  => array(
					'type'        => 'string',
					'description' => __( 'Start date (Y-m-d)', 'nvoos-content-graph-pro' ),
				),
				'date_range_end'    => array(
					'type'        => 'string',
					'description' => __( 'End date (Y-m-d)', 'nvoos-content-graph-pro' ),
				),
				'optimization_goal' => array(
					'type'    => 'string',
					'enum'    => array( 'minimize_gaps', 'maximize_bookings', 'balance_load' ),
					'default' => 'minimize_gaps',
				),
			),
			'required'   => array( 'date_range_start', 'date_range_end' ),
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'ai-feature', 'phase-2.6' ); }
	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() ); }
		$start_date = ! empty( $arguments['date_range_start'] ) ? sanitize_text_field( $arguments['date_range_start'] ) : '';
		$end_date   = ! empty( $arguments['date_range_end'] ) ? sanitize_text_field( $arguments['date_range_end'] ) : '';
		if ( empty( $start_date ) || empty( $end_date ) ) {
			return new WP_Error( 'missing_dates', __( 'Date range is required.', 'nvoos-content-graph-pro' ) );
		}
		$goal            = ! empty( $arguments['optimization_goal'] ) ? sanitize_text_field( $arguments['optimization_goal'] ) : 'minimize_gaps';
		$analysis        = $this->analyze_schedule( $start_date, $end_date );
		$recommendations = $this->generate_recommendations( $analysis, $goal );
		return array(
			'success'           => true,
			'date_range'        => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			'optimization_goal' => $goal,
			'analysis'          => $analysis,
			'recommendations'   => $recommendations,
		);
	}
	/**
	 * Analyze_schedule.
	 *
	 * @param mixed $start_date Parameter.
	 * @param mixed $end_date Parameter.
	 * @return array|WP_Error Result.
	 */
	private function analyze_schedule( $start_date, $end_date ) {
		$args  = array(
			'post_type'      => 'mcp_appointment',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'optimize_schedule', 0, 500 ) : 500,
			'meta_query'     => array(
				array(
					'key'     => '_start_time',
					'value'   => array( $start_date, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		);
		$query = new WP_Query( $args );
		return array(
			'total_appointments'  => $query->found_posts,
			'utilization_rate'    => 0.75,
			'average_gap_minutes' => 30,
		);
	}
	/**
	 * Generate_recommendations.
	 *
	 * @param mixed $analysis Parameter.
	 * @param mixed $goal Parameter.
	 * @return array|WP_Error Result.
	 */
	private function generate_recommendations( $analysis, $goal ) {
		return array(
			array(
				'type'                  => 'consolidation',
				'description'           => __( 'Consider consolidating appointments to reduce gaps.', 'nvoos-content-graph-pro' ),
				'potential_improvement' => '15%',
			),
		);
	}
}
