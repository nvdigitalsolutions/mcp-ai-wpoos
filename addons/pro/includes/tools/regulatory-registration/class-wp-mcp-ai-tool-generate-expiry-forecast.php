<?php
/**
 * Tool for generating registration expiry forecast reports.
 *
 * Allows AI assistants to generate renewal forecasting reports
 * for proactive registration management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates expiry forecast reports.
 */
class WP_MCP_AI_Tool_Generate_Expiry_Forecast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_expiry_forecast';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Expiry Forecast', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates registration expiry forecast report with renewal timeline, risk assessment, and proactive planning recommendations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'forecast_months' => array(
					'type'        => 'integer',
					'description' => __( 'Number of months to forecast (optional, default: 12)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 36,
					'default'     => 12,
				),
				'risk_threshold'  => array(
					'type'        => 'integer',
					'description' => __( 'Days before expiry to flag as high risk (optional, default: 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 30,
					'maximum'     => 180,
					'default'     => 90,
				),
				'countries'       => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate expiry forecasts.', 'mcp-ai-wpoos-pro' ) );
		}

		$forecast_months = ! empty( $arguments['forecast_months'] ) ? absint( $arguments['forecast_months'] ) : 12;
		$risk_threshold  = ! empty( $arguments['risk_threshold'] ) ? absint( $arguments['risk_threshold'] ) : 90;
		$countries       = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => 'expiry_date',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);

		// Add country filter.
		if ( ! empty( $countries ) ) {
			$query_args['meta_query'][] = array(
				'key'     => 'country',
				'value'   => $countries,
				'compare' => 'IN',
			);
		}

		$registrations_query = new WP_Query( $query_args );

		$today = time();
		$forecast_end = strtotime( "+{$forecast_months} months" );

		$expiry_data = array(
			'expired'      => array(),
			'high_risk'    => array(),
			'medium_risk'  => array(),
			'low_risk'     => array(),
			'by_month'     => array(),
			'by_country'   => array(),
		);

		// Initialize monthly buckets.
		for ( $i = 0; $i <= $forecast_months; $i++ ) {
			$month_key = gmdate( 'Y-m', strtotime( "+{$i} months" ) );
			$expiry_data['by_month'][ $month_key ] = 0;
		}

		if ( $registrations_query->have_posts() ) {
			foreach ( $registrations_query->posts as $post ) {
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				if ( ! $expiry_date ) {
					continue;
				}

				$expiry_time    = strtotime( $expiry_date );
				$days_to_expiry = floor( ( $expiry_time - $today ) / DAY_IN_SECONDS );
				$country        = get_post_meta( $post->ID, 'country', true );
				$product_id     = get_post_meta( $post->ID, 'product_id', true );

				$registration_data = array(
					'id'             => $post->ID,
					'title'          => $post->post_title,
					'country'        => $country,
					'expiry_date'    => $expiry_date,
					'days_to_expiry' => $days_to_expiry,
					'product_id'     => $product_id,
				);

				// Categorize by risk level.
				if ( $days_to_expiry < 0 ) {
					$expiry_data['expired'][] = $registration_data;
				} elseif ( $days_to_expiry <= $risk_threshold ) {
					$expiry_data['high_risk'][] = $registration_data;
				} elseif ( $days_to_expiry <= ( $risk_threshold * 2 ) ) {
					$expiry_data['medium_risk'][] = $registration_data;
				} elseif ( $expiry_time <= $forecast_end ) {
					$expiry_data['low_risk'][] = $registration_data;
				}

				// Group by month if within forecast period.
				if ( $expiry_time <= $forecast_end && $expiry_time >= $today ) {
					$month_key = gmdate( 'Y-m', $expiry_time );
					if ( isset( $expiry_data['by_month'][ $month_key ] ) ) {
						$expiry_data['by_month'][ $month_key ]++;
					}
				}

				// Group by country.
				if ( $country ) {
					if ( ! isset( $expiry_data['by_country'][ $country ] ) ) {
						$expiry_data['by_country'][ $country ] = 0;
					}
					$expiry_data['by_country'][ $country ]++;
				}
			}
		}

		// Generate recommendations.
		$recommendations = array();
		
		if ( count( $expiry_data['expired'] ) > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of expired registrations */
				__( 'Urgent: %d expired registrations require immediate renewal action.', 'mcp-ai-wpoos-pro' ),
				count( $expiry_data['expired'] )
			);
		}

		if ( count( $expiry_data['high_risk'] ) > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of high-risk registrations */
				__( 'High Priority: %d registrations expiring within %d days.', 'mcp-ai-wpoos-pro' ),
				count( $expiry_data['high_risk'] ),
				$risk_threshold
			);
		}

		if ( count( $expiry_data['medium_risk'] ) > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of medium-risk registrations */
				__( 'Planning Required: %d registrations expiring within %d days.', 'mcp-ai-wpoos-pro' ),
				count( $expiry_data['medium_risk'] ),
				$risk_threshold * 2
			);
		}

		return array(
			'success'          => true,
			'report_type'      => 'expiry_forecast',
			'generated_at'     => current_time( 'mysql' ),
			'forecast_months'  => $forecast_months,
			'risk_threshold'   => $risk_threshold,
			'summary'          => array(
				'total_expiring'  => count( $expiry_data['expired'] ) + count( $expiry_data['high_risk'] ) + count( $expiry_data['medium_risk'] ) + count( $expiry_data['low_risk'] ),
				'expired'         => count( $expiry_data['expired'] ),
				'high_risk'       => count( $expiry_data['high_risk'] ),
				'medium_risk'     => count( $expiry_data['medium_risk'] ),
				'low_risk'        => count( $expiry_data['low_risk'] ),
			),
			'expiry_data'      => $expiry_data,
			'recommendations'  => $recommendations,
		);
	}
}
