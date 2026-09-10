<?php
/**
 * underwriting/class-wp-mcp-ai-tool-cre-underwriting-memo-generator.php (ecosystem port — Wave F4, cre-debt underwriting batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-underwriting-memo-generator.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the `__DIR__` calculator require resolves from the addon's already-ported
 * `src/tools/cre-debt/` copy.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Generates a structured underwriting / credit memo from deal parameters,
 * borrower information, risk factors, and recommendation.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Underwriting_Memo_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_underwriting_memo_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Underwriting Memo Generator', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate a structured CRE underwriting / credit committee memo. Takes deal name, property details, loan metrics (DSCR, LTV, debt yield), borrower information, market overview, risk factors with mitigants, and recommendation. Returns a formatted memo ready for review.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_name'           => array(
					'type'        => 'string',
					'description' => __( 'Deal / project name.', 'nvoos-content-graph-pro' ),
				),
				'property_type'       => array(
					'type'        => 'string',
					'description' => __( 'Property type (e.g. office, multifamily, industrial, retail).', 'nvoos-content-graph-pro' ),
				),
				'property_address'    => array(
					'type'        => 'string',
					'description' => __( 'Property street address, city, state, ZIP.', 'nvoos-content-graph-pro' ),
				),
				'loan_amount'         => array(
					'type'        => 'number',
					'description' => __( 'Proposed loan amount.', 'nvoos-content-graph-pro' ),
				),
				'property_value'      => array(
					'type'        => 'number',
					'description' => __( 'Appraised property value.', 'nvoos-content-graph-pro' ),
				),
				'noi'                 => array(
					'type'        => 'number',
					'description' => __( 'Underwritten annual NOI.', 'nvoos-content-graph-pro' ),
				),
				'dscr'                => array(
					'type'        => 'number',
					'description' => __( 'Debt Service Coverage Ratio.', 'nvoos-content-graph-pro' ),
				),
				'ltv'                 => array(
					'type'        => 'number',
					'description' => __( 'Loan-to-Value ratio as decimal (e.g. 0.65).', 'nvoos-content-graph-pro' ),
				),
				'debt_yield'          => array(
					'type'        => 'number',
					'description' => __( 'Debt yield as decimal (e.g. 0.11).', 'nvoos-content-graph-pro' ),
				),
				'borrower_name'       => array(
					'type'        => 'string',
					'description' => __( 'Borrower / sponsor name.', 'nvoos-content-graph-pro' ),
				),
				'borrower_experience' => array(
					'type'        => 'string',
					'description' => __( 'Brief description of borrower experience and track record.', 'nvoos-content-graph-pro' ),
				),
				'market_overview'     => array(
					'type'        => 'string',
					'description' => __( 'Brief market / sub-market overview.', 'nvoos-content-graph-pro' ),
				),
				'risk_factors'        => array(
					'type'        => 'array',
					'description' => __( 'Array of identified risk factor strings.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'mitigants'           => array(
					'type'        => 'array',
					'description' => __( 'Array of risk mitigant strings.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'recommendation'      => array(
					'type'        => 'string',
					'description' => __( 'Final recommendation.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'approve', 'decline', 'conditional' ),
				),
			),
			'required'   => array(
				'deal_name',
				'property_type',
				'property_address',
				'loan_amount',
				'property_value',
				'noi',
				'dscr',
				'ltv',
				'debt_yield',
				'borrower_name',
				'recommendation',
			),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$deal_name      = sanitize_text_field( $arguments['deal_name'] ?? '' );
		$prop_type      = sanitize_text_field( $arguments['property_type'] ?? '' );
		$prop_address   = sanitize_text_field( $arguments['property_address'] ?? '' );
		$loan_amount    = (float) ( $arguments['loan_amount'] ?? 0 );
		$prop_value     = (float) ( $arguments['property_value'] ?? 0 );
		$noi            = (float) ( $arguments['noi'] ?? 0 );
		$dscr           = (float) ( $arguments['dscr'] ?? 0 );
		$ltv            = (float) ( $arguments['ltv'] ?? 0 );
		$debt_yield     = (float) ( $arguments['debt_yield'] ?? 0 );
		$borrower       = sanitize_text_field( $arguments['borrower_name'] ?? '' );
		$experience     = sanitize_text_field( $arguments['borrower_experience'] ?? '' );
		$market         = sanitize_text_field( $arguments['market_overview'] ?? '' );
		$risks          = array_map( 'sanitize_text_field', $arguments['risk_factors'] ?? array() );
		$mitigants      = array_map( 'sanitize_text_field', $arguments['mitigants'] ?? array() );
		$recommendation = sanitize_text_field( $arguments['recommendation'] ?? 'conditional' );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Build recommendation text.
		$rec_labels = array(
			'approve'     => __( 'APPROVE', 'nvoos-content-graph-pro' ),
			'decline'     => __( 'DECLINE', 'nvoos-content-graph-pro' ),
			'conditional' => __( 'CONDITIONAL APPROVAL', 'nvoos-content-graph-pro' ),
		);
		$rec_label  = $rec_labels[ $recommendation ] ?? $rec_labels['conditional'];

		// DSCR / LTV / DY rating.
		$dscr_rating = match ( true ) {
			$dscr >= 1.50 => __( 'Strong', 'nvoos-content-graph-pro' ),
			$dscr >= 1.25 => __( 'Adequate', 'nvoos-content-graph-pro' ),
			$dscr >= 1.10 => __( 'Thin', 'nvoos-content-graph-pro' ),
			default       => __( 'Below Threshold', 'nvoos-content-graph-pro' ),
		};

		$ltv_rating = match ( true ) {
			$ltv <= 0.60 => __( 'Conservative', 'nvoos-content-graph-pro' ),
			$ltv <= 0.75 => __( 'Moderate', 'nvoos-content-graph-pro' ),
			$ltv <= 0.80 => __( 'Aggressive', 'nvoos-content-graph-pro' ),
			default      => __( 'Exceeds Guidelines', 'nvoos-content-graph-pro' ),
		};

		$dy_rating = match ( true ) {
			$debt_yield >= 0.12 => __( 'Strong', 'nvoos-content-graph-pro' ),
			$debt_yield >= 0.10 => __( 'Adequate', 'nvoos-content-graph-pro' ),
			$debt_yield >= 0.08 => __( 'Thin', 'nvoos-content-graph-pro' ),
			default             => __( 'Below Threshold', 'nvoos-content-graph-pro' ),
		};

		// Build structured memo sections.
		$date_str = wp_date( 'F j, Y' );
		$equity   = $prop_value - $loan_amount;

		$memo_lines   = array();
		$memo_lines[] = '══════════════════════════════════════════════════';
		$memo_lines[] = strtoupper( __( 'Credit Committee Underwriting Memo', 'nvoos-content-graph-pro' ) );
		$memo_lines[] = '══════════════════════════════════════════════════';
		$memo_lines[] = '';
		$memo_lines[] = sprintf( '%s: %s', __( 'Date', 'nvoos-content-graph-pro' ), $date_str );
		$memo_lines[] = sprintf( '%s: %s', __( 'Deal Name', 'nvoos-content-graph-pro' ), $deal_name );
		$memo_lines[] = sprintf( '%s: %s', __( 'Recommendation', 'nvoos-content-graph-pro' ), $rec_label );
		$memo_lines[] = '';

		// Section 1: Property Overview.
		$memo_lines[] = '─── ' . strtoupper( __( '1. Property Overview', 'nvoos-content-graph-pro' ) ) . ' ───';
		$memo_lines[] = sprintf( '%s: %s', __( 'Property Type', 'nvoos-content-graph-pro' ), ucfirst( $prop_type ) );
		$memo_lines[] = sprintf( '%s: %s', __( 'Address', 'nvoos-content-graph-pro' ), $prop_address );
		$memo_lines[] = sprintf( '%s: %s', __( 'Appraised Value', 'nvoos-content-graph-pro' ), $calc::format_currency( $prop_value ) );
		$memo_lines[] = '';

		// Section 2: Loan Terms.
		$memo_lines[] = '─── ' . strtoupper( __( '2. Loan Metrics', 'nvoos-content-graph-pro' ) ) . ' ───';
		$memo_lines[] = sprintf( '%s: %s', __( 'Loan Amount', 'nvoos-content-graph-pro' ), $calc::format_currency( $loan_amount ) );
		$memo_lines[] = sprintf( '%s: %s (%s)', __( 'LTV', 'nvoos-content-graph-pro' ), $calc::format_percentage( $ltv ), $ltv_rating );
		$memo_lines[] = sprintf( '%s: %sx (%s)', __( 'DSCR', 'nvoos-content-graph-pro' ), round( $dscr, 2 ), $dscr_rating );
		$memo_lines[] = sprintf( '%s: %s (%s)', __( 'Debt Yield', 'nvoos-content-graph-pro' ), $calc::format_percentage( $debt_yield ), $dy_rating );
		$memo_lines[] = sprintf( '%s: %s', __( 'NOI', 'nvoos-content-graph-pro' ), $calc::format_currency( $noi ) );
		$memo_lines[] = sprintf( '%s: %s', __( 'Equity', 'nvoos-content-graph-pro' ), $calc::format_currency( $equity ) );
		$memo_lines[] = '';

		// Section 3: Borrower.
		$memo_lines[] = '─── ' . strtoupper( __( '3. Borrower / Sponsor', 'nvoos-content-graph-pro' ) ) . ' ───';
		$memo_lines[] = sprintf( '%s: %s', __( 'Name', 'nvoos-content-graph-pro' ), $borrower );
		if ( $experience ) {
			$memo_lines[] = sprintf( '%s: %s', __( 'Experience', 'nvoos-content-graph-pro' ), $experience );
		}
		$memo_lines[] = '';

		// Section 4: Market.
		if ( $market ) {
			$memo_lines[] = '─── ' . strtoupper( __( '4. Market Overview', 'nvoos-content-graph-pro' ) ) . ' ───';
			$memo_lines[] = $market;
			$memo_lines[] = '';
		}

		// Section 5: Risk Factors.
		$memo_lines[] = '─── ' . strtoupper( __( '5. Risk Factors', 'nvoos-content-graph-pro' ) ) . ' ───';
		if ( ! empty( $risks ) ) {
			foreach ( $risks as $i => $r ) {
				$memo_lines[] = sprintf( '  %d. %s', $i + 1, $r );
			}
		} else {
			$memo_lines[] = '  ' . __( 'No material risk factors identified.', 'nvoos-content-graph-pro' );
		}
		$memo_lines[] = '';

		// Section 6: Mitigants.
		$memo_lines[] = '─── ' . strtoupper( __( '6. Risk Mitigants', 'nvoos-content-graph-pro' ) ) . ' ───';
		if ( ! empty( $mitigants ) ) {
			foreach ( $mitigants as $i => $m ) {
				$memo_lines[] = sprintf( '  %d. %s', $i + 1, $m );
			}
		} else {
			$memo_lines[] = '  ' . __( 'None specified.', 'nvoos-content-graph-pro' );
		}
		$memo_lines[] = '';

		// Section 7: Recommendation.
		$memo_lines[] = '─── ' . strtoupper( __( '7. Recommendation', 'nvoos-content-graph-pro' ) ) . ' ───';
		$memo_lines[] = sprintf( '  >> %s <<', $rec_label );
		$memo_lines[] = '';
		$memo_lines[] = '══════════════════════════════════════════════════';
		$memo_lines[] = __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' );
		$memo_lines[] = '══════════════════════════════════════════════════';

		$memo_text = implode( "\n", $memo_lines );

		return array(
			'success' => true,
			'message' => __( 'Underwriting memo generated. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
			'data'    => array(
				'memo_text'      => $memo_text,
				'deal_summary'   => array(
					'deal_name'      => $deal_name,
					'property_type'  => $prop_type,
					'address'        => $prop_address,
					'loan_amount'    => $calc::format_currency( $loan_amount ),
					'property_value' => $calc::format_currency( $prop_value ),
					'noi'            => $calc::format_currency( $noi ),
				),
				'credit_metrics' => array(
					'dscr'        => round( $dscr, 2 ) . 'x',
					'dscr_rating' => $dscr_rating,
					'ltv'         => $calc::format_percentage( $ltv ),
					'ltv_rating'  => $ltv_rating,
					'debt_yield'  => $calc::format_percentage( $debt_yield ),
					'dy_rating'   => $dy_rating,
				),
				'recommendation' => $rec_label,
				'risk_factors'   => $risks,
				'mitigants'      => $mitigants,
			),
		);
	}
}
