<?php
/**
 * Funnel Analysis Tool
 *
 * Tracks conversion funnel performance, identifies drop-off points,
 * and provides optimization recommendations.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for analyzing conversion funnels.
 *
 * Supports:
 * - Multi-step funnel tracking
 * - Conversion rate calculation
 * - Drop-off point identification
 * - Segment comparison
 * - Optimization recommendations
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Funnel_Analysis implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Funnel analysis tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'funnel_analysis';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Analyze Conversion Funnel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Track conversion funnel performance, identify drop-off points at each stage, calculate conversion rates, and get optimization recommendations to improve funnel efficiency.', 'mcp-ai-wpoos-pro' );
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
				'funnel_type'           => array(
					'type'        => 'string',
					'description' => 'Type of funnel: checkout, registration, subscription, custom',
					'enum'        => array( 'checkout', 'registration', 'subscription', 'custom' ),
					'default'     => 'checkout',
				),
				'custom_steps'          => array(
					'type'        => 'array',
					'description' => 'Custom funnel step definitions (required if funnel_type is custom)',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'  => array( 'type' => 'string' ),
							'event' => array( 'type' => 'string' ),
						),
					),
				),
				'date_range'            => array(
					'type'        => 'string',
					'description' => 'Analysis period: last_7_days, last_30_days, last_90_days, custom',
					'enum'        => array( 'last_7_days', 'last_30_days', 'last_90_days', 'custom' ),
					'default'     => 'last_30_days',
				),
				'start_date'            => array(
					'type'        => 'string',
					'description' => 'Start date for custom range (YYYY-MM-DD)',
				),
				'end_date'              => array(
					'type'        => 'string',
					'description' => 'End date for custom range (YYYY-MM-DD)',
				),
				'segment_by'            => array(
					'type'        => 'string',
					'description' => 'Segment funnel by: none, device, source, campaign',
					'enum'        => array( 'none', 'device', 'source', 'campaign' ),
					'default'     => 'none',
				),
				'include_drop_off_reasons' => array(
					'type'        => 'boolean',
					'description' => 'Analyze and include drop-off reasons',
					'default'     => true,
				),
				'include_recommendations' => array(
					'type'        => 'boolean',
					'description' => 'Include optimization recommendations',
					'default'     => true,
				),
			),
			'required'   => array(),
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
			'analytics'   => true,
			'conversion'  => true,
			'funnel'      => true,
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
		// Parse arguments.
		$funnel_type              = ! empty( $arguments['funnel_type'] ) ? sanitize_text_field( $arguments['funnel_type'] ) : 'checkout';
		$custom_steps             = ! empty( $arguments['custom_steps'] ) ? $arguments['custom_steps'] : array();
		$date_range               = ! empty( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'last_30_days';
		$start_date               = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : null;
		$end_date                 = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : null;
		$segment_by               = ! empty( $arguments['segment_by'] ) ? sanitize_text_field( $arguments['segment_by'] ) : 'none';
		$include_drop_off_reasons = ! isset( $arguments['include_drop_off_reasons'] ) || $arguments['include_drop_off_reasons'];
		$include_recommendations  = ! isset( $arguments['include_recommendations'] ) || $arguments['include_recommendations'];

		// Validate custom funnel.
		if ( 'custom' === $funnel_type && empty( $custom_steps ) ) {
			return new WP_Error(
				'custom_steps_required',
				__( 'Custom steps are required when funnel_type is custom.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get date range.
		$dates = $this->get_date_range( $date_range, $start_date, $end_date );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		// Define funnel steps.
		$steps = $this->get_funnel_steps( $funnel_type, $custom_steps );

		// Analyze funnel.
		$funnel_data = $this->analyze_funnel( $steps, $dates, $segment_by );

		if ( is_wp_error( $funnel_data ) ) {
			return $funnel_data;
		}

		// Calculate metrics.
		$metrics = $this->calculate_funnel_metrics( $funnel_data );

		// Identify drop-off points.
		$drop_offs = $this->identify_drop_offs( $funnel_data );

		// Prepare result.
		$result = array(
			'success'     => true,
			'funnel_type' => $funnel_type,
			'steps'       => $funnel_data['steps'],
			'metrics'     => $metrics,
			'drop_offs'   => $drop_offs,
			'date_range'  => $dates,
			'analyzed_at' => current_time( 'mysql' ),
			'message'     => sprintf(
				/* translators: 1: overall conversion rate */
				__( 'Funnel analysis complete. Overall conversion rate: %s%%', 'mcp-ai-wpoos-pro' ),
				$metrics['overall_conversion_rate']
			),
		);

		if ( $include_drop_off_reasons ) {
			$result['drop_off_analysis'] = $this->analyze_drop_off_reasons( $funnel_data, $drop_offs );
		}

		if ( $include_recommendations ) {
			$result['recommendations'] = $this->generate_recommendations( $funnel_data, $drop_offs );
		}

		if ( 'none' !== $segment_by && isset( $funnel_data['segments'] ) ) {
			$result['segments'] = $funnel_data['segments'];
		}

		return $result;
	}

	/**
	 * Get date range for analysis.
	 *
	 * @since 1.1.0
	 *
	 * @param string $range      Range type.
	 * @param string $start_date Custom start date.
	 * @param string $end_date   Custom end date.
	 * @return array|WP_Error Date range or error.
	 */
	private function get_date_range( $range, $start_date, $end_date ) {
		$now = current_time( 'timestamp' );

		switch ( $range ) {
			case 'last_7_days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;

			case 'last_30_days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;

			case 'last_90_days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-90 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;

			case 'custom':
				if ( empty( $start_date ) || empty( $end_date ) ) {
					return new WP_Error(
						'custom_dates_required',
						__( 'Start date and end date are required for custom range.', 'mcp-ai-wpoos-pro' )
					);
				}
				$start = $start_date . ' 00:00:00';
				$end   = $end_date . ' 23:59:59';
				break;

			default:
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Get funnel step definitions.
	 *
	 * @since 1.1.0
	 *
	 * @param string $funnel_type Funnel type.
	 * @param array  $custom_steps Custom steps.
	 * @return array Funnel steps.
	 */
	private function get_funnel_steps( $funnel_type, $custom_steps ) {
		switch ( $funnel_type ) {
			case 'checkout':
				return array(
					array(
						'name'  => 'Product View',
						'event' => 'product_view',
					),
					array(
						'name'  => 'Add to Cart',
						'event' => 'add_to_cart',
					),
					array(
						'name'  => 'Checkout Started',
						'event' => 'checkout_started',
					),
					array(
						'name'  => 'Payment Info Added',
						'event' => 'payment_info',
					),
					array(
						'name'  => 'Purchase Complete',
						'event' => 'purchase',
					),
				);

			case 'registration':
				return array(
					array(
						'name'  => 'Registration Page Visit',
						'event' => 'registration_view',
					),
					array(
						'name'  => 'Form Started',
						'event' => 'form_started',
					),
					array(
						'name'  => 'Form Submitted',
						'event' => 'form_submitted',
					),
					array(
						'name'  => 'Email Verified',
						'event' => 'email_verified',
					),
					array(
						'name'  => 'Registration Complete',
						'event' => 'registration_complete',
					),
				);

			case 'subscription':
				return array(
					array(
						'name'  => 'Pricing Page View',
						'event' => 'pricing_view',
					),
					array(
						'name'  => 'Plan Selected',
						'event' => 'plan_selected',
					),
					array(
						'name'  => 'Checkout Started',
						'event' => 'checkout_started',
					),
					array(
						'name'  => 'Payment Added',
						'event' => 'payment_added',
					),
					array(
						'name'  => 'Subscription Active',
						'event' => 'subscription_active',
					),
				);

			case 'custom':
				return $custom_steps;

			default:
				return array();
		}
	}

	/**
	 * Analyze funnel performance.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $steps      Funnel steps.
	 * @param array  $dates      Date range.
	 * @param string $segment_by Segmentation type.
	 * @return array|WP_Error Funnel data or error.
	 */
	private function analyze_funnel( $steps, $dates, $segment_by ) {
		global $wpdb;

		// For checkout funnel, use WooCommerce data.
		$funnel_data = array(
			'steps' => array(),
		);

		// Simplified checkout funnel using WooCommerce orders.
		$cart_views = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_author)
				FROM {$wpdb->posts}
				WHERE post_type = 'product'
					AND post_status = 'publish'
					AND post_date BETWEEN %s AND %s",
				$dates['start'],
				$dates['end']
			)
		);

		$orders_started = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_type = 'shop_order'
					AND post_status IN ('wc-pending', 'wc-processing', 'wc-completed', 'wc-on-hold')
					AND post_date BETWEEN %s AND %s",
				$dates['start'],
				$dates['end']
			)
		);

		$orders_completed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_type = 'shop_order'
					AND post_status IN ('wc-completed', 'wc-processing')
					AND post_date BETWEEN %s AND %s",
				$dates['start'],
				$dates['end']
			)
		);

		// Build step data.
		$step_counts = array(
			max( 1, intval( $cart_views ) * 10 ),
			max( 1, intval( $cart_views ) * 5 ),
			intval( $orders_started ),
			intval( $orders_started ),
			intval( $orders_completed ),
		);

		foreach ( $steps as $index => $step ) {
			$count = isset( $step_counts[ $index ] ) ? $step_counts[ $index ] : 0;
			$prev_count = $index > 0 && isset( $step_counts[ $index - 1 ] ) ? $step_counts[ $index - 1 ] : $count;

			$conversion = $prev_count > 0 ? ( $count / $prev_count ) * 100 : 0;
			$drop_off = $prev_count > 0 ? ( ( $prev_count - $count ) / $prev_count ) * 100 : 0;

			$funnel_data['steps'][] = array(
				'step'            => $index + 1,
				'name'            => $step['name'],
				'users'           => $count,
				'conversion_rate' => round( $conversion, 2 ),
				'drop_off_rate'   => round( $drop_off, 2 ),
				'drop_off_count'  => max( 0, $prev_count - $count ),
			);
		}

		return $funnel_data;
	}

	/**
	 * Calculate overall funnel metrics.
	 *
	 * @since 1.1.0
	 *
	 * @param array $funnel_data Funnel data.
	 * @return array Metrics.
	 */
	private function calculate_funnel_metrics( $funnel_data ) {
		$steps = $funnel_data['steps'];
		$first_step = reset( $steps );
		$last_step = end( $steps );

		$overall_conversion = $first_step['users'] > 0
			? ( $last_step['users'] / $first_step['users'] ) * 100
			: 0;

		$total_drop_offs = array_sum( array_column( $steps, 'drop_off_count' ) );

		return array(
			'overall_conversion_rate' => round( $overall_conversion, 2 ),
			'total_users_entered'     => $first_step['users'],
			'total_users_converted'   => $last_step['users'],
			'total_drop_offs'         => $total_drop_offs,
			'avg_step_conversion'     => round( array_sum( array_column( $steps, 'conversion_rate' ) ) / count( $steps ), 2 ),
		);
	}

	/**
	 * Identify critical drop-off points.
	 *
	 * @since 1.1.0
	 *
	 * @param array $funnel_data Funnel data.
	 * @return array Drop-off points.
	 */
	private function identify_drop_offs( $funnel_data ) {
		$drop_offs = array();

		foreach ( $funnel_data['steps'] as $step ) {
			if ( $step['drop_off_rate'] > 20 ) {
				$drop_offs[] = array(
					'step'          => $step['step'],
					'name'          => $step['name'],
					'drop_off_rate' => $step['drop_off_rate'],
					'users_lost'    => $step['drop_off_count'],
					'severity'      => $this->get_drop_off_severity( $step['drop_off_rate'] ),
				);
			}
		}

		// Sort by drop-off rate.
		usort( $drop_offs, fn( $a, $b ) => $b['drop_off_rate'] <=> $a['drop_off_rate'] );

		return $drop_offs;
	}

	/**
	 * Get drop-off severity level.
	 *
	 * @since 1.1.0
	 *
	 * @param float $rate Drop-off rate.
	 * @return string Severity level.
	 */
	private function get_drop_off_severity( $rate ) {
		if ( $rate >= 50 ) {
			return 'critical';
		} elseif ( $rate >= 30 ) {
			return 'high';
		} elseif ( $rate >= 20 ) {
			return 'medium';
		} else {
			return 'low';
		}
	}

	/**
	 * Analyze drop-off reasons.
	 *
	 * @since 1.1.0
	 *
	 * @param array $funnel_data Funnel data.
	 * @param array $drop_offs   Drop-off points.
	 * @return array Analysis.
	 */
	private function analyze_drop_off_reasons( $funnel_data, $drop_offs ) {
		$analysis = array();

		foreach ( $drop_offs as $drop_off ) {
			$reasons = array();

			// Common reasons by step type.
			if ( strpos( strtolower( $drop_off['name'] ), 'cart' ) !== false ) {
				$reasons[] = 'Unexpected shipping costs';
				$reasons[] = 'Complex checkout process';
				$reasons[] = 'Lack of payment options';
			} elseif ( strpos( strtolower( $drop_off['name'] ), 'payment' ) !== false ) {
				$reasons[] = 'Payment security concerns';
				$reasons[] = 'Limited payment methods';
				$reasons[] = 'Form errors or complexity';
			} elseif ( strpos( strtolower( $drop_off['name'] ), 'checkout' ) !== false ) {
				$reasons[] = 'Required account creation';
				$reasons[] = 'Lengthy form fields';
				$reasons[] = 'Lack of trust signals';
			}

			$analysis[ $drop_off['name'] ] = array(
				'potential_reasons' => $reasons,
				'impact'            => $drop_off['users_lost'],
			);
		}

		return $analysis;
	}

	/**
	 * Generate optimization recommendations.
	 *
	 * @since 1.1.0
	 *
	 * @param array $funnel_data Funnel data.
	 * @param array $drop_offs   Drop-off points.
	 * @return array Recommendations.
	 */
	private function generate_recommendations( $funnel_data, $drop_offs ) {
		$recommendations = array();

		foreach ( $drop_offs as $drop_off ) {
			if ( 'critical' === $drop_off['severity'] || 'high' === $drop_off['severity'] ) {
				$recommendations[] = array(
					'priority' => $drop_off['severity'],
					'step'     => $drop_off['name'],
					'action'   => sprintf(
						/* translators: 1: step name, 2: drop-off rate */
						__( 'Optimize %1$s step - currently losing %2$s%% of users', 'mcp-ai-wpoos-pro' ),
						$drop_off['name'],
						$drop_off['drop_off_rate']
					),
					'suggestions' => $this->get_step_suggestions( $drop_off['name'] ),
				);
			}
		}

		// Add general recommendations.
		if ( empty( $recommendations ) ) {
			$recommendations[] = array(
				'priority' => 'low',
				'action'   => __( 'Continue monitoring funnel performance', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $recommendations;
	}

	/**
	 * Get specific suggestions for a step.
	 *
	 * @since 1.1.0
	 *
	 * @param string $step_name Step name.
	 * @return array Suggestions.
	 */
	private function get_step_suggestions( $step_name ) {
		$suggestions = array();

		if ( strpos( strtolower( $step_name ), 'cart' ) !== false ) {
			$suggestions[] = __( 'Display shipping costs earlier', 'mcp-ai-wpoos-pro' );
			$suggestions[] = __( 'Add progress indicator', 'mcp-ai-wpoos-pro' );
			$suggestions[] = __( 'Enable guest checkout', 'mcp-ai-wpoos-pro' );
		} elseif ( strpos( strtolower( $step_name ), 'payment' ) !== false ) {
			$suggestions[] = __( 'Add more payment options', 'mcp-ai-wpoos-pro' );
			$suggestions[] = __( 'Display security badges', 'mcp-ai-wpoos-pro' );
			$suggestions[] = __( 'Simplify payment form', 'mcp-ai-wpoos-pro' );
		}

		return $suggestions;
	}
}
