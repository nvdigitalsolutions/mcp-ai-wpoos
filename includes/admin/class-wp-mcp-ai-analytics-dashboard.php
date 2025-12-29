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
	 * Maximum number of users to process for dashboard widgets.
	 * Can be overridden with wp_mcp_ai_dashboard_max_users filter.
	 */
	const MAX_USERS_FOR_DASHBOARD = 100;

	/**
	 * Initialize the analytics dashboard.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widgets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Invalidate analytics cache when usage is tracked.
		add_action( 'wp_mcp_ai_usage_tracked', array( 'WP_MCP_AI_Cache_Helper', 'invalidate_analytics_caches' ) );
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

		// Analytics Engine widgets (Phase 7, Week 5-6).
		if ( class_exists( 'WP_MCP_AI_Analytics_Engine' ) ) {
			wp_add_dashboard_widget(
				'wp_mcp_ai_analytics_trends',
				__( 'AI Usage Trends', 'wp-mcp-ai' ),
				array( __CLASS__, 'render_analytics_trends_widget' )
			);

			wp_add_dashboard_widget(
				'wp_mcp_ai_analytics_patterns',
				__( 'AI Usage Patterns', 'wp-mcp-ai' ),
				array( __CLASS__, 'render_analytics_patterns_widget' )
			);

			wp_add_dashboard_widget(
				'wp_mcp_ai_analytics_anomalies',
				__( 'AI Anomaly Detection', 'wp-mcp-ai' ),
				array( __CLASS__, 'render_analytics_anomalies_widget' )
			);
		}
	}

	/**
	 * Get cached user IDs for dashboard widgets.
	 *
	 * Caches user IDs for 5 minutes to prevent repeated database queries.
	 * Limits to MAX_USERS_FOR_DASHBOARD users by default.
	 *
	 * @return array Array of user IDs.
	 */
	private static function get_cached_user_ids() {
		// Try to get from cache.
		$user_ids = WP_MCP_AI_Cache_Helper::get( 'dashboard_user_ids' );

		if ( false !== $user_ids && is_array( $user_ids ) ) {
			return $user_ids;
		}

		// Get max users from filter (default 100).
		$max_users = apply_filters( 'wp_mcp_ai_dashboard_max_users', self::MAX_USERS_FOR_DASHBOARD );

		// Fetch fresh user IDs with limit.
		$user_ids = get_users(
			array(
				'fields' => 'ID',
				'number' => $max_users,
			)
		);

		// Cache for 5 minutes using Cache Helper.
		WP_MCP_AI_Cache_Helper::set( 'dashboard_user_ids', $user_ids, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );

		return $user_ids;
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
	 * Render analytics trends widget.
	 */
	public static function render_analytics_trends_widget() {
		$data = array(
			'user_id' => 0, // Site-wide.
			'days'    => 30,
		);
		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-trends.php';
	}

	/**
	 * Render analytics patterns widget.
	 */
	public static function render_analytics_patterns_widget() {
		$data = array(
			'user_id' => get_current_user_id(),
		);
		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-patterns.php';
	}

	/**
	 * Render analytics anomalies widget.
	 */
	public static function render_analytics_anomalies_widget() {
		$data = array(
			'user_id'   => 0, // Site-wide.
			'threshold' => 3.0,
		);
		include WP_MCP_AI_PATH . 'includes/admin/widgets/analytics-anomalies.php';
	}

	/**
	 * Get usage overview data formatted for Chart.js.
	 *
	 * @return array Chart data.
	 */
	private static function get_usage_overview_data() {
		// Try to get from cache.
		$data = WP_MCP_AI_Cache_Helper::get( 'dashboard_usage_overview' );

		if ( false !== $data && is_array( $data ) ) {
			return $data;
		}

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

		// Get gauge chart data for current usage percentage.
		$gauge_data = WP_MCP_AI_Chart_JS_Helper::get_usage_gauge_data(
			array(
				'user_id' => 0, // Site-wide.
			)
		);

		$data = array(
			'trend'         => $trend_data,
			'tiers'         => $tier_data,
			'current_stats' => $current_stats,
			'gauge'         => $gauge_data,
		);

		// Cache for 5 minutes using Cache Helper.
		WP_MCP_AI_Cache_Helper::set( 'dashboard_usage_overview', $data, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );

		return $data;
	}

	/**
	 * Get cost breakdown data.
	 *
	 * Presentation layer - delegates to Cost Tracking Service (SoC).
	 *
	 * @return array Cost data.
	 */
	private static function get_cost_breakdown_data() {
		// Try to get from cache.
		$data = WP_MCP_AI_Cache_Helper::get( 'dashboard_cost_breakdown' );

		if ( false !== $data && is_array( $data ) ) {
			return $data;
		}

		// Delegate to Cost Tracking Service (separation of concerns).
		if ( ! class_exists( 'WP_MCP_AI_Cost_Tracking_Service' ) ) {
			// Fallback if service not available.
			return array(
				'total_cost'   => 0.0,
				'total_tokens' => 0,
				'by_provider'  => array(),
				'by_model'     => array(),
				'by_tool'      => array(),
				'period_start' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				'period_end'   => gmdate( 'Y-m-d' ),
			);
		}

		$data = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( 7 );

		// Cache for 5 minutes using Cache Helper.
		WP_MCP_AI_Cache_Helper::set( 'dashboard_cost_breakdown', $data, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );

		return $data;
	}

	/**
	 * Get usage forecast data.
	 *
	 * @return array Forecast data.
	 */
	private static function get_usage_forecast_data() {
		// Try to get from cache.
		$data = WP_MCP_AI_Cache_Helper::get( 'dashboard_usage_forecast' );

		if ( false !== $data && is_array( $data ) ) {
			return $data;
		}

		$forecast_data = array(
			'projected_usage' => 0,
			'projected_date'  => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
			'confidence'      => 0,
			'trend'           => 'stable',
		);

		// Use existing forecast functionality if available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			// Cache empty result for 5 minutes using Cache Helper.
			WP_MCP_AI_Cache_Helper::set( 'dashboard_usage_forecast', $forecast_data, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );
			return $forecast_data;
		}

		// Get cached users to calculate site-wide trend.
		$users                = self::get_cached_user_ids();
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

		// Cache for 5 minutes using Cache Helper.
		WP_MCP_AI_Cache_Helper::set( 'dashboard_usage_forecast', $forecast_data, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );

		return $forecast_data;
	}

	/**
	 * Get current usage statistics.
	 *
	 * @return array Current stats.
	 */
	private static function get_current_usage_stats() {
		// Try to get from cache.
		$stats = WP_MCP_AI_Cache_Helper::get( 'dashboard_current_stats' );

		if ( false !== $stats && is_array( $stats ) ) {
			return $stats;
		}

		$stats = array(
			'today_tokens'     => 0,
			'week_tokens'      => 0,
			'month_tokens'     => 0,
			'active_users'     => 0,
			'total_users'      => 0,
			'peak_hour'        => 0,
			'average_per_user' => 0,
		);

		// Get cached users.
		$users                = self::get_cached_user_ids();
		$stats['total_users'] = count( $users );

		// Calculate usage across all users using actual date ranges.
		if ( class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
			// Define date ranges based on current time.
			$now = current_time( 'mysql' );

			// Today: from start of today to now.
			$today_start = gmdate( 'Y-m-d 00:00:00', strtotime( 'today', strtotime( $now ) ) );
			$today_end   = $now;

			// This week: from start of this week (Monday) to now.
			$week_start = gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week', strtotime( $now ) ) );
			$week_end   = $now;

			// This month: from start of this month to now.
			$month_start = gmdate( 'Y-m-01 00:00:00', strtotime( $now ) );
			$month_end   = $now;

			$active_count = 0;
			$today_tokens = 0;
			$week_tokens  = 0;
			$month_tokens = 0;

			foreach ( $users as $user_id ) {
				// Get actual token usage for each period.
				$today_summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $today_start, $today_end );
				$week_summary  = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $week_start, $week_end );
				$month_summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $month_start, $month_end );

				// Count as active if user has any token usage this month.
				if ( $month_summary['total_tokens'] > 0 ) {
					++$active_count;
				}

				$today_tokens += absint( $today_summary['total_tokens'] );
				$week_tokens  += absint( $week_summary['total_tokens'] );
				$month_tokens += absint( $month_summary['total_tokens'] );
			}

			$stats['active_users']     = $active_count;
			$stats['today_tokens']     = $today_tokens;
			$stats['week_tokens']      = $week_tokens;
			$stats['month_tokens']     = $month_tokens;
			$stats['average_per_user'] = $active_count > 0 ? (int) ( $month_tokens / $active_count ) : 0;
		}

		// Cache for 5 minutes using Cache Helper.
		WP_MCP_AI_Cache_Helper::set( 'dashboard_current_stats', $stats, WP_MCP_AI_Cache_Helper::ANALYTICS_EXPIRATION );

		return $stats;
	}
}

// Initialize the analytics dashboard.
WP_MCP_AI_Analytics_Dashboard::init();
