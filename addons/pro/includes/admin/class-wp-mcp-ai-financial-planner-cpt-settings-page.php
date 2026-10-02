<?php
/**
 * Financial Planner Toolkit Settings Page (CPT-Based)
 *
 * Settings page for Financial Planner Toolkit that appears under
 * the Financial Accounts CPT menu. Follows the same pattern as
 * Quiz, Project, and other CPT-based toolkits.
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

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Financial Planner Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
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
		<h2><?php esc_html_e( 'Financial Planner Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
		
		<div class="toolkit-description">
			<p><?php esc_html_e( 'Comprehensive financial planning toolkit with 24 powerful tools for retirement planning, budgeting, portfolio management, and financial analysis.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><strong><?php esc_html_e( 'Works Independently:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'All tools function without requiring external API connections. You can manually manage all financial data. Optional Plaid API integration available for automatic bank account sync.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Manual Financial Management: Track accounts, budgets, and transactions without any API dependencies', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Retirement Planning: Calculate retirement needs, optimize social security, and plan withdrawals', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Budget Management: Track expenses, analyze cash flow, and plan savings goals', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Investment Analysis: Visualize portfolios, plan asset allocation, and track rebalancing', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Debt Management: Calculate payoff strategies, track mortgage amortization', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Tax Planning: Estimate taxes, track tax-loss harvesting opportunities', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Financial Health: Calculate net worth, analyze financial health score, plan insurance needs', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Optional API Sync: Connect to Plaid for automatic bank transaction sync (not required)', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Personal financial planning and budgeting', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Retirement and investment planning', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Debt management and payoff strategies', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Financial education and goal tracking', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Privacy First:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'Your financial data stays in your WordPress database. External API connections are completely optional.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>

		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Educational Use Only:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'This toolkit is for educational and informational purposes only. It does not constitute financial advice. Always consult with qualified financial professionals for personal financial decisions.', 'mcp-ai-wpoos-pro' ); ?>
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
			'retirement_calculator'        => __( 'Retirement Calculator', 'mcp-ai-wpoos-pro' ),
			'ira_roth_comparison'          => __( 'IRA/Roth Comparison', 'mcp-ai-wpoos-pro' ),
			'withdrawal_strategy_planner'  => __( 'Withdrawal Strategy Planner', 'mcp-ai-wpoos-pro' ),
			'social_security_optimizer'    => __( 'Social Security Optimizer', 'mcp-ai-wpoos-pro' ),
			'pension_analyzer'             => __( 'Pension Analyzer', 'mcp-ai-wpoos-pro' ),
			'budget_planner'               => __( 'Budget Planner', 'mcp-ai-wpoos-pro' ),
			'expense_tracker'              => __( 'Expense Tracker', 'mcp-ai-wpoos-pro' ),
			'net_worth_calculator'         => __( 'Net Worth Calculator', 'mcp-ai-wpoos-pro' ),
			'cash_flow_analyzer'           => __( 'Cash Flow Analyzer', 'mcp-ai-wpoos-pro' ),
			'bank_account_sync'            => __( 'Bank Account Sync (Optional API)', 'mcp-ai-wpoos-pro' ),
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
			'watchlist_sync'               => __( 'Watchlist Sync', 'mcp-ai-wpoos-pro' ),
			'market_overview_widget'       => __( 'Market Overview Widget', 'mcp-ai-wpoos-pro' ),
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
			__( 'Default Financial Settings', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'default_currency',
			__( 'Default Currency', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_currency_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_interest_rate',
			__( 'Default Interest Rate', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_interest_rate_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_inflation_rate',
			__( 'Default Inflation Rate', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_inflation_rate_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		// Plaid API settings (optional).
		add_settings_section(
			$this->option_name . '_api_section',
			__( 'API Integration (Optional)', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_api_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'enable_bank_sync',
			__( 'Enable Bank Account Sync', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_bank_sync_field' ),
			$this->option_name,
			$this->option_name . '_api_section'
		);

		// Market data providers (OpenStock parity, proposal 051).
		add_settings_section(
			$this->option_name . '_market_data_section',
			__( 'Market Data Providers (Optional)', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_market_data_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'finnhub_api_keys',
			__( 'Finnhub API Keys', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_finnhub_keys_field' ),
			$this->option_name,
			$this->option_name . '_market_data_section'
		);

		add_settings_field(
			'finnhub_data_mode',
			__( 'Market Data Mode', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_finnhub_data_mode_field' ),
			$this->option_name,
			$this->option_name . '_market_data_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default values used by financial planning tools.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render API section description.
	 */
	public function render_api_section_description() {
		echo '<p>' . esc_html__( 'Optional: Configure external API integrations for automatic data sync. The toolkit works completely independently without these.', 'mcp-ai-wpoos-pro' ) . '</p>';
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
		<p class="description"><?php esc_html_e( 'Default currency for financial calculations (e.g., USD, EUR, GBP)', 'mcp-ai-wpoos-pro' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'Default annual interest rate for investment calculations', 'mcp-ai-wpoos-pro' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'Default annual inflation rate for future value calculations', 'mcp-ai-wpoos-pro' ); ?></p>
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
			<?php esc_html_e( 'Allow optional syncing with bank accounts via third-party services', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Optional feature. The toolkit works completely independently without this. When enabled, users can choose to connect their bank accounts via Plaid API.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render market data providers section description.
	 *
	 * @since 1.1.90
	 */
	public function render_market_data_section_description() {
		echo '<p>' . esc_html__( 'Optional: bring-your-own-key market data providers that upgrade quote quality and freshness. The toolkit works fully without them via keyless public endpoints.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render the Finnhub API keys field (masked — full keys are never echoed back).
	 *
	 * @since 1.1.90
	 */
	public function render_finnhub_keys_field() {
		$options = get_option( $this->option_name, array() );
		$stored  = isset( $options['finnhub_api_keys'] ) ? $options['finnhub_api_keys'] : '';

		if ( class_exists( 'WP_MCP_AI_Finnhub_Provider' ) ) {
			$keys = WP_MCP_AI_Finnhub_Provider::get_keys();
		} else {
			$keys = array_filter( array_map( 'trim', explode( ',', (string) $stored ) ) );
		}

		$masked = array();
		foreach ( $keys as $key ) {
			$len      = strlen( $key );
			$masked[] = ( $len > 8 ? substr( $key, 0, 2 ) . str_repeat( '*', 6 ) . substr( $key, -4 ) : '******' );
		}
		?>
		<input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[finnhub_api_keys]"
			value="<?php echo esc_attr( implode( ',', $masked ) ); ?>"
			placeholder="<?php esc_attr_e( 'e.g. c1234...,c5678... (comma-separated)', 'mcp-ai-wpoos-pro' ); ?>"
			class="regular-text" autocomplete="new-password" />
		<p class="description">
			<?php
			printf(
				/* translators: 1: number of configured keys, 2: free-tier request cap */
				esc_html__( '%1$d key(s) configured. Comma-separate multiple keys for rotation — the free tier allows 60 requests/minute per key. Keys are stored server-side and never displayed in full.', 'mcp-ai-wpoos-pro' ),
				count( $keys ),
				60
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the market data mode field (cached vs realtime).
	 *
	 * @since 1.1.90
	 */
	public function render_finnhub_data_mode_field() {
		$options = get_option( $this->option_name, array() );
		$mode    = isset( $options['finnhub_data_mode'] ) ? sanitize_key( $options['finnhub_data_mode'] ) : 'cached';

		if ( class_exists( 'WP_MCP_AI_Finnhub_Provider' ) ) {
			$mode = WP_MCP_AI_Finnhub_Provider::get_data_mode();
		}
		?>
		<label>
			<input type="radio" name="<?php echo esc_attr( $this->option_name ); ?>[finnhub_data_mode]"
				value="cached" <?php checked( $mode, 'cached' ); ?> />
			<?php esc_html_e( 'Cached (default)', 'mcp-ai-wpoos-pro' ); ?>
			— <?php esc_html_e( 'quotes refresh hourly, gentlest on rate limits', 'mcp-ai-wpoos-pro' ); ?>
		</label><br />
		<label>
			<input type="radio" name="<?php echo esc_attr( $this->option_name ); ?>[finnhub_data_mode]"
				value="realtime" <?php checked( $mode, 'realtime' ); ?> />
			<?php esc_html_e( 'Realtime (opt-in)', 'mcp-ai-wpoos-pro' ); ?>
			— <?php esc_html_e( '60-second cache and hourly price-alert checks; consumes the free tier faster', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Finnhub free-tier data may still be delayed 15+ minutes for non-US exchanges. See Finnhub terms for commercial use.', 'mcp-ai-wpoos-pro' ); ?>
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

		// Sanitize Finnhub API keys. A masked placeholder (contains * chars)
		// means "keep existing" — never overwrite real keys with the mask.
		if ( isset( $input['finnhub_api_keys'] ) ) {
			$raw_keys = sanitize_text_field( $input['finnhub_api_keys'] );

			if ( '' === trim( $raw_keys ) ) {
				$sanitized['finnhub_api_keys'] = '';
			} elseif ( false === strpos( $raw_keys, '*' ) ) {
				$keys    = explode( ',', $raw_keys );
				$cleaned = array();
				foreach ( $keys as $key ) {
					$key = trim( $key );
					if ( '' !== $key && preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $key ) ) {
						$cleaned[] = $key;
					}
				}
				$sanitized['finnhub_api_keys'] = implode( ',', $cleaned );
			}
		}

		// Sanitize Finnhub data mode.
		if ( isset( $input['finnhub_data_mode'] ) ) {
			$mode                           = sanitize_key( $input['finnhub_data_mode'] );
			$sanitized['finnhub_data_mode'] = 'realtime' === $mode ? 'realtime' : 'cached';
		}

		return $sanitized;
	}
}

// Initialize if financial planner toolkit is enabled.
$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( ! empty( $settings['enable_financial_planner_toolkit'] ) ) {
	new WP_MCP_AI_Financial_Planner_CPT_Settings_Page();
}
