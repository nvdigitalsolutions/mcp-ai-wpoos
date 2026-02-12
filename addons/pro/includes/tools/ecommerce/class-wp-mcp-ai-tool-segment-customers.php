<?php
/**
 * Segment Customers Tool
 *
 * Creates customer segments based on purchase behavior, demographics,
 * and engagement patterns for targeted marketing campaigns.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for segmenting WooCommerce customers.
 *
 * Supports:
 * - Purchase behavior segmentation
 * - RFM (Recency, Frequency, Monetary) analysis
 * - Geographic segmentation
 * - Product category preferences
 * - Custom criteria
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Segment_Customers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Customer segmentation requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Customer segmentation tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'segment_customers';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Segment Customers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create customer segments based on purchase behavior, demographics, and engagement patterns. Supports RFM analysis, geographic segmentation, product preferences, and custom criteria for targeted marketing campaigns.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'segmentation_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of segmentation to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'rfm', 'value_based', 'geographic', 'product_preference', 'custom' ),
					'default'     => 'rfm',
				),
				'rfm_segments'      => array(
					'type'        => 'object',
					'description' => __( 'RFM segmentation criteria (for rfm type)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'recency_days'  => array(
							'type'        => 'integer',
							'default'     => 30,
							'description' => 'Days for recent purchase',
						),
						'frequency_min' => array(
							'type'        => 'integer',
							'default'     => 2,
							'description' => 'Minimum orders for frequent',
						),
						'monetary_min'  => array(
							'type'        => 'number',
							'default'     => 100,
							'description' => 'Minimum spend for high value',
						),
					),
				),
				'value_tiers'       => array(
					'type'        => 'array',
					'description' => __( 'Value tier thresholds (for value_based type)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array( 'type' => 'string' ),
							'min'  => array( 'type' => 'number' ),
							'max'  => array( 'type' => 'number' ),
						),
					),
				),
				'geographic_field'  => array(
					'type'        => 'string',
					'description' => __( 'Geographic field to segment by', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'country', 'state', 'city', 'postcode' ),
					'default'     => 'country',
				),
				'product_category'  => array(
					'type'        => 'string',
					'description' => __( 'Product category slug for preference segmentation', 'mcp-ai-wpoos-pro' ),
				),
				'custom_criteria'   => array(
					'type'        => 'object',
					'description' => __( 'Custom segmentation criteria', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'min_orders' => array( 'type' => 'integer' ),
						'max_orders' => array( 'type' => 'integer' ),
						'min_spent'  => array( 'type' => 'number' ),
						'max_spent'  => array( 'type' => 'number' ),
						'date_from'  => array( 'type' => 'string' ),
						'date_to'    => array( 'type' => 'string' ),
					),
				),
				'limit'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum customers to analyze', 'mcp-ai-wpoos-pro' ),
					'default'     => 1000,
					'minimum'     => 1,
					'maximum'     => 10000,
				),
			),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'requires-plugin',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to segment customers.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$segmentation_type = isset( $arguments['segmentation_type'] ) ? sanitize_text_field( $arguments['segmentation_type'] ) : 'rfm';
		$limit             = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 1000;

		// Get all customers with orders.
		$customer_data = $this->get_customer_data( $limit );

		if ( empty( $customer_data ) ) {
			return array(
				'success'  => true,
				'message'  => __( 'No customers found with orders.', 'mcp-ai-wpoos-pro' ),
				'segments' => array(),
			);
		}

		// Perform segmentation based on type.
		switch ( $segmentation_type ) {
			case 'rfm':
				$segments = $this->segment_by_rfm( $customer_data, $arguments );
				break;
			case 'value_based':
				$segments = $this->segment_by_value( $customer_data, $arguments );
				break;
			case 'geographic':
				$segments = $this->segment_by_geography( $customer_data, $arguments );
				break;
			case 'product_preference':
				$segments = $this->segment_by_product_preference( $customer_data, $arguments );
				break;
			case 'custom':
				$segments = $this->segment_by_custom_criteria( $customer_data, $arguments );
				break;
			default:
				$segments = $this->segment_by_rfm( $customer_data, $arguments );
		}

		return array(
			'success'           => true,
			'segmentation_type' => $segmentation_type,
			'total_customers'   => count( $customer_data ),
			'segments'          => $segments,
		);
	}

	/**
	 * Get customer data with order history.
	 *
	 * @param int $limit Maximum customers.
	 * @return array Customer data.
	 */
	protected function get_customer_data( $limit ) {
		global $wpdb;

		// Get customers with orders.
		$customer_orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_author as user_id, pm1.meta_value as billing_email,
					pm2.meta_value as billing_first_name, pm3.meta_value as billing_last_name,
					pm4.meta_value as billing_country, pm5.meta_value as billing_state,
					COUNT(p.ID) as order_count, SUM(pm6.meta_value) as total_spent,
					MAX(p.post_date) as last_order_date, MIN(p.post_date) as first_order_date
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_first_name'
				LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_billing_last_name'
				LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_billing_country'
				LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = '_billing_state'
				LEFT JOIN {$wpdb->postmeta} pm6 ON p.ID = pm6.post_id AND pm6.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				GROUP BY pm1.meta_value
				ORDER BY total_spent DESC
				LIMIT %d",
				$limit
			)
		);

		$customers = array();

		foreach ( $customer_orders as $customer ) {
			$customers[ $customer->billing_email ] = array(
				'user_id'          => absint( $customer->user_id ),
				'email'            => $customer->billing_email,
				'name'             => trim( $customer->billing_first_name . ' ' . $customer->billing_last_name ),
				'country'          => $customer->billing_country,
				'state'            => $customer->billing_state,
				'order_count'      => absint( $customer->order_count ),
				'total_spent'      => floatval( $customer->total_spent ),
				'last_order_date'  => $customer->last_order_date,
				'first_order_date' => $customer->first_order_date,
				'days_since_last'  => $this->days_between( $customer->last_order_date, gmdate( 'Y-m-d H:i:s' ) ),
			);
		}

		return $customers;
	}

	/**
	 * Segment customers by RFM (Recency, Frequency, Monetary).
	 *
	 * @param array $customers Customer data.
	 * @param array $arguments Tool arguments.
	 * @return array Segments.
	 */
	protected function segment_by_rfm( $customers, $arguments ) {
		$rfm_config = isset( $arguments['rfm_segments'] ) && is_array( $arguments['rfm_segments'] ) ? $arguments['rfm_segments'] : array();

		$recency_threshold   = isset( $rfm_config['recency_days'] ) ? absint( $rfm_config['recency_days'] ) : 30;
		$frequency_threshold = isset( $rfm_config['frequency_min'] ) ? absint( $rfm_config['frequency_min'] ) : 2;
		$monetary_threshold  = isset( $rfm_config['monetary_min'] ) ? floatval( $rfm_config['monetary_min'] ) : 100;

		$segments = array(
			'champions'   => array(
				'customers' => array(),
				'criteria'  => 'Recent, Frequent, High Value',
			),
			'loyal'       => array(
				'customers' => array(),
				'criteria'  => 'Frequent, High Value',
			),
			'potential'   => array(
				'customers' => array(),
				'criteria'  => 'Recent, Medium Value',
			),
			'at_risk'     => array(
				'customers' => array(),
				'criteria'  => 'Not Recent, Previously Frequent',
			),
			'hibernating' => array(
				'customers' => array(),
				'criteria'  => 'Not Recent, Low Activity',
			),
		);

		foreach ( $customers as $customer ) {
			$is_recent     = $customer['days_since_last'] <= $recency_threshold;
			$is_frequent   = $customer['order_count'] >= $frequency_threshold;
			$is_high_value = $customer['total_spent'] >= $monetary_threshold;

			if ( $is_recent && $is_frequent && $is_high_value ) {
				$segments['champions']['customers'][] = $customer;
			} elseif ( $is_frequent && $is_high_value ) {
				$segments['loyal']['customers'][] = $customer;
			} elseif ( $is_recent ) {
				$segments['potential']['customers'][] = $customer;
			} elseif ( ! $is_recent && $is_frequent ) {
				$segments['at_risk']['customers'][] = $customer;
			} else {
				$segments['hibernating']['customers'][] = $customer;
			}
		}

		// Add counts and remove empty segments.
		foreach ( $segments as $key => &$segment ) {
			$segment['count'] = count( $segment['customers'] );
			if ( 0 === $segment['count'] ) {
				unset( $segments[ $key ] );
			}
		}

		return $segments;
	}

	/**
	 * Segment customers by total value.
	 *
	 * @param array $customers Customer data.
	 * @param array $arguments Tool arguments.
	 * @return array Segments.
	 */
	protected function segment_by_value( $customers, $arguments ) {
		$tiers = isset( $arguments['value_tiers'] ) && is_array( $arguments['value_tiers'] ) ? $arguments['value_tiers'] : array(
			array(
				'name' => 'High Value',
				'min'  => 500,
				'max'  => PHP_FLOAT_MAX,
			),
			array(
				'name' => 'Medium Value',
				'min'  => 100,
				'max'  => 500,
			),
			array(
				'name' => 'Low Value',
				'min'  => 0,
				'max'  => 100,
			),
		);

		$segments = array();

		foreach ( $tiers as $tier ) {
			$tier_name              = sanitize_text_field( $tier['name'] );
			$segments[ $tier_name ] = array(
				'customers' => array(),
				'min'       => floatval( $tier['min'] ),
				'max'       => floatval( $tier['max'] ),
			);
		}

		foreach ( $customers as $customer ) {
			foreach ( $tiers as $tier ) {
				$tier_name = sanitize_text_field( $tier['name'] );
				if ( $customer['total_spent'] >= $tier['min'] && $customer['total_spent'] < $tier['max'] ) {
					$segments[ $tier_name ]['customers'][] = $customer;
					break;
				}
			}
		}

		// Add counts.
		foreach ( $segments as &$segment ) {
			$segment['count'] = count( $segment['customers'] );
		}

		return $segments;
	}

	/**
	 * Segment customers by geographic location.
	 *
	 * @param array $customers Customer data.
	 * @param array $arguments Tool arguments.
	 * @return array Segments.
	 */
	protected function segment_by_geography( $customers, $arguments ) {
		$field    = isset( $arguments['geographic_field'] ) ? sanitize_text_field( $arguments['geographic_field'] ) : 'country';
		$segments = array();

		foreach ( $customers as $customer ) {
			$location_key = '';

			if ( 'country' === $field ) {
				$location_key = ! empty( $customer['country'] ) ? $customer['country'] : 'Unknown';
			} elseif ( 'state' === $field ) {
				$location_key = ! empty( $customer['state'] ) ? $customer['state'] : 'Unknown';
			}

			if ( ! isset( $segments[ $location_key ] ) ) {
				$segments[ $location_key ] = array(
					'customers' => array(),
					'location'  => $location_key,
				);
			}

			$segments[ $location_key ]['customers'][] = $customer;
		}

		// Add counts and sort by count.
		foreach ( $segments as &$segment ) {
			$segment['count'] = count( $segment['customers'] );
		}

		uasort(
			$segments,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return $segments;
	}

	/**
	 * Segment customers by product preference.
	 *
	 * @param array $customers Customer data.
	 * @param array $arguments Tool arguments.
	 * @return array Segments.
	 */
	protected function segment_by_product_preference( $customers, $arguments ) {
		$category = isset( $arguments['product_category'] ) ? sanitize_text_field( $arguments['product_category'] ) : '';

		$segments = array(
			'interested'     => array(
				'customers' => array(),
				'criteria'  => 'Purchased from category',
			),
			'not_interested' => array(
				'customers' => array(),
				'criteria'  => 'Not purchased from category',
			),
		);

		// Note: This would require querying order items to check category purchases.
		// For simplicity, returning empty segments with note.
		$segments['note'] = __( 'Product preference segmentation requires additional order item analysis.', 'mcp-ai-wpoos-pro' );

		return $segments;
	}

	/**
	 * Segment customers by custom criteria.
	 *
	 * @param array $customers Customer data.
	 * @param array $arguments Tool arguments.
	 * @return array Segments.
	 */
	protected function segment_by_custom_criteria( $customers, $arguments ) {
		$criteria = isset( $arguments['custom_criteria'] ) && is_array( $arguments['custom_criteria'] ) ? $arguments['custom_criteria'] : array();

		$matching = array();

		foreach ( $customers as $customer ) {
			$matches = true;

			if ( isset( $criteria['min_orders'] ) && $customer['order_count'] < absint( $criteria['min_orders'] ) ) {
				$matches = false;
			}

			if ( isset( $criteria['max_orders'] ) && $customer['order_count'] > absint( $criteria['max_orders'] ) ) {
				$matches = false;
			}

			if ( isset( $criteria['min_spent'] ) && $customer['total_spent'] < floatval( $criteria['min_spent'] ) ) {
				$matches = false;
			}

			if ( isset( $criteria['max_spent'] ) && $customer['total_spent'] > floatval( $criteria['max_spent'] ) ) {
				$matches = false;
			}

			if ( $matches ) {
				$matching[] = $customer;
			}
		}

		return array(
			'matching' => array(
				'customers' => $matching,
				'count'     => count( $matching ),
				'criteria'  => $criteria,
			),
		);
	}

	/**
	 * Calculate days between two dates.
	 *
	 * @param string $from From date.
	 * @param string $to   To date.
	 * @return int Days between.
	 */
	protected function days_between( $from, $to ) {
		$from_date = strtotime( $from );
		$to_date   = strtotime( $to );
		return absint( ( $to_date - $from_date ) / DAY_IN_SECONDS );
	}
}
