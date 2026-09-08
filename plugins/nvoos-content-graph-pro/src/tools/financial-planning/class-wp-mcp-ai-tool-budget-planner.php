<?php
/**
 * Financial planning tool (ecosystem port — Wave F2, financial tools batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-budget-planner.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps (yfinance
 * service require / vendor autoload probe).
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

/**
 * Tool for creating and managing monthly budgets.
 *
 * Supports:
 * - Category-based budget allocation
 * - Spending vs budget tracking
 * - Variance analysis
 * - 50/30/20 rule suggestions
 * - Custom category creation
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Budget_Planner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Budget planner tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'budget_planner';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Budget Planner', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create and track monthly budgets with category allocations. Supports custom categories, spending limits, variance tracking, and 50/30/20 rule recommendations for needs, wants, and savings.', 'nvoos-content-graph-pro' );
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
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'create', 'update', 'track', 'suggest' ),
					'default'     => 'create',
				),
				'monthly_income' => array(
					'type'        => 'number',
					'description' => __( 'Monthly after-tax income', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'categories'     => array(
					'type'        => 'array',
					'description' => __( 'Budget categories with allocations', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array(
								'type'        => 'string',
								'description' => __( 'Category name', 'nvoos-content-graph-pro' ),
							),
							'amount' => array(
								'type'        => 'number',
								'description' => __( 'Budgeted amount', 'nvoos-content-graph-pro' ),
								'minimum'     => 0,
							),
							'type'   => array(
								'type'        => 'string',
								'description' => __( 'Category type', 'nvoos-content-graph-pro' ),
								'enum'        => array( 'needs', 'wants', 'savings' ),
								'default'     => 'needs',
							),
							'spent'  => array(
								'type'        => 'number',
								'description' => __( 'Amount spent (for tracking)', 'nvoos-content-graph-pro' ),
								'minimum'     => 0,
								'default'     => 0,
							),
						),
						'required'   => array( 'name', 'amount' ),
					),
				),
				'use_50_30_20'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use 50/30/20 rule for suggestions', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'savings_goal'   => array(
					'type'        => 'number',
					'description' => __( 'Monthly savings goal', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
			),
			'required'   => array( 'action', 'monthly_income' ),
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
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the budget planner.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$action         = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'create';
		$monthly_income = isset( $arguments['monthly_income'] ) ? floatval( $arguments['monthly_income'] ) : 0;
		$categories     = isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ? $arguments['categories'] : array();
		$use_50_30_20   = isset( $arguments['use_50_30_20'] ) ? (bool) $arguments['use_50_30_20'] : false;
		$savings_goal   = isset( $arguments['savings_goal'] ) ? floatval( $arguments['savings_goal'] ) : 0;

		if ( $monthly_income <= 0 ) {
			return new WP_Error(
				'invalid_income',
				__( 'Monthly income must be greater than zero.', 'nvoos-content-graph-pro' )
			);
		}

		// Handle different actions.
		switch ( $action ) {
			case 'suggest':
				return $this->suggest_budget( $monthly_income, $use_50_30_20, $savings_goal );
			case 'track':
				return $this->track_spending( $monthly_income, $categories );
			case 'create':
			case 'update':
				return $this->create_or_update_budget( $monthly_income, $categories, $use_50_30_20, $savings_goal );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * Suggest a budget based on income.
	 *
	 * @param float $monthly_income Monthly income.
	 * @param bool  $use_50_30_20   Use 50/30/20 rule.
	 * @param float $savings_goal   Savings goal.
	 * @return array Budget suggestion.
	 */
	protected function suggest_budget( $monthly_income, $use_50_30_20, $savings_goal ) {
		$suggested_categories = array();

		if ( $use_50_30_20 ) {
			// 50/30/20 rule: 50% needs, 30% wants, 20% savings.
			$needs_budget   = $monthly_income * 0.50;
			$wants_budget   = $monthly_income * 0.30;
			$savings_budget = $monthly_income * 0.20;

			$suggested_categories = array(
				array(
					'name'   => __( 'Housing (Rent/Mortgage)', 'nvoos-content-graph-pro' ),
					'amount' => round( $needs_budget * 0.40, 2 ),
					'type'   => 'needs',
				),
				array(
					'name'   => __( 'Utilities', 'nvoos-content-graph-pro' ),
					'amount' => round( $needs_budget * 0.10, 2 ),
					'type'   => 'needs',
				),
				array(
					'name'   => __( 'Groceries', 'nvoos-content-graph-pro' ),
					'amount' => round( $needs_budget * 0.20, 2 ),
					'type'   => 'needs',
				),
				array(
					'name'   => __( 'Transportation', 'nvoos-content-graph-pro' ),
					'amount' => round( $needs_budget * 0.15, 2 ),
					'type'   => 'needs',
				),
				array(
					'name'   => __( 'Insurance', 'nvoos-content-graph-pro' ),
					'amount' => round( $needs_budget * 0.10, 2 ),
					'type'   => 'needs',
				),
				array(
					'name'   => __( 'Healthcare', 'nvoos-content-graph-pro' ),
					'amount' => round( $needs_budget * 0.05, 2 ),
					'type'   => 'needs',
				),
				array(
					'name'   => __( 'Entertainment', 'nvoos-content-graph-pro' ),
					'amount' => round( $wants_budget * 0.40, 2 ),
					'type'   => 'wants',
				),
				array(
					'name'   => __( 'Dining Out', 'nvoos-content-graph-pro' ),
					'amount' => round( $wants_budget * 0.30, 2 ),
					'type'   => 'wants',
				),
				array(
					'name'   => __( 'Shopping', 'nvoos-content-graph-pro' ),
					'amount' => round( $wants_budget * 0.30, 2 ),
					'type'   => 'wants',
				),
				array(
					'name'   => __( 'Emergency Fund', 'nvoos-content-graph-pro' ),
					'amount' => round( $savings_budget * 0.40, 2 ),
					'type'   => 'savings',
				),
				array(
					'name'   => __( 'Retirement', 'nvoos-content-graph-pro' ),
					'amount' => round( $savings_budget * 0.40, 2 ),
					'type'   => 'savings',
				),
				array(
					'name'   => __( 'Debt Payoff', 'nvoos-content-graph-pro' ),
					'amount' => round( $savings_budget * 0.20, 2 ),
					'type'   => 'savings',
				),
			);
		} else {
			// Custom suggestion based on savings goal.
			$remaining = $monthly_income;
			if ( $savings_goal > 0 ) {
				$remaining             -= $savings_goal;
				$suggested_categories[] = array(
					'name'   => __( 'Savings/Investments', 'nvoos-content-graph-pro' ),
					'amount' => round( $savings_goal, 2 ),
					'type'   => 'savings',
				);
			}

			// Distribute remaining.
			$suggested_categories = array_merge(
				$suggested_categories,
				array(
					array(
						'name'   => __( 'Housing', 'nvoos-content-graph-pro' ),
						'amount' => round( $remaining * 0.35, 2 ),
						'type'   => 'needs',
					),
					array(
						'name'   => __( 'Food & Groceries', 'nvoos-content-graph-pro' ),
						'amount' => round( $remaining * 0.15, 2 ),
						'type'   => 'needs',
					),
					array(
						'name'   => __( 'Transportation', 'nvoos-content-graph-pro' ),
						'amount' => round( $remaining * 0.15, 2 ),
						'type'   => 'needs',
					),
					array(
						'name'   => __( 'Utilities & Bills', 'nvoos-content-graph-pro' ),
						'amount' => round( $remaining * 0.10, 2 ),
						'type'   => 'needs',
					),
					array(
						'name'   => __( 'Discretionary', 'nvoos-content-graph-pro' ),
						'amount' => round( $remaining * 0.25, 2 ),
						'type'   => 'wants',
					),
				)
			);
		}

		$total_suggested = array_sum( array_column( $suggested_categories, 'amount' ) );

		return array(
			'success'              => true,
			'monthly_income'       => $monthly_income,
			'suggested_categories' => $suggested_categories,
			'total_budgeted'       => round( $total_suggested, 2 ),
			'remaining'            => round( $monthly_income - $total_suggested, 2 ),
			'rule_used'            => $use_50_30_20 ? '50/30/20' : 'custom',
			'message'              => __( 'Budget suggestion created based on your income and preferences.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Track spending against budget.
	 *
	 * @param float $monthly_income Monthly income.
	 * @param array $categories     Categories with spending.
	 * @return array Tracking analysis.
	 */
	protected function track_spending( $monthly_income, $categories ) {
		$total_budgeted = 0;
		$total_spent    = 0;
		$over_budget    = array();
		$under_budget   = array();

		foreach ( $categories as &$category ) {
			$category['name']            = sanitize_text_field( $category['name'] );
			$category['amount']          = floatval( $category['amount'] );
			$category['spent']           = isset( $category['spent'] ) ? floatval( $category['spent'] ) : 0;
			$category['variance']        = $category['spent'] - $category['amount'];
			$category['percentage_used'] = $category['amount'] > 0 ? round( $category['spent'] / $category['amount'] * 100, 2 ) : 0;

			$total_budgeted += $category['amount'];
			$total_spent    += $category['spent'];

			if ( $category['variance'] > 0 ) {
				$over_budget[] = $category;
			} elseif ( $category['variance'] < 0 ) {
				$under_budget[] = $category;
			}
		}

		$total_variance = $total_spent - $total_budgeted;
		$is_on_track    = $total_variance <= 0;

		return array(
			'success'            => true,
			'monthly_income'     => $monthly_income,
			'total_budgeted'     => round( $total_budgeted, 2 ),
			'total_spent'        => round( $total_spent, 2 ),
			'total_variance'     => round( $total_variance, 2 ),
			'is_on_track'        => $is_on_track,
			'categories'         => $categories,
			'over_budget_count'  => count( $over_budget ),
			'under_budget_count' => count( $under_budget ),
			'over_budget'        => $over_budget,
			'under_budget'       => $under_budget,
			'message'            => $is_on_track
				? __( 'Great! You are staying within your budget.', 'nvoos-content-graph-pro' )
				: sprintf(
					/* translators: %s: Amount over budget */
					__( 'You are $%s over budget. Review over-budget categories.', 'nvoos-content-graph-pro' ),
					number_format( abs( $total_variance ), 2 )
				),
		);
	}

	/**
	 * Create or update budget.
	 *
	 * @param float $monthly_income Monthly income.
	 * @param array $categories     Categories.
	 * @param bool  $use_50_30_20   Use 50/30/20 rule.
	 * @param float $savings_goal   Savings goal.
	 * @return array Budget summary.
	 */
	protected function create_or_update_budget( $monthly_income, $categories, $use_50_30_20, $savings_goal ) {
		$total_budgeted = 0;
		$needs_total    = 0;
		$wants_total    = 0;
		$savings_total  = 0;

		foreach ( $categories as &$category ) {
			$category['name']   = sanitize_text_field( $category['name'] );
			$category['amount'] = floatval( $category['amount'] );
			$category['type']   = isset( $category['type'] ) ? sanitize_text_field( $category['type'] ) : 'needs';

			$total_budgeted += $category['amount'];

			switch ( $category['type'] ) {
				case 'needs':
					$needs_total += $category['amount'];
					break;
				case 'wants':
					$wants_total += $category['amount'];
					break;
				case 'savings':
					$savings_total += $category['amount'];
					break;
			}
		}

		$remaining = $monthly_income - $total_budgeted;

		// Calculate percentages.
		$needs_percentage   = $monthly_income > 0 ? round( $needs_total / $monthly_income * 100, 2 ) : 0;
		$wants_percentage   = $monthly_income > 0 ? round( $wants_total / $monthly_income * 100, 2 ) : 0;
		$savings_percentage = $monthly_income > 0 ? round( $savings_total / $monthly_income * 100, 2 ) : 0;

		return array(
			'success'        => true,
			'monthly_income' => $monthly_income,
			'total_budgeted' => round( $total_budgeted, 2 ),
			'remaining'      => round( $remaining, 2 ),
			'categories'     => $categories,
			'breakdown'      => array(
				'needs'   => array(
					'amount'     => round( $needs_total, 2 ),
					'percentage' => $needs_percentage,
				),
				'wants'   => array(
					'amount'     => round( $wants_total, 2 ),
					'percentage' => $wants_percentage,
				),
				'savings' => array(
					'amount'     => round( $savings_total, 2 ),
					'percentage' => $savings_percentage,
				),
			),
			'is_balanced'    => abs( $remaining ) < 0.01,
			'message'        => abs( $remaining ) < 0.01
				? __( 'Budget created successfully with all income allocated.', 'nvoos-content-graph-pro' )
				: sprintf(
					/* translators: %s: Remaining amount */
					__( 'Budget created. You have $%s remaining to allocate.', 'nvoos-content-graph-pro' ),
					number_format( abs( $remaining ), 2 )
				),
		);
	}
}
