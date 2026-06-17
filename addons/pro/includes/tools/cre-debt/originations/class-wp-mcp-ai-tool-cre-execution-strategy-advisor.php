<?php
/**
 * CRE Execution Strategy Advisor — Score and rank execution paths for CRE loan originations
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
 * Evaluates and ranks execution paths (balance sheet, agency, CMBS, debt fund,
 * CRE CLO, life company, bank) based on deal characteristics. Each path receives
 * a suitability score with pros and cons.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_execution_strategy_advisor';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Execution Strategy Advisor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Score and rank CRE loan execution paths (balance sheet, agency, CMBS, debt fund, CRE CLO, life company, bank) based on deal characteristics, property type, and borrower profile. Returns suitability scores with pros and cons.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_amount'        => array(
					'type'        => 'number',
					'description' => __( 'Loan amount.', 'mcp-ai-wpoos-pro' ),
				),
				'property_type'      => array(
					'type'        => 'string',
					'description' => __( 'Property type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'office', 'retail', 'industrial', 'multifamily', 'hotel', 'other' ),
				),
				'property_value'     => array(
					'type'        => 'number',
					'description' => __( 'Property value.', 'mcp-ai-wpoos-pro' ),
				),
				'noi'                => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'mcp-ai-wpoos-pro' ),
				),
				'sponsor_experience' => array(
					'type'        => 'integer',
					'description' => __( 'Sponsor years of CRE experience.', 'mcp-ai-wpoos-pro' ),
				),
				'market_tier'        => array(
					'type'        => 'string',
					'description' => __( 'Market tier.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'primary', 'secondary', 'tertiary' ),
				),
				'loan_purpose'       => array(
					'type'        => 'string',
					'description' => __( 'Loan purpose.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'acquisition', 'refinance', 'construction', 'bridge' ),
				),
				'stabilized'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the property is stabilized.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'occupancy_pct'      => array(
					'type'        => 'number',
					'description' => __( 'Current occupancy as percentage (e.g. 92 for 92%).', 'mcp-ai-wpoos-pro' ),
					'default'     => 90,
				),
			),
			'required'   => array( 'loan_amount', 'property_type', 'property_value', 'noi', 'loan_purpose' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loan_amount   = (float) ( $arguments['loan_amount'] ?? 0 );
		$property_type = sanitize_text_field( $arguments['property_type'] ?? 'other' );
		$prop_value    = (float) ( $arguments['property_value'] ?? 0 );
		$noi           = (float) ( $arguments['noi'] ?? 0 );
		$sponsor_exp   = (int) ( $arguments['sponsor_experience'] ?? 0 );
		$market_tier   = sanitize_text_field( $arguments['market_tier'] ?? 'secondary' );
		$loan_purpose  = sanitize_text_field( $arguments['loan_purpose'] ?? 'acquisition' );
		$stabilized    = (bool) ( $arguments['stabilized'] ?? true );
		$occupancy     = (float) ( $arguments['occupancy_pct'] ?? 90 );

		if ( $loan_amount <= 0 || $prop_value <= 0 || $noi <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Loan amount, property value, and NOI must be positive.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;
		$ltv  = $calc::calculate_ltv( $loan_amount, $prop_value );

		// Build context for scoring.
		$deal = array(
			'loan_amount'   => $loan_amount,
			'property_type' => $property_type,
			'ltv'           => $ltv,
			'noi'           => $noi,
			'sponsor_exp'   => $sponsor_exp,
			'market_tier'   => $market_tier,
			'loan_purpose'  => $loan_purpose,
			'stabilized'    => $stabilized,
			'occupancy'     => $occupancy,
		);

		$paths = array(
			'balance_sheet' => $this->score_balance_sheet( $deal ),
			'agency'        => $this->score_agency( $deal ),
			'cmbs'          => $this->score_cmbs( $deal ),
			'debt_fund'     => $this->score_debt_fund( $deal ),
			'cre_clo'       => $this->score_cre_clo( $deal ),
			'life_company'  => $this->score_life_company( $deal ),
			'bank'          => $this->score_bank( $deal ),
		);

		// Sort by score descending.
		uasort(
			$paths,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$ranked = array();
		$rank   = 1;
		foreach ( $paths as $path_name => $info ) {
			$ranked[] = array(
				'rank'           => $rank++,
				'execution_path' => $path_name,
				'suitability'    => $info['score'] . '/100',
				'rating'         => $this->score_to_rating( $info['score'] ),
				'pros'           => $info['pros'],
				'cons'           => $info['cons'],
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Execution strategy analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'deal_summary'         => array(
					'loan_amount'   => $calc::format_currency( $loan_amount ),
					'property_type' => $property_type,
					'ltv'           => $calc::format_percentage( $ltv ),
					'loan_purpose'  => $loan_purpose,
					'stabilized'    => $stabilized ? __( 'Yes', 'mcp-ai-wpoos-pro' ) : __( 'No', 'mcp-ai-wpoos-pro' ),
					'occupancy'     => $occupancy . '%',
				),
				'recommended_path'     => $ranked[0]['execution_path'],
				'execution_strategies' => $ranked,
			),
		);
	}

	/**
	 * Convert score to rating string.
	 *
	 * @param int $score Suitability score.
	 * @return string
	 */
	private function score_to_rating( int $score ): string {
		if ( $score >= 80 ) {
			return __( 'Highly Suitable', 'mcp-ai-wpoos-pro' );
		}
		if ( $score >= 60 ) {
			return __( 'Suitable', 'mcp-ai-wpoos-pro' );
		}
		if ( $score >= 40 ) {
			return __( 'Marginal', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Not Recommended', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Score balance sheet execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_balance_sheet( array $deal ): array {
		$score = 50;
		$pros  = array();
		$cons  = array();

		if ( $deal['sponsor_exp'] >= 10 ) {
			$score += 15;
			$pros[] = __( 'Experienced sponsor favored for relationship lending', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['loan_amount'] < 10000000 ) {
			$score += 10;
			$pros[] = __( 'Smaller deal size fits balance sheet appetite', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 10;
			$cons[] = __( 'Larger deals may exceed single-lender hold limits', 'mcp-ai-wpoos-pro' );
		}
		if ( ! $deal['stabilized'] || 'construction' === $deal['loan_purpose'] || 'bridge' === $deal['loan_purpose'] ) {
			$score += 15;
			$pros[] = __( 'Balance sheet lenders offer flexibility for transitional assets', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['ltv'] > 0.75 ) {
			$score -= 10;
			$cons[] = __( 'High LTV may face regulatory capital constraints', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Flexible terms and relationship-based pricing', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Typically shorter terms (3-7 years)', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}

	/**
	 * Score agency (Fannie/Freddie) execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_agency( array $deal ): array {
		$score = 30;
		$pros  = array();
		$cons  = array();

		if ( 'multifamily' === $deal['property_type'] ) {
			$score += 45;
			$pros[] = __( 'Agency lending is purpose-built for multifamily', 'mcp-ai-wpoos-pro' );
			$pros[] = __( 'Non-recourse with low rates and long terms', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 30;
			$cons[] = __( 'Agency programs only available for multifamily', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['stabilized'] && $deal['occupancy'] >= 85 ) {
			$score += 10;
			$pros[] = __( 'Stabilized property meets agency occupancy requirements', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 15;
			$cons[] = __( 'Non-stabilized or low-occupancy properties ineligible', 'mcp-ai-wpoos-pro' );
		}
		if ( 'primary' === $deal['market_tier'] || 'secondary' === $deal['market_tier'] ) {
			$score += 5;
		}
		if ( in_array( $deal['loan_purpose'], array( 'construction', 'bridge' ), true ) ) {
			$score -= 20;
			$cons[] = __( 'Not suitable for construction or bridge loans', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Best-in-class pricing for qualifying deals', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Strict underwriting requirements and reporting', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}

	/**
	 * Score CMBS execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_cmbs( array $deal ): array {
		$score = 50;
		$pros  = array();
		$cons  = array();

		if ( $deal['stabilized'] && $deal['occupancy'] >= 80 ) {
			$score += 15;
			$pros[] = __( 'Stabilized assets with strong occupancy ideal for CMBS', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 20;
			$cons[] = __( 'CMBS requires stabilized, performing collateral', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['loan_amount'] >= 5000000 ) {
			$score += 10;
			$pros[] = __( 'Deal size meets CMBS minimum thresholds', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 15;
			$cons[] = __( 'Deal may be too small for efficient CMBS execution', 'mcp-ai-wpoos-pro' );
		}
		if ( in_array( $deal['loan_purpose'], array( 'acquisition', 'refinance' ), true ) ) {
			$score += 5;
		} else {
			$score -= 15;
			$cons[] = __( 'CMBS not suited for construction or bridge loans', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Non-recourse with fixed rate and 10-year terms', 'mcp-ai-wpoos-pro' );
		$pros[] = __( 'Higher leverage available (up to 75% LTV)', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Defeasance or yield maintenance prepayment penalty', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Limited flexibility after closing', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}

	/**
	 * Score debt fund execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_debt_fund( array $deal ): array {
		$score = 45;
		$pros  = array();
		$cons  = array();

		if ( ! $deal['stabilized'] || in_array( $deal['loan_purpose'], array( 'bridge', 'construction' ), true ) ) {
			$score += 25;
			$pros[] = __( 'Debt funds specialize in transitional and value-add lending', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['ltv'] > 0.70 ) {
			$score += 10;
			$pros[] = __( 'Higher leverage available vs. traditional lenders', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['occupancy'] < 80 ) {
			$score += 10;
			$pros[] = __( 'Flexible on occupancy for value-add business plans', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Fast execution and flexible structuring', 'mcp-ai-wpoos-pro' );
		$pros[] = __( 'IO periods and future funding available', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Higher spread and pricing than permanent lenders', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Floating rate with shorter terms (2-5 years)', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}

	/**
	 * Score CRE CLO execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_cre_clo( array $deal ): array {
		$score = 40;
		$pros  = array();
		$cons  = array();

		if ( ! $deal['stabilized'] ) {
			$score += 15;
			$pros[] = __( 'CRE CLO structure accommodates transitional assets', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['loan_amount'] >= 10000000 ) {
			$score += 10;
			$pros[] = __( 'Larger loan sizes efficiently securitizable', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 10;
			$cons[] = __( 'Smaller loans may not justify securitization economics', 'mcp-ai-wpoos-pro' );
		}
		if ( 'primary' === $deal['market_tier'] ) {
			$score += 10;
			$pros[] = __( 'Primary market collateral enhances AAA bond execution', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Matched-term non-mark-to-market financing', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Complex structuring and longer lead times', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Requires originator with CLO program', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}

	/**
	 * Score life company execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_life_company( array $deal ): array {
		$score = 45;
		$pros  = array();
		$cons  = array();

		if ( $deal['stabilized'] && $deal['occupancy'] >= 90 ) {
			$score += 20;
			$pros[] = __( 'High-quality stabilized assets preferred by life companies', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 15;
			$cons[] = __( 'Life companies require stabilized, institutional-quality assets', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['ltv'] <= 0.65 ) {
			$score += 15;
			$pros[] = __( 'Low-leverage sweet spot for life company pricing', 'mcp-ai-wpoos-pro' );
		} else {
			$score -= 10;
			$cons[] = __( 'Typically limited to 65% LTV maximum', 'mcp-ai-wpoos-pro' );
		}
		if ( 'primary' === $deal['market_tier'] ) {
			$score += 10;
			$pros[] = __( 'Primary market focus aligns with portfolio strategy', 'mcp-ai-wpoos-pro' );
		} elseif ( 'tertiary' === $deal['market_tier'] ) {
			$score -= 10;
			$cons[] = __( 'Tertiary markets generally outside appetite', 'mcp-ai-wpoos-pro' );
		}
		if ( in_array( $deal['loan_purpose'], array( 'construction', 'bridge' ), true ) ) {
			$score -= 20;
			$cons[] = __( 'Not suitable for construction or bridge financing', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Best pricing for low-leverage, high-quality deals', 'mcp-ai-wpoos-pro' );
		$pros[] = __( 'Flexible prepayment and long terms available', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Longer approval process and conservative underwriting', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}

	/**
	 * Score bank execution.
	 *
	 * @param array $deal Deal context.
	 * @return array
	 */
	private function score_bank( array $deal ): array {
		$score = 50;
		$pros  = array();
		$cons  = array();

		if ( $deal['loan_amount'] < 25000000 ) {
			$score += 10;
			$pros[] = __( 'Banks competitive for smaller and mid-sized loans', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['sponsor_exp'] >= 5 ) {
			$score += 10;
			$pros[] = __( 'Relationship banking rewards experienced sponsors', 'mcp-ai-wpoos-pro' );
		}
		if ( $deal['ltv'] <= 0.70 ) {
			$score += 5;
		} else {
			$score -= 5;
			$cons[] = __( 'Regulatory constraints may limit leverage above 70% LTV', 'mcp-ai-wpoos-pro' );
		}
		if ( 'secondary' === $deal['market_tier'] || 'tertiary' === $deal['market_tier'] ) {
			$score += 5;
			$pros[] = __( 'Local/regional banks serve secondary and tertiary markets', 'mcp-ai-wpoos-pro' );
		}

		$pros[] = __( 'Deposit relationship can enhance pricing', 'mcp-ai-wpoos-pro' );
		$pros[] = __( 'Construction and bridge lending available', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Typically shorter terms with recourse', 'mcp-ai-wpoos-pro' );
		$cons[] = __( 'Variable rate most common', 'mcp-ai-wpoos-pro' );

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'pros'  => $pros,
			'cons'  => $cons,
		);
	}
}
