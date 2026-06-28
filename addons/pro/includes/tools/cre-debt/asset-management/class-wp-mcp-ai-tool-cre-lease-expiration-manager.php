<?php
/**
 * CRE Lease Expiration Manager — Analyze lease expirations with renewal and exposure modeling
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
 * Analyzes lease expiration schedules grouped by year, modeling renewal
 * probability, mark-to-market rent adjustments, vacancy cost exposure,
 * tenant improvement costs, and leasing commission estimates.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Lease_Expiration_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_lease_expiration_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Lease Expiration Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze lease expiration schedules with renewal probability modeling, mark-to-market analysis, and tenant improvement / leasing commission exposure calculations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'leases' => array(
					'type'        => 'array',
					'description' => __( 'Array of lease objects to analyze.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'tenant_name'             => array(
								'type'        => 'string',
								'description' => __( 'Tenant name.', 'mcp-ai-wpoos-pro' ),
							),
							'suite'                   => array(
								'type'        => 'string',
								'description' => __( 'Suite or unit number.', 'mcp-ai-wpoos-pro' ),
							),
							'sf'                      => array(
								'type'        => 'number',
								'description' => __( 'Leased square footage.', 'mcp-ai-wpoos-pro' ),
							),
							'annual_rent'             => array(
								'type'        => 'number',
								'description' => __( 'Annual base rent.', 'mcp-ai-wpoos-pro' ),
							),
							'lease_end'               => array(
								'type'        => 'string',
								'description' => __( 'Lease expiration date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'renewal_probability_pct' => array(
								'type'        => 'number',
								'description' => __( 'Probability the tenant renews (0-100). Default 70.', 'mcp-ai-wpoos-pro' ),
								'default'     => 70,
							),
							'downtime_months'         => array(
								'type'        => 'number',
								'description' => __( 'Expected months of vacancy if tenant vacates. Default 3.', 'mcp-ai-wpoos-pro' ),
								'default'     => 3,
							),
							'ti_per_sf'               => array(
								'type'        => 'number',
								'description' => __( 'Tenant improvement allowance per SF for new tenant. Default 20.', 'mcp-ai-wpoos-pro' ),
								'default'     => 20,
							),
							'lc_pct'                  => array(
								'type'        => 'number',
								'description' => __( 'Leasing commission as percentage of annual rent. Default 5.', 'mcp-ai-wpoos-pro' ),
								'default'     => 5,
							),
							'market_rent_per_sf'      => array(
								'type'        => 'number',
								'description' => __( 'Current market rent per SF. If provided, enables mark-to-market analysis.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'tenant_name', 'sf', 'annual_rent', 'lease_end' ),
					),
				),
			),
			'required'   => array( 'leases' ),
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

		$raw_leases = $arguments['leases'] ?? array();
		if ( empty( $raw_leases ) || ! is_array( $raw_leases ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one lease entry is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Parse leases and group by expiration year.
		$grand_total_sf = 0.0;
		$leases_by_year = array();

		foreach ( $raw_leases as $raw ) {
			$sf = (float) ( $raw['sf'] ?? 0 );
			if ( $sf <= 0 ) {
				continue;
			}

			$tenant_name = sanitize_text_field( $raw['tenant_name'] ?? '' );
			$suite       = sanitize_text_field( $raw['suite'] ?? '' );
			$annual_rent = (float) ( $raw['annual_rent'] ?? 0 );
			$lease_end   = sanitize_text_field( $raw['lease_end'] ?? '' );
			$renewal_pct = (float) ( $raw['renewal_probability_pct'] ?? 70 );
			$downtime_mo = (float) ( $raw['downtime_months'] ?? 3 );
			$ti_per_sf   = (float) ( $raw['ti_per_sf'] ?? 20 );
			$lc_pct      = (float) ( $raw['lc_pct'] ?? 5 );
			$market_psf  = isset( $raw['market_rent_per_sf'] ) ? (float) $raw['market_rent_per_sf'] : null;

			$exp_year = (int) substr( $lease_end, 0, 4 );
			if ( $exp_year <= 0 ) {
				continue;
			}

			$grand_total_sf += $sf;

			$current_psf     = ( $sf > 0 ) ? $annual_rent / $sf : 0;
			$mtm_per_sf      = ( null !== $market_psf ) ? $market_psf - $current_psf : 0;
			$mtm_total       = $mtm_per_sf * $sf;
			$renewal_decimal = $renewal_pct / 100;
			$vacancy_cost    = $annual_rent / 12 * $downtime_mo * ( 1 - $renewal_decimal );
			$new_tenant_ti   = $ti_per_sf * $sf * ( 1 - $renewal_decimal );
			$renewal_ti      = $ti_per_sf * 0.5 * $sf * $renewal_decimal;
			$total_ti        = $new_tenant_ti + $renewal_ti;
			$lc_cost         = $annual_rent * $lc_pct / 100;

			$lease_detail = array(
				'tenant_name'             => $tenant_name,
				'suite'                   => $suite,
				'sf'                      => $sf,
				'annual_rent'             => $calc::format_currency( $annual_rent ),
				'lease_end'               => $lease_end,
				'renewal_probability_pct' => round( $renewal_pct, 1 ),
				'current_rent_per_sf'     => $calc::format_currency( $current_psf ),
				'mark_to_market_per_sf'   => $calc::format_currency( $mtm_per_sf ),
				'mark_to_market_total'    => $calc::format_currency( $mtm_total ),
				'vacancy_cost'            => $calc::format_currency( $vacancy_cost ),
				'ti_new_tenant'           => $calc::format_currency( $new_tenant_ti ),
				'ti_renewal'              => $calc::format_currency( $renewal_ti ),
				'total_ti'                => $calc::format_currency( $total_ti ),
				'lc_cost'                 => $calc::format_currency( $lc_cost ),
			);

			if ( ! isset( $leases_by_year[ $exp_year ] ) ) {
				$leases_by_year[ $exp_year ] = array(
					'leases'            => array(),
					'total_sf'          => 0.0,
					'total_annual_rent' => 0.0,
					'weighted_renewal'  => 0.0,
					'total_vacancy'     => 0.0,
					'total_ti'          => 0.0,
					'total_lc'          => 0.0,
					'net_mtm'           => 0.0,
				);
			}

			$leases_by_year[ $exp_year ]['leases'][]           = $lease_detail;
			$leases_by_year[ $exp_year ]['total_sf']          += $sf;
			$leases_by_year[ $exp_year ]['total_annual_rent'] += $annual_rent;
			$leases_by_year[ $exp_year ]['weighted_renewal']  += $renewal_pct * $sf;
			$leases_by_year[ $exp_year ]['total_vacancy']     += $vacancy_cost;
			$leases_by_year[ $exp_year ]['total_ti']          += $total_ti;
			$leases_by_year[ $exp_year ]['total_lc']          += $lc_cost;
			$leases_by_year[ $exp_year ]['net_mtm']           += $mtm_total;
		}

		if ( $grand_total_sf <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'No valid leases with positive square footage provided.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build year-level summaries.
		ksort( $leases_by_year );

		$year_summaries        = array();
		$portfolio_vacancy     = 0.0;
		$portfolio_ti          = 0.0;
		$portfolio_lc          = 0.0;
		$portfolio_mtm         = 0.0;
		$portfolio_rent        = 0.0;
		$portfolio_sf_expiring = 0.0;

		foreach ( $leases_by_year as $year => $group ) {
			$yr_sf         = $group['total_sf'];
			$pct_portfolio = ( $grand_total_sf > 0 ) ? $yr_sf / $grand_total_sf * 100 : 0;
			$wtd_renewal   = ( $yr_sf > 0 ) ? $group['weighted_renewal'] / $yr_sf : 0;

			$year_summaries[] = array(
				'year'                         => $year,
				'lease_count'                  => count( $group['leases'] ),
				'total_sf_expiring'            => $yr_sf,
				'pct_of_portfolio'             => round( $pct_portfolio, 1 ) . '%',
				'total_annual_rent'            => $calc::format_currency( $group['total_annual_rent'] ),
				'weighted_renewal_probability' => round( $wtd_renewal, 1 ) . '%',
				'total_vacancy_cost'           => $calc::format_currency( $group['total_vacancy'] ),
				'total_ti_exposure'            => $calc::format_currency( $group['total_ti'] ),
				'total_lc_exposure'            => $calc::format_currency( $group['total_lc'] ),
				'net_mark_to_market'           => $calc::format_currency( $group['net_mtm'] ),
				'leases'                       => $group['leases'],
			);

			$portfolio_vacancy     += $group['total_vacancy'];
			$portfolio_ti          += $group['total_ti'];
			$portfolio_lc          += $group['total_lc'];
			$portfolio_mtm         += $group['net_mtm'];
			$portfolio_rent        += $group['total_annual_rent'];
			$portfolio_sf_expiring += $yr_sf;
		}

		return array(
			'success'    => true,
			'message'    => __( 'Lease expiration analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'portfolio_summary'   => array(
					'total_sf'           => $grand_total_sf,
					'total_sf_expiring'  => $portfolio_sf_expiring,
					'total_annual_rent'  => $calc::format_currency( $portfolio_rent ),
					'total_vacancy_cost' => $calc::format_currency( $portfolio_vacancy ),
					'total_ti_exposure'  => $calc::format_currency( $portfolio_ti ),
					'total_lc_exposure'  => $calc::format_currency( $portfolio_lc ),
					'net_mark_to_market' => $calc::format_currency( $portfolio_mtm ),
					'total_exposure'     => $calc::format_currency( $portfolio_vacancy + $portfolio_ti + $portfolio_lc ),
				),
				'expiration_schedule' => $year_summaries,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
