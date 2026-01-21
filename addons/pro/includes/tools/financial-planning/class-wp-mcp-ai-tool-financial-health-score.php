<?php
/**
 * Financial Health Score Tool
 *
 * Assess overall financial health with a 0-100 score based on savings,
 * debt, budgeting, credit, and insurance factors.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for calculating financial health score.
 *
 * Supports:
 * - Comprehensive 0-100 scoring
 * - Multi-factor assessment
 * - Personalized recommendations
 * - Benchmarking against best practices
 * - Improvement tracking over time
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Financial_Health_Score implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Financial health score tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'financial_health_score';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Financial Health Score', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Assess overall financial health with a comprehensive 0-100 score. Evaluates savings, debt management, budgeting, credit, insurance, and retirement readiness. Provides actionable recommendations for improvement.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'monthly_income'         => array(
					'type'        => 'number',
					'description' => __( 'Monthly gross income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'monthly_expenses'       => array(
					'type'        => 'number',
					'description' => __( 'Monthly expenses', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'emergency_fund'         => array(
					'type'        => 'number',
					'description' => __( 'Emergency fund balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'total_debt'             => array(
					'type'        => 'number',
					'description' => __( 'Total debt balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'retirement_savings'     => array(
					'type'        => 'number',
					'description' => __( 'Retirement account balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'credit_score'           => array(
					'type'        => 'integer',
					'description' => __( 'Credit score', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 300,
					'maximum'     => 850,
				),
				'has_budget'             => array(
					'type'        => 'boolean',
					'description' => __( 'Maintains a budget', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'has_life_insurance'     => array(
					'type'        => 'boolean',
					'description' => __( 'Has life insurance', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'has_health_insurance'   => array(
					'type'        => 'boolean',
					'description' => __( 'Has health insurance', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'retirement_contribution_pct' => array(
					'type'        => 'number',
					'description' => __( 'Retirement contribution as % of income', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
					'default'     => 0,
				),
				'age'                    => array(
					'type'        => 'integer',
					'description' => __( 'Current age', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 18,
					'maximum'     => 100,
				),
			),
			'required'   => array( 'monthly_income', 'monthly_expenses' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the financial health score tool.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$monthly_income       = isset( $arguments['monthly_income'] ) ? floatval( $arguments['monthly_income'] ) : 0;
		$monthly_expenses     = isset( $arguments['monthly_expenses'] ) ? floatval( $arguments['monthly_expenses'] ) : 0;
		$emergency_fund       = isset( $arguments['emergency_fund'] ) ? floatval( $arguments['emergency_fund'] ) : 0;
		$total_debt           = isset( $arguments['total_debt'] ) ? floatval( $arguments['total_debt'] ) : 0;
		$retirement_savings   = isset( $arguments['retirement_savings'] ) ? floatval( $arguments['retirement_savings'] ) : 0;
		$credit_score         = isset( $arguments['credit_score'] ) ? absint( $arguments['credit_score'] ) : 0;
		$has_budget           = isset( $arguments['has_budget'] ) ? (bool) $arguments['has_budget'] : false;
		$has_life_insurance   = isset( $arguments['has_life_insurance'] ) ? (bool) $arguments['has_life_insurance'] : false;
		$has_health_insurance = isset( $arguments['has_health_insurance'] ) ? (bool) $arguments['has_health_insurance'] : false;
		$retirement_contribution = isset( $arguments['retirement_contribution_pct'] ) ? floatval( $arguments['retirement_contribution_pct'] ) : 0;
		$age                  = isset( $arguments['age'] ) ? absint( $arguments['age'] ) : 0;

		if ( $monthly_income <= 0 ) {
			return new WP_Error( 'invalid_income', __( 'Monthly income must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$category_scores = array();
		$recommendations = array();

		$savings_rate = $monthly_income > 0 ? ( ( $monthly_income - $monthly_expenses ) / $monthly_income ) * 100 : 0;
		if ( $savings_rate >= 20 ) {
			$category_scores['savings'] = 20;
		} elseif ( $savings_rate >= 10 ) {
			$category_scores['savings'] = 15;
		} elseif ( $savings_rate >= 5 ) {
			$category_scores['savings'] = 10;
		} else {
			$category_scores['savings'] = 5;
			$recommendations[] = __( 'Increase savings rate to at least 20% of income.', 'mcp-ai-wpoos-pro' );
		}

		$months_emergency = $monthly_expenses > 0 ? $emergency_fund / $monthly_expenses : 0;
		if ( $months_emergency >= 6 ) {
			$category_scores['emergency_fund'] = 20;
		} elseif ( $months_emergency >= 3 ) {
			$category_scores['emergency_fund'] = 15;
		} elseif ( $months_emergency >= 1 ) {
			$category_scores['emergency_fund'] = 10;
		} else {
			$category_scores['emergency_fund'] = 0;
			$recommendations[] = __( 'Build emergency fund to cover 3-6 months of expenses.', 'mcp-ai-wpoos-pro' );
		}

		$debt_to_income = $monthly_income > 0 ? ( $total_debt / ( $monthly_income * 12 ) ) * 100 : 0;
		if ( $total_debt === 0.0 ) {
			$category_scores['debt'] = 20;
		} elseif ( $debt_to_income <= 30 ) {
			$category_scores['debt'] = 15;
		} elseif ( $debt_to_income <= 50 ) {
			$category_scores['debt'] = 10;
		} else {
			$category_scores['debt'] = 5;
			$recommendations[] = __( 'Reduce debt-to-income ratio below 30%.', 'mcp-ai-wpoos-pro' );
		}

		if ( $credit_score >= 740 ) {
			$category_scores['credit'] = 15;
		} elseif ( $credit_score >= 670 ) {
			$category_scores['credit'] = 10;
		} elseif ( $credit_score >= 580 ) {
			$category_scores['credit'] = 5;
		} else {
			$category_scores['credit'] = 0;
			$recommendations[] = __( 'Improve credit score through on-time payments and lower utilization.', 'mcp-ai-wpoos-pro' );
		}

		if ( $retirement_contribution >= 15 ) {
			$category_scores['retirement'] = 15;
		} elseif ( $retirement_contribution >= 10 ) {
			$category_scores['retirement'] = 10;
		} elseif ( $retirement_contribution >= 5 ) {
			$category_scores['retirement'] = 5;
		} else {
			$category_scores['retirement'] = 0;
			$recommendations[] = __( 'Increase retirement contributions to at least 15% of income.', 'mcp-ai-wpoos-pro' );
		}

		$category_scores['budget'] = $has_budget ? 5 : 0;
		if ( ! $has_budget ) {
			$recommendations[] = __( 'Create and maintain a monthly budget.', 'mcp-ai-wpoos-pro' );
		}

		$insurance_score = 0;
		if ( $has_health_insurance ) {
			$insurance_score += 2.5;
		} else {
			$recommendations[] = __( 'Obtain health insurance coverage.', 'mcp-ai-wpoos-pro' );
		}
		if ( $has_life_insurance ) {
			$insurance_score += 2.5;
		}
		$category_scores['insurance'] = $insurance_score;

		$total_score = array_sum( $category_scores );
		$rating = $this->get_health_rating( $total_score );

		return array(
			'success'          => true,
			'total_score'      => round( $total_score, 1 ),
			'rating'           => $rating,
			'category_scores'  => $category_scores,
			'savings_rate'     => round( $savings_rate, 1 ),
			'debt_to_income'   => round( $debt_to_income, 1 ),
			'months_emergency' => round( $months_emergency, 1 ),
			'recommendations'  => $recommendations,
			'message'          => sprintf(
				/* translators: 1: Score, 2: Rating */
				__( 'Your financial health score is %1$d/100 (%2$s).', 'mcp-ai-wpoos-pro' ),
				round( $total_score ),
				$rating
			),
		);
	}

	/**
	 * Get health rating from score.
	 *
	 * @param float $score Total score.
	 * @return string Rating.
	 */
	protected function get_health_rating( $score ) {
		if ( $score >= 80 ) {
			return 'excellent';
		} elseif ( $score >= 60 ) {
			return 'good';
		} elseif ( $score >= 40 ) {
			return 'fair';
		} else {
			return 'needs_improvement';
		}
	}
}
