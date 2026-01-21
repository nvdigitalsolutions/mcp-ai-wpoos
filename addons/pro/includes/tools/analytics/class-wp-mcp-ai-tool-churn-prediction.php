<?php
/**
 * Churn Prediction Tool
 *
 * Identifies customers at risk of churning using behavioral
 * analysis and predictive modeling.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for predicting customer churn risk.
 *
 * Supports:
 * - Behavioral pattern analysis
 * - Recency/Frequency/Monetary scoring
 * - Engagement tracking
 * - Risk scoring
 * - Intervention recommendations
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Churn_Prediction implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Churn prediction tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'churn_prediction';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Predict Customer Churn', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Identify customers at risk of churning using behavioral analysis and RFM scoring. Provides risk scores, intervention recommendations, and customer retention strategies.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'min_risk_score'   => array(
					'type'        => 'integer',
					'description' => 'Minimum churn risk score to include (0-100)',
					'minimum'     => 0,
					'maximum'     => 100,
					'default'     => 50,
				),
				'lookback_days'    => array(
					'type'        => 'integer',
					'description' => 'Days to analyze for behavior patterns',
					'minimum'     => 30,
					'maximum'     => 365,
					'default'     => 90,
				),
				'customer_type'    => array(
					'type'        => 'string',
					'description' => 'Type of customers: all, high_value, regular, new',
					'enum'        => array( 'all', 'high_value', 'regular', 'new' ),
					'default'     => 'all',
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => 'Maximum number of at-risk customers to return',
					'minimum'     => 1,
					'maximum'     => 1000,
					'default'     => 50,
				),
				'include_recommendations' => array(
					'type'        => 'boolean',
					'description' => 'Include intervention recommendations',
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Get capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'analytics'  => true,
			'predictive' => true,
			'customers'  => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( $arguments, $context ) {
		// Parse arguments.
		$min_risk_score   = isset( $arguments['min_risk_score'] ) ? absint( $arguments['min_risk_score'] ) : 50;
		$lookback_days    = isset( $arguments['lookback_days'] ) ? absint( $arguments['lookback_days'] ) : 90;
		$customer_type    = ! empty( $arguments['customer_type'] ) ? sanitize_text_field( $arguments['customer_type'] ) : 'all';
		$limit            = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$include_recommendations = ! isset( $arguments['include_recommendations'] ) || $arguments['include_recommendations'];

		// Validate parameters.
		if ( $min_risk_score < 0 || $min_risk_score > 100 ) {
			return new WP_Error(
				'invalid_risk_score',
				__( 'Risk score must be between 0 and 100.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $lookback_days < 30 || $lookback_days > 365 ) {
			return new WP_Error(
				'invalid_lookback_days',
				__( 'Lookback days must be between 30 and 365.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $limit < 1 || $limit > 1000 ) {
			return new WP_Error(
				'invalid_limit',
				__( 'Limit must be between 1 and 1000.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get customers with activity data.
		$customers = $this->get_customers_with_activity( $lookback_days, $customer_type );

		if ( is_wp_error( $customers ) ) {
			return $customers;
		}

		// Calculate churn risk for each customer.
		$at_risk_customers = array();

		foreach ( $customers as $customer ) {
			$risk_score = $this->calculate_churn_risk( $customer, $lookback_days );

			if ( $risk_score >= $min_risk_score ) {
				$customer['churn_risk_score'] = $risk_score;
				$customer['risk_level']       = $this->get_risk_level( $risk_score );
				$customer['risk_factors']     = $this->identify_risk_factors( $customer, $lookback_days );

				if ( $include_recommendations ) {
					$customer['recommendations'] = $this->generate_recommendations( $customer );
				}

				$at_risk_customers[] = $customer;
			}
		}

		// Sort by risk score (highest first).
		usort( $at_risk_customers, fn( $a, $b ) => $b['churn_risk_score'] - $a['churn_risk_score'] );

		// Limit results.
		$at_risk_customers = array_slice( $at_risk_customers, 0, $limit );

		// Calculate summary statistics.
		$summary = $this->calculate_summary( $at_risk_customers, count( $customers ) );

		return array(
			'success'           => true,
			'at_risk_customers' => $at_risk_customers,
			'summary'           => $summary,
			'parameters'        => array(
				'min_risk_score' => $min_risk_score,
				'lookback_days'  => $lookback_days,
				'customer_type'  => $customer_type,
			),
			'analyzed_at'       => current_time( 'mysql' ),
			'message'           => sprintf(
				/* translators: 1: count of at-risk customers, 2: total customers */
				__( 'Identified %1$d at-risk customers out of %2$d analyzed.', 'mcp-ai-wpoos-pro' ),
				count( $at_risk_customers ),
				count( $customers )
			),
		);
	}

	/**
	 * Get customers with activity data.
	 *
	 * @param int    $lookback_days Days to look back.
	 * @param string $customer_type Customer type filter.
	 * @return array|WP_Error Customer data or error.
	 */
	private function get_customers_with_activity( $lookback_days, $customer_type ) {
		global $wpdb;

		$cutoff_date = date( 'Y-m-d', strtotime( "-{$lookback_days} days" ) );

		// Get customers with orders.
		$query = "
			SELECT 
				p.post_author as user_id,
				u.user_email,
				u.display_name,
				COUNT(DISTINCT p.ID) as total_orders,
				SUM(CASE WHEN p.post_date >= %s THEN 1 ELSE 0 END) as recent_orders,
				MAX(p.post_date) as last_order_date,
				SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as total_spent,
				MIN(p.post_date) as first_order_date
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND pm.meta_key = '_order_total'
				AND p.post_author > 0
		";

		// Add customer type filter.
		if ( 'high_value' === $customer_type ) {
			$query .= " AND CAST(pm.meta_value AS DECIMAL(10,2)) > 500";
		} elseif ( 'new' === $customer_type ) {
			$new_customer_date = date( 'Y-m-d', strtotime( '-6 months' ) );
			$query .= $wpdb->prepare( " AND p.post_date >= %s", $new_customer_date );
		}

		$query .= " GROUP BY user_id HAVING total_orders >= 2 ORDER BY last_order_date DESC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $cutoff_date ), ARRAY_A );

		if ( ! $results ) {
			return array();
		}

		return $results;
	}

	/**
	 * Calculate churn risk score (0-100).
	 *
	 * @param array $customer      Customer data.
	 * @param int   $lookback_days Days analyzed.
	 * @return int Risk score.
	 */
	private function calculate_churn_risk( $customer, $lookback_days ) {
		$score = 0;

		// Recency factor (0-40 points).
		$days_since_order = ( time() - strtotime( $customer['last_order_date'] ) ) / DAY_IN_SECONDS;
		$recency_score    = min( 40, ( $days_since_order / $lookback_days ) * 40 );
		$score += $recency_score;

		// Frequency decline factor (0-30 points).
		$customer_lifetime_days = ( time() - strtotime( $customer['first_order_date'] ) ) / DAY_IN_SECONDS;
		$expected_frequency     = max( 1, ( $customer['total_orders'] / $customer_lifetime_days ) * $lookback_days );
		$actual_frequency       = intval( $customer['recent_orders'] );
		$frequency_decline      = max( 0, min( 30, ( ( $expected_frequency - $actual_frequency ) / $expected_frequency ) * 30 ) );
		$score += $frequency_decline;

		// Engagement decline factor (0-20 points).
		if ( $customer['recent_orders'] == 0 ) {
			$score += 20;
		} elseif ( $customer['recent_orders'] == 1 && $customer['total_orders'] > 5 ) {
			$score += 10;
		}

		// Value decline factor (0-10 points).
		$avg_order_value = $customer['total_spent'] / $customer['total_orders'];
		if ( $avg_order_value > 100 && $customer['recent_orders'] == 0 ) {
			$score += 10;
		} elseif ( $avg_order_value > 50 && $customer['recent_orders'] <= 1 ) {
			$score += 5;
		}

		return min( 100, round( $score ) );
	}

	/**
	 * Get risk level label.
	 *
	 * @param int $score Risk score.
	 * @return string Risk level.
	 */
	private function get_risk_level( $score ) {
		if ( $score >= 80 ) {
			return 'critical';
		} elseif ( $score >= 60 ) {
			return 'high';
		} elseif ( $score >= 40 ) {
			return 'medium';
		} else {
			return 'low';
		}
	}

	/**
	 * Identify specific risk factors.
	 *
	 * @param array $customer      Customer data.
	 * @param int   $lookback_days Days analyzed.
	 * @return array Risk factors.
	 */
	private function identify_risk_factors( $customer, $lookback_days ) {
		$factors = array();

		$days_since_order = ( time() - strtotime( $customer['last_order_date'] ) ) / DAY_IN_SECONDS;

		if ( $days_since_order > $lookback_days * 0.75 ) {
			$factors[] = 'inactive_for_extended_period';
		}

		if ( $customer['recent_orders'] == 0 ) {
			$factors[] = 'no_recent_purchases';
		}

		if ( $customer['recent_orders'] < $customer['total_orders'] * 0.2 ) {
			$factors[] = 'declining_purchase_frequency';
		}

		$avg_order_value = $customer['total_spent'] / $customer['total_orders'];
		if ( $avg_order_value > 100 && $customer['recent_orders'] == 0 ) {
			$factors[] = 'high_value_customer_at_risk';
		}

		if ( $customer['total_orders'] > 10 && $customer['recent_orders'] <= 1 ) {
			$factors[] = 'loyal_customer_disengaging';
		}

		return $factors;
	}

	/**
	 * Generate intervention recommendations.
	 *
	 * @param array $customer Customer data.
	 * @return array Recommendations.
	 */
	private function generate_recommendations( $customer ) {
		$recommendations = array();

		if ( in_array( 'high_value_customer_at_risk', $customer['risk_factors'], true ) ) {
			$recommendations[] = array(
				'priority' => 'high',
				'action'   => 'personal_outreach',
				'details'  => 'Send personalized email with exclusive VIP offer',
			);
		}

		if ( in_array( 'inactive_for_extended_period', $customer['risk_factors'], true ) ) {
			$recommendations[] = array(
				'priority' => 'high',
				'action'   => 'win_back_campaign',
				'details'  => 'Offer "We miss you" discount (15-20% off)',
			);
		}

		if ( in_array( 'no_recent_purchases', $customer['risk_factors'], true ) ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'action'   => 'product_recommendations',
				'details'  => 'Send targeted product suggestions based on past purchases',
			);
		}

		if ( in_array( 'declining_purchase_frequency', $customer['risk_factors'], true ) ) {
			$recommendations[] = array(
				'priority' => 'medium',
				'action'   => 'loyalty_incentive',
				'details'  => 'Offer loyalty points or subscription discount',
			);
		}

		if ( in_array( 'loyal_customer_disengaging', $customer['risk_factors'], true ) ) {
			$recommendations[] = array(
				'priority' => 'high',
				'action'   => 'feedback_request',
				'details'  => 'Request feedback to understand concerns',
			);
		}

		// Add general recommendation if no specific ones.
		if ( empty( $recommendations ) ) {
			$recommendations[] = array(
				'priority' => 'low',
				'action'   => 'engagement_campaign',
				'details'  => 'Include in general re-engagement email campaign',
			);
		}

		return $recommendations;
	}

	/**
	 * Calculate summary statistics.
	 *
	 * @param array $at_risk_customers At-risk customers.
	 * @param int   $total_customers   Total customers analyzed.
	 * @return array Summary data.
	 */
	private function calculate_summary( $at_risk_customers, $total_customers ) {
		$risk_levels = array(
			'critical' => 0,
			'high'     => 0,
			'medium'   => 0,
			'low'      => 0,
		);

		$total_risk_value = 0;

		foreach ( $at_risk_customers as $customer ) {
			$risk_levels[ $customer['risk_level'] ]++;
			$total_risk_value += floatval( $customer['total_spent'] );
		}

		$avg_risk_score = count( $at_risk_customers ) > 0
			? array_sum( array_column( $at_risk_customers, 'churn_risk_score' ) ) / count( $at_risk_customers )
			: 0;

		return array(
			'total_at_risk'      => count( $at_risk_customers ),
			'total_analyzed'     => $total_customers,
			'churn_rate'         => $total_customers > 0 ? round( ( count( $at_risk_customers ) / $total_customers ) * 100, 2 ) : 0,
			'risk_levels'        => $risk_levels,
			'avg_risk_score'     => round( $avg_risk_score, 2 ),
			'potential_revenue_at_risk' => round( $total_risk_value, 2 ),
		);
	}
}
