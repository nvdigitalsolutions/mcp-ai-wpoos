<?php
/**
 * Law Firm Toolkit Settings Page
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

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

class WP_MCP_AI_Law_Firm_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	public function __construct() {
		$this->option_name = 'wp_mcp_ai_law_firm_settings';
		$this->post_type   = 'mcp_ai_lf_matter';
		$this->page_title  = __( 'Law Firm Toolkit Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'law-firm-settings';
		parent::__construct();
	}

	public function register_settings() {
		parent::register_settings();

		add_settings_section(
			$this->option_name . '_defaults_section',
			__( 'Default Configuration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_defaults_section' ),
			$this->option_name
		);

		$fields = array(
			'default_jurisdiction' => __( 'Default Jurisdiction', 'mcp-ai-wpoos-pro' ),
			'default_state'        => __( 'Default State', 'mcp-ai-wpoos-pro' ),
			'default_billing_rate' => __( 'Default Billing Rate ($/hr)', 'mcp-ai-wpoos-pro' ),
			'billing_increment'    => __( 'Billing Increment', 'mcp-ai-wpoos-pro' ),
			'enable_trust_accounting' => __( 'Enable Trust Accounting', 'mcp-ai-wpoos-pro' ),
			'enable_firm_dashboard'   => __( 'Enable Firm Dashboard', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $fields as $id => $label ) {
			add_settings_field( $id, $label, array( $this, 'render_field_' . $id ), $this->option_name, $this->option_name . '_defaults_section' );
		}
	}

	public function render_defaults_section() {
		echo '<p>' . esc_html__( 'Configure default settings for the Law Firm toolkit.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	public function render_field_default_jurisdiction() {
		$options = get_option( $this->option_name, array() );
		$val = $options['default_jurisdiction'] ?? 'federal';
		echo '<select name="' . esc_attr( $this->option_name ) . '[default_jurisdiction]">';
		foreach ( array( 'federal' => 'Federal', 'state' => 'State' ) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $val, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
	}

	public function render_field_default_state() {
		$options = get_option( $this->option_name, array() );
		$val = $options['default_state'] ?? '';
		echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[default_state]" value="' . esc_attr( $val ) . '" placeholder="CA" maxlength="2" style="width:60px;" />';
	}

	public function render_field_default_billing_rate() {
		$options = get_option( $this->option_name, array() );
		$val = $options['default_billing_rate'] ?? '350';
		echo '<input type="number" name="' . esc_attr( $this->option_name ) . '[default_billing_rate]" value="' . esc_attr( $val ) . '" min="0" step="25" style="width:100px;" />';
	}

	public function render_field_billing_increment() {
		$options = get_option( $this->option_name, array() );
		$val = $options['billing_increment'] ?? '0.1';
		echo '<select name="' . esc_attr( $this->option_name ) . '[billing_increment]">';
		foreach ( array( '0.1' => '6-minute (0.1)', '0.25' => '15-minute (0.25)' ) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $val, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
	}

	public function render_field_enable_trust_accounting() {
		$options = get_option( $this->option_name, array() );
		$val = ! empty( $options['enable_trust_accounting'] );
		echo '<label><input type="checkbox" name="' . esc_attr( $this->option_name ) . '[enable_trust_accounting]" value="1"' . checked( $val, true, false ) . ' /> ' . esc_html__( 'Enable IOLTA trust accounting tools', 'mcp-ai-wpoos-pro' ) . '</label>';
	}

	public function render_field_enable_firm_dashboard() {
		$options = get_option( $this->option_name, array() );
		$val = isset( $options['enable_firm_dashboard'] ) ? ! empty( $options['enable_firm_dashboard'] ) : true;
		echo '<label><input type="checkbox" name="' . esc_attr( $this->option_name ) . '[enable_firm_dashboard]" value="1"' . checked( $val, true, false ) . ' /> ' . esc_html__( 'Enable firm performance dashboard', 'mcp-ai-wpoos-pro' ) . '</label>';
	}

	protected function render_overview_tab() {
		echo '<div class="wrap"><h2>' . esc_html__( 'Law Firm Toolkit', 'mcp-ai-wpoos-pro' ) . '</h2>';
		echo '<p>' . esc_html__( 'AI-powered tools for law firm management aligned with ABA Model Rules, IOLTA regulations, UTBMS billing codes, and LEDES invoicing standards.', 'mcp-ai-wpoos-pro' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'DISCLAIMER:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html__( 'All tool outputs are for informational purposes only and do not constitute legal advice.', 'mcp-ai-wpoos-pro' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Module', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Tools', 'mcp-ai-wpoos-pro' ) . '</th></tr></thead><tbody>';
		$modules = array(
			'Client Intake & Management' => 8, 'Matter & Case Management' => 10,
			'Document Automation' => 10, 'Billing & Trust Accounting' => 10,
			'Compliance & Ethics' => 8, 'Litigation Support' => 8, 'Legal Research & Analytics' => 8,
		);
		foreach ( $modules as $name => $count ) {
			echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $count ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th>' . esc_html__( 'Total', 'mcp-ai-wpoos-pro' ) . '</th><th>62</th></tr></tfoot></table></div>';
	}

	protected function get_tools_list() {
		return array(
			'lf_client_intake_processor' => __( 'Client Intake Processor', 'mcp-ai-wpoos-pro' ),
			'lf_conflict_of_interest_checker' => __( 'Conflict of Interest Checker', 'mcp-ai-wpoos-pro' ),
			'lf_client_profile_analyzer' => __( 'Client Profile Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_lead_scoring_calculator' => __( 'Lead Scoring Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_engagement_letter_generator' => __( 'Engagement Letter Generator', 'mcp-ai-wpoos-pro' ),
			'lf_client_communication_logger' => __( 'Client Communication Logger', 'mcp-ai-wpoos-pro' ),
			'lf_referral_source_tracker' => __( 'Referral Source Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_client_portal_manager' => __( 'Client Portal Manager', 'mcp-ai-wpoos-pro' ),
			'lf_matter_pipeline_manager' => __( 'Matter Pipeline Manager', 'mcp-ai-wpoos-pro' ),
			'lf_statute_of_limitations_calculator' => __( 'Statute of Limitations Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_court_deadline_tracker' => __( 'Court Filing Deadline Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_case_timeline_generator' => __( 'Case Timeline Generator', 'mcp-ai-wpoos-pro' ),
			'lf_task_assignment_manager' => __( 'Task Assignment Manager', 'mcp-ai-wpoos-pro' ),
			'lf_calendar_rule_calculator' => __( 'Calendar Rule Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_opposing_counsel_tracker' => __( 'Opposing Counsel Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_case_outcome_predictor' => __( 'Case Outcome Predictor', 'mcp-ai-wpoos-pro' ),
			'lf_matter_budget_manager' => __( 'Matter Budget Manager', 'mcp-ai-wpoos-pro' ),
			'lf_case_status_dashboard' => __( 'Case Status Dashboard', 'mcp-ai-wpoos-pro' ),
			'lf_document_drafter' => __( 'Legal Document Drafter', 'mcp-ai-wpoos-pro' ),
			'lf_contract_reviewer' => __( 'Contract Review Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_clause_library_manager' => __( 'Clause Library Manager', 'mcp-ai-wpoos-pro' ),
			'lf_redline_comparator' => __( 'Document Redline Comparator', 'mcp-ai-wpoos-pro' ),
			'lf_pleading_generator' => __( 'Pleading & Motion Generator', 'mcp-ai-wpoos-pro' ),
			'lf_discovery_request_builder' => __( 'Discovery Request Builder', 'mcp-ai-wpoos-pro' ),
			'lf_document_version_tracker' => __( 'Document Version Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_legal_citation_checker' => __( 'Legal Citation Checker', 'mcp-ai-wpoos-pro' ),
			'lf_brief_outline_generator' => __( 'Brief Outline Generator', 'mcp-ai-wpoos-pro' ),
			'lf_document_template_manager' => __( 'Document Template Manager', 'mcp-ai-wpoos-pro' ),
			'lf_time_entry_recorder' => __( 'Time Entry Recorder', 'mcp-ai-wpoos-pro' ),
			'lf_invoice_generator' => __( 'Invoice Generator (LEDES)', 'mcp-ai-wpoos-pro' ),
			'lf_trust_account_manager' => __( 'Trust (IOLTA) Account Manager', 'mcp-ai-wpoos-pro' ),
			'lf_trust_reconciliation_tool' => __( 'Trust Account Reconciliation', 'mcp-ai-wpoos-pro' ),
			'lf_fee_calculator' => __( 'Legal Fee Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_billing_compliance_checker' => __( 'Billing Compliance Checker', 'mcp-ai-wpoos-pro' ),
			'lf_accounts_receivable_tracker' => __( 'Accounts Receivable Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_retainer_balance_monitor' => __( 'Retainer Balance Monitor', 'mcp-ai-wpoos-pro' ),
			'lf_expense_reimbursement_tracker' => __( 'Expense Reimbursement Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_profitability_analyzer' => __( 'Matter Profitability Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_ethics_rule_checker' => __( 'Ethics Rule Checker', 'mcp-ai-wpoos-pro' ),
			'lf_bar_deadline_monitor' => __( 'Bar Reporting Deadline Monitor', 'mcp-ai-wpoos-pro' ),
			'lf_cle_credit_tracker' => __( 'CLE Credit Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_malpractice_risk_scorer' => __( 'Malpractice Risk Scorer', 'mcp-ai-wpoos-pro' ),
			'lf_data_privacy_compliance_checker' => __( 'Data Privacy Compliance Checker', 'mcp-ai-wpoos-pro' ),
			'lf_client_confidentiality_auditor' => __( 'Client Confidentiality Auditor', 'mcp-ai-wpoos-pro' ),
			'lf_regulatory_change_monitor' => __( 'Regulatory Change Monitor', 'mcp-ai-wpoos-pro' ),
			'lf_ai_usage_disclosure_generator' => __( 'AI Usage Disclosure Generator', 'mcp-ai-wpoos-pro' ),
			'lf_ediscovery_document_analyzer' => __( 'eDiscovery Document Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_deposition_summary_generator' => __( 'Deposition Summary Generator', 'mcp-ai-wpoos-pro' ),
			'lf_evidence_catalog_manager' => __( 'Evidence Catalog Manager', 'mcp-ai-wpoos-pro' ),
			'lf_jury_instruction_drafter' => __( 'Jury Instruction Drafter', 'mcp-ai-wpoos-pro' ),
			'lf_settlement_value_calculator' => __( 'Settlement Value Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_damages_calculator' => __( 'Damages Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_expert_witness_tracker' => __( 'Expert Witness Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_trial_preparation_checklist' => __( 'Trial Preparation Checklist', 'mcp-ai-wpoos-pro' ),
			'lf_legal_research_assistant' => __( 'Legal Research Assistant', 'mcp-ai-wpoos-pro' ),
			'lf_case_law_analyzer' => __( 'Case Law Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_firm_performance_dashboard' => __( 'Firm Performance Dashboard', 'mcp-ai-wpoos-pro' ),
			'lf_matter_analytics_generator' => __( 'Matter Analytics Generator', 'mcp-ai-wpoos-pro' ),
			'lf_revenue_forecaster' => __( 'Revenue Forecaster', 'mcp-ai-wpoos-pro' ),
			'lf_attorney_utilization_tracker' => __( 'Attorney Utilization Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_client_satisfaction_analyzer' => __( 'Client Satisfaction Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_competitive_benchmarker' => __( 'Competitive Benchmarker', 'mcp-ai-wpoos-pro' ),
		);
	}

	public function sanitize_settings( $input ) {
		$output = parent::sanitize_settings( $input );
		if ( isset( $input['default_jurisdiction'] ) ) {
			$output['default_jurisdiction'] = sanitize_text_field( $input['default_jurisdiction'] );
		}
		if ( isset( $input['default_state'] ) ) {
			$output['default_state'] = strtoupper( sanitize_text_field( substr( $input['default_state'], 0, 2 ) ) );
		}
		if ( isset( $input['default_billing_rate'] ) ) {
			$output['default_billing_rate'] = absint( $input['default_billing_rate'] );
		}
		if ( isset( $input['billing_increment'] ) ) {
			$output['billing_increment'] = in_array( $input['billing_increment'], array( '0.1', '0.25' ), true ) ? $input['billing_increment'] : '0.1';
		}
		$output['enable_trust_accounting'] = ! empty( $input['enable_trust_accounting'] ) ? 1 : 0;
		$output['enable_firm_dashboard'] = ! empty( $input['enable_firm_dashboard'] ) ? 1 : 0;
		return $output;
	}
}

$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( ! empty( $settings['enable_law_firm_toolkit'] ) ) {
	new WP_MCP_AI_Law_Firm_Settings_Page();
}
