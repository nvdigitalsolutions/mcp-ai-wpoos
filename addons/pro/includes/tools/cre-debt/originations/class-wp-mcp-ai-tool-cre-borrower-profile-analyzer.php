<?php
/**
 * CRE Borrower Profile Analyzer — Assess borrower/sponsor financial strength and creditworthiness
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

/**
 * Analyzes borrower financial profile including net worth, liquidity, leverage,
 * experience, and credit metrics to produce a composite strength rating.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_borrower_profile_analyzer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Borrower Profile Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Assess borrower/sponsor financial strength by analyzing net worth, liquidity, leverage, experience, and credit score to produce a composite strength rating (strong/acceptable/weak/unacceptable).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'borrower_name'        => array(
					'type'        => 'string',
					'description' => __( 'Borrower or sponsor name.', 'mcp-ai-wpoos-pro' ),
				),
				'net_worth'            => array(
					'type'        => 'number',
					'description' => __( 'Total net worth.', 'mcp-ai-wpoos-pro' ),
				),
				'liquidity'            => array(
					'type'        => 'number',
					'description' => __( 'Liquid assets (cash and equivalents).', 'mcp-ai-wpoos-pro' ),
				),
				'total_debt'           => array(
					'type'        => 'number',
					'description' => __( 'Total outstanding debt obligations.', 'mcp-ai-wpoos-pro' ),
				),
				'annual_income'        => array(
					'type'        => 'number',
					'description' => __( 'Annual gross income.', 'mcp-ai-wpoos-pro' ),
				),
				'years_experience'     => array(
					'type'        => 'integer',
					'description' => __( 'Years of CRE experience.', 'mcp-ai-wpoos-pro' ),
				),
				'num_properties_owned' => array(
					'type'        => 'integer',
					'description' => __( 'Number of properties currently owned.', 'mcp-ai-wpoos-pro' ),
				),
				'prior_defaults'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of prior loan defaults.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'credit_score'         => array(
					'type'        => 'integer',
					'description' => __( 'Credit score (300-850).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'borrower_name', 'net_worth', 'liquidity', 'total_debt', 'annual_income', 'years_experience', 'credit_score' ),
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

		$borrower_name  = sanitize_text_field( $arguments['borrower_name'] ?? '' );
		$net_worth      = (float) ( $arguments['net_worth'] ?? 0 );
		$liquidity      = (float) ( $arguments['liquidity'] ?? 0 );
		$total_debt     = (float) ( $arguments['total_debt'] ?? 0 );
		$annual_income  = (float) ( $arguments['annual_income'] ?? 0 );
		$years_exp      = (int) ( $arguments['years_experience'] ?? 0 );
		$num_properties = (int) ( $arguments['num_properties_owned'] ?? 0 );
		$prior_defaults = (int) ( $arguments['prior_defaults'] ?? 0 );
		$credit_score   = (int) ( $arguments['credit_score'] ?? 0 );

		if ( $net_worth <= 0 || $annual_income <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'Net worth and annual income must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		// Calculate financial ratios.
		$total_assets       = $net_worth + $total_debt;
		$global_leverage    = ( $total_assets > 0 ) ? $total_debt / $total_assets : 0.0;
		$liquidity_coverage = ( $total_debt > 0 ) ? $liquidity / $total_debt : ( $liquidity > 0 ? 99.0 : 0.0 );
		$debt_to_income     = ( $annual_income > 0 ) ? $total_debt / $annual_income : 0.0;
		$net_worth_ratio    = ( $total_debt > 0 ) ? $net_worth / $total_debt : ( $net_worth > 0 ? 99.0 : 0.0 );

		// Score each dimension (0-25 pts each, total 100).
		$leverage_score   = $this->score_leverage( $global_leverage );
		$liquidity_score  = $this->score_liquidity( $liquidity_coverage );
		$credit_score_pts = $this->score_credit( $credit_score, $prior_defaults );
		$experience_score = $this->score_experience( $years_exp, $num_properties );

		$composite_score = $leverage_score + $liquidity_score + $credit_score_pts + $experience_score;

		// Determine rating.
		if ( $prior_defaults >= 2 ) {
			$rating = 'unacceptable';
		} elseif ( $composite_score >= 80 ) {
			$rating = 'strong';
		} elseif ( $composite_score >= 60 ) {
			$rating = 'acceptable';
		} elseif ( $composite_score >= 40 ) {
			$rating = 'weak';
		} else {
			$rating = 'unacceptable';
		}

		$flags = array();
		if ( $global_leverage > 0.75 ) {
			$flags[] = __( 'High global leverage (>75%)', 'mcp-ai-wpoos-pro' );
		}
		if ( $liquidity_coverage < 0.10 ) {
			$flags[] = __( 'Low liquidity coverage (<10%)', 'mcp-ai-wpoos-pro' );
		}
		if ( $debt_to_income > 8.0 ) {
			$flags[] = __( 'Elevated debt-to-income (>8x)', 'mcp-ai-wpoos-pro' );
		}
		if ( $credit_score < 650 ) {
			$flags[] = __( 'Below-threshold credit score (<650)', 'mcp-ai-wpoos-pro' );
		}
		if ( $prior_defaults > 0 ) {
			$flags[] = sprintf(
				/* translators: %d: number of defaults */
				__( '%d prior default(s) on record', 'mcp-ai-wpoos-pro' ),
				$prior_defaults
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Borrower profile analysis complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'borrower_name'    => $borrower_name,
				'financial_ratios' => array(
					'global_leverage'     => round( $global_leverage, 4 ),
					'global_leverage_pct' => round( $global_leverage * 100, 2 ) . '%',
					'liquidity_coverage'  => round( $liquidity_coverage, 4 ),
					'debt_to_income'      => round( $debt_to_income, 2 ) . 'x',
					'net_worth_ratio'     => round( $net_worth_ratio, 2 ) . 'x',
				),
				'scoring'          => array(
					'leverage_score'   => $leverage_score . '/25',
					'liquidity_score'  => $liquidity_score . '/25',
					'credit_score'     => $credit_score_pts . '/25',
					'experience_score' => $experience_score . '/25',
					'composite_score'  => $composite_score . '/100',
				),
				'rating'           => strtoupper( $rating ),
				'risk_flags'       => $flags,
			),
		);
	}

	/**
	 * Score leverage ratio (0-25 pts). Lower leverage = higher score.
	 *
	 * @param float $leverage Global leverage ratio.
	 * @return int
	 */
	private function score_leverage( float $leverage ): int {
		if ( $leverage <= 0.40 ) {
			return 25;
		}
		if ( $leverage <= 0.55 ) {
			return 20;
		}
		if ( $leverage <= 0.65 ) {
			return 15;
		}
		if ( $leverage <= 0.75 ) {
			return 10;
		}
		if ( $leverage <= 0.85 ) {
			return 5;
		}
		return 0;
	}

	/**
	 * Score liquidity coverage (0-25 pts). Higher coverage = higher score.
	 *
	 * @param float $coverage Liquidity / total debt.
	 * @return int
	 */
	private function score_liquidity( float $coverage ): int {
		if ( $coverage >= 0.25 ) {
			return 25;
		}
		if ( $coverage >= 0.15 ) {
			return 20;
		}
		if ( $coverage >= 0.10 ) {
			return 15;
		}
		if ( $coverage >= 0.05 ) {
			return 10;
		}
		if ( $coverage >= 0.02 ) {
			return 5;
		}
		return 0;
	}

	/**
	 * Score credit (0-25 pts). Higher score + no defaults = better.
	 *
	 * @param int $credit_score  FICO score.
	 * @param int $prior_defaults Number of prior defaults.
	 * @return int
	 */
	private function score_credit( int $credit_score, int $prior_defaults ): int {
		$pts = 0;
		if ( $credit_score >= 760 ) {
			$pts = 25;
		} elseif ( $credit_score >= 720 ) {
			$pts = 20;
		} elseif ( $credit_score >= 680 ) {
			$pts = 15;
		} elseif ( $credit_score >= 650 ) {
			$pts = 10;
		} elseif ( $credit_score >= 600 ) {
			$pts = 5;
		}

		// Penalise for defaults.
		$pts -= $prior_defaults * 8;
		return max( 0, $pts );
	}

	/**
	 * Score experience (0-25 pts).
	 *
	 * @param int $years_exp     Years of CRE experience.
	 * @param int $num_properties Properties owned.
	 * @return int
	 */
	private function score_experience( int $years_exp, int $num_properties ): int {
		$pts = 0;

		// Years experience (up to 15 pts).
		if ( $years_exp >= 15 ) {
			$pts += 15;
		} elseif ( $years_exp >= 10 ) {
			$pts += 12;
		} elseif ( $years_exp >= 5 ) {
			$pts += 8;
		} elseif ( $years_exp >= 2 ) {
			$pts += 4;
		}

		// Portfolio size (up to 10 pts).
		if ( $num_properties >= 10 ) {
			$pts += 10;
		} elseif ( $num_properties >= 5 ) {
			$pts += 7;
		} elseif ( $num_properties >= 2 ) {
			$pts += 4;
		} elseif ( $num_properties >= 1 ) {
			$pts += 2;
		}

		return min( 25, $pts );
	}
}
