<?php
/**
 * admin/class-wp-mcp-ai-cre-debt-settings-page.php (ecosystem port — Wave F4, cre-debt admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-cre-debt-settings-page.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps — the cpt-settings-page-base and research-page-trait
 * requires resolve from the already-ported `src/admin/` copies; the base-owned `WP_MCP_AI_URL` /
 * `WP_MCP_AI_VERSION` chart asset refs stay byte-identical (page-hook-gated).
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
 * CRE Debt Settings Page (CPT-Based)
 */
class WP_MCP_AI_CRE_Debt_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_cre_debt_settings';
		$this->post_type   = 'mcp_ai_cre_loan';
		$this->page_title  = __( 'CRE Debt & Securitization Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'cre-debt-settings';

		parent::__construct();
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'CRE Debt & Securitization Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>

		<div class="toolkit-description">
			<p><?php esc_html_e( 'Enterprise-grade commercial real estate debt toolkit with 57 AI-powered tools across originations, underwriting, CMBS/CLO securitization, debt fund management, and asset management.', 'nvoos-content-graph-pro' ); ?></p>
			<p><strong><?php esc_html_e( 'Industry Standards:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Aligned with CREFC IRP (Investor Reporting Package) data standards, ARGUS property-level analytics, and MBA/CMB certification methodologies.', 'nvoos-content-graph-pro' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Loan & Property Management: Track CRE loans and collateral with CREFC-aligned fields', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Originations Pipeline: Deal sourcing, screening, LOI, IC review, term sheets, and closing', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Underwriting Engine: NOI, DSCR, LTV, DCF, stress testing, and property valuation', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'CMBS & Securitization: Pool analysis, bond cash flows, surveillance, and special servicing', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Debt Fund Management: Waterfall modeling, capital calls, LP reporting, warehouse lines', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Asset Management: Loan surveillance, watchlists, workouts, and disposition analysis', 'nvoos-content-graph-pro' ); ?></li>
			<li><?php esc_html_e( 'Portfolio Dashboard: Visual analytics with Chart.js for portfolio composition and risk metrics', 'nvoos-content-graph-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Toolkit Modules', 'nvoos-content-graph-pro' ); ?></h3>
		<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Module', 'nvoos-content-graph-pro' ); ?></th>
					<th><?php esc_html_e( 'Tools', 'nvoos-content-graph-pro' ); ?></th>
					<th><?php esc_html_e( 'Description', 'nvoos-content-graph-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Originations', 'nvoos-content-graph-pro' ); ?></strong></td>
					<td>11</td>
					<td><?php esc_html_e( 'Deal pipeline, borrower analysis, loan quotes, rate locks', 'nvoos-content-graph-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Underwriting', 'nvoos-content-graph-pro' ); ?></strong></td>
					<td>13</td>
					<td><?php esc_html_e( 'NOI, DSCR, DCF, stress testing, environmental risk', 'nvoos-content-graph-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'CMBS / Securitization', 'nvoos-content-graph-pro' ); ?></strong></td>
					<td>10</td>
					<td><?php esc_html_e( 'Pool analysis, bond modeling, rating agency, defeasance', 'nvoos-content-graph-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Debt Fund', 'nvoos-content-graph-pro' ); ?></strong></td>
					<td>11</td>
					<td><?php esc_html_e( 'Portfolio dashboard, waterfall, capital calls, LP reports', 'nvoos-content-graph-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Asset Management', 'nvoos-content-graph-pro' ); ?></strong></td>
					<td>12</td>
					<td><?php esc_html_e( 'Surveillance, watchlist, capex, hold/sell analysis', 'nvoos-content-graph-pro' ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="notice notice-warning inline" style="margin-top:20px;">
			<p>
				<strong><?php esc_html_e( 'Professional Use:', 'nvoos-content-graph-pro' ); ?></strong>
				<?php esc_html_e( 'This toolkit provides AI-assisted analysis for educational and professional evaluation purposes. Always verify calculations and consult qualified professionals for investment decisions.', 'nvoos-content-graph-pro' ); ?>
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
			'cre_deal_pipeline_manager'         => __( 'Deal Pipeline Manager', 'nvoos-content-graph-pro' ),
			'cre_borrower_profile_analyzer'     => __( 'Borrower Profile Analyzer', 'nvoos-content-graph-pro' ),
			'cre_loan_quote_generator'          => __( 'Loan Quote Generator', 'nvoos-content-graph-pro' ),
			'cre_market_comp_analyzer'          => __( 'Market Comp Analyzer', 'nvoos-content-graph-pro' ),
			'cre_deal_screening_calculator'     => __( 'Deal Screening Calculator', 'nvoos-content-graph-pro' ),
			'cre_origination_volume_tracker'    => __( 'Origination Volume Tracker', 'nvoos-content-graph-pro' ),
			'cre_rate_lock_manager'             => __( 'Rate Lock Manager', 'nvoos-content-graph-pro' ),
			'cre_broker_relationship_tracker'   => __( 'Broker Relationship Tracker', 'nvoos-content-graph-pro' ),
			'cre_term_sheet_comparator'         => __( 'Term Sheet Comparator', 'nvoos-content-graph-pro' ),
			'cre_execution_strategy_advisor'    => __( 'Execution Strategy Advisor', 'nvoos-content-graph-pro' ),
			'cre_closing_checklist_manager'     => __( 'Closing Checklist Manager', 'nvoos-content-graph-pro' ),
			// Underwriting.
			'cre_dcf_modeler'                   => __( 'DCF Modeler', 'nvoos-content-graph-pro' ),
			'cre_noi_calculator'                => __( 'NOI Calculator', 'nvoos-content-graph-pro' ),
			'cre_loan_sizer'                    => __( 'Loan Sizer', 'nvoos-content-graph-pro' ),
			'cre_amortization_scheduler'        => __( 'Amortization Scheduler', 'nvoos-content-graph-pro' ),
			'cre_debt_yield_analyzer'           => __( 'Debt Yield Analyzer', 'nvoos-content-graph-pro' ),
			'cre_cap_rate_sensitivity'          => __( 'Cap Rate Sensitivity', 'nvoos-content-graph-pro' ),
			'cre_rent_roll_analyzer'            => __( 'Rent Roll Analyzer', 'nvoos-content-graph-pro' ),
			'cre_operating_expense_benchmarker' => __( 'Operating Expense Benchmarker', 'nvoos-content-graph-pro' ),
			'cre_stress_test_modeler'           => __( 'Stress Test Modeler', 'nvoos-content-graph-pro' ),
			'cre_leverage_return_analyzer'      => __( 'Leverage Return Analyzer', 'nvoos-content-graph-pro' ),
			'cre_property_valuation_engine'     => __( 'Property Valuation Engine', 'nvoos-content-graph-pro' ),
			'cre_environmental_risk_scorer'     => __( 'Environmental Risk Scorer', 'nvoos-content-graph-pro' ),
			'cre_underwriting_memo_generator'   => __( 'Underwriting Memo Generator', 'nvoos-content-graph-pro' ),
			// CMBS / Securitization.
			'cmbs_deal_structurer'              => __( 'CMBS Deal Structurer', 'nvoos-content-graph-pro' ),
			'cmbs_bond_cash_flow_modeler'       => __( 'Bond Cash Flow Modeler', 'nvoos-content-graph-pro' ),
			'cmbs_pool_analyzer'                => __( 'Pool Analyzer', 'nvoos-content-graph-pro' ),
			'cmbs_surveillance_monitor'         => __( 'Surveillance Monitor', 'nvoos-content-graph-pro' ),
			'cmbs_special_servicing_tracker'    => __( 'Special Servicing Tracker', 'nvoos-content-graph-pro' ),
			'cre_clo_modeler'                   => __( 'CRE CLO Modeler', 'nvoos-content-graph-pro' ),
			'cmbs_defeasance_calculator'        => __( 'Defeasance Calculator', 'nvoos-content-graph-pro' ),
			'cmbs_rating_agency_analyzer'       => __( 'Rating Agency Analyzer', 'nvoos-content-graph-pro' ),
			'cmbs_investor_reporting_generator' => __( 'Investor Reporting Generator', 'nvoos-content-graph-pro' ),
			'cmbs_maturity_risk_analyzer'       => __( 'Maturity Risk Analyzer', 'nvoos-content-graph-pro' ),
			// Debt Fund Management.
			'cre_fund_portfolio_dashboard'      => __( 'Fund Portfolio Dashboard', 'nvoos-content-graph-pro' ),
			'cre_debt_waterfall_modeler'        => __( 'Debt Waterfall Modeler', 'nvoos-content-graph-pro' ),
			'cre_fund_return_calculator'        => __( 'Fund Return Calculator', 'nvoos-content-graph-pro' ),
			'cre_credit_risk_scorer'            => __( 'Credit Risk Scorer', 'nvoos-content-graph-pro' ),
			'cre_concentration_limit_monitor'   => __( 'Concentration Limit Monitor', 'nvoos-content-graph-pro' ),
			'cre_warehouse_line_manager'        => __( 'Warehouse Line Manager', 'nvoos-content-graph-pro' ),
			'cre_lp_report_generator'           => __( 'LP Report Generator', 'nvoos-content-graph-pro' ),
			'cre_fund_capital_call_calculator'  => __( 'Capital Call Calculator', 'nvoos-content-graph-pro' ),
			'cre_fund_liquidity_analyzer'       => __( 'Fund Liquidity Analyzer', 'nvoos-content-graph-pro' ),
			'cre_covenant_compliance_checker'   => __( 'Covenant Compliance Checker', 'nvoos-content-graph-pro' ),
			'cre_fund_scenario_modeler'         => __( 'Fund Scenario Modeler', 'nvoos-content-graph-pro' ),
			// Asset Management.
			'cre_property_budget_manager'       => __( 'Property Budget Manager', 'nvoos-content-graph-pro' ),
			'cre_lease_expiration_manager'      => __( 'Lease Expiration Manager', 'nvoos-content-graph-pro' ),
			'cre_capex_reserve_planner'         => __( 'CapEx Reserve Planner', 'nvoos-content-graph-pro' ),
			'cre_tenant_credit_analyzer'        => __( 'Tenant Credit Analyzer', 'nvoos-content-graph-pro' ),
			'cre_hold_sell_analyzer'            => __( 'Hold/Sell Analyzer', 'nvoos-content-graph-pro' ),
			'cre_property_performance_tracker'  => __( 'Property Performance Tracker', 'nvoos-content-graph-pro' ),
			'cre_loan_surveillance_dashboard'   => __( 'Loan Surveillance Dashboard', 'nvoos-content-graph-pro' ),
			'cre_watchlist_manager'             => __( 'Watchlist Manager', 'nvoos-content-graph-pro' ),
			'cre_workout_scenario_modeler'      => __( 'Workout Scenario Modeler', 'nvoos-content-graph-pro' ),
			'cre_loan_modification_calculator'  => __( 'Loan Modification Calculator', 'nvoos-content-graph-pro' ),
			'cre_servicing_fee_calculator'      => __( 'Servicing Fee Calculator', 'nvoos-content-graph-pro' ),
			'cre_asset_disposition_analyzer'    => __( 'Asset Disposition Analyzer', 'nvoos-content-graph-pro' ),
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
			__( 'Default Underwriting Parameters', 'nvoos-content-graph-pro' ),
			array( $this, 'render_defaults_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'default_cap_rate',
			__( 'Default Cap Rate', 'nvoos-content-graph-pro' ),
			array( $this, 'render_cap_rate_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_dscr_minimum',
			__( 'Minimum DSCR', 'nvoos-content-graph-pro' ),
			array( $this, 'render_dscr_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_max_ltv',
			__( 'Maximum LTV', 'nvoos-content-graph-pro' ),
			array( $this, 'render_ltv_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		add_settings_field(
			'default_min_debt_yield',
			__( 'Minimum Debt Yield', 'nvoos-content-graph-pro' ),
			array( $this, 'render_debt_yield_field' ),
			$this->option_name,
			$this->option_name . '_defaults_section'
		);

		// Dashboard section.
		add_settings_section(
			$this->option_name . '_dashboard_section',
			__( 'Dashboard Configuration', 'nvoos-content-graph-pro' ),
			array( $this, 'render_dashboard_section_description' ),
			$this->option_name
		);

		add_settings_field(
			'enable_portfolio_dashboard',
			__( 'Enable Portfolio Dashboard', 'nvoos-content-graph-pro' ),
			array( $this, 'render_dashboard_toggle_field' ),
			$this->option_name,
			$this->option_name . '_dashboard_section'
		);
	}

	/**
	 * Render defaults section description.
	 */
	public function render_defaults_section_description() {
		echo '<p>' . esc_html__( 'Configure default underwriting parameters used by CRE debt analysis tools. These can be overridden per-calculation.', 'nvoos-content-graph-pro' ) . '</p>';
	}

	/**
	 * Render dashboard section description.
	 */
	public function render_dashboard_section_description() {
		echo '<p>' . esc_html__( 'Configure the portfolio analytics dashboard.', 'nvoos-content-graph-pro' ) . '</p>';
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
		<p class="description"><?php esc_html_e( 'Default market cap rate for property valuations', 'nvoos-content-graph-pro' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'Minimum acceptable Debt Service Coverage Ratio (industry standard: 1.25x)', 'nvoos-content-graph-pro' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'Maximum acceptable Loan-to-Value ratio (industry standard: 75%)', 'nvoos-content-graph-pro' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'Minimum acceptable Debt Yield (industry standard: 9-10%)', 'nvoos-content-graph-pro' ); ?></p>
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
			<?php esc_html_e( 'Show the portfolio analytics dashboard under the CRE Debt menu', 'nvoos-content-graph-pro' ); ?>
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
