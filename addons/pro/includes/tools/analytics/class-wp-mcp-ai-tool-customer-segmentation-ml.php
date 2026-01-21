<?php
/**
 * Customer Segmentation ML Tool
 *
 * ML-based customer segmentation using clustering algorithms
 * to identify distinct customer groups.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for ML-based customer segmentation.
 *
 * Supports:
 * - K-means clustering
 * - RFM-based segmentation
 * - Behavioral clustering
 * - Segment profiling
 * - Actionable insights
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Customer_Segmentation_ML implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Advanced Analytics toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Customer segmentation ML tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	public function get_slug() {
		return 'customer_segmentation_ml';
	}

	public function get_name() {
		return __( 'ML Customer Segmentation', 'mcp-ai-wpoos-pro' );
	}

	public function get_description() {
		return __( 'ML-based customer segmentation using clustering algorithms. Identifies distinct customer groups based on RFM analysis, purchase behavior, and engagement patterns.', 'mcp-ai-wpoos-pro' );
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'num_segments'   => array(
					'type'        => 'integer',
					'description' => 'Number of customer segments to create',
					'minimum'     => 2,
					'maximum'     => 10,
					'default'     => 5,
				),
				'method'         => array(
					'type'        => 'string',
					'description' => 'Segmentation method: rfm, behavioral, hybrid',
					'enum'        => array( 'rfm', 'behavioral', 'hybrid' ),
					'default'     => 'rfm',
				),
				'min_orders'     => array(
					'type'        => 'integer',
					'description' => 'Minimum orders for customer inclusion',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 2,
				),
				'lookback_days'  => array(
					'type'        => 'integer',
					'description' => 'Days of data to analyze',
					'minimum'     => 30,
					'maximum'     => 730,
					'default'     => 365,
				),
			),
			'required'   => array(),
		);
	}

	public function get_required_capability() {
		return 'manage_options';
	}

	public function get_capability_flags() {
		return array(
			'analytics'  => true,
			'predictive' => true,
			'customers'  => true,
		);
	}

	public function execute( $arguments, $context ) {
		$num_segments  = isset( $arguments['num_segments'] ) ? absint( $arguments['num_segments'] ) : 5;
		$method        = ! empty( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'rfm';
		$min_orders    = isset( $arguments['min_orders'] ) ? absint( $arguments['min_orders'] ) : 2;
		$lookback_days = isset( $arguments['lookback_days'] ) ? absint( $arguments['lookback_days'] ) : 365;

		if ( $num_segments < 2 || $num_segments > 10 ) {
			return new WP_Error( 'invalid_segments', __( 'Number of segments must be between 2 and 10.', 'mcp-ai-wpoos-pro' ) );
		}

		// Collect customer data.
		$customers = $this->collect_customer_data( $lookback_days, $min_orders );

		if ( empty( $customers ) ) {
			return new WP_Error( 'insufficient_data', __( 'Insufficient customer data for segmentation.', 'mcp-ai-wpoos-pro' ) );
		}

		// Calculate features based on method.
		$features = $this->calculate_features( $customers, $method );

		// Perform clustering.
		$segments = $this->perform_clustering( $features, $num_segments );

		// Profile segments.
		$segment_profiles = $this->profile_segments( $segments, $customers );

		return array(
			'success'  => true,
			'segments' => $segment_profiles,
			'summary'  => array(
				'total_customers'  => count( $customers ),
				'num_segments'     => $num_segments,
				'method'           => $method,
				'lookback_days'    => $lookback_days,
			),
			'generated_at' => current_time( 'mysql' ),
			'message'  => sprintf(
				/* translators: 1: segment count, 2: customer count */
				__( 'Created %1$d customer segments from %2$d customers.', 'mcp-ai-wpoos-pro' ),
				$num_segments,
				count( $customers )
			),
		);
	}

	private function collect_customer_data( $lookback_days, $min_orders ) {
		global $wpdb;

		$cutoff_date = date( 'Y-m-d', strtotime( "-{$lookback_days} days" ) );

		$query = "
			SELECT 
				p.post_author as user_id,
				COUNT(DISTINCT p.ID) as order_count,
				MAX(p.post_date) as last_order_date,
				SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as total_spent,
				AVG(CAST(pm.meta_value AS DECIMAL(10,2))) as avg_order_value,
				MIN(p.post_date) as first_order_date
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND pm.meta_key = '_order_total'
				AND p.post_author > 0
				AND p.post_date >= %s
			GROUP BY user_id
			HAVING order_count >= %d
			ORDER BY total_spent DESC
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $query, $cutoff_date, $min_orders ), ARRAY_A );
	}

	private function calculate_features( $customers, $method ) {
		$features = array();

		foreach ( $customers as $customer ) {
			$recency   = ( time() - strtotime( $customer['last_order_date'] ) ) / DAY_IN_SECONDS;
			$frequency = intval( $customer['order_count'] );
			$monetary  = floatval( $customer['total_spent'] );

			if ( 'rfm' === $method ) {
				$features[ $customer['user_id'] ] = array( $recency, $frequency, $monetary );
			} elseif ( 'behavioral' === $method ) {
				$avg_days_between = ( strtotime( $customer['last_order_date'] ) - strtotime( $customer['first_order_date'] ) ) / DAY_IN_SECONDS / max( 1, $frequency - 1 );
				$features[ $customer['user_id'] ] = array( $recency, $frequency, $avg_days_between, $monetary );
			} else {
				$avg_order_value = floatval( $customer['avg_order_value'] );
				$features[ $customer['user_id'] ] = array( $recency, $frequency, $monetary, $avg_order_value );
			}
		}

		// Normalize features.
		return $this->normalize_features( $features );
	}

	private function normalize_features( $features ) {
		if ( empty( $features ) ) {
			return array();
		}

		$num_features = count( reset( $features ) );
		$normalized   = array();

		for ( $i = 0; $i < $num_features; $i++ ) {
			$column = array_column( $features, $i );
			$min    = min( $column );
			$max    = max( $column );
			$range  = $max - $min;

			foreach ( $features as $user_id => $feature_vector ) {
				$normalized[ $user_id ][ $i ] = $range > 0 ? ( $feature_vector[ $i ] - $min ) / $range : 0;
			}
		}

		return $normalized;
	}

	private function perform_clustering( $features, $k ) {
		if ( empty( $features ) || $k < 2 ) {
			return array();
		}

		$user_ids = array_keys( $features );
		$data     = array_values( $features );

		// Initialize centroids randomly.
		$centroid_indices = array_rand( $data, min( $k, count( $data ) ) );
		if ( ! is_array( $centroid_indices ) ) {
			$centroid_indices = array( $centroid_indices );
		}

		$centroids = array();
		foreach ( $centroid_indices as $idx ) {
			$centroids[] = $data[ $idx ];
		}

		$assignments = array();
		$max_iterations = 100;

		// K-means algorithm.
		for ( $iter = 0; $iter < $max_iterations; $iter++ ) {
			$new_assignments = array();

			// Assign points to nearest centroid.
			foreach ( $data as $idx => $point ) {
				$min_distance = PHP_FLOAT_MAX;
				$nearest_cluster = 0;

				foreach ( $centroids as $cluster_id => $centroid ) {
					$distance = $this->euclidean_distance( $point, $centroid );
					if ( $distance < $min_distance ) {
						$min_distance = $distance;
						$nearest_cluster = $cluster_id;
					}
				}

				$new_assignments[ $user_ids[ $idx ] ] = $nearest_cluster;
			}

			// Check convergence.
			if ( $new_assignments === $assignments ) {
				break;
			}

			$assignments = $new_assignments;

			// Update centroids.
			for ( $cluster_id = 0; $cluster_id < $k; $cluster_id++ ) {
				$cluster_points = array();
				foreach ( $assignments as $user_id => $assigned_cluster ) {
					if ( $assigned_cluster === $cluster_id ) {
						$user_idx = array_search( $user_id, $user_ids, true );
						if ( false !== $user_idx ) {
							$cluster_points[] = $data[ $user_idx ];
						}
					}
				}

				if ( ! empty( $cluster_points ) ) {
					$centroids[ $cluster_id ] = $this->calculate_centroid( $cluster_points );
				}
			}
		}

		return $assignments;
	}

	private function euclidean_distance( $point1, $point2 ) {
		$sum = 0;
		for ( $i = 0; $i < count( $point1 ); $i++ ) {
			$sum += pow( $point1[ $i ] - $point2[ $i ], 2 );
		}
		return sqrt( $sum );
	}

	private function calculate_centroid( $points ) {
		$num_features = count( $points[0] );
		$centroid = array_fill( 0, $num_features, 0 );

		foreach ( $points as $point ) {
			for ( $i = 0; $i < $num_features; $i++ ) {
				$centroid[ $i ] += $point[ $i ];
			}
		}

		$count = count( $points );
		for ( $i = 0; $i < $num_features; $i++ ) {
			$centroid[ $i ] /= $count;
		}

		return $centroid;
	}

	private function profile_segments( $segments, $customers ) {
		$customer_map = array();
		foreach ( $customers as $customer ) {
			$customer_map[ $customer['user_id'] ] = $customer;
		}

		$profiles = array();
		$segment_stats = array();

		// Group by segment.
		foreach ( $segments as $user_id => $segment_id ) {
			if ( ! isset( $segment_stats[ $segment_id ] ) ) {
				$segment_stats[ $segment_id ] = array(
					'customers' => array(),
					'total_spent' => 0,
					'total_orders' => 0,
				);
			}

			$customer = $customer_map[ $user_id ];
			$segment_stats[ $segment_id ]['customers'][] = $user_id;
			$segment_stats[ $segment_id ]['total_spent'] += floatval( $customer['total_spent'] );
			$segment_stats[ $segment_id ]['total_orders'] += intval( $customer['order_count'] );
		}

		// Create profiles.
		foreach ( $segment_stats as $segment_id => $stats ) {
			$count = count( $stats['customers'] );
			$avg_spent = $count > 0 ? $stats['total_spent'] / $count : 0;
			$avg_orders = $count > 0 ? $stats['total_orders'] / $count : 0;

			$label = $this->generate_segment_label( $avg_spent, $avg_orders );

			$profiles[] = array(
				'segment_id'    => $segment_id,
				'label'         => $label,
				'customer_count' => $count,
				'avg_spent'     => round( $avg_spent, 2 ),
				'avg_orders'    => round( $avg_orders, 2 ),
				'total_value'   => round( $stats['total_spent'], 2 ),
				'percentage'    => round( ( $count / count( $customers ) ) * 100, 2 ),
			);
		}

		// Sort by value.
		usort( $profiles, fn( $a, $b ) => $b['total_value'] - $a['total_value'] );

		return $profiles;
	}

	private function generate_segment_label( $avg_spent, $avg_orders ) {
		if ( $avg_spent > 500 && $avg_orders > 5 ) {
			return 'VIP Champions';
		} elseif ( $avg_spent > 300 && $avg_orders > 3 ) {
			return 'Loyal Customers';
		} elseif ( $avg_spent > 200 ) {
			return 'Big Spenders';
		} elseif ( $avg_orders > 5 ) {
			return 'Frequent Buyers';
		} elseif ( $avg_orders < 3 ) {
			return 'New/Occasional';
		} else {
			return 'Regular Customers';
		}
	}
}
