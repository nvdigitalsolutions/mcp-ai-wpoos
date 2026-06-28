<?php
/**
 * CRE Rent Roll Analyzer — WALT, concentration, rollover & mark-to-market
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
 * Deep rent roll analysis: weighted average lease term (WALT), tenant
 * concentration risk, rollover by year, and mark-to-market opportunity.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Rent_Roll_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_rent_roll_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Rent Roll Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Analyze a commercial rent roll: weighted average lease term (WALT), tenant income concentration, lease rollover by year, and mark-to-market analysis comparing in-place rents to market rents per SF.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tenants' => array(
					'type'        => 'array',
					'description' => __( 'Array of tenant objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'               => array(
								'type'        => 'string',
								'description' => __( 'Tenant name.', 'mcp-ai-wpoos-pro' ),
							),
							'sf'                 => array(
								'type'        => 'number',
								'description' => __( 'Leased square footage.', 'mcp-ai-wpoos-pro' ),
							),
							'annual_rent'        => array(
								'type'        => 'number',
								'description' => __( 'Annual base rent.', 'mcp-ai-wpoos-pro' ),
							),
							'lease_start'        => array(
								'type'        => 'string',
								'description' => __( 'Lease start date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'lease_end'          => array(
								'type'        => 'string',
								'description' => __( 'Lease end date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
							'market_rent_per_sf' => array(
								'type'        => 'number',
								'description' => __( 'Current market rent per SF (for mark-to-market).', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'required'   => array( 'tenants' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$tenants = $arguments['tenants'] ?? array();
		if ( empty( $tenants ) ) {
			return new WP_Error( 'invalid_input', __( 'At least one tenant is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc          = WP_MCP_AI_CRE_Debt_Calculator::class;
		$now           = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$total_rent    = 0.0;
		$total_sf      = 0.0;
		$walt_num      = 0.0; // numerator: rent * remaining years.
		$rollover      = array(); // year => array of tenant data.
		$concentration = array();
		$mtm_details   = array();

		foreach ( $tenants as $t ) {
			$name        = sanitize_text_field( $t['name'] ?? 'Unknown' );
			$sf          = (float) ( $t['sf'] ?? 0 );
			$annual_rent = (float) ( $t['annual_rent'] ?? 0 );
			$lease_end   = $t['lease_end'] ?? '';
			$lease_start = $t['lease_start'] ?? '';
			$market_psf  = (float) ( $t['market_rent_per_sf'] ?? 0 );

			$total_rent += $annual_rent;
			$total_sf   += $sf;

			// Remaining lease term in years.
			$remaining_years = 0;
			if ( $lease_end ) {
				try {
					$end_dt          = new \DateTimeImmutable( $lease_end );
					$diff            = $now->diff( $end_dt );
					$remaining_years = max( 0, ( $diff->days / 365.25 ) * ( $diff->invert ? -1 : 1 ) );
				} catch ( \Exception $e ) {
					$remaining_years = 0;
				}
			}

			$walt_num += $annual_rent * $remaining_years;

			// Rollover year.
			$expiry_year = $lease_end ? (int) substr( $lease_end, 0, 4 ) : 0;
			if ( $expiry_year > 0 ) {
				if ( ! isset( $rollover[ $expiry_year ] ) ) {
					$rollover[ $expiry_year ] = array(
						'sf'      => 0,
						'rent'    => 0,
						'tenants' => 0,
					);
				}
				$rollover[ $expiry_year ]['sf']      += $sf;
				$rollover[ $expiry_year ]['rent']    += $annual_rent;
				$rollover[ $expiry_year ]['tenants'] += 1;
			}

			// Concentration.
			$concentration[] = array(
				'name'            => $name,
				'annual_rent'     => round( $annual_rent, 2 ),
				'sf'              => round( $sf, 2 ),
				'remaining_years' => round( $remaining_years, 1 ),
			);

			// Mark-to-market.
			if ( $sf > 0 && $market_psf > 0 ) {
				$in_place_psf  = $annual_rent / $sf;
				$market_rent   = $market_psf * $sf;
				$mtm_details[] = array(
					'name'              => $name,
					'in_place_rent_psf' => round( $in_place_psf, 2 ),
					'market_rent_psf'   => round( $market_psf, 2 ),
					'spread_psf'        => round( $market_psf - $in_place_psf, 2 ),
					'spread_pct'        => ( $in_place_psf > 0 )
						? $calc::format_percentage( ( $market_psf - $in_place_psf ) / $in_place_psf )
						: 'N/A',
					'in_place_total'    => $calc::format_currency( $annual_rent ),
					'market_total'      => $calc::format_currency( $market_rent ),
					'annual_upside'     => $calc::format_currency( $market_rent - $annual_rent ),
				);
			}
		}

		// WALT.
		$walt = ( $total_rent > 0 ) ? $walt_num / $total_rent : 0;

		// Concentration percentages.
		usort(
			$concentration,
			function ( $a, $b ) {
				return $b['annual_rent'] <=> $a['annual_rent'];
			}
		);
		foreach ( $concentration as &$c ) {
			$c['pct_of_total_rent'] = ( $total_rent > 0 )
				? $calc::format_percentage( $c['annual_rent'] / $total_rent )
				: '0.00%';
			$c['pct_of_total_sf']   = ( $total_sf > 0 )
				? $calc::format_percentage( $c['sf'] / $total_sf )
				: '0.00%';
		}
		unset( $c );

		// Format rollover.
		ksort( $rollover );
		$rollover_table = array();
		foreach ( $rollover as $year => $data ) {
			$rollover_table[] = array(
				'year'          => $year,
				'sf_expiring'   => round( $data['sf'], 2 ),
				'pct_sf'        => ( $total_sf > 0 ) ? $calc::format_percentage( $data['sf'] / $total_sf ) : '0.00%',
				'rent_expiring' => $calc::format_currency( $data['rent'] ),
				'pct_rent'      => ( $total_rent > 0 ) ? $calc::format_percentage( $data['rent'] / $total_rent ) : '0.00%',
				'num_tenants'   => $data['tenants'],
			);
		}

		// MTM summary.
		$total_market = 0.0;
		foreach ( $mtm_details as $m ) {
			// Parse back from currency for summation.
			$total_market += ( $m['market_total'] ?? 0 );
		}

		return array(
			'success' => true,
			'message' => __( 'Rent roll analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'summary'              => array(
					'total_tenants'     => count( $tenants ),
					'total_sf'          => round( $total_sf, 2 ),
					'total_annual_rent' => $calc::format_currency( $total_rent ),
					'avg_rent_per_sf'   => ( $total_sf > 0 ) ? round( $total_rent / $total_sf, 2 ) : 0,
					'walt_years'        => round( $walt, 2 ),
				),
				'tenant_concentration' => $concentration,
				'rollover_schedule'    => $rollover_table,
				'mark_to_market'       => $mtm_details,
			),
		);
	}
}
