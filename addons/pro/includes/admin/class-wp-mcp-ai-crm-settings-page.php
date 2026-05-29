<?php
/**
 * CRM & Email Marketing Toolkit Settings Page
 *
 * Provides admin UI for the CRM Toolkit with Overview, Configuration,
 * Tools Management, Research & Add, and Help tabs.  Updated for Phase A
 * (shared engine infrastructure) and the upcoming Phase B–E roadmap.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0 (original), 2.3.0 (Phase A update)
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * CRM & Email Marketing Toolkit Settings Page Class
 */
class WP_MCP_AI_CRM_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'crm';
		$this->toolkit_name     = __( 'CRM & Email Marketing Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_crm_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-crm-toolkit-settings';
		$this->parent_slug      = WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG;
		$this->has_research     = false;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-email';

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
		// Resolve real toolkit settings for the overview.
		$settings = array();
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		}
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'CRM & Email Marketing Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="toolkit-description">
				<p>
					<?php esc_html_e( 'Comprehensive customer relationship management toolkit with AI-powered lead scoring, BANT/MEDDIC qualification frameworks, multichannel inbox triage, outreach sequence automation, pipeline analytics, and regulatory compliance (GDPR, CAN-SPAM, TCPA).', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Architecture:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( 'Mirrors the Healthcare Toolkit pattern — shared engine, standards registry, audit ledger, capability map, consent ledger, pipeline stage registry, and classifier.  See the enhancement roadmap for the phased delivery plan.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>

			<!-- Phase A: Current State -->
			<h3><?php esc_html_e( 'Phase A — Available Now (Shared Engine + 11 Tools)', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Shared Engine:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Engine — lead scoring (fit + intent + engagement + recency), lifecycle stage progression (Subscriber → Lead → MQL → SAL → SQL → Opportunity → Customer), weighted pipeline forecasting, round-robin/weighted routing, DNC/suppression enforcement, currency formatting.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Standards Registry:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Codes — BANT, MEDDIC, & CHAMP qualification frameworks; HubSpot lifecycle stages; Salesforce pipeline stages; GDPR legal bases; intent/sentiment/disposition codes.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Audit Ledger:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Audit — append-only PII/consent audit log (10 000 entry rolling buffer), forwardable to external SIEM.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Capability Map:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Capabilities — 8 sales roles (sales_manager, account_executive, sdr, …) mapped to 30+ WordPress capabilities.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Consent Ledger:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Consent — per-channel consent records, real-time cross-channel revocation (TCPA Apr 2025 FCC rule), automatic DNC propagation.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Pipeline Stages:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Pipeline_Stages — 10-stage Salesforce pipeline with win-probability weights for forecasting.', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'Classifier:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'WP_MCP_AI_CRM_Classifier — heuristic intent/sentiment classification + BANT & MEDDIC field extraction from inbound messages.', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Current Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat fixed striped" style="max-width: 700px;">
				<tbody>
					<tr><th><?php esc_html_e( 'Qualification Framework', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo esc_html( $settings['qualification_framework'] ?? 'bant' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Hot Score Threshold', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo esc_html( $settings['hot_score_threshold'] ?? '70' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Warm Score Threshold', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo esc_html( $settings['warm_score_threshold'] ?? '40' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Routing Strategy', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo esc_html( $settings['routing']['strategy'] ?? 'round_robin' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Consent — Double Opt-In', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo ! empty( $settings['consent']['require_double_opt_in'] ) ? esc_html__( 'Yes', 'mcp-ai-wpoos-pro' ) : esc_html__( 'No', 'mcp-ai-wpoos-pro' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Audit Retention', 'mcp-ai-wpoos-pro' ); ?></th><td><?php echo esc_html( ( $settings['audit_retention_days'] ?? 365 ) . ' ' . __( 'days', 'mcp-ai-wpoos-pro' ) ); ?></td></tr>
				</tbody>
			</table>

			<!-- Phase B–E upcoming -->
			<h3><?php esc_html_e( 'Upcoming Phases (B → E)', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat fixed striped" style="max-width: 900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Phase', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Deliverable', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Est. Tools Added', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr style="background-color: #d4edda;">
						<td><strong><?php esc_html_e( 'Phase A', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Shared engine, codes, audit, capabilities, consent, pipeline stages, classifier + is_available() on all tools', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>—</td>
						<td><span style="color: #155724;">✓ <?php esc_html_e( 'Done', 'mcp-ai-wpoos-pro' ); ?></span></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Phase B', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Lead CRUD, deal/opportunity CRUD, activity CRUD, pipeline analytics, lead routing', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>~22</td>
						<td><?php esc_html_e( 'Planned', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Phase C', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Inbound triage (email/SMS/WhatsApp), multichannel outbound send, auto-reply, AI draft', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>~18</td>
						<td><?php esc_html_e( 'Planned', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Phase D', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Outreach sequences, Workflow Command Center unified inbox, workflow rules engine', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>~14</td>
						<td><?php esc_html_e( 'Planned', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Phase E', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Consent/DNC/opt-out tools, CSV import, external CRM sync, 4 assistant blueprints', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>~8</td>
						<td><?php esc_html_e( 'Planned', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'NPM Packages Integrated', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong>nodemailer</strong> (6M/week): Advanced SMTP email sending</li>
				<li><strong>validator</strong> (14M/week): Comprehensive input validation</li>
				<li><strong>csv-parse/stringify</strong> (8M/week): Contact import/export</li>
				<li><strong>email-validator</strong> (600K/week): Email validation</li>
				<li><strong>libphonenumber-js</strong> (4M/week): Phone validation</li>
				<li><strong>mailparser</strong> (1M/week): Email parsing</li>
				<li><strong>ical-generator</strong> (300K/week): Calendar generation</li>
			</ul>

			<h3><?php esc_html_e( 'Requirements', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'Node.js:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo $this->check_nodejs_available() ? '<span style="color: green;">✓ Available</span>' : '<span style="color: orange;">⚠ Optional (PHP fallbacks available)</span>'; ?></li>
				<li><strong><?php esc_html_e( 'JetEngine (Optional):', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo function_exists( 'jet_engine' ) ? '<span style="color: green;">✓ Active (CCT storage available)</span>' : '<span style="color: blue;">○ Not installed (CPT storage will be used)</span>'; ?></li>
			</ul>

			<h3><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><code>addons/pro/docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md</code> — Phases A→E enhancement roadmap</li>
				<li><code>addons/pro/docs/CRM_EMAIL_MARKETING_GUIDE.md</code> — Comprehensive integration guide</li>
				<li><code>addons/pro/includes/tools/crm/README.md</code> — Developer architecture reference</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab.
	 *
	 * This now only outputs the CRM-specific fields (which are embedded
	 * inside the form by the overridden render_configuration_form() below).
	 * The method is kept for any direct tab rendering that doesn't go
	 * through render_configuration_form().
	 */
	protected function render_configuration_tab() {
		$this->render_configuration_form();
	}

	/**
	 * Render configuration form — overrides parent to embed CRM-specific
	 * fields INSIDE the form so that save processing works correctly.
	 */
	protected function render_configuration_form() {
		$settings = array();
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		}
		$option_name = $this->option_name;
		?>
		<div class="toolkit-card">
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name . '_group' );
				do_settings_sections( $this->option_name );
				?>

				<h2><?php esc_html_e( 'CRM & Email Marketing Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'These settings control the shared CRM engine used by all CRM tools.  Changes apply immediately to all lead scoring, routing, pipeline, and consent decisions.', 'mcp-ai-wpoos-pro' ); ?>
				</p>

				<table class="form-table">
					<!-- Lead Scoring -->
					<tr><td colspan="2"><h3><?php esc_html_e( 'Lead Scoring & Qualification', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Qualification Framework', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $option_name ); ?>[qualification_framework]">
								<option value="bant" <?php selected( $settings['qualification_framework'] ?? 'bant', 'bant' ); ?>><?php esc_html_e( 'BANT — Budget · Authority · Need · Timeline', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="meddic" <?php selected( $settings['qualification_framework'] ?? '', 'meddic' ); ?>><?php esc_html_e( 'MEDDIC — Metrics · Economic Buyer · Decision Criteria · Decision Process · Identify Pain · Champion', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="champ" <?php selected( $settings['qualification_framework'] ?? '', 'champ' ); ?>><?php esc_html_e( 'CHAMP — Challenges · Authority · Money · Prioritisation', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default qualification framework used by qualify_lead_bant / qualify_lead_meddic tools.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hot Lead Threshold', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="number" name="<?php echo esc_attr( $option_name ); ?>[hot_score_threshold]" value="<?php echo esc_attr( $settings['hot_score_threshold'] ?? 70 ); ?>" min="0" max="100" class="small-text" />
							<p class="description"><?php esc_html_e( 'Score ≥ threshold → hot lead (0–100).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Warm Lead Threshold', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="number" name="<?php echo esc_attr( $option_name ); ?>[warm_score_threshold]" value="<?php echo esc_attr( $settings['warm_score_threshold'] ?? 40 ); ?>" min="0" max="100" class="small-text" />
							<p class="description"><?php esc_html_e( 'Score ≥ threshold → warm lead (0–100).', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<!-- Routing -->
					<tr><td colspan="2"><h3><?php esc_html_e( 'Lead Routing', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Routing Strategy', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $option_name ); ?>[routing][strategy]">
								<option value="round_robin" <?php selected( $settings['routing']['strategy'] ?? 'round_robin', 'round_robin' ); ?>><?php esc_html_e( 'Round Robin — distribute evenly among pool', 'mcp-ai-wpoos-pro' ); ?></option>
								<option value="weighted" <?php selected( $settings['routing']['strategy'] ?? '', 'weighted' ); ?>><?php esc_html_e( 'Weighted — assign to rep with fewest active leads', 'mcp-ai-wpoos-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How new leads are automatically distributed to the sales team.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<!-- Consent -->
					<tr><td colspan="2"><h3><?php esc_html_e( 'Consent & Compliance', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require Double Opt-In', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[consent][require_double_opt_in]" value="1" <?php checked( ! empty( $settings['consent']['require_double_opt_in'] ) ); ?> />
								<?php esc_html_e( 'Require explicit opt-in confirmation before sending outbound email', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, email outbound is blocked for contacts without an active consent record (GDPR-compliant default). When disabled, legitimate-interest emails are allowed.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'CAN-SPAM Physical Address', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( $option_name ); ?>[consent][physical_address]" value="<?php echo esc_attr( $settings['consent']['physical_address'] ?? '' ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Required by U.S. CAN-SPAM Act.  Inserted into the footer of outbound marketing emails.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<!-- Storage & Audit -->
					<tr><td colspan="2"><h3><?php esc_html_e( 'Storage & Auditing', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Audit Retention (days)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<input type="number" name="<?php echo esc_attr( $option_name ); ?>[audit_retention_days]" value="<?php echo esc_attr( $settings['audit_retention_days'] ?? 365 ); ?>" min="30" max="2555" class="small-text" />
							<p class="description"><?php esc_html_e( 'How long PII/consent audit entries are retained in the rolling buffer.  For long-term storage, use the wp_mcp_ai_crm_after_audit action to forward to an external SIEM.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Storage Backend', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$storage_type = class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' )
								? WP_MCP_AI_Toolkit_Data_Store_Factory::get_storage_type()
								: 'cpt';
							?>
							<p>
								<strong><?php echo esc_html( 'cct' === $storage_type ? __( 'JetEngine CCT', 'mcp-ai-wpoos-pro' ) : __( 'WordPress CPT', 'mcp-ai-wpoos-pro' ) ); ?></strong>
								<?php if ( 'cct' === $storage_type ) : ?>
									<br /><span style="color: green;">✓ Using JetEngine Custom Content Types for enhanced performance</span>
								<?php else : ?>
									<br /><span style="color: blue;">○ Using WordPress Custom Post Types (standard storage)</span>
								<?php endif; ?>
							</p>
							<p class="description"><?php esc_html_e( 'Storage backend is automatically selected based on JetEngine availability.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>

					<!-- AI Integration -->
					<tr><td colspan="2"><h3><?php esc_html_e( 'AI Integration', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Research & Add Assistant', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php
							$current_assistant = isset( $settings['research_assistant_id'] ) ? $settings['research_assistant_id'] : get_option( 'wp_mcp_ai_crm_research_assistant', 'default' );
							$assistants        = $this->get_available_assistants();
							?>
							<select name="<?php echo esc_attr( $option_name ); ?>[research_assistant_id]" id="crm_research_assistant">
								<?php foreach ( $assistants as $assistant_id => $assistant_name ) : ?>
									<option value="<?php echo esc_attr( $assistant_id ); ?>" <?php selected( (string) $current_assistant, (string) $assistant_id ); ?>>
										<?php echo esc_html( $assistant_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select which AI assistant to use for CRM research on the Research & Add pages. This assistant will help with web search, industry analysis, and company identification.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'mcp-ai-wpoos-pro' ) ); ?>
			</form>
		</div><!-- .toolkit-card -->
		<?php
	}

	/**
	 * Sanitize settings — overrides parent to handle CRM-specific
	 * nested fields and merge them with the existing defaults from
	 * WP_MCP_AI_CRM_Engine::get_toolkit_settings().
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array Sanitized settings ready for update_option().
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Start with the existing resolved settings (which includes all defaults).
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$sanitized = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			// Clear the cache so next read sees fresh values.
			WP_MCP_AI_CRM_Engine::flush_settings_cache();
		} else {
			$sanitized = array();
		}

		// ---- Top-level fields ----
		if ( isset( $input['qualification_framework'] ) ) {
			$valid_frameworks = array( 'bant', 'meddic', 'champ' );
			$sanitized['qualification_framework'] = in_array( $input['qualification_framework'], $valid_frameworks, true )
				? $input['qualification_framework']
				: 'bant';
		}

		if ( isset( $input['hot_score_threshold'] ) ) {
			$sanitized['hot_score_threshold'] = min( 100, max( 0, absint( $input['hot_score_threshold'] ) ) );
		}

		if ( isset( $input['warm_score_threshold'] ) ) {
			$sanitized['warm_score_threshold'] = min( 100, max( 0, absint( $input['warm_score_threshold'] ) ) );
		}

		if ( isset( $input['audit_retention_days'] ) ) {
			$sanitized['audit_retention_days'] = min( 2555, max( 30, absint( $input['audit_retention_days'] ) ) );
		}

		// ---- Nested: routing ----
		if ( isset( $input['routing'] ) && is_array( $input['routing'] ) ) {
			if ( ! isset( $sanitized['routing'] ) || ! is_array( $sanitized['routing'] ) ) {
				$sanitized['routing'] = array();
			}
			if ( isset( $input['routing']['strategy'] ) ) {
				$valid_strategies = array( 'round_robin', 'weighted' );
				$sanitized['routing']['strategy'] = in_array( $input['routing']['strategy'], $valid_strategies, true )
					? $input['routing']['strategy']
					: 'round_robin';
			}
		}

		// ---- Nested: consent ----
		if ( isset( $input['consent'] ) && is_array( $input['consent'] ) ) {
			if ( ! isset( $sanitized['consent'] ) || ! is_array( $sanitized['consent'] ) ) {
				$sanitized['consent'] = array();
			}
			$sanitized['consent']['require_double_opt_in'] = ! empty( $input['consent']['require_double_opt_in'] );
			if ( isset( $input['consent']['physical_address'] ) ) {
				$sanitized['consent']['physical_address'] = sanitize_text_field( $input['consent']['physical_address'] );
			}
		}

		// ---- Research assistant (migrate from legacy option to toolkit settings) ----
		if ( isset( $input['research_assistant_id'] ) && '' !== $input['research_assistant_id'] ) {
			$sanitized['research_assistant_id'] = sanitize_text_field( $input['research_assistant_id'] );
			// Also update the legacy option for backward compatibility.
			update_option( 'wp_mcp_ai_crm_research_assistant', $sanitized['research_assistant_id'] );
		}

		// ---- Pass through base-class fields if present ----
		$parent_sanitized = parent::sanitize_settings( $input );
		foreach ( $parent_sanitized as $key => $value ) {
			$sanitized[ $key ] = $value;
		}

		return $sanitized;
	}

	/**
	 * Get tools list — grouped by phase for the Tools Management tab.
	 *
	 * @return array Grouped array: 'group_label' => array( slug => name ).
	 */
	protected function get_tools_list() {
		$tools = array();

		// ---- AVAILABLE NOW (Phase A built + pre-existing) ----
		$tools[ __( 'Available Now (11 tools)', 'mcp-ai-wpoos-pro' ) ] = array(
			// Contact & Company.
			'manage_crm_contact'              => __( 'Manage CRM Contact', 'mcp-ai-wpoos-pro' ),
			'create_company'                  => __( 'Create Company', 'mcp-ai-wpoos-pro' ),
			'get_companies'                   => __( 'Get Companies', 'mcp-ai-wpoos-pro' ),
			'research_company'                => __( 'Research Company (Web Search)', 'mcp-ai-wpoos-pro' ),
			// Email search (with caching + scheduling).
			'crm_email_search_leads'          => __( 'Email Search: New Leads', 'mcp-ai-wpoos-pro' ),
			'crm_email_search_correspondence' => __( 'Email Search: Customer Correspondence', 'mcp-ai-wpoos-pro' ),
			'crm_email_search_accounting'     => __( 'Email Search: Accounting & Service', 'mcp-ai-wpoos-pro' ),
			// MemPalace.
			'crm_capture_interaction'         => __( 'Capture CRM Interaction (MemPalace)', 'mcp-ai-wpoos-pro' ),
			// Upwork.
			'draft_upwork_proposal'           => __( 'Draft Upwork Proposal', 'mcp-ai-wpoos-pro' ),
			'score_upwork_job'                => __( 'Score Upwork Job', 'mcp-ai-wpoos-pro' ),
			'search_upwork_jobs'              => __( 'Search Upwork Jobs', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase B: CRUD + Pipeline + Routing (22 tools planned) ----
		$tools[ __( 'Phase B — Lead, Deal & Pipeline (22 tools, upcoming)', 'mcp-ai-wpoos-pro' ) ] = array(
			'create_lead'               => __( 'Create Lead', 'mcp-ai-wpoos-pro' ),
			'list_leads'                => __( 'List Leads', 'mcp-ai-wpoos-pro' ),
			'get_lead'                  => __( 'Get Lead', 'mcp-ai-wpoos-pro' ),
			'update_lead'               => __( 'Update Lead', 'mcp-ai-wpoos-pro' ),
			'delete_lead'               => __( 'Delete Lead', 'mcp-ai-wpoos-pro' ),
			'convert_lead_to_customer'  => __( 'Convert Lead to Customer', 'mcp-ai-wpoos-pro' ),
			'create_deal'               => __( 'Create Deal', 'mcp-ai-wpoos-pro' ),
			'list_deals'                => __( 'List Deals', 'mcp-ai-wpoos-pro' ),
			'update_deal'               => __( 'Update Deal', 'mcp-ai-wpoos-pro' ),
			'move_deal_stage'           => __( 'Move Deal Stage', 'mcp-ai-wpoos-pro' ),
			'get_pipeline_view'         => __( 'Pipeline Kanban View', 'mcp-ai-wpoos-pro' ),
			'forecast_pipeline_revenue' => __( 'Forecast Pipeline Revenue', 'mcp-ai-wpoos-pro' ),
			'assign_lead_to_owner'      => __( 'Assign Lead to Owner', 'mcp-ai-wpoos-pro' ),
			'…'                         => __( '+ 9 more tools →', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase C: Inbound Triage + Outbound (18 tools planned) ----
		$tools[ __( 'Phase C — Inbound Triage & Outbound (18 tools, upcoming)', 'mcp-ai-wpoos-pro' ) ] = array(
			'evaluate_inbound_message'  => __( 'Evaluate Inbound Message', 'mcp-ai-wpoos-pro' ),
			'classify_message_intent'   => __( 'Classify Message Intent', 'mcp-ai-wpoos-pro' ),
			'extract_lead_from_message' => __( 'Extract Lead from Message', 'mcp-ai-wpoos-pro' ),
			'score_lead'                => __( 'Score Lead (composite)', 'mcp-ai-wpoos-pro' ),
			'qualify_lead_bant'         => __( 'Qualify Lead (BANT)', 'mcp-ai-wpoos-pro' ),
			'send_lead_email'           => __( 'Send Lead Email', 'mcp-ai-wpoos-pro' ),
			'send_lead_sms'             => __( 'Send Lead SMS', 'mcp-ai-wpoos-pro' ),
			'send_lead_whatsapp'        => __( 'Send Lead WhatsApp', 'mcp-ai-wpoos-pro' ),
			'…'                         => __( '+ 10 more tools →', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase D: Sequences + Command Center (14 tools planned) ----
		$tools[ __( 'Phase D — Sequences & Command Center (14 tools, upcoming)', 'mcp-ai-wpoos-pro' ) ] = array(
			'create_outreach_sequence' => __( 'Create Outreach Sequence', 'mcp-ai-wpoos-pro' ),
			'enroll_lead_in_sequence'  => __( 'Enroll Lead in Sequence', 'mcp-ai-wpoos-pro' ),
			'get_sequence_performance' => __( 'Sequence Performance', 'mcp-ai-wpoos-pro' ),
			'create_workflow_rule'     => __( 'Create Workflow Rule', 'mcp-ai-wpoos-pro' ),
			'get_workflow_inbox'       => __( 'Workflow Command Center Inbox', 'mcp-ai-wpoos-pro' ),
			'…'                        => __( '+ 9 more tools →', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase E: Compliance + Interop (8 tools planned) ----
		$tools[ __( 'Phase E — Compliance & Interop (8 tools, upcoming)', 'mcp-ai-wpoos-pro' ) ] = array(
			'record_consent'          => __( 'Record Consent', 'mcp-ai-wpoos-pro' ),
			'revoke_consent'          => __( 'Revoke Consent', 'mcp-ai-wpoos-pro' ),
			'check_dnc_status'        => __( 'Check DNC Status', 'mcp-ai-wpoos-pro' ),
			'connect_to_external_crm' => __( 'Connect to External CRM', 'mcp-ai-wpoos-pro' ),
			'…'                       => __( '+ 4 more tools →', 'mcp-ai-wpoos-pro' ),
		);

		return $tools;
	}

	/**
	 * Render tools management tab — overrides base class to support
	 * grouped tools (phase headers).
	 *
	 * @since 2.3.0
	 */
	protected function render_tools_tab() {
		$tools = $this->get_tools_list();

		// Count total tools across all groups.
		$total = 0;
		foreach ( $tools as $group ) {
			if ( is_array( $group ) ) {
				$total += count( $group );
			}
		}
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php
					printf(
						/* translators: %d: Number of tools listed */
						esc_html__( 'This toolkit provides %d AI-powered tools across five delivery phases.  Tools marked as available now are ready for your assistants; upcoming phases are in active development.', 'mcp-ai-wpoos-pro' ),
						esc_html( $total )
					);
				?>
			</p>

			<div class="tools-list" style="margin-top: 20px;">
				<?php foreach ( $tools as $group_label => $group_tools ) : ?>
					<h3 style="margin-top: 24px; border-bottom: 1px solid #ccd0d4; padding-bottom: 6px;">
						<?php echo esc_html( $group_label ); ?>
					</h3>
					<?php if ( is_array( $group_tools ) ) : ?>
						<?php foreach ( $group_tools as $tool_slug => $tool_name ) : ?>
							<div class="tool-item" style="padding: 4px 0;">
								<strong><?php echo esc_html( $tool_name ); ?></strong>
								<code style="margin-left: 10px; font-size: 11px;"><?php echo esc_html( $tool_slug ); ?></code>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="toolkit-card">
			<h2><?php esc_html_e( 'How to Use These Tools', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'All tools from this toolkit are automatically available to your AI assistants once the toolkit is enabled.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p><?php esc_html_e( 'To enable this toolkit:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Go to Settings → NV oOS → Tools & Features', 'mcp-ai-wpoos-pro' ); ?></li>
				<li>
					<?php
					/* translators: %s: Toolkit name */
					printf( esc_html__( 'Check the "%s" option', 'mcp-ai-wpoos-pro' ), esc_html( $this->toolkit_name ) );
					?>
				</li>
				<li><?php esc_html_e( 'Save the settings', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=features' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Get available assistants for selection
	 *
	 * @return array List of assistant_id => name
	 */
	private function get_available_assistants() {
		$assistants = array(
			'default' => __( 'Default Assistant', 'mcp-ai-wpoos-pro' ),
		);

		// Get all published assistants.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$assistants[ get_the_ID() ] = get_the_title();
			}
			wp_reset_postdata();
		}

		return $assistants;
	}

	/**
	 * Check if Node.js is available
	 *
	 * @return bool
	 */
	private function check_nodejs_available() {
		// Check if nodemailer package exists.
		$nodemailer = WP_MCP_AI_PRO_PATH . 'node_modules/nodemailer';
		return file_exists( $nodemailer );
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_CRM_Settings_Page();
}
