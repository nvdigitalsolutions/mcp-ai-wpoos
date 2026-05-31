<?php
/**
 * Law Firm Toolkit Settings Page
 *
 * Settings page for configuring AI provider, model, and assistant
 * for the Law Firm toolkit.
 *
 * Now extends WP_MCP_AI_Toolkit_Settings_Base for a consistent tabbed
 * interface with full MCP Server configuration.
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
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Law Firm Toolkit Settings Page
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Law_Firm_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->toolkit_slug = 'law_firm'; // Kebab-converts to 'law-firm' for MCP server lookup.
		$this->toolkit_name = __( 'Law Firm', 'mcp-ai-wpoos-pro' );
		$this->option_name  = 'wp_mcp_ai_law_firm_settings';
		$this->page_slug    = 'law-firm-settings';
		$this->icon         = 'dashicons-businessman';
		$this->has_research = true;

		parent::__construct();
	}

	/**
	 * Get toolkit slug.
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name.
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab.
	 *
	 * @since 2.0.0
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'Law Firm Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

		<div class="toolkit-description">
			<p><?php esc_html_e( 'Comprehensive AI-powered law firm management toolkit with 62 tools across client intake, matter management, document automation, billing, compliance, litigation support, and legal research.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><strong><?php esc_html_e( 'Industry Standards:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Aligned with ABA Model Rules of Professional Conduct, IOLTA trust accounting regulations, UTBMS billing codes, and LEDES 1998B electronic invoicing standards.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Client Intake & Management: Conflict checking, engagement letters, lead scoring, and client portals', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Matter & Case Management: Pipeline tracking, deadlines, calendar rules, and case outcome predictions', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Document Automation: Drafting, contract review, redlining, pleadings, and citation checking', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Billing & Trust Accounting: Time entries, LEDES invoicing, IOLTA management, and profitability analysis', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Compliance & Ethics: Ethics rule checking, CLE tracking, malpractice risk, and AI usage disclosures', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Litigation Support: eDiscovery, deposition summaries, settlement calculators, and trial preparation', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Legal Research & Analytics: Case law analysis, firm dashboards, revenue forecasting, and benchmarking', 'mcp-ai-wpoos-pro' ); ?></li>
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
					<td><strong><?php esc_html_e( 'Client Intake & Management', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>8</td>
					<td><?php esc_html_e( 'Intake processing, conflict checks, engagement letters, lead scoring', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Matter & Case Management', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>10</td>
					<td><?php esc_html_e( 'Matter pipeline, deadlines, calendar rules, case outcome predictions', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Document Automation', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>10</td>
					<td><?php esc_html_e( 'Document drafting, contract review, redlining, pleadings, citations', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Billing & Trust Accounting', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>10</td>
					<td><?php esc_html_e( 'Time entries, LEDES invoicing, IOLTA trust accounts, profitability', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Compliance & Ethics', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>8</td>
					<td><?php esc_html_e( 'Ethics rules, CLE credits, malpractice risk, AI disclosures', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Litigation Support', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>8</td>
					<td><?php esc_html_e( 'eDiscovery, depositions, settlement analysis, trial preparation', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Legal Research & Analytics', 'mcp-ai-wpoos-pro' ); ?></strong></td>
					<td>8</td>
					<td><?php esc_html_e( 'Case law analysis, firm dashboards, revenue forecasting, benchmarking', 'mcp-ai-wpoos-pro' ); ?></td>
				</tr>
			</tbody>
		</table>

		<div class="notice notice-warning inline" style="margin-top:20px;">
			<p>
				<strong><?php esc_html_e( 'Professional Use:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'This toolkit provides AI-assisted analysis for informational and professional evaluation purposes only. All outputs do not constitute legal advice. Always verify results and consult qualified legal professionals for client matters.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render configuration tab content.
	 *
	 * @since 2.0.0
	 */
	protected function render_configuration_tab() {
		$options              = get_option( $this->option_name, array() );
		$assistant_id         = isset( $options['research_assistant_id'] ) ? absint( $options['research_assistant_id'] ) : 0;
		$default_jurisdiction = isset( $options['default_jurisdiction'] ) ? $options['default_jurisdiction'] : 'federal';
		$default_state        = isset( $options['default_state'] ) ? $options['default_state'] : '';
		$default_billing_rate = isset( $options['default_billing_rate'] ) ? $options['default_billing_rate'] : '350';
		$billing_increment    = isset( $options['billing_increment'] ) ? $options['billing_increment'] : '0.1';
		$trust_accounting_on  = ! empty( $options['enable_trust_accounting'] );
		$firm_dashboard_on    = isset( $options['enable_firm_dashboard'] ) ? (bool) $options['enable_firm_dashboard'] : true;
		$research_on          = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;
		?>
		<h2><?php esc_html_e( 'Default Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Configure default settings for the Law Firm toolkit. These can be overridden per-matter.', 'mcp-ai-wpoos-pro' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="default_jurisdiction"><?php esc_html_e( 'Default Jurisdiction', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[default_jurisdiction]" id="default_jurisdiction">
						<option value="federal" <?php selected( $default_jurisdiction, 'federal' ); ?>><?php esc_html_e( 'Federal', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="state" <?php selected( $default_jurisdiction, 'state' ); ?>><?php esc_html_e( 'State', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Default court jurisdiction for new matters and filings.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="default_state"><?php esc_html_e( 'Default State', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[default_state]" id="default_state" value="<?php echo esc_attr( $default_state ); ?>" placeholder="CA" maxlength="2" class="small-text" />
					<p class="description"><?php esc_html_e( 'Two-letter state abbreviation (e.g., CA, NY, TX) for jurisdiction defaults.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="default_billing_rate"><?php esc_html_e( 'Default Billing Rate ($/hr)', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[default_billing_rate]" id="default_billing_rate" value="<?php echo esc_attr( $default_billing_rate ); ?>" min="0" step="25" class="small-text" />
					<span>$/hr</span>
					<p class="description"><?php esc_html_e( 'Default hourly billing rate for time entries and fee calculations.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="billing_increment"><?php esc_html_e( 'Billing Increment', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[billing_increment]" id="billing_increment">
						<option value="0.1" <?php selected( $billing_increment, '0.1' ); ?>><?php esc_html_e( '6-minute (0.1)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="0.25" <?php selected( $billing_increment, '0.25' ); ?>><?php esc_html_e( '15-minute (0.25)', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Minimum billing time increment. ABA standard is 6-minute (0.1 hour) increments.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Trust Accounting', 'mcp-ai-wpoos-pro' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_trust_accounting]" id="enable_trust_accounting" value="1" <?php checked( $trust_accounting_on, true ); ?> />
						<?php esc_html_e( 'Enable IOLTA trust accounting tools', 'mcp-ai-wpoos-pro' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Enables trust account management, reconciliation, and retainer balance monitoring per IOLTA regulations.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Firm Dashboard', 'mcp-ai-wpoos-pro' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_firm_dashboard]" id="enable_firm_dashboard" value="1" <?php checked( $firm_dashboard_on, true ); ?> />
						<?php esc_html_e( 'Show the firm performance dashboard under the Law Firm menu', 'mcp-ai-wpoos-pro' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Displays utilization rates, revenue forecasts, and matter analytics in a visual dashboard.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]" id="enable_research" value="1" <?php checked( $research_on, true ); ?> />
						<?php esc_html_e( 'Enable the Research & Add page for legal research', 'mcp-ai-wpoos-pro' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, users can access the Research & Add page to perform AI-assisted legal research and case law analysis.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="research_assistant_id"><?php esc_html_e( 'Research Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<?php
					$assistants = get_posts(
						array(
							'post_type'      => 'mcp_ai_assistant',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select name="<?php echo esc_attr( $this->option_name ); ?>[research_assistant_id]" id="research_assistant_id">
						<option value="0"><?php esc_html_e( '— Use default assistant —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $assistants as $assistant ) : ?>
							<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $assistant_id, $assistant->ID ); ?>>
								<?php echo esc_html( $assistant->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Select the AI assistant to use for legal research.', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Get tools list for the Available Tools tab.
	 *
	 * @since 2.0.0
	 *
	 * @return array Tools list with slugs as keys and translated names as values.
	 */
	protected function get_tools_list() {
		return array(
			// Client Intake & Management.
			'lf_client_intake_processor'           => __( 'Client Intake Processor', 'mcp-ai-wpoos-pro' ),
			'lf_conflict_of_interest_checker'      => __( 'Conflict of Interest Checker', 'mcp-ai-wpoos-pro' ),
			'lf_client_profile_analyzer'           => __( 'Client Profile Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_lead_scoring_calculator'           => __( 'Lead Scoring Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_engagement_letter_generator'       => __( 'Engagement Letter Generator', 'mcp-ai-wpoos-pro' ),
			'lf_client_communication_logger'       => __( 'Client Communication Logger', 'mcp-ai-wpoos-pro' ),
			'lf_referral_source_tracker'           => __( 'Referral Source Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_client_portal_manager'             => __( 'Client Portal Manager', 'mcp-ai-wpoos-pro' ),
			// Matter & Case Management.
			'lf_matter_pipeline_manager'           => __( 'Matter Pipeline Manager', 'mcp-ai-wpoos-pro' ),
			'lf_statute_of_limitations_calculator' => __( 'Statute of Limitations Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_court_deadline_tracker'            => __( 'Court Filing Deadline Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_case_timeline_generator'           => __( 'Case Timeline Generator', 'mcp-ai-wpoos-pro' ),
			'lf_task_assignment_manager'           => __( 'Task Assignment Manager', 'mcp-ai-wpoos-pro' ),
			'lf_calendar_rule_calculator'          => __( 'Calendar Rule Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_opposing_counsel_tracker'          => __( 'Opposing Counsel Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_case_outcome_predictor'            => __( 'Case Outcome Predictor', 'mcp-ai-wpoos-pro' ),
			'lf_matter_budget_manager'             => __( 'Matter Budget Manager', 'mcp-ai-wpoos-pro' ),
			'lf_case_status_dashboard'             => __( 'Case Status Dashboard', 'mcp-ai-wpoos-pro' ),
			// Document Automation.
			'lf_document_drafter'                  => __( 'Legal Document Drafter', 'mcp-ai-wpoos-pro' ),
			'lf_contract_reviewer'                 => __( 'Contract Review Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_clause_library_manager'            => __( 'Clause Library Manager', 'mcp-ai-wpoos-pro' ),
			'lf_redline_comparator'                => __( 'Document Redline Comparator', 'mcp-ai-wpoos-pro' ),
			'lf_pleading_generator'                => __( 'Pleading & Motion Generator', 'mcp-ai-wpoos-pro' ),
			'lf_discovery_request_builder'         => __( 'Discovery Request Builder', 'mcp-ai-wpoos-pro' ),
			'lf_document_version_tracker'          => __( 'Document Version Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_legal_citation_checker'            => __( 'Legal Citation Checker', 'mcp-ai-wpoos-pro' ),
			'lf_brief_outline_generator'           => __( 'Brief Outline Generator', 'mcp-ai-wpoos-pro' ),
			'lf_document_template_manager'         => __( 'Document Template Manager', 'mcp-ai-wpoos-pro' ),
			// Billing & Trust Accounting.
			'lf_time_entry_recorder'               => __( 'Time Entry Recorder', 'mcp-ai-wpoos-pro' ),
			'lf_invoice_generator'                 => __( 'Invoice Generator (LEDES)', 'mcp-ai-wpoos-pro' ),
			'lf_trust_account_manager'             => __( 'Trust (IOLTA) Account Manager', 'mcp-ai-wpoos-pro' ),
			'lf_trust_reconciliation_tool'         => __( 'Trust Account Reconciliation', 'mcp-ai-wpoos-pro' ),
			'lf_fee_calculator'                    => __( 'Legal Fee Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_billing_compliance_checker'        => __( 'Billing Compliance Checker', 'mcp-ai-wpoos-pro' ),
			'lf_accounts_receivable_tracker'       => __( 'Accounts Receivable Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_retainer_balance_monitor'          => __( 'Retainer Balance Monitor', 'mcp-ai-wpoos-pro' ),
			'lf_expense_reimbursement_tracker'     => __( 'Expense Reimbursement Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_profitability_analyzer'            => __( 'Matter Profitability Analyzer', 'mcp-ai-wpoos-pro' ),
			// Compliance & Ethics.
			'lf_ethics_rule_checker'               => __( 'Ethics Rule Checker', 'mcp-ai-wpoos-pro' ),
			'lf_bar_deadline_monitor'              => __( 'Bar Reporting Deadline Monitor', 'mcp-ai-wpoos-pro' ),
			'lf_cle_credit_tracker'                => __( 'CLE Credit Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_malpractice_risk_scorer'           => __( 'Malpractice Risk Scorer', 'mcp-ai-wpoos-pro' ),
			'lf_data_privacy_compliance_checker'   => __( 'Data Privacy Compliance Checker', 'mcp-ai-wpoos-pro' ),
			'lf_client_confidentiality_auditor'    => __( 'Client Confidentiality Auditor', 'mcp-ai-wpoos-pro' ),
			'lf_regulatory_change_monitor'         => __( 'Regulatory Change Monitor', 'mcp-ai-wpoos-pro' ),
			'lf_ai_usage_disclosure_generator'     => __( 'AI Usage Disclosure Generator', 'mcp-ai-wpoos-pro' ),
			// Litigation Support.
			'lf_ediscovery_document_analyzer'      => __( 'eDiscovery Document Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_deposition_summary_generator'      => __( 'Deposition Summary Generator', 'mcp-ai-wpoos-pro' ),
			'lf_evidence_catalog_manager'          => __( 'Evidence Catalog Manager', 'mcp-ai-wpoos-pro' ),
			'lf_jury_instruction_drafter'          => __( 'Jury Instruction Drafter', 'mcp-ai-wpoos-pro' ),
			'lf_settlement_value_calculator'       => __( 'Settlement Value Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_damages_calculator'                => __( 'Damages Calculator', 'mcp-ai-wpoos-pro' ),
			'lf_expert_witness_tracker'            => __( 'Expert Witness Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_trial_preparation_checklist'       => __( 'Trial Preparation Checklist', 'mcp-ai-wpoos-pro' ),
			// Legal Research & Analytics.
			'lf_legal_research_assistant'          => __( 'Legal Research Assistant', 'mcp-ai-wpoos-pro' ),
			'lf_case_law_analyzer'                 => __( 'Case Law Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_firm_performance_dashboard'        => __( 'Firm Performance Dashboard', 'mcp-ai-wpoos-pro' ),
			'lf_matter_analytics_generator'        => __( 'Matter Analytics Generator', 'mcp-ai-wpoos-pro' ),
			'lf_revenue_forecaster'                => __( 'Revenue Forecaster', 'mcp-ai-wpoos-pro' ),
			'lf_attorney_utilization_tracker'      => __( 'Attorney Utilization Tracker', 'mcp-ai-wpoos-pro' ),
			'lf_client_satisfaction_analyzer'      => __( 'Client Satisfaction Analyzer', 'mcp-ai-wpoos-pro' ),
			'lf_competitive_benchmarker'           => __( 'Competitive Benchmarker', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 2.0.0
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();

		if ( isset( $input['default_jurisdiction'] ) ) {
			$sanitized['default_jurisdiction'] = sanitize_text_field( $input['default_jurisdiction'] );
		}
		if ( isset( $input['default_state'] ) ) {
			$sanitized['default_state'] = strtoupper( sanitize_text_field( substr( $input['default_state'], 0, 2 ) ) );
		}
		if ( isset( $input['default_billing_rate'] ) ) {
			$sanitized['default_billing_rate'] = absint( $input['default_billing_rate'] );
		}
		if ( isset( $input['billing_increment'] ) ) {
			$sanitized['billing_increment'] = in_array( $input['billing_increment'], array( '0.1', '0.25' ), true ) ? $input['billing_increment'] : '0.1';
		}
		$sanitized['enable_trust_accounting'] = ! empty( $input['enable_trust_accounting'] ) ? 1 : 0;
		$sanitized['enable_firm_dashboard']   = ! empty( $input['enable_firm_dashboard'] ) ? 1 : 0;

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		if ( isset( $input['research_assistant_id'] ) ) {
			$sanitized['research_assistant_id'] = absint( $input['research_assistant_id'] );
		}

		return $sanitized;
	}
}
