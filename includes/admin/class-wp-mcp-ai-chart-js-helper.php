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

		// Validate days parameter.
		if ( $days < 1 ) {
			$days = 7;
		}

		// Limit to reasonable range.
		if ( $days > 90 ) {
			$days = 90;
		}

		// Generate labels for the past N days.
		$labels = array();
		$data   = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$labels[] = $date;
			$data[]   = 0; // Initialize with 0.
		}

		// Get actual usage data from WP_MCP_AI_Tool_Token_Limits.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			// Return empty chart data if class not loaded.
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

		// Determine which users to query.
		$user_ids = array();

		if ( ! empty( $args['user_id'] ) ) {
			// Specific user.
			$user_ids = array( absint( $args['user_id'] ) );
		} else {
			// All users.
			$user_ids = get_users( array( 'fields' => 'ID' ) );
		}

		// Aggregate usage across users and tools.
		foreach ( $user_ids as $user_id ) {
			$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

			if ( empty( $usage ) || ! is_array( $usage ) ) {
				continue;
			}

			// Filter by tool if specified.
			if ( ! empty( $args['tool_slug'] ) ) {
				$tool_slug = sanitize_key( $args['tool_slug'] );
				if ( ! isset( $usage[ $tool_slug ] ) ) {
					continue;
				}
				$usage = array( $tool_slug => $usage[ $tool_slug ] );
			}

			// Sum up usage for each day.
			foreach ( $usage as $tool_slug => $tool_data ) {
				if ( ! isset( $tool_data['daily'] ) || ! is_array( $tool_data['daily'] ) ) {
					continue;
				}

				foreach ( $tool_data['daily'] as $date => $tokens ) {
					// Find the index for this date in our labels array.
					$idx = array_search( $date, $labels, true );

					if ( false !== $idx ) {
						$data[ $idx ] += absint( $tokens );
					}
				}
			}
		}

		// Format data for Chart.js.
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

		// Count users by tier using WP_MCP_AI_Tool_Token_Limits if available.
		$users = get_users( array( 'fields' => 'ID' ) );

		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			// Use the proper tier detection method.
			foreach ( $users as $user_id ) {
				$user_tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );

				if ( isset( $tier_counts[ $user_tier ] ) ) {
					$tier_counts[ $user_tier ]++;
				} else {
					// Fallback to free tier for unknown tiers.
					$tier_counts['free']++;
				}
			}
		} else {
			// Fallback: Read meta key directly.
			foreach ( $users as $user_id ) {
				$user_tier = get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true );

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
		}

		return array(
			'labels' => array_values( $tiers ),
			'values' => array_values( $tier_counts ),
		);
	}

	/**
	 * Get chart data for tool usage breakdown.
	 *
	 * Returns token usage distribution across different tools.
	 *
	 * @param array $args Query arguments.
	 * @return array Chart data in Chart.js format.
	 */
	public static function get_tool_breakdown_data( $args = array() ) {
		$defaults = array(
			'user_id' => 0,
			'days'    => 7,
			'limit'   => 10, // Top N tools.
		);

		$args = wp_parse_args( $args, $defaults );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			return array(
				'labels' => array(),
				'values' => array(),
			);
		}

		$tool_usage = array();

		// Determine which users to query.
		$user_ids = array();

		if ( ! empty( $args['user_id'] ) ) {
			$user_ids = array( absint( $args['user_id'] ) );
		} else {
			$user_ids = get_users( array( 'fields' => 'ID' ) );
		}

		// Aggregate usage by tool.
		foreach ( $user_ids as $user_id ) {
			$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

			if ( empty( $usage ) || ! is_array( $usage ) ) {
				continue;
			}

			foreach ( $usage as $tool_slug => $tool_data ) {
				if ( ! isset( $tool_usage[ $tool_slug ] ) ) {
					$tool_usage[ $tool_slug ] = 0;
				}

				if ( isset( $tool_data['total_tokens'] ) ) {
					$tool_usage[ $tool_slug ] += absint( $tool_data['total_tokens'] );
				}
			}
		}

		// Sort by usage descending.
		arsort( $tool_usage );

		// Limit to top N tools.
		$limit      = absint( $args['limit'] );
		$tool_usage = array_slice( $tool_usage, 0, $limit, true );

		// Format for chart.
		$labels = array();
		$values = array();

		foreach ( $tool_usage as $tool_slug => $tokens ) {
			// Convert tool_slug to human-readable name.
			$labels[] = str_replace( '_', ' ', ucwords( $tool_slug, '_' ) );
			$values[] = $tokens;
		}

		return array(
			'labels' => $labels,
			'values' => $values,
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
