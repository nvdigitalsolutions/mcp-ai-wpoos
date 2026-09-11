<?php
/**
 * WP_MCP_AI_Tool_Generate_Expiry_Forecast (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Generate Expiry Forecast', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates registration expiry forecast report with renewal timeline, risk assessment, and proactive planning recommendations.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Number of months to forecast (optional, default: 12)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 36,
					'default'     => 12,
				),
				'risk_threshold'  => array(
					'type'        => 'integer',
					'description' => __( 'Days before expiry to flag as high risk (optional, default: 90)', 'nvoos-content-graph-pro' ),
					'minimum'     => 30,
					'maximum'     => 180,
					'default'     => 90,
				),
				'countries'       => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'nvoos-content-graph-pro' ),
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate expiry forecasts.', 'nvoos-content-graph-pro' ) );
		}

		$forecast_months = ! empty( $arguments['forecast_months'] ) ? absint( $arguments['forecast_months'] ) : 12;
		$risk_threshold  = ! empty( $arguments['risk_threshold'] ) ? absint( $arguments['risk_threshold'] ) : 90;
		$countries       = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_registration',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_expiry_forecast', 0, 1000 ) : 1000,
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

		$today        = time();
		$forecast_end = strtotime( "+{$forecast_months} months" );

		$expiry_data = array(
			'expired'     => array(),
			'high_risk'   => array(),
			'medium_risk' => array(),
			'low_risk'    => array(),
			'by_month'    => array(),
			'by_country'  => array(),
		);

		// Initialize monthly buckets.
		for ( $i = 0; $i <= $forecast_months; $i++ ) {
			$month_key                             = gmdate( 'Y-m', strtotime( "+{$i} months" ) );
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
						++$expiry_data['by_month'][ $month_key ];
					}
				}

				// Group by country.
				if ( $country ) {
					if ( ! isset( $expiry_data['by_country'][ $country ] ) ) {
						$expiry_data['by_country'][ $country ] = 0;
					}
					++$expiry_data['by_country'][ $country ];
				}
			}
		}

		// Generate recommendations.
		$recommendations = array();

		if ( count( $expiry_data['expired'] ) > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of expired registrations */
				__( 'Urgent: %d expired registrations require immediate renewal action.', 'nvoos-content-graph-pro' ),
				count( $expiry_data['expired'] )
			);
		}

		if ( count( $expiry_data['high_risk'] ) > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of high-risk registrations */
				__( 'High Priority: %1$d registrations expiring within %2$d days.', 'nvoos-content-graph-pro' ),
				count( $expiry_data['high_risk'] ),
				$risk_threshold
			);
		}

		if ( count( $expiry_data['medium_risk'] ) > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of medium-risk registrations */
				__( 'Planning Required: %1$d registrations expiring within %2$d days.', 'nvoos-content-graph-pro' ),
				count( $expiry_data['medium_risk'] ),
				$risk_threshold * 2
			);
		}

		return array(
			'success'         => true,
			'report_type'     => 'expiry_forecast',
			'generated_at'    => current_time( 'mysql' ),
			'forecast_months' => $forecast_months,
			'risk_threshold'  => $risk_threshold,
			'summary'         => array(
				'total_expiring' => count( $expiry_data['expired'] ) + count( $expiry_data['high_risk'] ) + count( $expiry_data['medium_risk'] ) + count( $expiry_data['low_risk'] ),
				'expired'        => count( $expiry_data['expired'] ),
				'high_risk'      => count( $expiry_data['high_risk'] ),
				'medium_risk'    => count( $expiry_data['medium_risk'] ),
				'low_risk'       => count( $expiry_data['low_risk'] ),
			),
			'expiry_data'     => $expiry_data,
			'recommendations' => $recommendations,
		);
	}
}
