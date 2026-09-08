<?php
/**
 * Financial admin page (ecosystem port — Wave F2, financial admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-financial-planner-cpt-settings-page.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the
 * `src/` root (base-class/yfinance-service requires).
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

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Financial Planner Settings Page (CPT-Based)
 */
class WP_MCP_AI_Financial_Planner_CPT_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_financial_planner_settings';
		$this->post_type   = 'mcp_ai_fin_account';
		$this->page_title  = __( 'Financial Planner Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'financial-planner-settings';

		// Call parent constructor to set up hooks.
		// Parent registers at priority 25 under CPT menu.
		parent::__construct();
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.1.0
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'Financial Planner Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
		
		<div class="toolkit-description">
			<p><?php esc_html_e( 'Comprehensive financial planning toolkit with 24 powerful tools for retirement planning, budgeting, portfolio management, and financial analysis.', 'nvoos-content-graph-pro' ); ?></p>
			<p><strong><?php esc_html_e( 'Works Independently:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'All tools function without requiring external API connections. You can manually manage all financial data. Optional Plaid API integration available for automatic bank account sync.', 'nvoos-content-graph-pro' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Manual Financial Management: Track accounts, budgets, and transactions without any API dependencies', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Retirement Planning: Calculate retirement needs, optimize social security, and plan withdrawals', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Budget Management: Track expenses, analyze cash flow, and plan savings goals', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Investment Analysis: Visualize portfolios, plan asset allocation, and track rebalancing', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Debt Management: Calculate payoff strategies, track mortgage amortization', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Tax Planning: Estimate taxes, track tax-loss harvesting opportunities', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Financial Health: Calculate net worth, analyze financial health score, plan insurance needs', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Optional API Sync: Connect to Plaid for automatic bank transaction sync (not required)', 'nvoos-content-graph-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'nvoos-content-graph-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Personal financial planning and budgeting', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Retirement and investment planning', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Debt management and payoff strategies', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Financial education and goal tracking', 'nvoos-content-graph-pro' ); ?></li>
		</ul>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Privacy First:', 'nvoos-content-graph-pro' ); ?></strong>
				<?php esc_html_e( 'Your financial data stays in your WordPress database. External API connections are completely optional.', 'nvoos-content-graph-pro' ); ?>
			</p>
		</div>

		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Educational Use Only:', 'nvoos-content-graph-pro' ); ?></strong>
				<?php esc_html_e( 'This toolkit is for educational and informational purposes only. It does not constitute financial advice. Always consult with qualified financial professionals for personal financial decisions.', 'nvoos-content-graph-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @since 1.1.0
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			'retirement_calculator'        => __( 'Retirement Calculator', 'nvoos-content-graph-pro' ),
			'ira_roth_comparison'          => __( 'IRA/Roth Comparison', 'nvoos-content-graph-pro' ),
			'withdrawal_strategy_planner'  => __( 'Withdrawal Strategy Planner', 'nvoos-content-graph-pro' ),
			'social_security_optimizer'    => __( 'Social Security Optimizer', 'nvoos-content-graph-pro' ),
			'pension_analyzer'             => __( 'Pension Analyzer', 'nvoos-content-graph-pro' ),
			'budget_planner'               => __( 'Budget Planner', 'nvoos-content-graph-pro' ),
			'expense_tracker'              => __( 'Expense Tracker', 'nvoos-content-graph-pro' ),
			'net_worth_calculator'         => __( 'Net Worth Calculator', 'nvoos-content-graph-pro' ),
			'cash_flow_analyzer'           => __( 'Cash Flow Analyzer', 'nvoos-content-graph-pro' ),
			'bank_account_sync'            => __( 'Bank Account Sync (Optional API)', 'nvoos-content-graph-pro' ),
			'portfolio_visualizer'         => __( 'Portfolio Visualizer', 'nvoos-content-graph-pro' ),
			'asset_allocation_planner'     => __( 'Asset Allocation Planner', 'nvoos-content-graph-pro' ),
			'investment_return_calculator' => __( 'Investment Return Calculator', 'nvoos-content-graph-pro' ),
			'rebalancing_analyzer'         => __( 'Rebalancing Analyzer', 'nvoos-content-graph-pro' ),
			'tax_loss_harvesting_tracker'  => __( 'Tax Loss Harvesting Tracker', 'nvoos-content-graph-pro' ),
			'debt_payoff_calculator'       => __( 'Debt Payoff Calculator', 'nvoos-content-graph-pro' ),
			'mortgage_calculator'          => __( 'Mortgage Calculator', 'nvoos-content-graph-pro' ),
			'credit_score_tracker'         => __( 'Credit Score Tracker', 'nvoos-content-graph-pro' ),
			'savings_goal_planner'         => __( 'Savings Goal Planner', 'nvoos-content-graph-pro' ),
			'emergency_fund_calculator'    => __( 'Emergency Fund Calculator', 'nvoos-content-graph-pro' ),
			'financial_health_score'       => __( 'Financial Health Score', 'nvoos-content-graph-pro' ),
			'tax_estimator'                => __( 'Tax Estimator', 'nvoos-content-graph-pro' ),
			'college_savings_calculator'   => __( 'College Savings Calculator', 'nvoos-content-graph-pro' ),
			'insurance_needs_analyzer'     => __( 'Insurance Needs Analyzer', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add financial planner-specific settings section.
		add_settings_section(
			$this->option_name . '_defaults_section',
			__( 'Default Financial Settings', 'nvoos-content-graph-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'default_currency',
			__( 'Default Currency', 'nvoos-content-graph-pro' ),
			array( $this, 'render_currency_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_interest_rate',
			__( 'Default Interest Rate', 'nvoos-content-graph-pro' ),
			array( $this, 'render_interest_rate_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_inflation_rate',
			__( 'Default Inflation Rate', 'nvoos-content-graph-pro' ),
			array( $this, 'render_inflation_rate_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		// Plaid API settings (optional).
		add_settings_section(
			$this->option_name . '_api_section',
			__( 'API Integration (Optional)', 'nvoos-content-graph-pro' ),
			array( $this, 'render_api_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'enable_bank_sync',
			__( 'Enable Bank Account Sync', 'nvoos-content-graph-pro' ),
			array( $this, 'render_bank_sync_field' ),
			$this->option_name,
			$this->option_name . '_api_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default values used by financial planning tools.', 'nvoos-content-graph-pro' ) . '</p>';
	}

	/**
	 * Render API section description.
	 */
	public function render_api_section_description() {
		echo '<p>' . esc_html__( 'Optional: Configure external API integrations for automatic data sync. The toolkit works completely independently without these.', 'nvoos-content-graph-pro' ) . '</p>';
	}

	/**
	 * Render currency field.
	 */
	public function render_currency_field() {
		$options  = get_option( $this->option_name, array() );
		$currency = isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD';
		?>
		<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[default_currency]" 
			value="<?php echo esc_attr( $currency ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Default currency for financial calculations (e.g., USD, EUR, GBP)', 'nvoos-content-graph-pro' ); ?></p>
		<?php
	}

	/**
	 * Render interest rate field.
	 */
	public function render_interest_rate_field() {
		$options = get_option( $this->option_name, array() );
		$rate    = isset( $options['default_interest_rate'] ) ? $options['default_interest_rate'] : '7';
		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_interest_rate]" 
			value="<?php echo esc_attr( $rate ); ?>" step="0.1" min="0" max="100" class="small-text" />
		<span>%</span>
		<p class="description"><?php esc_html_e( 'Default annual interest rate for investment calculations', 'nvoos-content-graph-pro' ); ?></p>
		<?php
	}

	/**
	 * Render inflation rate field.
	 */
	public function render_inflation_rate_field() {
		$options = get_option( $this->option_name, array() );
		$rate    = isset( $options['default_inflation_rate'] ) ? $options['default_inflation_rate'] : '3';
		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_inflation_rate]" 
			value="<?php echo esc_attr( $rate ); ?>" step="0.1" min="0" max="100" class="small-text" />
		<span>%</span>
		<p class="description"><?php esc_html_e( 'Default annual inflation rate for future value calculations', 'nvoos-content-graph-pro' ); ?></p>
		<?php
	}

	/**
	 * Render bank sync field.
	 */
	public function render_bank_sync_field() {
		$options = get_option( $this->option_name, array() );
		$enabled = isset( $options['enable_bank_sync'] ) ? (bool) $options['enable_bank_sync'] : false;
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_bank_sync]" 
				value="1" <?php checked( $enabled, true ); ?> />
			<?php esc_html_e( 'Allow optional syncing with bank accounts via third-party services', 'nvoos-content-graph-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Optional feature. The toolkit works completely independently without this. When enabled, users can choose to connect their bank accounts via Plaid API.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Let parent handle assistant_id.
		$sanitized = parent::sanitize_settings( $input );

		// Sanitize currency.
		if ( isset( $input['default_currency'] ) ) {
			$sanitized['default_currency'] = sanitize_text_field( $input['default_currency'] );
		}

		// Sanitize interest rate.
		if ( isset( $input['default_interest_rate'] ) ) {
			$sanitized['default_interest_rate'] = floatval( $input['default_interest_rate'] );
		}

		// Sanitize inflation rate.
		if ( isset( $input['default_inflation_rate'] ) ) {
			$sanitized['default_inflation_rate'] = floatval( $input['default_inflation_rate'] );
		}

		// Sanitize bank sync.
		$sanitized['enable_bank_sync'] = isset( $input['enable_bank_sync'] ) ? (bool) $input['enable_bank_sync'] : false;

		return $sanitized;
	}
}

// Initialize if financial planner toolkit is enabled.
$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( ! empty( $settings['enable_financial_planner_toolkit'] ) ) {
	new WP_MCP_AI_Financial_Planner_CPT_Settings_Page();
}
