<?php
/**
 * CRE Debt & Securitization Toolkit Settings Page (CPT-Based)
 *
 * Settings page that appears under the CRE Debt CPT menu.
 * Follows the same pattern as Financial Planner, Member, and Policy settings pages.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
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
 * CRE Debt Settings Page (CPT-Based)
 */
class WP_MCP_AI_CRE_Debt_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_cre_debt_settings';
		$this->post_type   = 'mcp_ai_cre_loan';
		$this->page_title  = __( 'CRE Debt & Securitization Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'cre-debt-settings';

		parent::__construct();
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'CRE Debt & Securitization Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

		<div class="toolkit-description">
			<p><?php esc_html_e( 'Enterprise-grade commercial real estate debt toolkit with 57 AI-powered tools across originations, underwriting, CMBS/CLO securitization, debt fund management, and asset management.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><strong><?php esc_html_e( 'Industry Standards:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Aligned with CREFC IRP (Investor Reporting Package) data standards, ARGUS property-level analytics, and MBA/CMB certification methodologies.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Loan & Property Management: Track CRE loans and collateral with CREFC-aligned fields', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Originations Pipeline: Deal sourcing, screening, LOI, IC review, term sheets, and closing', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Underwriting Engine: NOI, DSCR, LTV, DCF, stress testing, and property valuation', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'CMBS & Securitization: Pool analysis, bond cash flows, surveillance, and special servicing', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Debt Fund Management: Waterfall modeling, capital calls, LP reporting, warehouse lines', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Asset Management: Loan surveillance, watchlists, workouts, and disposition analysis', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Portfolio Dashboard: Visual analytics with Chart.js for portfolio composition and risk metrics', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Toolkit Modules', 'mcp-ai-wpoos-pro' ); ?></h3>
		<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Module', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Tools', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Originations', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>11</td>
					<td><?php esc_html_e( 'Deal pipeline, borrower analysis, loan quotes, rate locks', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Underwriting', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>13</td>
					<td><?php esc_html_e( 'NOI, DSCR, DCF, stress testing, environmental risk', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'CMBS / Securitization', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>10</td>
					<td><?php esc_html_e( 'Pool analysis, bond modeling, rating agency, defeasance', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Debt Fund', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>11</td>
					<td><?php esc_html_e( 'Portfolio dashboard, waterfall, capital calls, LP reports', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Asset Management', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>12</td>
					<td><?php esc_html_e( 'Surveillance, watchlist, capex, hold/sell analysis', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="notice notice-warning inline" style="margin-top:20px;">
			<p>
				<strong><?php esc_html_e( 'Professional Use:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'This toolkit provides AI-assisted analysis for educational and professional evaluation purposes. Always verify calculations and consult qualified professionals for investment decisions.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get tools list for the Available Tools tab.
	 *
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			// Originations.
			'cre_deal_pipeline_manager'         => __( 'Deal Pipeline Manager', 'mcp-ai-wpoos-pro' ),
			'cre_borrower_profile_analyzer'     => __( 'Borrower Profile Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_loan_quote_generator'          => __( 'Loan Quote Generator', 'mcp-ai-wpoos-pro' ),
			'cre_market_comp_analyzer'          => __( 'Market Comp Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_deal_screening_calculator'     => __( 'Deal Screening Calculator', 'mcp-ai-wpoos-pro' ),
			'cre_origination_volume_tracker'    => __( 'Origination Volume Tracker', 'mcp-ai-wpoos-pro' ),
			'cre_rate_lock_manager'             => __( 'Rate Lock Manager', 'mcp-ai-wpoos-pro' ),
			'cre_broker_relationship_tracker'   => __( 'Broker Relationship Tracker', 'mcp-ai-wpoos-pro' ),
			'cre_term_sheet_comparator'         => __( 'Term Sheet Comparator', 'mcp-ai-wpoos-pro' ),
			'cre_execution_strategy_advisor'    => __( 'Execution Strategy Advisor', 'mcp-ai-wpoos-pro' ),
			'cre_closing_checklist_manager'     => __( 'Closing Checklist Manager', 'mcp-ai-wpoos-pro' ),
			// Underwriting.
			'cre_dcf_modeler'                   => __( 'DCF Modeler', 'mcp-ai-wpoos-pro' ),
			'cre_noi_calculator'                => __( 'NOI Calculator', 'mcp-ai-wpoos-pro' ),
			'cre_loan_sizer'                    => __( 'Loan Sizer', 'mcp-ai-wpoos-pro' ),
			'cre_amortization_scheduler'        => __( 'Amortization Scheduler', 'mcp-ai-wpoos-pro' ),
			'cre_debt_yield_analyzer'           => __( 'Debt Yield Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_cap_rate_sensitivity'          => __( 'Cap Rate Sensitivity', 'mcp-ai-wpoos-pro' ),
			'cre_rent_roll_analyzer'            => __( 'Rent Roll Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_operating_expense_benchmarker' => __( 'Operating Expense Benchmarker', 'mcp-ai-wpoos-pro' ),
			'cre_stress_test_modeler'           => __( 'Stress Test Modeler', 'mcp-ai-wpoos-pro' ),
			'cre_leverage_return_analyzer'      => __( 'Leverage Return Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_property_valuation_engine'     => __( 'Property Valuation Engine', 'mcp-ai-wpoos-pro' ),
			'cre_environmental_risk_scorer'     => __( 'Environmental Risk Scorer', 'mcp-ai-wpoos-pro' ),
			'cre_underwriting_memo_generator'   => __( 'Underwriting Memo Generator', 'mcp-ai-wpoos-pro' ),
			// CMBS / Securitization.
			'cmbs_deal_structurer'              => __( 'CMBS Deal Structurer', 'mcp-ai-wpoos-pro' ),
			'cmbs_bond_cash_flow_modeler'       => __( 'Bond Cash Flow Modeler', 'mcp-ai-wpoos-pro' ),
			'cmbs_pool_analyzer'                => __( 'Pool Analyzer', 'mcp-ai-wpoos-pro' ),
			'cmbs_surveillance_monitor'         => __( 'Surveillance Monitor', 'mcp-ai-wpoos-pro' ),
			'cmbs_special_servicing_tracker'    => __( 'Special Servicing Tracker', 'mcp-ai-wpoos-pro' ),
			'cre_clo_modeler'                   => __( 'CRE CLO Modeler', 'mcp-ai-wpoos-pro' ),
			'cmbs_defeasance_calculator'        => __( 'Defeasance Calculator', 'mcp-ai-wpoos-pro' ),
			'cmbs_rating_agency_analyzer'       => __( 'Rating Agency Analyzer', 'mcp-ai-wpoos-pro' ),
			'cmbs_investor_reporting_generator' => __( 'Investor Reporting Generator', 'mcp-ai-wpoos-pro' ),
			'cmbs_maturity_risk_analyzer'       => __( 'Maturity Risk Analyzer', 'mcp-ai-wpoos-pro' ),
			// Debt Fund Management.
			'cre_fund_portfolio_dashboard'      => __( 'Fund Portfolio Dashboard', 'mcp-ai-wpoos-pro' ),
			'cre_debt_waterfall_modeler'        => __( 'Debt Waterfall Modeler', 'mcp-ai-wpoos-pro' ),
			'cre_fund_return_calculator'        => __( 'Fund Return Calculator', 'mcp-ai-wpoos-pro' ),
			'cre_credit_risk_scorer'            => __( 'Credit Risk Scorer', 'mcp-ai-wpoos-pro' ),
			'cre_concentration_limit_monitor'   => __( 'Concentration Limit Monitor', 'mcp-ai-wpoos-pro' ),
			'cre_warehouse_line_manager'        => __( 'Warehouse Line Manager', 'mcp-ai-wpoos-pro' ),
			'cre_lp_report_generator'           => __( 'LP Report Generator', 'mcp-ai-wpoos-pro' ),
			'cre_fund_capital_call_calculator'  => __( 'Capital Call Calculator', 'mcp-ai-wpoos-pro' ),
			'cre_fund_liquidity_analyzer'       => __( 'Fund Liquidity Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_covenant_compliance_checker'   => __( 'Covenant Compliance Checker', 'mcp-ai-wpoos-pro' ),
			'cre_fund_scenario_modeler'         => __( 'Fund Scenario Modeler', 'mcp-ai-wpoos-pro' ),
			// Asset Management.
			'cre_property_budget_manager'       => __( 'Property Budget Manager', 'mcp-ai-wpoos-pro' ),
			'cre_lease_expiration_manager'      => __( 'Lease Expiration Manager', 'mcp-ai-wpoos-pro' ),
			'cre_capex_reserve_planner'         => __( 'CapEx Reserve Planner', 'mcp-ai-wpoos-pro' ),
			'cre_tenant_credit_analyzer'        => __( 'Tenant Credit Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_hold_sell_analyzer'            => __( 'Hold/Sell Analyzer', 'mcp-ai-wpoos-pro' ),
			'cre_property_performance_tracker'  => __( 'Property Performance Tracker', 'mcp-ai-wpoos-pro' ),
			'cre_loan_surveillance_dashboard'   => __( 'Loan Surveillance Dashboard', 'mcp-ai-wpoos-pro' ),
			'cre_watchlist_manager'             => __( 'Watchlist Manager', 'mcp-ai-wpoos-pro' ),
			'cre_workout_scenario_modeler'      => __( 'Workout Scenario Modeler', 'mcp-ai-wpoos-pro' ),
			'cre_loan_modification_calculator'  => __( 'Loan Modification Calculator', 'mcp-ai-wpoos-pro' ),
			'cre_servicing_fee_calculator'      => __( 'Servicing Fee Calculator', 'mcp-ai-wpoos-pro' ),
			'cre_asset_disposition_analyzer'    => __( 'Asset Disposition Analyzer', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		parent::register_settings();

		// Defaults section.
		add_settings_section(
			$this->option_name . '_defaults_section',
			__( 'Default Underwriting Parameters', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'default_cap_rate',
			__( 'Default Cap Rate', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_cap_rate_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_dscr_minimum',
			__( 'Minimum DSCR', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_dscr_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_max_ltv',
			__( 'Maximum LTV', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ltv_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_min_debt_yield',
			__( 'Minimum Debt Yield', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_debt_yield_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		// Dashboard section.
		add_settings_section(
			$this->option_name . '_dashboard_section',
			__( 'Dashboard Configuration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_dashboard_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'enable_portfolio_dashboard',
			__( 'Enable Portfolio Dashboard', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_dashboard_toggle_field' ),
			$this->option_name,
			$this->option_name . '_dashboard_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default underwriting parameters used by CRE debt analysis tools. These can be overridden per-calculation.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render dashboard section description.
	 */
	public function render_dashboard_section_description() {
		echo '<p>' . esc_html__( 'Configure the portfolio analytics dashboard.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render cap rate field.
	 */
	public function render_cap_rate_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_cap_rate'] ) ? $options['default_cap_rate'] : '6.5';
		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_cap_rate]"
			value="<?php echo esc_attr( $value ); ?>" step="0.1" min="0" max="30" class="small-text" />
		<span>%</span>
		<p class="description"><?php esc_html_e( 'Default market cap rate for property valuations', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render DSCR field.
	 */
	public function render_dscr_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_dscr_minimum'] ) ? $options['default_dscr_minimum'] : '1.25';
		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_dscr_minimum]"
			value="<?php echo esc_attr( $value ); ?>" step="0.01" min="0" max="5" class="small-text" />
		<span>x</span>
		<p class="description"><?php esc_html_e( 'Minimum acceptable Debt Service Coverage Ratio (industry standard: 1.25x)', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render LTV field.
	 */
	public function render_ltv_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_max_ltv'] ) ? $options['default_max_ltv'] : '75';
		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_max_ltv]"
			value="<?php echo esc_attr( $value ); ?>" step="1" min="0" max="100" class="small-text" />
		<span>%</span>
		<p class="description"><?php esc_html_e( 'Maximum acceptable Loan-to-Value ratio (industry standard: 75%)', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render debt yield field.
	 */
	public function render_debt_yield_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_min_debt_yield'] ) ? $options['default_min_debt_yield'] : '9';
		?>
		<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_min_debt_yield]"
			value="<?php echo esc_attr( $value ); ?>" step="0.1" min="0" max="50" class="small-text" />
		<span>%</span>
		<p class="description"><?php esc_html_e( 'Minimum acceptable Debt Yield (industry standard: 9-10%)', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render dashboard toggle field.
	 */
	public function render_dashboard_toggle_field() {
		$options = get_option( $this->option_name, array() );
		$enabled = isset( $options['enable_portfolio_dashboard'] ) ? (bool) $options['enable_portfolio_dashboard'] : true;
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_portfolio_dashboard]"
				value="1" <?php checked( $enabled, true ); ?> />
			<?php esc_html_e( 'Show the portfolio analytics dashboard under the CRE Debt menu', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = parent::sanitize_settings( $input );

		if ( isset( $input['default_cap_rate'] ) ) {
			$sanitized['default_cap_rate'] = floatval( $input['default_cap_rate'] );
		}
		if ( isset( $input['default_dscr_minimum'] ) ) {
			$sanitized['default_dscr_minimum'] = floatval( $input['default_dscr_minimum'] );
		}
		if ( isset( $input['default_max_ltv'] ) ) {
			$sanitized['default_max_ltv'] = floatval( $input['default_max_ltv'] );
		}
		if ( isset( $input['default_min_debt_yield'] ) ) {
			$sanitized['default_min_debt_yield'] = floatval( $input['default_min_debt_yield'] );
		}
		$sanitized['enable_portfolio_dashboard'] = isset( $input['enable_portfolio_dashboard'] ) ? (bool) $input['enable_portfolio_dashboard'] : false;

		return $sanitized;
	}
}

// Initialize if CRE Debt toolkit is enabled.
$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( ! empty( $settings['enable_cre_debt_toolkit'] ) ) {
	new WP_MCP_AI_CRE_Debt_Settings_Page();
}
