<?php
/**
 * Chart.js Integration Helper for Token Manager
 *
 * Provides helper methods for integrating Chart.js visualizations
 * in the Token Manager admin section.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chart.js helper class for Token Manager visualizations.
 */
class WP_MCP_AI_Chart_JS_Helper {

	/**
	 * Chart.js version.
	 */
	const CHART_JS_VERSION = '4.4.1';

	/**
	 * Initialize the helper.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_chart_js' ) );
	}

	/**
	 * Enqueue Chart.js library on Token Manager page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function maybe_enqueue_chart_js( $hook ) {
		// Only load on WP oOS settings pages.
		if ( false === strpos( $hook, 'wp-mcp-ai' ) ) {
			return;
		}

		// Check if we're on the token manager tab.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'token_manager' !== $active_tab && empty( $active_tab ) ) {
			return;
		}

		self::enqueue_chart_js();
	}

	/**
	 * Enqueue Chart.js library and integration script.
	 */
	public static function enqueue_chart_js() {
		$chart_js_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
		$chart_js_url  = WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js';

		// Enqueue Chart.js library.
		wp_enqueue_script(
			'chartjs',
			$chart_js_url,
			array(),
			file_exists( $chart_js_path ) ? filemtime( $chart_js_path ) : self::CHART_JS_VERSION,
			true
		);

		// Enqueue token manager charts integration (to be created in Phase 3).
		$charts_path = WP_MCP_AI_PATH . 'assets/js/token-manager-charts.js';
		if ( file_exists( $charts_path ) ) {
			wp_enqueue_script(
				'wp-mcp-ai-token-charts',
				WP_MCP_AI_URL . 'assets/js/token-manager-charts.js',
				array( 'jquery', 'chartjs' ),
				filemtime( $charts_path ),
				true
			);

			// Localize script with chart data.
			wp_localize_script(
				'wp-mcp-ai-token-charts',
				'wpMcpAiChartData',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_token_charts' ),
				)
			);
		}
	}

	/**
	 * Get chart data for token usage trends.
	 *
	 * @param array $args Query arguments.
	 * @return array Chart data in Chart.js format.
	 */
	public static function get_usage_trend_data( $args = array() ) {
		$defaults = array(
			'user_id'   => 0,
			'tool_slug' => '',
			'days'      => 7,
		);

		$args = wp_parse_args( $args, $defaults );
		$days = absint( $args['days'] );

		// Generate labels for the past N days.
		$labels = array();
		$data   = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$labels[] = $date;

			// Get token usage for this day.
			// For now, return sample data - will be connected to actual usage tracking.
			$data[] = 0;
		}

		// Get actual usage data if available.
		if ( class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			$users = get_users( array( 'fields' => 'ID' ) );

			foreach ( $users as $user_id ) {
				$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

				if ( ! empty( $usage ) ) {
					foreach ( $usage as $provider => $models ) {
						foreach ( $models as $model => $totals ) {
							if ( isset( $totals['total_tokens'] ) ) {
								// Distribute tokens across days (simplified).
								$tokens_per_day = absint( $totals['total_tokens'] ) / $days;
								for ( $i = 0; $i < $days; $i++ ) {
									$data[ $i ] += $tokens_per_day;
								}
							}
						}
					}
				}
			}
		}

		// Round data values.
		$data = array_map( 'absint', $data );

		return array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label'           => __( 'Token Usage', 'wp-mcp-ai' ),
					'data'            => $data,
					'borderColor'     => 'rgba(75, 192, 192, 1)',
					'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
					'fill'            => true,
					'tension'         => 0.4,
				),
			),
		);
	}

	/**
	 * Get chart data for tier distribution.
	 *
	 * @return array Chart data in Chart.js format.
	 */
	public static function get_tier_distribution_data() {
		$tiers = array(
			'free'       => __( 'Free', 'wp-mcp-ai' ),
			'pro'        => __( 'Pro', 'wp-mcp-ai' ),
			'enterprise' => __( 'Enterprise', 'wp-mcp-ai' ),
		);

		$tier_counts = array(
			'free'       => 0,
			'pro'        => 0,
			'enterprise' => 0,
		);

		// Count users by tier.
		$users = get_users( array( 'fields' => 'ID' ) );

		foreach ( $users as $user_id ) {
			$user_tier = get_user_meta( $user_id, 'wp_mcp_ai_token_tier', true );

			if ( empty( $user_tier ) ) {
				$user_tier = 'free';
			}

			$user_tier = sanitize_key( $user_tier );

			if ( isset( $tier_counts[ $user_tier ] ) ) {
				$tier_counts[ $user_tier ]++;
			} else {
				$tier_counts['free']++;
			}
		}

		return array(
			'labels' => array_values( $tiers ),
			'values' => array_values( $tier_counts ),
		);
	}

	/**
	 * Get chart configuration for usage trend chart.
	 *
	 * Returns Chart.js configuration object.
	 *
	 * @return array Chart.js config.
	 */
	public static function get_usage_trend_config() {
		return array(
			'type'    => 'line',
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'plugins'             => array(
					'legend' => array(
						'display' => true,
					),
					'title'  => array(
						'display' => true,
						'text'    => __( 'Token Usage Trend', 'wp-mcp-ai' ),
					),
				),
				'scales'              => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Tokens', 'wp-mcp-ai' ),
						),
					),
					'x' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Date', 'wp-mcp-ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Get chart configuration for tier distribution pie chart.
	 *
	 * Returns Chart.js configuration object.
	 *
	 * @return array Chart.js config.
	 */
	public static function get_tier_distribution_config() {
		return array(
			'type'    => 'pie',
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'plugins'             => array(
					'legend' => array(
						'display'  => true,
						'position' => 'right',
					),
					'title'  => array(
						'display' => true,
						'text'    => __( 'User Tier Distribution', 'wp-mcp-ai' ),
					),
				),
			),
		);
	}
}

// Initialize the helper.
WP_MCP_AI_Chart_JS_Helper::init();
