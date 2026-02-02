<?php
/**
 * Tool for generating compliance dashboard reports.
 *
 * Allows AI assistants to generate comprehensive compliance reports
 * with key metrics and status overview.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates compliance dashboard reports.
 */
class WP_MCP_AI_Tool_Generate_Compliance_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_compliance_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Compliance Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates comprehensive compliance dashboard report with registration status metrics, expiry warnings, and compliance overview across all countries.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'date_range' => array(
					'type'        => 'string',
					'description' => __( 'Date range for report (optional, default: "all")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'last_30_days', 'last_90_days', 'last_year', 'custom' ),
					'default'     => 'all',
				),
				'start_date' => array(
					'type'        => 'string',
					'description' => __( 'Start date for custom range (format: YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => __( 'End date for custom range (format: YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'countries'  => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'format'     => array(
					'type'        => 'string',
					'description' => __( 'Report format (optional, default: "json")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'json', 'pdf', 'excel' ),
					'default'     => 'json',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate compliance reports.', 'mcp-ai-wpoos-pro' ) );
		}

		$date_range = ! empty( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'all';
		$start_date = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date   = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$countries  = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();
		$format     = ! empty( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'json';

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		// Add date filter if specified.
		if ( 'custom' === $date_range && $start_date && $end_date ) {
			$query_args['date_query'] = array(
				array(
					'after'     => $start_date,
					'before'    => $end_date,
					'inclusive' => true,
				),
			);
		} elseif ( 'last_30_days' === $date_range ) {
			$query_args['date_query'] = array(
				array(
					'after' => '30 days ago',
				),
			);
		} elseif ( 'last_90_days' === $date_range ) {
			$query_args['date_query'] = array(
				array(
					'after' => '90 days ago',
				),
			);
		} elseif ( 'last_year' === $date_range ) {
			$query_args['date_query'] = array(
				array(
					'after' => '1 year ago',
				),
			);
		}

		// Add country filter if specified.
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

		// Initialize metrics.
		$metrics = array(
			'total_registrations'   => 0,
			'by_status'             => array(),
			'by_country'            => array(),
			'expiring_soon'         => 0,
			'expired'               => 0,
			'active'                => 0,
			'pending'               => 0,
			'approval_rate'         => 0,
			'average_approval_days' => 0,
		);

		$approval_days = array();
		$today         = time();

		if ( $registrations_query->have_posts() ) {
			foreach ( $registrations_query->posts as $post ) {
				++$metrics['total_registrations'];

				// Count by status.
				$statuses = wp_get_post_terms( $post->ID, 'mcp_ai_reg_status' );
				if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
					$status = $statuses[0]->name;
					if ( ! isset( $metrics['by_status'][ $status ] ) ) {
						$metrics['by_status'][ $status ] = 0;
					}
					++$metrics['by_status'][ $status ];

					// Count active/pending.
					$status_slug = $statuses[0]->slug;
					if ( in_array( $status_slug, array( 'approved', 'active' ), true ) ) {
						++$metrics['active'];
					} elseif ( in_array( $status_slug, array( 'submitted', 'pending', 'under-review' ), true ) ) {
						++$metrics['pending'];
					}
				}

				// Count by country.
				$country = get_post_meta( $post->ID, 'country', true );
				if ( $country ) {
					if ( ! isset( $metrics['by_country'][ $country ] ) ) {
						$metrics['by_country'][ $country ] = 0;
					}
					++$metrics['by_country'][ $country ];
				}

				// Check expiry.
				$expiry_date = get_post_meta( $post->ID, 'expiry_date', true );
				if ( $expiry_date ) {
					$expiry         = strtotime( $expiry_date );
					$days_to_expiry = floor( ( $expiry - $today ) / DAY_IN_SECONDS );

					if ( $days_to_expiry < 0 ) {
						++$metrics['expired'];
					} elseif ( $days_to_expiry <= 90 ) {
						++$metrics['expiring_soon'];
					}
				}

				// Calculate approval time.
				$submission_date = get_post_meta( $post->ID, 'submission_date', true );
				$approval_date   = get_post_meta( $post->ID, 'approval_date', true );
				if ( $submission_date && $approval_date ) {
					$submission_time = strtotime( $submission_date );
					$approval_time   = strtotime( $approval_date );
					$approval_days[] = floor( ( $approval_time - $submission_time ) / DAY_IN_SECONDS );
				}
			}
		}

		// Calculate approval metrics.
		if ( $metrics['total_registrations'] > 0 ) {
			$metrics['approval_rate'] = round( ( $metrics['active'] / $metrics['total_registrations'] ) * 100, 2 );
		}

		if ( ! empty( $approval_days ) ) {
			$metrics['average_approval_days'] = round( array_sum( $approval_days ) / count( $approval_days ), 1 );
		}

		$report_data = array(
			'success'          => true,
			'report_type'      => 'compliance',
			'generated_at'     => current_time( 'mysql' ),
			'date_range'       => $date_range,
			'filter_countries' => $countries,
			'metrics'          => $metrics,
			'format'           => $format,
		);

		return $report_data;
	}
}
