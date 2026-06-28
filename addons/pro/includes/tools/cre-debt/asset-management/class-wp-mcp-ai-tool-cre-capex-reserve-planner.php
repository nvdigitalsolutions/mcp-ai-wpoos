<?php
/**
 * CRE CapEx Reserve Planner — Plan capital expenditure reserves with fund projections
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Plans capital expenditure reserves by categorizing projects, projecting
 * a 10-year reserve fund balance, and assessing reserve adequacy with
 * building-age-based contingency recommendations.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Capex_Reserve_Planner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_capex_reserve_planner';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE CapEx Reserve Planner', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Plan capital expenditure reserves with categorized project tracking, reserve adequacy analysis, and multi-year fund projections.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'property_type'           => array(
					'type'        => 'string',
					'description' => __( 'Property type (e.g. office, retail, industrial, multifamily).', 'mcp-ai-wpoos-pro' ),
				),
				'total_sf'                => array(
					'type'        => 'number',
					'description' => __( 'Total building square footage.', 'mcp-ai-wpoos-pro' ),
				),
				'year_built'              => array(
					'type'        => 'integer',
					'description' => __( 'Year the building was constructed.', 'mcp-ai-wpoos-pro' ),
				),
				'items'                   => array(
					'type'        => 'array',
					'description' => __( 'Array of CapEx project items.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'category'          => array(
								'type'        => 'string',
								'description' => __( 'Project category.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'structural', 'mep', 'ti', 'common_area', 'deferred_maintenance' ),
							),
							'description'       => array(
								'type'        => 'string',
								'description' => __( 'Project description.', 'mcp-ai-wpoos-pro' ),
							),
							'estimated_cost'    => array(
								'type'        => 'number',
								'description' => __( 'Estimated project cost.', 'mcp-ai-wpoos-pro' ),
							),
							'useful_life_years' => array(
								'type'        => 'integer',
								'description' => __( 'Useful life of the improvement in years.', 'mcp-ai-wpoos-pro' ),
							),
							'year_due'          => array(
								'type'        => 'integer',
								'description' => __( 'Year the expenditure is expected.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'category', 'description', 'estimated_cost', 'useful_life_years', 'year_due' ),
					),
				),
				'current_reserve_balance' => array(
					'type'        => 'number',
					'description' => __( 'Current reserve fund balance. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'annual_contribution'     => array(
					'type'        => 'number',
					'description' => __( 'Annual contribution to the reserve fund. Default 0.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'property_type', 'total_sf', 'year_built', 'items' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$property_type   = sanitize_text_field( $arguments['property_type'] ?? '' );
		$total_sf        = (float) ( $arguments['total_sf'] ?? 0 );
		$year_built      = absint( $arguments['year_built'] ?? 0 );
		$raw_items       = $arguments['items'] ?? array();
		$reserve_balance = (float) ( $arguments['current_reserve_balance'] ?? 0 );
		$annual_contrib  = (float) ( $arguments['annual_contribution'] ?? 0 );

		if ( empty( $property_type ) ) {
			return new WP_Error( 'invalid_input', __( 'property_type is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $total_sf <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'total_sf must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $year_built <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'year_built is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $raw_items ) || ! is_array( $raw_items ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one CapEx item is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc         = WP_MCP_AI_CRE_Debt_Calculator::class;
		$current_year = (int) gmdate( 'Y' );
		$building_age = $current_year - $year_built;

		// Parse and classify items.
		$items           = array();
		$near_term_total = 0.0;
		$long_term_total = 0.0;
		$total_capex     = 0.0;
		$by_category     = array();
		$capex_by_year   = array();

		foreach ( $raw_items as $raw ) {
			$category       = sanitize_text_field( $raw['category'] ?? '' );
			$description    = sanitize_text_field( $raw['description'] ?? '' );
			$estimated_cost = (float) ( $raw['estimated_cost'] ?? 0 );
			$useful_life    = absint( $raw['useful_life_years'] ?? 0 );
			$year_due       = absint( $raw['year_due'] ?? 0 );

			if ( $estimated_cost <= 0 || $year_due <= 0 ) {
				continue;
			}

			$is_near_term = ( $year_due >= $current_year && $year_due <= $current_year + 3 );
			$is_long_term = ( $year_due >= $current_year + 4 && $year_due <= $current_year + 10 );
			$horizon      = $is_near_term ? 'near_term' : ( $is_long_term ? 'long_term' : 'beyond_10yr' );

			if ( $is_near_term ) {
				$near_term_total += $estimated_cost;
			} elseif ( $is_long_term ) {
				$long_term_total += $estimated_cost;
			}
			$total_capex += $estimated_cost;

			if ( ! isset( $by_category[ $category ] ) ) {
				$by_category[ $category ] = 0.0;
			}
			$by_category[ $category ] += $estimated_cost;

			if ( ! isset( $capex_by_year[ $year_due ] ) ) {
				$capex_by_year[ $year_due ] = 0.0;
			}
			$capex_by_year[ $year_due ] += $estimated_cost;

			$items[] = array(
				'category'          => $category,
				'description'       => $description,
				'estimated_cost'    => $calc::format_currency( $estimated_cost ),
				'useful_life_years' => $useful_life,
				'year_due'          => $year_due,
				'horizon'           => $horizon,
			);
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'invalid_input', __( 'No valid CapEx items with positive cost and year provided.', 'mcp-ai-wpoos-pro' ) );
		}

		// Per-SF metrics over the 10-year window.
		$ten_year_capex            = $near_term_total + $long_term_total;
		$total_capex_per_sf        = ( $total_sf > 0 ) ? $total_capex / $total_sf : 0;
		$recommended_annual_per_sf = ( $total_sf > 0 && $ten_year_capex > 0 ) ? $ten_year_capex / $total_sf / 10 : 0;

		// Contingency factor based on building age.
		$contingency_pct = 0;
		if ( $building_age > 30 ) {
			$contingency_pct = 25;
		} elseif ( $building_age > 20 ) {
			$contingency_pct = 15;
		}

		// Category summary formatted.
		$category_summary = array();
		foreach ( $by_category as $cat => $cost ) {
			$category_summary[] = array(
				'category' => $cat,
				'total'    => $calc::format_currency( $cost ),
				'pct'      => ( $total_capex > 0 ) ? round( $cost / $total_capex * 100, 1 ) . '%' : '0%',
			);
		}

		// 10-year reserve fund projection.
		$projection     = array();
		$balance        = $reserve_balance;
		$min_balance    = $reserve_balance;
		$shortfall      = 0.0;
		$is_underfunded = false;

		for ( $yr = $current_year; $yr <= $current_year + 9; $yr++ ) {
			$yr_capex = isset( $capex_by_year[ $yr ] ) ? $capex_by_year[ $yr ] : 0.0;
			$balance  = $balance + $annual_contrib - $yr_capex;

			$underfunded_flag = ( $balance < 0 );
			if ( $underfunded_flag ) {
				$is_underfunded = true;
				if ( $balance < $min_balance ) {
					$min_balance = $balance;
				}
			}

			$projection[] = array(
				'year'                => $yr,
				'annual_contribution' => $calc::format_currency( $annual_contrib ),
				'capex_due'           => $calc::format_currency( $yr_capex ),
				'ending_balance'      => $calc::format_currency( $balance ),
				'underfunded'         => $underfunded_flag,
			);
		}

		if ( $is_underfunded ) {
			$shortfall = abs( $min_balance );
		}

		$reserve_adequacy = $is_underfunded ? 'underfunded' : 'adequate';

		return array(
			'success'    => true,
			'message'    => __( 'CapEx reserve plan generated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'property_info'      => array(
					'property_type' => $property_type,
					'total_sf'      => $total_sf,
					'year_built'    => $year_built,
					'building_age'  => $building_age,
				),
				'capex_summary'      => array(
					'total_items'                       => count( $items ),
					'near_term_total'                   => $calc::format_currency( $near_term_total ),
					'long_term_total'                   => $calc::format_currency( $long_term_total ),
					'total_capex'                       => $calc::format_currency( $total_capex ),
					'total_capex_per_sf'                => $calc::format_currency( $total_capex_per_sf ),
					'recommended_annual_reserve_per_sf' => $calc::format_currency( $recommended_annual_per_sf ),
				),
				'category_breakdown' => $category_summary,
				'contingency'        => array(
					'building_age'    => $building_age,
					'contingency_pct' => $contingency_pct . '%',
					'recommendation'  => ( $contingency_pct > 0 )
						? sprintf(
							/* translators: %d: contingency percentage */
							__( 'Building age exceeds threshold — recommend adding %d%% contingency to CapEx estimates.', 'mcp-ai-wpoos-pro' ),
							$contingency_pct
						)
						: __( 'Building age within normal range — no additional contingency recommended.', 'mcp-ai-wpoos-pro' ),
				),
				'reserve_projection' => $projection,
				'reserve_adequacy'   => array(
					'status'    => $reserve_adequacy,
					'shortfall' => $is_underfunded ? $calc::format_currency( $shortfall ) : $calc::format_currency( 0 ),
					'message'   => $is_underfunded
						? sprintf(
							/* translators: %s: shortfall amount */
							__( 'Reserve fund is projected to be underfunded. Maximum shortfall: %s.', 'mcp-ai-wpoos-pro' ),
							$calc::format_currency( $shortfall )
						)
						: __( 'Reserve fund is projected to remain adequate over the 10-year horizon.', 'mcp-ai-wpoos-pro' ),
				),
				'items'              => $items,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
