<?php
/**
 * Financial Planner Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Financial Planner Toolkit Settings Page Class
 */
class WP_MCP_AI_Financial_Planner_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'financial_planner';
		$this->toolkit_name     = __( 'Financial Planner Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_financial_planner_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-financial-planner-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-money-alt';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Financial Planner Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive financial planning toolkit with 24 powerful tools for retirement planning, budgeting, portfolio management, and financial analysis.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Retirement Planning: Calculate retirement needs, optimize social security, and plan withdrawals', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Budget Management: Track expenses, analyze cash flow, and plan savings goals', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Investment Analysis: Visualize portfolios, plan asset allocation, and track rebalancing', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Debt Management: Calculate payoff strategies, track mortgage amortization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Tax Planning: Estimate taxes, track tax-loss harvesting opportunities', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Financial Health: Calculate net worth, analyze financial health score, plan insurance needs', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Financial Planner Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Currency', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="default_currency" value="USD" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Default currency for financial calculations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Interest Rate', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="default_interest_rate" value="7" step="0.1" min="0" max="100" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Default annual interest rate for investment calculations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Inflation Rate', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="default_inflation_rate" value="3" step="0.1" min="0" max="100" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Default annual inflation rate for future value calculations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Bank Account Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_bank_sync" value="1" />
							<?php esc_html_e( 'Allow syncing with bank accounts via third-party services', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'retirement_calculator'        => __( 'Retirement Calculator', 'mcp-ai-wpoos-pro' ),
			'ira_roth_comparison'          => __( 'IRA/Roth Comparison', 'mcp-ai-wpoos-pro' ),
			'withdrawal_strategy_planner'  => __( 'Withdrawal Strategy Planner', 'mcp-ai-wpoos-pro' ),
			'social_security_optimizer'    => __( 'Social Security Optimizer', 'mcp-ai-wpoos-pro' ),
			'pension_analyzer'             => __( 'Pension Analyzer', 'mcp-ai-wpoos-pro' ),
			'budget_planner'               => __( 'Budget Planner', 'mcp-ai-wpoos-pro' ),
			'expense_tracker'              => __( 'Expense Tracker', 'mcp-ai-wpoos-pro' ),
			'net_worth_calculator'         => __( 'Net Worth Calculator', 'mcp-ai-wpoos-pro' ),
			'cash_flow_analyzer'           => __( 'Cash Flow Analyzer', 'mcp-ai-wpoos-pro' ),
			'bank_account_sync'            => __( 'Bank Account Sync', 'mcp-ai-wpoos-pro' ),
			'portfolio_visualizer'         => __( 'Portfolio Visualizer', 'mcp-ai-wpoos-pro' ),
			'asset_allocation_planner'     => __( 'Asset Allocation Planner', 'mcp-ai-wpoos-pro' ),
			'investment_return_calculator' => __( 'Investment Return Calculator', 'mcp-ai-wpoos-pro' ),
			'rebalancing_analyzer'         => __( 'Rebalancing Analyzer', 'mcp-ai-wpoos-pro' ),
			'tax_loss_harvesting_tracker'  => __( 'Tax Loss Harvesting Tracker', 'mcp-ai-wpoos-pro' ),
			'debt_payoff_calculator'       => __( 'Debt Payoff Calculator', 'mcp-ai-wpoos-pro' ),
			'mortgage_calculator'          => __( 'Mortgage Calculator', 'mcp-ai-wpoos-pro' ),
			'credit_score_tracker'         => __( 'Credit Score Tracker', 'mcp-ai-wpoos-pro' ),
			'savings_goal_planner'         => __( 'Savings Goal Planner', 'mcp-ai-wpoos-pro' ),
			'emergency_fund_calculator'    => __( 'Emergency Fund Calculator', 'mcp-ai-wpoos-pro' ),
			'financial_health_score'       => __( 'Financial Health Score', 'mcp-ai-wpoos-pro' ),
			'tax_estimator'                => __( 'Tax Estimator', 'mcp-ai-wpoos-pro' ),
			'college_savings_calculator'   => __( 'College Savings Calculator', 'mcp-ai-wpoos-pro' ),
			'insurance_needs_analyzer'     => __( 'Insurance Needs Analyzer', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Financial_Planner_Settings_Page();
}
