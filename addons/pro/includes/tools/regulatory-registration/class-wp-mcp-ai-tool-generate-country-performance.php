<?php
/**
 * Tool for generating country performance metrics reports.
 *
 * Allows AI assistants to analyze registration performance
 * across different countries and jurisdictions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates country performance reports.
 */
class WP_MCP_AI_Tool_Generate_Country_Performance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_country_performance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Country Performance Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates country-level performance metrics including approval rates, processing times, compliance status, and jurisdiction comparisons.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'countries'         => array(
					'type'        => 'array',
					'description' => __( 'Specific countries to analyze (optional, analyzes all if not provided)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'include_comparison' => array(
					'type'        => 'boolean',
					'description' => __( 'Include country comparison (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'sort_by'           => array(
					'type'        => 'string',
					'description' => __( 'Sort results by metric (optional, default: "total")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'total', 'approval_rate', 'avg_approval_days', 'active' ),
					'default'     => 'total',
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate country performance reports.', 'mcp-ai-wpoos-pro' ) );
		}

		$countries          = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();
		$include_comparison = isset( $arguments['include_comparison'] ) ? (bool) $arguments['include_comparison'] : true;
		$sort_by            = ! empty( $arguments['sort_by'] ) ? sanitize_text_field( $arguments['sort_by'] ) : 'total';

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		// Add country filter.
		if ( ! empty( $countries ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => 'country',
					'value'   => $countries,
					'compare' => 'IN',
				),
			);
		}

		$registrations_query = new WP_Query( $query_args );

		$country_data = array();

		if ( $registrations_query->have_posts() ) {
			foreach ( $registrations_query->posts as $post ) {
				$country = get_post_meta( $post->ID, 'country', true );
				if ( ! $country ) {
					$country = 'Unknown';
				}

				if ( ! isset( $country_data[ $country ] ) ) {
					$country_data[ $country ] = array(
						'total'            => 0,
						'active'           => 0,
						'pending'          => 0,
						'expired'          => 0,
						'approval_days'    => array(),
						'authority'        => get_post_meta( $post->ID, 'authority', true ),
					);
				}

				$country_data[ $country ]['total']++;

				// Get status.
				$statuses = wp_get_post_terms( $post->ID, 'mcp_ai_reg_status' );
				if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
					$status_slug = $statuses[0]->slug;
					if ( in_array( $status_slug, array( 'approved', 'active' ), true ) ) {
						$country_data[ $country ]['active']++;
					} elseif ( in_array( $status_slug, array( 'submitted', 'pending', 'under-review' ), true ) ) {
						$country_data[ $country ]['pending']++;
					}
				}

				// Check expiry.
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				if ( $expiry_date && strtotime( $expiry_date ) < time() ) {
					$country_data[ $country ]['expired']++;
				}

				// Calculate approval time.
				$submission_date = get_post_meta( $post->ID, 'submission_date', true );
				$approval_date   = get_post_meta( $post->ID, 'approval_date', true );
				if ( $submission_date && $approval_date ) {
					$days = floor( ( strtotime( $approval_date ) - strtotime( $submission_date ) ) / DAY_IN_SECONDS );
					$country_data[ $country ]['approval_days'][] = $days;
				}
			}
		}

		// Calculate metrics for each country.
		$performance_metrics = array();
		foreach ( $country_data as $country => $data ) {
			$approval_rate = $data['total'] > 0 ? round( ( $data['active'] / $data['total'] ) * 100, 2 ) : 0;
			$avg_approval_days = ! empty( $data['approval_days'] ) ? round( array_sum( $data['approval_days'] ) / count( $data['approval_days'] ), 1 ) : 0;

			$performance_metrics[ $country ] = array(
				'country'              => $country,
				'authority'            => $data['authority'],
				'total_registrations'  => $data['total'],
				'active'               => $data['active'],
				'pending'              => $data['pending'],
				'expired'              => $data['expired'],
				'approval_rate'        => $approval_rate,
				'avg_approval_days'    => $avg_approval_days,
				'compliance_score'     => $this->calculate_compliance_score( $data, $approval_rate ),
			);
		}

		// Sort results.
		uasort(
			$performance_metrics,
			function ( $a, $b ) use ( $sort_by ) {
				return $b[ $sort_by ] <=> $a[ $sort_by ];
			}
		);

		// Generate comparison if requested.
		$comparison = array();
		if ( $include_comparison && count( $performance_metrics ) > 1 ) {
			$comparison = $this->generate_comparison( $performance_metrics );
		}

		return array(
			'success'      => true,
			'report_type'  => 'country_performance',
			'generated_at' => current_time( 'mysql' ),
			'total_countries' => count( $performance_metrics ),
			'metrics'      => $performance_metrics,
			'comparison'   => $comparison,
			'sorted_by'    => $sort_by,
		);
	}

	/**
	 * Calculate compliance score.
	 *
	 * @param array $data          Country data.
	 * @param float $approval_rate Approval rate.
	 * @return float Compliance score.
	 */
	private function calculate_compliance_score( $data, $approval_rate ) {
		$score = $approval_rate * 0.6; // Approval rate weight: 60%.

		if ( $data['total'] > 0 ) {
			$expired_ratio = $data['expired'] / $data['total'];
			$score += ( 1 - $expired_ratio ) * 40; // Expiry compliance weight: 40%.
		}

		return round( $score, 2 );
	}

	/**
	 * Generate comparison data.
	 *
	 * @param array $metrics Performance metrics.
	 * @return array Comparison data.
	 */
	private function generate_comparison( $metrics ) {
		$totals = array_column( $metrics, 'total_registrations' );
		$approval_rates = array_column( $metrics, 'approval_rate' );
		$approval_days = array_column( $metrics, 'avg_approval_days' );

		return array(
			'highest_volume'        => array_keys( $metrics )[0],
			'highest_approval_rate' => array_keys( $metrics, max( array_column( $metrics, 'approval_rate' ) ) )[0],
			'fastest_processing'    => array_keys( $metrics, min( array_filter( $approval_days ) ) )[0],
			'avg_approval_rate'     => round( array_sum( $approval_rates ) / count( $approval_rates ), 2 ),
			'avg_processing_days'   => round( array_sum( array_filter( $approval_days ) ) / count( array_filter( $approval_days ) ), 1 ),
		);
	}
}
