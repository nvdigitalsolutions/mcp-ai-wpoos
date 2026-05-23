<?php
/**
 * CRE Concentration Limit Monitor — Track portfolio concentration against policy limits
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
 * Monitors portfolio concentration by borrower, property type, geography,
 * and single-loan exposure against configurable policy limits.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Concentration_Limit_Monitor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_concentration_limit_monitor';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Concentration Limit Monitor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Monitor portfolio concentration by borrower, property type, geography, and single-loan exposure against configurable policy limits. Flags breaches and warnings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loans'  => array(
					'type'        => 'array',
					'description' => __( 'Array of loan objects in the portfolio.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Loan or property name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance'       => array(
								'type'        => 'number',
								'description' => __( 'Current outstanding balance.', 'mcp-ai-wpoos-pro' ),
							),
							'borrower'      => array(
								'type'        => 'string',
								'description' => __( 'Borrower name or entity.', 'mcp-ai-wpoos-pro' ),
							),
							'property_type' => array(
								'type'        => 'string',
								'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
							),
							'state'         => array(
								'type'        => 'string',
								'description' => __( 'State abbreviation.', 'mcp-ai-wpoos-pro' ),
							),
							'maturity_date' => array(
								'type'        => 'string',
								'description' => __( 'Maturity date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance', 'borrower', 'property_type', 'state' ),
					),
				),
				'limits' => array(
					'type'        => 'object',
					'description' => __( 'Concentration limits as percentages.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'max_single_borrower_pct' => array(
							'type'        => 'number',
							'description' => __( 'Max single-borrower concentration percent.', 'mcp-ai-wpoos-pro' ),
							'default'     => 10,
						),
						'max_property_type_pct'   => array(
							'type'        => 'number',
							'description' => __( 'Max property-type concentration percent.', 'mcp-ai-wpoos-pro' ),
							'default'     => 35,
						),
						'max_geographic_pct'      => array(
							'type'        => 'number',
							'description' => __( 'Max single-state geographic concentration percent.', 'mcp-ai-wpoos-pro' ),
							'default'     => 25,
						),
						'max_single_loan_pct'     => array(
							'type'        => 'number',
							'description' => __( 'Max single-loan concentration percent.', 'mcp-ai-wpoos-pro' ),
							'default'     => 10,
						),
					),
				),
			),
			'required'   => array( 'loans' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loans  = $arguments['loans'] ?? array();
		$limits = $arguments['limits'] ?? array();

		if ( empty( $loans ) || ! is_array( $loans ) ) {
			return new \WP_Error( 'invalid_input', __( 'At least one loan is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$max_borrower_pct = (float) ( $limits['max_single_borrower_pct'] ?? 10 );
		$max_proptype_pct = (float) ( $limits['max_property_type_pct'] ?? 35 );
		$max_geo_pct      = (float) ( $limits['max_geographic_pct'] ?? 25 );
		$max_loan_pct     = (float) ( $limits['max_single_loan_pct'] ?? 10 );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$total_balance    = 0.0;
		$by_borrower      = array();
		$by_property      = array();
		$by_state         = array();
		$single_loan_max  = 0.0;
		$single_loan_name = '';

		foreach ( $loans as $loan ) {
			$balance       = (float) ( $loan['balance'] ?? 0 );
			$borrower      = sanitize_text_field( $loan['borrower'] ?? 'unknown' );
			$property_type = sanitize_text_field( $loan['property_type'] ?? 'other' );
			$state         = sanitize_text_field( $loan['state'] ?? 'unknown' );
			$name          = sanitize_text_field( $loan['name'] ?? '' );

			$total_balance += $balance;

			if ( ! isset( $by_borrower[ $borrower ] ) ) {
				$by_borrower[ $borrower ] = 0.0;
			}
			$by_borrower[ $borrower ] += $balance;

			if ( ! isset( $by_property[ $property_type ] ) ) {
				$by_property[ $property_type ] = 0.0;
			}
			$by_property[ $property_type ] += $balance;

			if ( ! isset( $by_state[ $state ] ) ) {
				$by_state[ $state ] = 0.0;
			}
			$by_state[ $state ] += $balance;

			if ( $balance > $single_loan_max ) {
				$single_loan_max  = $balance;
				$single_loan_name = $name;
			}
		}

		if ( $total_balance <= 0 ) {
			return new \WP_Error( 'invalid_input', __( 'Total portfolio balance must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$breaches = array();
		$warnings = array();

		// Helper: check a category grouping against a limit.
		$check_group = function ( array $group, float $limit_pct, string $category ) use ( $total_balance, $calc, &$breaches, &$warnings ) {
			$details = array();
			foreach ( $group as $key => $balance ) {
				$pct    = ( $balance / $total_balance ) * 100;
				$status = 'ok';
				if ( $pct > $limit_pct ) {
					$status     = 'breach';
					$breaches[] = array(
						'category' => $category,
						'name'     => $key,
						'current'  => round( $pct, 2 ) . '%',
						'limit'    => $limit_pct . '%',
						'excess'   => $calc::format_currency( $balance - ( $total_balance * $limit_pct / 100 ) ),
					);
				} elseif ( $pct >= $limit_pct * 0.90 ) {
					$status     = 'warning';
					$warnings[] = array(
						'category' => $category,
						'name'     => $key,
						'current'  => round( $pct, 2 ) . '%',
						'limit'    => $limit_pct . '%',
						'headroom' => $calc::format_currency( ( $total_balance * $limit_pct / 100 ) - $balance ),
					);
				}
				$details[ $key ] = array(
					'balance'    => $calc::format_currency( $balance ),
					'percentage' => round( $pct, 2 ) . '%',
					'status'     => $status,
				);
			}
			return $details;
		};

		$borrower_detail = $check_group( $by_borrower, $max_borrower_pct, __( 'Single Borrower', 'mcp-ai-wpoos-pro' ) );
		$property_detail = $check_group( $by_property, $max_proptype_pct, __( 'Property Type', 'mcp-ai-wpoos-pro' ) );
		$geo_detail      = $check_group( $by_state, $max_geo_pct, __( 'Geographic', 'mcp-ai-wpoos-pro' ) );

		// Single loan check.
		$single_loan_pct = ( $single_loan_max / $total_balance ) * 100;
		if ( $single_loan_pct > $max_loan_pct ) {
			$breaches[] = array(
				'category' => __( 'Single Loan', 'mcp-ai-wpoos-pro' ),
				'name'     => $single_loan_name,
				'current'  => round( $single_loan_pct, 2 ) . '%',
				'limit'    => $max_loan_pct . '%',
				'excess'   => $calc::format_currency( $single_loan_max - ( $total_balance * $max_loan_pct / 100 ) ),
			);
		} elseif ( $single_loan_pct >= $max_loan_pct * 0.90 ) {
			$warnings[] = array(
				'category' => __( 'Single Loan', 'mcp-ai-wpoos-pro' ),
				'name'     => $single_loan_name,
				'current'  => round( $single_loan_pct, 2 ) . '%',
				'limit'    => $max_loan_pct . '%',
				'headroom' => $calc::format_currency( ( $total_balance * $max_loan_pct / 100 ) - $single_loan_max ),
			);
		}

		$overall_status = 'pass';
		if ( ! empty( $breaches ) ) {
			$overall_status = 'fail';
		} elseif ( ! empty( $warnings ) ) {
			$overall_status = 'warning';
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: overall compliance status */
				__( 'Concentration analysis complete — status: %s. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				strtoupper( $overall_status )
			),
			'data'       => array(
				'overall_status'            => $overall_status,
				'total_portfolio_balance'   => $calc::format_currency( $total_balance ),
				'num_loans'                 => count( $loans ),
				'limits_applied'            => array(
					'max_single_borrower_pct' => $max_borrower_pct . '%',
					'max_property_type_pct'   => $max_proptype_pct . '%',
					'max_geographic_pct'      => $max_geo_pct . '%',
					'max_single_loan_pct'     => $max_loan_pct . '%',
				),
				'borrower_concentrations'   => $borrower_detail,
				'property_concentrations'   => $property_detail,
				'geographic_concentrations' => $geo_detail,
				'largest_single_loan'       => array(
					'name'       => $single_loan_name,
					'balance'    => $calc::format_currency( $single_loan_max ),
					'percentage' => round( $single_loan_pct, 2 ) . '%',
				),
				'breaches'                  => $breaches,
				'warnings'                  => $warnings,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
