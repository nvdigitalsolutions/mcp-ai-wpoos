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
	 * Maximum number of users to process for charts.
	 * Can be overridden with wp_mcp_ai_chart_max_users filter.
	 */
	const MAX_USERS_FOR_CHARTS = 100;

	/**
	 * Initialize the helper.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_chart_js' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_chart_js' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'register_chart_js' ) );
	}

	/**
	 * Check if currently in Elementor editor context.
	 *
	 * @return bool True if in Elementor editor, false otherwise.
	 */
	protected static function is_elementor_editor_context() {
		// Check if Elementor editor is active via GET parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
		if ( isset( $_GET['action'] ) && 'elementor' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			return true;
		}

		// Check if Elementor Plugin is loaded and editor is active.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::instance();
			if ( $elementor && isset( $elementor->editor ) && $elementor->editor && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
				return $elementor->editor->is_edit_mode();
			}
		}

		return false;
	}

	/**
	 * Enqueue Chart.js library on Token Manager page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function maybe_enqueue_chart_js( $hook ) {
		// Skip loading in Elementor editor to prevent JavaScript conflicts.
		if ( self::is_elementor_editor_context() ) {
			return;
		}

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
	 * Register Chart.js library for use by Elementor widgets and frontend.
	 *
	 * This makes Chart.js available as a dependency without enqueuing it.
	 */
	public static function register_chart_js() {
		$chart_js_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
		$chart_js_url  = WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js';

		// Register Chart.js library so widgets can depend on it.
		wp_register_script(
			'chartjs',
			$chart_js_url,
			array(),
			file_exists( $chart_js_path ) ? filemtime( $chart_js_path ) : self::CHART_JS_VERSION,
			true
		);
	}

	/**
	 * Enqueue Chart.js library and integration script.
	 */
	public static function enqueue_chart_js() {
		// Register first (if not already registered).
		self::register_chart_js();

		// Then enqueue.
		wp_enqueue_script( 'chartjs' );

		// Enqueue analytics dashboard CSS.
		$analytics_css_path = WP_MCP_AI_PATH . 'assets/css/analytics-dashboard.css';
		if ( file_exists( $analytics_css_path ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-analytics-dashboard',
				WP_MCP_AI_URL . 'assets/css/analytics-dashboard.css',
				array(),
				filemtime( $analytics_css_path )
			);
		}

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
	 * Get cached user IDs for charts.
	 *
	 * Caches user IDs for 5 minutes to prevent repeated database queries.
	 * Limits to MAX_USERS_FOR_CHARTS users by default.
	 *
	 * @return array Array of user IDs.
	 */
	private static function get_cached_user_ids() {
		// Try to get from cache using Cache Helper.
		$user_ids = WP_MCP_AI_Cache_Helper::get( 'dashboard_user_ids' );

		if ( false !== $user_ids && is_array( $user_ids ) ) {
			return $user_ids;
		}

		// Get max users from filter (default 100).
		$max_users = apply_filters( 'wp_mcp_ai_chart_max_users', self::MAX_USERS_FOR_CHARTS );

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
			// Cached users (limited to 100 by default).
			$user_ids = self::get_cached_user_ids();
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
		$users = self::get_cached_user_ids();

		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			// Use the proper tier detection method.
			foreach ( $users as $user_id ) {
				$user_tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );

				if ( isset( $tier_counts[ $user_tier ] ) ) {
					++$tier_counts[ $user_tier ];
				} else {
					// Fallback to free tier for unknown tiers.
					++$tier_counts['free'];
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
					++$tier_counts[ $user_tier ];
				} else {
					++$tier_counts['free'];
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
			$user_ids = self::get_cached_user_ids();
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
	 * Get chart data for provider distribution.
	 *
	 * Returns token usage distribution across different providers.
	 *
	 * @param array $args Query arguments.
	 * @return array Chart data in Chart.js format.
	 */
	public static function get_provider_distribution_data( $args = array() ) {
		$defaults = array(
			'user_id' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$provider_usage = array();

		// Determine which users to query.
		$user_ids = array();

		if ( ! empty( $args['user_id'] ) ) {
			// Specific user.
			$user_ids = array( absint( $args['user_id'] ) );
		} else {
			// All users.
			$user_ids = self::get_cached_user_ids();
		}

		// Aggregate usage across users.
		foreach ( $user_ids as $user_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
				continue;
			}

			$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

			if ( empty( $usage ) || ! is_array( $usage ) ) {
				continue;
			}

			foreach ( $usage as $provider => $models ) {
				if ( ! isset( $provider_usage[ $provider ] ) ) {
					$provider_usage[ $provider ] = 0;
				}

				foreach ( $models as $model => $totals ) {
					if ( isset( $totals['total_tokens'] ) ) {
						$provider_usage[ $provider ] += absint( $totals['total_tokens'] );
					}
				}
			}
		}

		// Sort by usage descending.
		arsort( $provider_usage );

		// Format data for Chart.js.
		$labels = array();
		$values = array();
		$colors = array(
			'rgba(54, 162, 235, 0.8)',   // Blue for OpenAI.
			'rgba(75, 192, 192, 0.8)',   // Teal for Google.
			'rgba(255, 159, 64, 0.8)',   // Orange for Anthropic.
			'rgba(153, 102, 255, 0.8)',  // Purple for Ollama.
			'rgba(255, 99, 132, 0.8)',   // Red for LM Studio.
			'rgba(255, 205, 86, 0.8)',   // Yellow.
			'rgba(201, 203, 207, 0.8)',  // Gray.
		);

		$i = 0;
		foreach ( $provider_usage as $provider => $tokens ) {
			$labels[] = ucfirst( $provider );
			$values[] = $tokens;
			++$i;
		}

		return array(
			'labels'   => $labels,
			'values'   => $values,
			'colors'   => array_slice( $colors, 0, count( $labels ) ),
			'datasets' => array(
				array(
					'data'            => $values,
					'backgroundColor' => array_slice( $colors, 0, count( $labels ) ),
					'borderWidth'     => 1,
				),
			),
		);
	}

	/**
	 * Get chart data for model distribution.
	 *
	 * Returns token usage distribution across different AI models.
	 *
	 * @param array $args Query arguments.
	 * @return array Chart data in Chart.js format.
	 */
	public static function get_model_distribution_data( $args = array() ) {
		$defaults = array(
			'user_id' => 0,
			'limit'   => 10, // Top N models.
		);

		$args = wp_parse_args( $args, $defaults );

		$model_usage = array();

		// Determine which users to query.
		$user_ids = array();

		if ( ! empty( $args['user_id'] ) ) {
			// Specific user.
			$user_ids = array( absint( $args['user_id'] ) );
		} else {
			// All users.
			$user_ids = self::get_cached_user_ids();
		}

		// Aggregate usage across users.
		foreach ( $user_ids as $user_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
				continue;
			}

			$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

			if ( empty( $usage ) || ! is_array( $usage ) ) {
				continue;
			}

			foreach ( $usage as $provider => $models ) {
				foreach ( $models as $model => $totals ) {
					$key = $provider . '/' . $model;
					if ( ! isset( $model_usage[ $key ] ) ) {
						$model_usage[ $key ] = 0;
					}

					if ( isset( $totals['total_tokens'] ) ) {
						$model_usage[ $key ] += absint( $totals['total_tokens'] );
					}
				}
			}
		}

		// Sort by usage descending.
		arsort( $model_usage );

		// Limit to top N models.
		$limit = absint( $args['limit'] );
		if ( $limit > 0 ) {
			$model_usage = array_slice( $model_usage, 0, $limit, true );
		}

		// Format data for Chart.js.
		$labels = array();
		$values = array();
		$colors = array(
			'rgba(54, 162, 235, 0.8)',
			'rgba(75, 192, 192, 0.8)',
			'rgba(255, 159, 64, 0.8)',
			'rgba(153, 102, 255, 0.8)',
			'rgba(255, 99, 132, 0.8)',
			'rgba(255, 205, 86, 0.8)',
			'rgba(201, 203, 207, 0.8)',
			'rgba(83, 102, 255, 0.8)',
			'rgba(255, 99, 255, 0.8)',
			'rgba(99, 255, 132, 0.8)',
		);

		$i = 0;
		foreach ( $model_usage as $model => $tokens ) {
			$labels[] = $model;
			$values[] = $tokens;
			++$i;
		}

		return array(
			'labels'   => $labels,
			'values'   => $values,
			'colors'   => array_slice( $colors, 0, count( $labels ) ),
			'datasets' => array(
				array(
					'data'            => $values,
					'backgroundColor' => array_slice( $colors, 0, count( $labels ) ),
					'borderWidth'     => 1,
				),
			),
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

	/**
	 * Get gauge chart data for current usage percentage.
	 *
	 * Returns data showing current usage vs limit for a user or site-wide.
	 *
	 * @param array $args Query arguments.
	 * @return array Chart data in Chart.js format.
	 */
	public static function get_usage_gauge_data( $args = array() ) {
		$defaults = array(
			'user_id' => 0, // 0 for site-wide.
		);

		$args = wp_parse_args( $args, $defaults );

		$total_usage = 0;
		$total_limit = 0;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			return array(
				'percentage' => 0,
				'usage'      => 0,
				'limit'      => 0,
				'label'      => __( 'Usage', 'wp-mcp-ai' ),
			);
		}

		// Determine which users to query.
		$user_ids = array();

		if ( ! empty( $args['user_id'] ) ) {
			$user_ids = array( absint( $args['user_id'] ) );
		} else {
			// Get all users for site-wide calculation.
			$user_ids = self::get_cached_user_ids();
		}

		// Calculate total usage and limits.
		foreach ( $user_ids as $user_id ) {
			// Get today's usage.
			$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );

			if ( ! empty( $usage ) && is_array( $usage ) ) {
				$today = gmdate( 'Y-m-d' );

				foreach ( $usage as $tool_slug => $tool_data ) {
					if ( isset( $tool_data['daily'] ) && is_array( $tool_data['daily'] ) && isset( $tool_data['daily'][ $today ] ) ) {
						$total_usage += absint( $tool_data['daily'][ $today ] );
					}
				}
			}

			// Get user's tier limit.
			$user_tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );
			$tier_info = WP_MCP_AI_Tool_Token_Limits::get_tier_info( $user_tier );

			if ( isset( $tier_info['daily_limit'] ) ) {
				$total_limit += absint( $tier_info['daily_limit'] );
			}
		}

		// Calculate percentage.
		$percentage = 0;
		if ( $total_limit > 0 ) {
			$percentage = min( 100, ( $total_usage / $total_limit ) * 100 );
		}

		// Determine color based on usage percentage.
		$color = 'rgba(75, 192, 192, 1)'; // Green (default).
		if ( $percentage >= 90 ) {
			$color = 'rgba(255, 99, 132, 1)'; // Red.
		} elseif ( $percentage >= 75 ) {
			$color = 'rgba(255, 159, 64, 1)'; // Orange.
		} elseif ( $percentage >= 50 ) {
			$color = 'rgba(255, 205, 86, 1)'; // Yellow.
		}

		return array(
			'percentage' => round( $percentage, 1 ),
			'usage'      => $total_usage,
			'limit'      => $total_limit,
			'label'      => __( 'Current Usage', 'wp-mcp-ai' ),
			'color'      => $color,
			'datasets'   => array(
				array(
					'data'            => array( round( $percentage, 1 ), 100 - round( $percentage, 1 ) ),
					'backgroundColor' => array( $color, 'rgba(201, 203, 207, 0.2)' ),
					'borderWidth'     => 0,
					'circumference'   => 180,
					'rotation'        => 270,
				),
			),
		);
	}

	/**
	 * Get chart configuration for gauge chart.
	 *
	 * Returns Chart.js configuration object for doughnut gauge chart.
	 *
	 * @return array Chart.js config.
	 */
	public static function get_usage_gauge_config() {
		return array(
			'type'    => 'doughnut',
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'circumference'       => 180,
				'rotation'            => 270,
				'cutout'              => '75%',
				'plugins'             => array(
					'legend'  => array(
						'display' => false,
					),
					'tooltip' => array(
						'enabled' => false,
					),
					'title'   => array(
						'display' => true,
						'text'    => __( 'Token Usage', 'wp-mcp-ai' ),
					),
				),
			),
		);
	}
}

// Initialize the helper.
WP_MCP_AI_Chart_JS_Helper::init();
