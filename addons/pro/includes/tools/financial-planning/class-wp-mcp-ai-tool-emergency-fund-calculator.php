<?php
/**
 * Emergency Fund Calculator Tool
 *
 * Calculate emergency fund needs based on monthly expenses and recommend
 * 3-6 months of expenses for financial security.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for calculating emergency fund requirements.
 *
 * Supports:
 * - 3-6 month expense calculation
 * - Income stability assessment
 * - Customized recommendations
 * - Progress tracking
 * - Gap analysis
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Emergency_Fund_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

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

		return __( 'Emergency fund calculator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'emergency_fund_calculator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Emergency Fund Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate emergency fund needs based on monthly expenses. Recommends 3-6 months of expenses based on income stability and family situation. Helps build financial safety net.', 'mcp-ai-wpoos-pro' );
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
				'monthly_expenses'         => array(
					'type'        => 'number',
					'description' => __( 'Average monthly expenses', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'current_emergency_fund'   => array(
					'type'        => 'number',
					'description' => __( 'Current emergency fund balance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'employment_type'          => array(
					'type'        => 'string',
					'description' => __( 'Employment situation', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'stable', 'contractor', 'self_employed', 'single_income', 'dual_income' ),
					'default'     => 'stable',
				),
				'dependents'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of dependents', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'has_disability_insurance' => array(
					'type'        => 'boolean',
					'description' => __( 'Has disability insurance', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'industry_stability'       => array(
					'type'        => 'string',
					'description' => __( 'Industry stability', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'stable', 'volatile', 'uncertain' ),
					'default'     => 'stable',
				),
			),
			'required'   => array( 'monthly_expenses' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the emergency fund calculator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$monthly_expenses         = isset( $arguments['monthly_expenses'] ) ? floatval( $arguments['monthly_expenses'] ) : 0;
		$current_emergency_fund   = isset( $arguments['current_emergency_fund'] ) ? floatval( $arguments['current_emergency_fund'] ) : 0;
		$employment_type          = isset( $arguments['employment_type'] ) ? sanitize_text_field( $arguments['employment_type'] ) : 'stable';
		$dependents               = isset( $arguments['dependents'] ) ? absint( $arguments['dependents'] ) : 0;
		$has_disability_insurance = isset( $arguments['has_disability_insurance'] ) ? (bool) $arguments['has_disability_insurance'] : false;
		$industry_stability       = isset( $arguments['industry_stability'] ) ? sanitize_text_field( $arguments['industry_stability'] ) : 'stable';

		if ( $monthly_expenses <= 0 ) {
			return new WP_Error( 'invalid_expenses', __( 'Monthly expenses must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$base_months = 3;

		if ( 'self_employed' === $employment_type || 'contractor' === $employment_type ) {
			$base_months += 2;
		} elseif ( 'single_income' === $employment_type ) {
			++$base_months;
		}

		if ( $dependents > 0 ) {
			++$base_months;
		}

		if ( ! $has_disability_insurance ) {
			++$base_months;
		}

		if ( 'volatile' === $industry_stability ) {
			++$base_months;
		} elseif ( 'uncertain' === $industry_stability ) {
			$base_months += 2;
		}

		$recommended_months = min( 12, max( 3, $base_months ) );
		$minimum_fund       = $monthly_expenses * 3;
		$recommended_fund   = $monthly_expenses * $recommended_months;
		$optimal_fund       = $monthly_expenses * 6;

		$gap            = $recommended_fund - $current_emergency_fund;
		$months_covered = $monthly_expenses > 0 ? $current_emergency_fund / $monthly_expenses : 0;
		$progress_pct   = $recommended_fund > 0 ? ( $current_emergency_fund / $recommended_fund ) * 100 : 0;

		$status = 'needs_building';
		if ( $current_emergency_fund >= $recommended_fund ) {
			$status = 'fully_funded';
		} elseif ( $current_emergency_fund >= $minimum_fund ) {
			$status = 'partially_funded';
		}

		$recommendations = array();
		if ( 'needs_building' === $status ) {
			$recommendations[] = __( 'Start building your emergency fund immediately. Aim for at least 3 months of expenses.', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Set up automatic transfers to a dedicated savings account.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'partially_funded' === $status ) {
			$recommendations[] = sprintf(
				/* translators: %d: Recommended months */
				__( 'Continue building toward %d months of expenses for optimal protection.', 'mcp-ai-wpoos-pro' ),
				$recommended_months
			);
		} else {
			$recommendations[] = __( 'Your emergency fund is well-funded. Maintain this balance and adjust as expenses change.', 'mcp-ai-wpoos-pro' );
		}

		$recommendations[] = __( 'Keep emergency funds in a high-yield savings account for easy access.', 'mcp-ai-wpoos-pro' );
		$recommendations[] = __( 'Review and adjust your emergency fund annually or after major life changes.', 'mcp-ai-wpoos-pro' );

		return array(
			'success'                => true,
			'monthly_expenses'       => $monthly_expenses,
			'current_emergency_fund' => $current_emergency_fund,
			'minimum_fund'           => round( $minimum_fund, 2 ),
			'recommended_fund'       => round( $recommended_fund, 2 ),
			'optimal_fund'           => round( $optimal_fund, 2 ),
			'recommended_months'     => $recommended_months,
			'months_covered'         => round( $months_covered, 1 ),
			'gap'                    => round( max( 0, $gap ), 2 ),
			'progress_pct'           => round( $progress_pct, 1 ),
			'status'                 => $status,
			'recommendations'        => $recommendations,
			'message'                => sprintf(
				/* translators: 1: Recommended fund amount, 2: Months */
				__( 'Build an emergency fund of $%1$s (%2$d months of expenses).', 'mcp-ai-wpoos-pro' ),
				number_format( $recommended_fund, 2 ),
				$recommended_months
			),
		);
	}
}
