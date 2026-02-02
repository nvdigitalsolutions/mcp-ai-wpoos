<?php
/**
 * Tool for generating cost analysis reports for regulatory registrations.
 *
 * Allows AI assistants to track and analyze financial costs
 * associated with regulatory registrations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates cost analysis reports.
 */
class WP_MCP_AI_Tool_Generate_Cost_Analysis implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_cost_analysis';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Cost Analysis', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates financial cost analysis report tracking registration fees, renewal costs, and budget allocation across countries and products.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'date_range'       => array(
					'type'        => 'string',
					'description' => __( 'Date range for analysis (optional, default: "last_year")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'last_month', 'last_quarter', 'last_year', 'custom' ),
					'default'     => 'last_year',
				),
				'start_date'       => array(
					'type'        => 'string',
					'description' => __( 'Start date for custom range (format: YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'         => array(
					'type'        => 'string',
					'description' => __( 'End date for custom range (format: YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'grouping'         => array(
					'type'        => 'string',
					'description' => __( 'Group costs by (optional, default: "country")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'country', 'product', 'type', 'month' ),
					'default'     => 'country',
				),
				'include_forecast' => array(
					'type'        => 'boolean',
					'description' => __( 'Include future renewal cost forecast (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate cost analysis reports.', 'mcp-ai-wpoos-pro' ) );
		}

		$date_range       = ! empty( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'last_year';
		$start_date       = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date         = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$grouping         = ! empty( $arguments['grouping'] ) ? sanitize_text_field( $arguments['grouping'] ) : 'country';
		$include_forecast = isset( $arguments['include_forecast'] ) ? (bool) $arguments['include_forecast'] : true;

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		// Add date filter.
		if ( 'custom' === $date_range && $start_date && $end_date ) {
			$query_args['date_query'] = array(
				array(
					'after'     => $start_date,
					'before'    => $end_date,
					'inclusive' => true,
				),
			);
		} elseif ( 'last_month' === $date_range ) {
			$query_args['date_query'] = array( array( 'after' => '1 month ago' ) );
		} elseif ( 'last_quarter' === $date_range ) {
			$query_args['date_query'] = array( array( 'after' => '3 months ago' ) );
		} elseif ( 'last_year' === $date_range ) {
			$query_args['date_query'] = array( array( 'after' => '1 year ago' ) );
		}

		$registrations_query = new WP_Query( $query_args );

		$cost_data   = array();
		$total_costs = 0;

		if ( $registrations_query->have_posts() ) {
			foreach ( $registrations_query->posts as $post ) {
				// Get cost data (stored in post meta).
				$registration_fee = (float) get_post_meta( $post->ID, '_registration_fee', true );
				$renewal_fee      = (float) get_post_meta( $post->ID, '_renewal_fee', true );
				$additional_fees  = (float) get_post_meta( $post->ID, '_additional_fees', true );
				$total_fee        = $registration_fee + $renewal_fee + $additional_fees;

				$total_costs += $total_fee;

				// Get grouping key.
				$group_key = '';
				switch ( $grouping ) {
					case 'country':
						$group_key = get_post_meta( $post->ID, 'country', true );
						if ( ! $group_key ) {
							$group_key = 'Unknown';
						}
						break;
					case 'product':
						$product_id = get_post_meta( $post->ID, 'product_id', true );
						if ( $product_id ) {
							$product   = get_post( $product_id );
							$group_key = $product ? $product->post_title : 'Unknown';
						} else {
							$group_key = 'Unknown';
						}
						break;
					case 'type':
						$group_key = get_post_meta( $post->ID, 'registration_type', true );
						if ( ! $group_key ) {
							$group_key = 'Unknown';
						}
						break;
					case 'month':
						$group_key = gmdate( 'Y-m', strtotime( $post->post_date ) );
						break;
				}

				if ( ! isset( $cost_data[ $group_key ] ) ) {
					$cost_data[ $group_key ] = array(
						'count'             => 0,
						'registration_fees' => 0,
						'renewal_fees'      => 0,
						'additional_fees'   => 0,
						'total'             => 0,
					);
				}

				++$cost_data[ $group_key ]['count'];
				$cost_data[ $group_key ]['registration_fees'] += $registration_fee;
				$cost_data[ $group_key ]['renewal_fees']      += $renewal_fee;
				$cost_data[ $group_key ]['additional_fees']   += $additional_fees;
				$cost_data[ $group_key ]['total']             += $total_fee;
			}
		}

		// Generate forecast if requested.
		$forecast = array();
		if ( $include_forecast ) {
			// Get registrations expiring in next 12 months.
			$forecast_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_registration',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_query'     => array(
						array(
							'key'     => 'expiry_date',
							'value'   => array(
								gmdate( 'Y-m-d' ),
								gmdate( 'Y-m-d', strtotime( '+12 months' ) ),
							),
							'compare' => 'BETWEEN',
							'type'    => 'DATE',
						),
					),
				)
			);

			$forecast_total = 0;
			foreach ( $forecast_query->posts as $post ) {
				$renewal_fee     = (float) get_post_meta( $post->ID, '_renewal_fee', true );
				$forecast_total += $renewal_fee;

				$expiry_month = gmdate( 'Y-m', strtotime( get_post_meta( $post->ID, 'expiry_date', true ) ) );
				if ( ! isset( $forecast[ $expiry_month ] ) ) {
					$forecast[ $expiry_month ] = 0;
				}
				$forecast[ $expiry_month ] += $renewal_fee;
			}

			ksort( $forecast );
		}

		// Calculate average cost per registration.
		$avg_cost_per_registration = $registrations_query->found_posts > 0 ? round( $total_costs / $registrations_query->found_posts, 2 ) : 0;

		return array(
			'success'                 => true,
			'report_type'             => 'cost_analysis',
			'generated_at'            => current_time( 'mysql' ),
			'date_range'              => $date_range,
			'grouping'                => $grouping,
			'summary'                 => array(
				'total_registrations'       => $registrations_query->found_posts,
				'total_costs'               => round( $total_costs, 2 ),
				'avg_cost_per_registration' => $avg_cost_per_registration,
			),
			'cost_breakdown'          => $cost_data,
			'forecast'                => $forecast,
			'forecast_total_next_12m' => isset( $forecast_total ) ? round( $forecast_total, 2 ) : 0,
		);
	}
}
