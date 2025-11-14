<?php
/**
 * Analytics Dashboard for WP oOS Token Manager
 *
 * Provides WordPress dashboard widgets for token usage analytics.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics Dashboard class for token usage visualizations.
 */
class WP_MCP_AI_Analytics_Dashboard {

	/**
	 * Initialize the analytics dashboard.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widgets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register dashboard widgets.
	 */
	public static function register_widgets() {
		// Only show to users with manage_options capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wp_mcp_ai_token_usage_overview',
			__( 'AI Token Usage Overview', 'wp-mcp-ai' ),
			array( __CLASS__, 'render_usage_overview_widget' )
		);

		wp_add_dashboard_widget(
			'wp_mcp_ai_cost_breakdown',
			__( 'AI Cost Breakdown', 'wp-mcp-ai' ),
			array( __CLASS__, 'render_cost_breakdown_widget' )
		);

		wp_add_dashboard_widget(
			'wp_mcp_ai_usage_forecast',
			__( 'AI Usage Forecast', 'wp-mcp-ai' ),
			array( __CLASS__, 'render_usage_forecast_widget' )
		);
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on dashboard page.
		if ( 'index.php' !== $hook ) {
			return;
		}

		// Enqueue Chart.js and dashboard scripts.
		WP_MCP_AI_Chart_JS_Helper::enqueue_chart_js();

		// Enqueue analytics dashboard JavaScript.
		$dashboard_js_path = WP_MCP_AI_PATH . 'assets/js/analytics-dashboard.js';
		if ( file_exists( $dashboard_js_path ) ) {
			wp_enqueue_script(
				'wp-mcp-ai-analytics-dashboard',
				WP_MCP_AI_URL . 'assets/js/analytics-dashboard.js',
				array( 'jquery', 'chartjs' ),
				filemtime( $dashboard_js_path ),
				true
			);

			// Localize script with necessary data.
			wp_localize_script(
				'wp-mcp-ai-analytics-dashboard',
				'wpMcpAiAnalytics',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_analytics' ),
				)
			);
		}

		// Enqueue analytics dashboard CSS.
		$dashboard_css_path = WP_MCP_AI_PATH . 'assets/css/analytics-dashboard.css';
		if ( file_exists( $dashboard_css_path ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-analytics-dashboard',
				WP_MCP_AI_URL . 'assets/css/analytics-dashboard.css',
				array(),
				filemtime( $dashboard_css_path )
			);
		}
	}

	/**
	 * Render usage overview widget with Chart.js.
	 */
	public static function render_usage_overview_widget() {
		$data = self::get_usage_overview_data();
		include WP_MCP_AI_PATH . 'includes/admin/widgets/token-usage-overview.php';
	}

	/**
	 * Render cost breakdown widget.
	 */
	public static function render_cost_breakdown_widget() {
		$data = self::get_cost_breakdown_data();
		include WP_MCP_AI_PATH . 'includes/admin/widgets/cost-breakdown.php';
	}

	/**
	 * Render usage forecast widget.
	 */
	public static function render_usage_forecast_widget() {
		$data = self::get_usage_forecast_data();
		include WP_MCP_AI_PATH . 'includes/admin/widgets/usage-forecast.php';
	}

	/**
	 * Get usage overview data formatted for Chart.js.
	 *
	 * @return array Chart data.
	 */
	private static function get_usage_overview_data() {
		// Get 7-day usage trend data.
		$trend_data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
			array(
				'days' => 7,
			)
		);

		// Get tier distribution.
		$tier_data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();

		// Get current usage stats.
		$current_stats = self::get_current_usage_stats();

		return array(
			'trend'         => $trend_data,
			'tiers'         => $tier_data,
			'current_stats' => $current_stats,
		);
	}

	/**
	 * Get cost breakdown data.
	 *
	 * @return array Cost data.
	 */
	private static function get_cost_breakdown_data() {
		// Placeholder for cost calculation.
		// This will be implemented in Phase 7, Week 3-4.
		return array(
			'total_cost'   => 0.0,
			'by_provider'  => array(),
			'by_model'     => array(),
			'by_user'      => array(),
			'period_start' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			'period_end'   => gmdate( 'Y-m-d' ),
		);
	}

	/**
	 * Get usage forecast data.
	 *
	 * @return array Forecast data.
	 */
	private static function get_usage_forecast_data() {
		$forecast_data = array(
			'projected_usage' => 0,
			'projected_date'  => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'confidence'      => 0,
			'trend'           => 'stable',
		);

		// Use existing forecast functionality if available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			return $forecast_data;
		}

		// Get all users to calculate site-wide trend.
		$users                = get_users( array( 'fields' => 'ID' ) );
		$total_current_usage  = 0;
		$total_forecast_usage = 0;
		$forecast_count       = 0;
		$confidence_sum       = 0;

		foreach ( $users as $user_id ) {
			$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

			if ( empty( $usage ) || ! is_array( $usage ) ) {
				continue;
			}

			foreach ( $usage as $tool_slug => $tool_data ) {
				// Get current daily usage.
				if ( isset( $tool_data['daily'] ) && is_array( $tool_data['daily'] ) ) {
					$today = gmdate( 'Y-m-d' );
					if ( isset( $tool_data['daily'][ $today ] ) ) {
						$total_current_usage += absint( $tool_data['daily'][ $today ] );
					}
				}

				// Get forecast for this user/tool combo.
				$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, $tool_slug );

				if ( $forecast && isset( $forecast['projected_daily_usage'] ) ) {
					$total_forecast_usage += absint( $forecast['projected_daily_usage'] );
					$confidence_sum       += absint( $forecast['confidence'] ?? 0 );
					++$forecast_count;
				}
			}
		}

		// Calculate averages and trend.
		if ( $forecast_count > 0 ) {
			$forecast_data['projected_usage'] = (int) ( $total_forecast_usage / $forecast_count );
			$forecast_data['confidence']      = (int) ( $confidence_sum / $forecast_count );

			// Determine trend based on comparison.
			if ( $total_forecast_usage > $total_current_usage * 1.1 ) {
				$forecast_data['trend'] = 'increasing';
			} elseif ( $total_forecast_usage < $total_current_usage * 0.9 ) {
				$forecast_data['trend'] = 'decreasing';
			} else {
				$forecast_data['trend'] = 'stable';
			}
		}

		return $forecast_data;
	}

	/**
	 * Get current usage statistics.
	 *
	 * @return array Current stats.
	 */
	private static function get_current_usage_stats() {
		$stats = array(
			'today_tokens'     => 0,
			'week_tokens'      => 0,
			'month_tokens'     => 0,
			'active_users'     => 0,
			'total_users'      => 0,
			'peak_hour'        => 0,
			'average_per_user' => 0,
		);

		// Get all users.
		$users                = get_users( array( 'fields' => 'ID' ) );
		$stats['total_users'] = count( $users );

		// Calculate usage across all users.
		if ( class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			$active_count = 0;
			$total_tokens = 0;
			$today_tokens = 0;
			$week_tokens  = 0;
			$month_tokens = 0;

			foreach ( $users as $user_id ) {
				$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

				if ( ! empty( $usage ) ) {
					++$active_count;

					foreach ( $usage as $provider => $models ) {
						foreach ( $models as $model => $totals ) {
							if ( isset( $totals['total_tokens'] ) ) {
								$tokens        = absint( $totals['total_tokens'] );
								$total_tokens += $tokens;

								// Estimate distribution (simplified).
								$today_tokens += (int) ( $tokens * 0.1 );
								$week_tokens  += (int) ( $tokens * 0.3 );
								$month_tokens += $tokens;
							}
						}
					}
				}
			}

			$stats['active_users']     = $active_count;
			$stats['today_tokens']     = $today_tokens;
			$stats['week_tokens']      = $week_tokens;
			$stats['month_tokens']     = $month_tokens;
			$stats['average_per_user'] = $active_count > 0 ? (int) ( $month_tokens / $active_count ) : 0;
		}

		return $stats;
	}
}

// Initialize the analytics dashboard.
WP_MCP_AI_Analytics_Dashboard::init();
