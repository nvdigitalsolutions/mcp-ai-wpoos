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
					<?php esc_html_e( 'Mirrors the Healthcare Toolkit pattern — shared engine, standards registry, audit ledger, capability map, consent ledger, pipeline stage registry, and classifier. Phases A, B, D, E & F are complete; Phase C has 1 integration stub remaining.', 'mcp-ai-wpoos-pro' ); ?>
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

			<!-- Phase roadmap status -->
			<h3><?php esc_html_e( 'Phased Roadmap Status', 'mcp-ai-wpoos-pro' ); ?></h3>
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
					<tr style="background-color: #d4edda;">
						<td><strong><?php esc_html_e( 'Phase B', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Lead CRUD, deal/opportunity CRUD, activity CRUD, pipeline analytics, lead routing', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>~22</td>
						<td><span style="color: #155724;">✓ <?php esc_html_e( 'Done', 'mcp-ai-wpoos-pro' ); ?></span></td>
					</tr>
					<tr style="background-color: #d4edda;">
						<td><strong><?php esc_html_e( 'Phase C', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Inbound triage (email/SMS/WhatsApp), multichannel outbound send, auto-reply, AI draft', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>15 tools + 3 listeners (IMAP/SMS/WhatsApp webhooks)</td>
						<td><span style="color: #155724;">✓ <?php esc_html_e( 'Done — Pure PHP IMAP (no ext-imap), Twilio + notify.lk SMS, Meta WhatsApp API, AI-powered drafts', 'mcp-ai-wpoos-pro' ); ?></span></td>
					</tr>
					<tr style="background-color: #d4edda;">
						<td><strong><?php esc_html_e( 'Phase D', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Outreach sequences, Workflow Command Center unified inbox, workflow rules engine', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>13 tools built</td>
						<td><span style="color: #155724;">✓ <?php esc_html_e( 'Done', 'mcp-ai-wpoos-pro' ); ?></span></td>
					</tr>
					<tr style="background-color: #d4edda;">
						<td><strong><?php esc_html_e( 'Phase E', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php esc_html_e( 'Consent/DNC/opt-out tools, CSV import, external CRM sync, 8 assistant blueprints', 'mcp-ai-wpoos-pro' ); ?></td>
						<td>8 tools built</td>
						<td><span style="color: #155724;">✓ <?php esc_html_e( 'Done', 'mcp-ai-wpoos-pro' ); ?></span></td>
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
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		// Read current values from the real toolkit settings option.
		$settings = array();
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		}

		// Migrate legacy CRM research assistant option into the grouped option.
		$legacy_assistant = get_option( 'wp_mcp_ai_crm_research_assistant', null );
		if ( null !== $legacy_assistant && ! isset( $settings['research_assistant'] ) ) {
			$settings['research_assistant'] = $legacy_assistant;
			// Persist the change so the engine cache stays current.
			$stored                       = get_option( $this->option_name, array() );
			$stored['research_assistant'] = $legacy_assistant;
			update_option( $this->option_name, $stored );
			// Update engine cache.
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
				WP_MCP_AI_CRM_Engine::flush_settings_cache();
			}
		}

		// Resolve current assistant selection.
		$current_assistant = isset( $settings['research_assistant'] ) ? $settings['research_assistant'] : 'default';
		$assistants        = $this->get_available_assistants();

		$option_name = $this->option_name;
		?>
		<div class="toolkit-configuration">
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

				<!-- Integrations (Phase C) -->
				<tr><td colspan="2"><h3><?php esc_html_e( 'Channel Integrations', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description" style="margin: 4px 0 8px 0;">
					<?php esc_html_e( 'Gmail and WhatsApp credentials can also be managed via', 'mcp-ai-wpoos-pro' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' ) ); ?>"><?php esc_html_e( 'Remote Sites', 'mcp-ai-wpoos-pro' ); ?></a>.
					<?php esc_html_e( 'The CRM tools automatically discover Gmail and WhatsApp connections configured there. Twilio and notify.lk direct entry is provided below until Remote Sites types are added for them.', 'mcp-ai-wpoos-pro' ); ?>
				</p></td></tr>

				<!-- Gmail Import Default Query -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Gmail Import Query', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[integrations][gmail_default_query]" value="<?php echo esc_attr( $settings['integrations']['gmail_default_query'] ?? '' ); ?>" class="large-text" placeholder="newer_than:7d is:unread -category:promotions -category:social" />
						<p class="description">
							<?php esc_html_e( 'Default Gmail search query used by import_gmail_to_crm and crm_email_search_leads when no query is provided. Uses Gmail search syntax.', 'mcp-ai-wpoos-pro' ); ?><br>
							<?php esc_html_e( 'Examples:', 'mcp-ai-wpoos-pro' ); ?>
							<code>newer_than:7d is:unread</code>,
							<code>from:client.com newer_than:3d</code>,
							<code>subject:demo OR subject:pricing is:unread</code>,
							<code>newer_than:14d -category:promotions -category:social -category:forums</code>
						</p>
					</td>
				</tr>

				<!-- Gmail Scheduled Import -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Gmail Poll Interval (seconds)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[integrations][gmail_poll_interval]" value="<?php echo esc_attr( $settings['integrations']['gmail_poll_interval'] ?? 300 ); ?>" min="60" max="3600" step="60" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'How often the Gmail listener polls for new emails (60–3600 seconds). Shorter intervals consume more API quota. Default: 300 (5 minutes).', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max Emails Per Poll', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[integrations][gmail_max_per_poll]" value="<?php echo esc_attr( $settings['integrations']['gmail_max_per_poll'] ?? 10 ); ?>" min="1" max="25" class="small-text" />
						<p class="description"><?php esc_html_e( 'Maximum emails to fetch per poll cycle (1–25). Use lower values to reduce API usage.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Incremental Sync (historyId)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[integrations][gmail_use_history_sync]" value="1" <?php checked( ! empty( $settings['integrations']['gmail_use_history_sync'] ) ); ?> />
							<?php esc_html_e( 'Use Gmail historyId for incremental sync (only fetch new messages since last poll). When disabled, performs a fresh search each cycle.', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Recommended: enabled. Reduces API quota consumption by up to 90%. Uses Gmail users.history.list() API.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- SMS Provider -->
				<tr>
					<th scope="row"><?php esc_html_e( 'SMS Provider', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $option_name ); ?>[integrations][sms_provider]">
							<option value="twilio" <?php selected( $settings['integrations']['sms_provider'] ?? 'twilio', 'twilio' ); ?>>Twilio</option>
							<option value="notifylk" <?php selected( $settings['integrations']['sms_provider'] ?? '', 'notifylk' ); ?>>notify.lk (Sri Lanka)</option>
						</select>
						<p class="description"><?php esc_html_e( 'Select which SMS gateway to use for outbound SMS and auto-reply.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Twilio -->
				<tr><td colspan="2"><h4 style="margin: 0; padding-top: 8px;"><?php esc_html_e( 'Twilio (SMS)', 'mcp-ai-wpoos-pro' ); ?></h4></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Account SID', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[integrations][twilio_account_sid_secret]" value="<?php echo esc_attr( $settings['integrations']['twilio_account_sid_secret'] ?? '' ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Your Twilio Account SID from the Twilio Console dashboard.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auth Token', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $option_name ); ?>[integrations][twilio_auth_token_secret]" value="<?php echo esc_attr( $settings['integrations']['twilio_auth_token_secret'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Your Twilio Auth Token. Stored securely in the WordPress options table.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'From Number', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[integrations][twilio_from_number]" value="<?php echo esc_attr( $settings['integrations']['twilio_from_number'] ?? '' ); ?>" class="regular-text" placeholder="+1234567890" />
						<p class="description"><?php esc_html_e( 'Your Twilio phone number in E.164 format (e.g. +1234567890). Used as the sender for outbound SMS.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- WhatsApp -->
				<tr><td colspan="2"><h4 style="margin: 0; padding-top: 8px;"><?php esc_html_e( 'WhatsApp (Meta Cloud API)', 'mcp-ai-wpoos-pro' ); ?></h4></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access Token', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $option_name ); ?>[integrations][whatsapp_access_token]" value="<?php echo esc_attr( $settings['integrations']['whatsapp_access_token'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'System User Access Token from Meta Business Suite → Business Settings → System Users. Must have whatsapp_business_messaging permission.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Phone Number ID', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[integrations][whatsapp_phone_number_id]" value="<?php echo esc_attr( $settings['integrations']['whatsapp_phone_number_id'] ?? '' ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'WhatsApp Business phone number ID from the WABA settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'App Secret', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $option_name ); ?>[integrations][whatsapp_app_secret]" value="<?php echo esc_attr( $settings['integrations']['whatsapp_app_secret'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Meta App Secret for webhook signature validation. Used to verify inbound WhatsApp messages are authentic.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- notify.lk -->
				<tr><td colspan="2"><h4 style="margin: 0; padding-top: 8px;"><?php esc_html_e( 'notify.lk (Sri Lanka SMS Gateway)', 'mcp-ai-wpoos-pro' ); ?></h4></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'User ID', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[integrations][notifylk_user_id]" value="<?php echo esc_attr( $settings['integrations']['notifylk_user_id'] ?? '' ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Your notify.lk User ID from the API Keys page at app.notify.lk.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $option_name ); ?>[integrations][notifylk_api_key]" value="<?php echo esc_attr( $settings['integrations']['notifylk_api_key'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Your notify.lk API Key.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sender ID', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[integrations][notifylk_sender_id]" value="<?php echo esc_attr( $settings['integrations']['notifylk_sender_id'] ?? '' ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Your pre-approved sender ID for outbound SMS (e.g. your business name).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Storage & Audit -->
				<tr><td colspan="2"><h3><?php esc_html_e( 'Storage & Auditing', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage Backend', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$jetengine_active = function_exists( 'jet_engine' );
						?>
						<p>
							<strong><?php esc_html_e( 'WordPress CPT', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php if ( $jetengine_active ) : ?>
								<br /><span style="color: #856404;">&#9888; <?php esc_html_e( 'JetEngine is active but CRM entities (Company, Lead, Deal, Activity) currently use WordPress CPTs. CCT migration is on the roadmap.', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<br /><span style="color: blue;">&#9711; <?php esc_html_e( 'Using WordPress Custom Post Types. Install JetEngine to enable CCT migration in a future release.', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</p>
						<p class="description"><?php esc_html_e( 'CRM entities (Company, Lead, Deal, Activity) are stored as WordPress custom post types. JetEngine CCT storage for high-performance is planned for a future release.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Audit Retention (days)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[audit_retention_days]" value="<?php echo esc_attr( $settings['audit_retention_days'] ?? 365 ); ?>" min="30" max="2555" class="small-text" />
						<p class="description"><?php esc_html_e( 'How long PII/consent audit entries are retained in the rolling buffer.  For long-term storage, use the wp_mcp_ai_crm_after_audit action to forward to an external SIEM.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Email Hygiene -->
				<tr><td colspan="2"><h3><?php esc_html_e( 'Email Hygiene &amp; List Management', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exclude List', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$hygiene          = class_exists( 'WP_MCP_AI_CRM_Engine' )
							? WP_MCP_AI_CRM_Engine::get_hygiene_settings()
							: array();
						$exclude_list     = isset( $hygiene['exclude_list'] ) ? (array) $hygiene['exclude_list'] : array();
						$priority_list    = isset( $hygiene['priority_list'] ) ? (array) $hygiene['priority_list'] : array();
						$spam_domains     = isset( $hygiene['spam_domains'] ) ? (array) $hygiene['spam_domains'] : array();
						$promo_domains    = isset( $hygiene['promotional_domains'] ) ? (array) $hygiene['promotional_domains'] : array();
						$priority_domains = isset( $hygiene['priority_domains'] ) ? (array) $hygiene['priority_domains'] : array();
						$promo_keywords   = isset( $hygiene['promotional_keywords'] ) ? (array) $hygiene['promotional_keywords'] : array();
						?>
						<textarea name="<?php echo esc_attr( WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ); ?>[exclude_list]" rows="5" class="large-text code" placeholder="spammer@example.com&#10;@newsletters.spammy.net&#10;@unwanted-domain.com"><?php echo esc_textarea( implode( "\n", $exclude_list ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Email addresses or domains to ALWAYS skip during import. One per line. Use @domain.com to block an entire domain.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Priority List', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ); ?>[priority_list]" rows="5" class="large-text code" placeholder="vip@client.com&#10;@important-partner.com"><?php echo esc_textarea( implode( "\n", $priority_list ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Email addresses or domains to ALWAYS fast-track. One per line. Use @domain.com to prioritise an entire domain.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Spam Domains', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ); ?>[spam_domains]" rows="3" class="large-text code" placeholder="seo-spam.com&#10;cheap-meds.example"><?php echo esc_textarea( implode( "\n", $spam_domains ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Known spam domains. Any email from these domains is automatically classified as spam. Substring match (e.g. "spam" matches "super-spam.net").', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Promotional Domains', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ); ?>[promotional_domains]" rows="3" class="large-text code" placeholder="mailchimp.app&#10;sendgrid.net"><?php echo esc_textarea( implode( "\n", $promo_domains ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Domains that send bulk marketing/newsletters. Substring match. Overlaps with exclude list — entries here help the classifier detect promotional content even from new addresses.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Priority Domains', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ); ?>[priority_domains]" rows="3" class="large-text code" placeholder="@client-corp.com&#10;@partner.org"><?php echo esc_textarea( implode( "\n", $priority_domains ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Domains that should always be treated as priority. Substring match against sender domain.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Promotional Keywords', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ); ?>[promotional_keywords]" rows="3" class="large-text code" placeholder="flash sale&#10;weekly newsletter&#10;limited time offer"><?php echo esc_textarea( implode( "\n", $promo_keywords ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Keywords/phrases that indicate promotional or newsletter content. Case-insensitive substring match. One per line.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<!-- Freelance Platforms & External Sourcing -->
				<tr><td colspan="2"><h3><?php esc_html_e( 'Freelance Platforms &amp; External Sourcing', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p class="description" style="margin: 4px 0 8px 0;">
					<?php esc_html_e( 'Configure LinkedIn and Upwork connections for job/project search, import, and pipeline tracking. These credentials are managed via', 'mcp-ai-wpoos-pro' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' ) ); ?>"><?php esc_html_e( 'Remote Sites', 'mcp-ai-wpoos-pro' ); ?></a>.
					<?php esc_html_e( 'When a connection is configured, tools use the platform API directly; otherwise they fall back to AI-powered web search.', 'mcp-ai-wpoos-pro' ); ?>
				</p></td></tr>

				<!-- Upwork External Sourcing -->
				<tr><td colspan="2"><h4 style="margin: 0; padding-top: 8px;"><?php esc_html_e( 'Upwork', 'mcp-ai-wpoos-pro' ); ?></h4></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$_upwork_connections = array();
						if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
							$_all_conns = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
							foreach ( $_all_conns as $_id => $_conn ) {
								if ( ! empty( $_conn['connection_type'] ) && 'upwork' === $_conn['connection_type'] ) {
									$_upwork_connections[ $_id ] = isset( $_conn['name'] ) ? $_conn['name'] : $_id;
								}
							}
						}
						$_current_upwork = $settings['external_sourcing']['upwork']['default_connection_id'] ?? '';
						?>
						<?php if ( empty( $_upwork_connections ) ) : ?>
							<p class="description" style="color: #856404;">
								&#9888; <?php esc_html_e( 'No Upwork connections found. Add one via Remote Sites to enable API-based job search and contract sync.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						<?php else : ?>
							<select name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][upwork][default_connection_id]">
								<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $_upwork_connections as $_id => $_label ) : ?>
									<option value="<?php echo esc_attr( $_id ); ?>" <?php selected( $_current_upwork, $_id ); ?>><?php echo esc_html( $_label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Select which Upwork connection to use as default for CRM tools (search, import, contracts, tasks).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Import Jobs As', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][upwork][auto_import_as]">
							<option value="deal" <?php selected( $settings['external_sourcing']['upwork']['auto_import_as'] ?? 'deal', 'deal' ); ?>><?php esc_html_e( 'Deal (Pipeline Opportunity)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="project" <?php selected( $settings['external_sourcing']['upwork']['auto_import_as'] ?? '', 'project' ); ?>><?php esc_html_e( 'Project', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="task" <?php selected( $settings['external_sourcing']['upwork']['auto_import_as'] ?? '', 'task' ); ?>><?php esc_html_e( 'Task', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default CRM entity type when importing Upwork jobs into the pipeline.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Min Score to Auto-Import', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][upwork][auto_import_min_score]" value="<?php echo esc_attr( $settings['external_sourcing']['upwork']['auto_import_min_score'] ?? 60 ); ?>" min="0" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Only auto-import Upwork jobs scoring at or above this threshold (0–100). Set to 0 to import all scored jobs.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Use My Profile for AI Grounding', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][upwork][use_profile_context]" value="1" <?php checked( ! empty( $settings['external_sourcing']['upwork']['use_profile_context'] ) ); ?> />
							<?php esc_html_e( 'Feed my connected Upwork profile (skills, work history, rate) to the AI as grounding context when drafting proposals and scoring jobs. This improves personalisation and accuracy.', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>

				<!-- LinkedIn External Sourcing -->
				<tr><td colspan="2"><h4 style="margin: 0; padding-top: 8px;"><?php esc_html_e( 'LinkedIn', 'mcp-ai-wpoos-pro' ); ?></h4></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$_linkedin_connections = array();
						if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
							$_all_conns = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
							foreach ( $_all_conns as $_id => $_conn ) {
								if ( ! empty( $_conn['connection_type'] ) && 'linkedin' === $_conn['connection_type'] ) {
									$_linkedin_connections[ $_id ] = isset( $_conn['name'] ) ? $_conn['name'] : $_id;
								}
							}
						}
						$_current_linkedin = $settings['external_sourcing']['linkedin']['default_connection_id'] ?? '';
						?>
						<?php if ( empty( $_linkedin_connections ) ) : ?>
							<p class="description" style="color: #856404;">
								&#9888; <?php esc_html_e( 'No LinkedIn connections found. Add one via Remote Sites to enable API-based job search and profile import. Tools will use AI-powered web search as fallback.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						<?php else : ?>
							<select name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][linkedin][default_connection_id]">
								<option value=""><?php esc_html_e( '— None —', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $_linkedin_connections as $_id => $_label ) : ?>
									<option value="<?php echo esc_attr( $_id ); ?>" <?php selected( $_current_linkedin, $_id ); ?>><?php echo esc_html( $_label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Select which LinkedIn connection to use as default for CRM tools (job search, scoring, profile import).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Use My Profile for AI Grounding', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][linkedin][use_profile_context]" value="1" <?php checked( ! empty( $settings['external_sourcing']['linkedin']['use_profile_context'] ) ); ?> />
							<?php esc_html_e( 'Feed my LinkedIn profile (headline, summary, skills, experience) to the AI as grounding context. Helps the AI filter jobs most relevant to your background and craft personalised outreach.', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Location', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][linkedin][default_location]" value="<?php echo esc_attr( $settings['external_sourcing']['linkedin']['default_location'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Remote, United States, London', 'mcp-ai-wpoos-pro' ); ?>" />
						<p class="description"><?php esc_html_e( 'Default location filter for LinkedIn job searches. Leave blank for worldwide.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Search Keywords', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][linkedin][default_search_keywords]" value="<?php echo esc_attr( $settings['external_sourcing']['linkedin']['default_search_keywords'] ?? '' ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g. WordPress developer, SEO consultant, content writer', 'mcp-ai-wpoos-pro' ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated keywords used as defaults when searching LinkedIn jobs without specifying a query.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Import Jobs As', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][linkedin][auto_import_as]">
							<option value="deal" <?php selected( $settings['external_sourcing']['linkedin']['auto_import_as'] ?? 'deal', 'deal' ); ?>><?php esc_html_e( 'Deal (Pipeline Opportunity)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="project" <?php selected( $settings['external_sourcing']['linkedin']['auto_import_as'] ?? '', 'project' ); ?>><?php esc_html_e( 'Project', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="task" <?php selected( $settings['external_sourcing']['linkedin']['auto_import_as'] ?? '', 'task' ); ?>><?php esc_html_e( 'Task', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default CRM entity type when importing LinkedIn jobs into the pipeline.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Min Score to Auto-Import', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][linkedin][auto_import_min_score]" value="<?php echo esc_attr( $settings['external_sourcing']['linkedin']['auto_import_min_score'] ?? 60 ); ?>" min="0" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Only auto-import LinkedIn jobs scoring at or above this threshold (0–100). Set to 0 to import all.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Shared: Ideal Client Profile & Search Filters -->
				<tr><td colspan="2"><h4 style="margin: 0; padding-top: 8px;"><?php esc_html_e( 'Shared: Ideal Client Profile &amp; Search Filters', 'mcp-ai-wpoos-pro' ); ?></h4></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ideal Client Profile', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][ideal_client_profile]" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'Describe your ideal client or project for AI scoring.\\n\\nExample:\\n- Budget: $5k–$20k per project\\n- Industry: SaaS, eCommerce, FinTech\\n- Tech stack: WordPress, WooCommerce, React\\n- Project type: Custom plugin, API integrations', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $settings['external_sourcing']['ideal_client_profile'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Injected as context into score_upwork_job and score_linkedin_job tools. The AI evaluates each job against this profile. Leave blank for generic scoring.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Budget Range', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][default_budget_min]" value="<?php echo esc_attr( $settings['external_sourcing']['default_budget_min'] ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Min', 'mcp-ai-wpoos-pro' ); ?>" min="0" step="100" />
						&nbsp;–&nbsp;
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][default_budget_max]" value="<?php echo esc_attr( $settings['external_sourcing']['default_budget_max'] ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Max', 'mcp-ai-wpoos-pro' ); ?>" min="0" step="100" />
						<p class="description"><?php esc_html_e( 'Default budget range (in your CRM currency) for filtering jobs on both platforms. Leave blank to skip budget filtering.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Excluded Keywords', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $option_name ); ?>[external_sourcing][excluded_keywords]" rows="3" class="large-text code" placeholder="<?php esc_attr_e( 'crypto\\nNFT\\nadult\\ngambling', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $settings['external_sourcing']['excluded_keywords'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Jobs containing any of these keywords (case-insensitive) are filtered out of results and never scored. One per line.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Performance Optimization -->
				<tr><td colspan="2"><h3><?php esc_html_e( 'Performance &amp; Storage', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Message Retention (days)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[optimization][message_retention_days]" value="<?php echo esc_attr( $settings['optimization']['message_retention_days'] ?? 90 ); ?>" min="0" max="730" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Automatically delete CRM messages older than this many days. Set to 0 to keep forever. Default: 90 days. Industry recommendation: 30–365 days.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Audit Log Max Entries', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $option_name ); ?>[optimization][audit_max_entries]" value="<?php echo esc_attr( $settings['optimization']['audit_max_entries'] ?? 5000 ); ?>" min="1000" max="10000" step="500" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Maximum audit log entries before automatic compaction. Lower values reduce option size but keep less history. Default: 5,000.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<!-- Research Assistant -->
				<tr><td colspan="2"><h3><?php esc_html_e( 'AI Integration', 'mcp-ai-wpoos-pro' ); ?></h3></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Research & Add Assistant', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $option_name ); ?>[research_assistant]" id="crm_research_assistant">
							<?php foreach ( $assistants as $assistant_id => $assistant_name ) : ?>
								<option value="<?php echo esc_attr( $assistant_id ); ?>" <?php selected( $current_assistant, $assistant_id ); ?>>
									<?php echo esc_html( $assistant_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select which AI assistant to use as the default for all Research & Add pages (Company, Lead, Deal, Customer). Each CPT settings page can override this default.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
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
			'import_upwork_project'           => __( 'Import Upwork Project', 'mcp-ai-wpoos-pro' ),
			'list_upwork_contracts'           => __( 'List Upwork Contracts', 'mcp-ai-wpoos-pro' ),
			'sync_upwork_tasks'               => __( 'Sync Upwork Tasks', 'mcp-ai-wpoos-pro' ),
			// LinkedIn.
			'search_linkedin_jobs'            => __( 'Search LinkedIn Jobs', 'mcp-ai-wpoos-pro' ),
			'score_linkedin_job'              => __( 'Score LinkedIn Job', 'mcp-ai-wpoos-pro' ),
			'import_linkedin_profile'         => __( 'Import LinkedIn Profile', 'mcp-ai-wpoos-pro' ),
			'save_linkedin_job'               => __( 'Save LinkedIn Job', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase B: CRUD + Pipeline + Routing (22 tools) ----
		$tools[ __( 'Phase B — Lead, Deal & Pipeline (22 tools)', 'mcp-ai-wpoos-pro' ) ] = array(
			'create_lead'               => __( 'Create Lead', 'mcp-ai-wpoos-pro' ),
			'list_leads'                => __( 'List Leads', 'mcp-ai-wpoos-pro' ),
			'get_lead'                  => __( 'Get Lead', 'mcp-ai-wpoos-pro' ),
			'update_lead'               => __( 'Update Lead', 'mcp-ai-wpoos-pro' ),
			'delete_lead'               => __( 'Delete Lead', 'mcp-ai-wpoos-pro' ),
			'convert_lead_to_customer'  => __( 'Convert Lead to Customer', 'mcp-ai-wpoos-pro' ),
			'create_deal'               => __( 'Create Deal', 'mcp-ai-wpoos-pro' ),
			'list_deals'                => __( 'List Deals', 'mcp-ai-wpoos-pro' ),
			'get_deal'                  => __( 'Get Deal', 'mcp-ai-wpoos-pro' ),
			'update_deal'               => __( 'Update Deal', 'mcp-ai-wpoos-pro' ),
			'delete_deal'               => __( 'Delete Deal', 'mcp-ai-wpoos-pro' ),
			'move_deal_stage'           => __( 'Move Deal Stage', 'mcp-ai-wpoos-pro' ),
			'create_crm_activity'       => __( 'Create CRM Activity', 'mcp-ai-wpoos-pro' ),
			'list_crm_activities'       => __( 'List CRM Activities', 'mcp-ai-wpoos-pro' ),
			'get_crm_activity'          => __( 'Get CRM Activity', 'mcp-ai-wpoos-pro' ),
			'complete_crm_activity'     => __( 'Complete CRM Activity', 'mcp-ai-wpoos-pro' ),
			'snooze_crm_activity'       => __( 'Snooze CRM Activity', 'mcp-ai-wpoos-pro' ),
			'get_pipeline_view'         => __( 'Pipeline Kanban View', 'mcp-ai-wpoos-pro' ),
			'get_conversion_funnel'     => __( 'Conversion Funnel', 'mcp-ai-wpoos-pro' ),
			'forecast_pipeline_revenue' => __( 'Forecast Pipeline Revenue', 'mcp-ai-wpoos-pro' ),
			'identify_top_customers'    => __( 'Identify Top Customers', 'mcp-ai-wpoos-pro' ),
			'identify_top_clients'      => __( 'Identify Top Clients', 'mcp-ai-wpoos-pro' ),
			'assign_lead_to_owner'      => __( 'Assign Lead to Owner', 'mcp-ai-wpoos-pro' ),
			'rotate_leads'              => __( 'Rotate Leads', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase C: Inbound Triage + Outbound (15 tools, in progress) ----
		$tools[ __( 'Phase C — Inbound Triage & Outbound (15 tools, in progress)', 'mcp-ai-wpoos-pro' ) ] = array(
			'evaluate_inbound_message'  => __( 'Evaluate Inbound Message', 'mcp-ai-wpoos-pro' ),
			'classify_message_intent'   => __( 'Classify Message Intent', 'mcp-ai-wpoos-pro' ),
			'extract_lead_from_message' => __( 'Extract Lead from Message', 'mcp-ai-wpoos-pro' ),
			'detect_buying_signals'     => __( 'Detect Buying Signals', 'mcp-ai-wpoos-pro' ),
			'score_lead'                => __( 'Score Lead (composite)', 'mcp-ai-wpoos-pro' ),
			'qualify_lead_bant'         => __( 'Qualify Lead (BANT)', 'mcp-ai-wpoos-pro' ),
			'qualify_lead_meddic'       => __( 'Qualify Lead (MEDDIC)', 'mcp-ai-wpoos-pro' ),
			'send_lead_email'           => __( 'Send Lead Email', 'mcp-ai-wpoos-pro' ),
			'send_lead_sms'             => __( 'Send Lead SMS', 'mcp-ai-wpoos-pro' ),
			'send_lead_whatsapp'        => __( 'Send Lead WhatsApp', 'mcp-ai-wpoos-pro' ),
			'send_lead_dm'              => __( 'Send Lead DM', 'mcp-ai-wpoos-pro' ),
			'log_call_outcome'          => __( 'Log Call Outcome', 'mcp-ai-wpoos-pro' ),
			'draft_lead_reply'          => __( 'Draft Lead Reply', 'mcp-ai-wpoos-pro' ),
			'auto_reply_inbound'        => __( 'Auto-Reply Inbound', 'mcp-ai-wpoos-pro' ),
			'schedule_follow_up'        => __( 'Schedule Follow-Up', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase D: Sequences + Command Center (13 tools) ----
		$tools[ __( 'Phase D — Sequences & Command Center (13 tools)', 'mcp-ai-wpoos-pro' ) ] = array(
			'create_outreach_sequence'   => __( 'Create Outreach Sequence', 'mcp-ai-wpoos-pro' ),
			'update_outreach_sequence'   => __( 'Update Outreach Sequence', 'mcp-ai-wpoos-pro' ),
			'delete_outreach_sequence'   => __( 'Delete Outreach Sequence', 'mcp-ai-wpoos-pro' ),
			'list_outreach_sequences'    => __( 'List Outreach Sequences', 'mcp-ai-wpoos-pro' ),
			'enroll_lead_in_sequence'    => __( 'Enroll Lead in Sequence', 'mcp-ai-wpoos-pro' ),
			'manage_sequence_state'      => __( 'Manage Sequence State', 'mcp-ai-wpoos-pro' ),
			'get_sequence_performance'   => __( 'Sequence Performance', 'mcp-ai-wpoos-pro' ),
			'create_workflow_rule'       => __( 'Create Workflow Rule', 'mcp-ai-wpoos-pro' ),
			'manage_workflow_rules'      => __( 'Manage Workflow Rules', 'mcp-ai-wpoos-pro' ),
			'simulate_workflow_rule'     => __( 'Simulate Workflow Rule', 'mcp-ai-wpoos-pro' ),
			'get_workflow_inbox'         => __( 'Workflow Command Center Inbox', 'mcp-ai-wpoos-pro' ),
			'get_owner_workload'         => __( 'Get Owner Workload', 'mcp-ai-wpoos-pro' ),
			'auto_route_inbound_message' => __( 'Auto-Route Inbound Message', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase E: Compliance + Interop (8 tools) ----
		$tools[ __( 'Phase E — Compliance &amp; Interop (11 tools)', 'mcp-ai-wpoos-pro' ) ] = array(
			'record_consent'          => __( 'Record Consent', 'mcp-ai-wpoos-pro' ),
			'revoke_consent'          => __( 'Revoke Consent', 'mcp-ai-wpoos-pro' ),
			'process_opt_out'         => __( 'Process Opt-Out', 'mcp-ai-wpoos-pro' ),
			'check_dnc_status'        => __( 'Check DNC Status', 'mcp-ai-wpoos-pro' ),
			'get_consent_audit'       => __( 'Get Consent Audit', 'mcp-ai-wpoos-pro' ),
			'import_crm_csv'          => __( 'Import CRM CSV', 'mcp-ai-wpoos-pro' ),
			'connect_to_external_crm' => __( 'Connect to External CRM', 'mcp-ai-wpoos-pro' ),
			'import_crm_blueprint'    => __( 'Import CRM Blueprint', 'mcp-ai-wpoos-pro' ),
			'classify_email_hygiene'  => __( 'Classify Email Hygiene', 'mcp-ai-wpoos-pro' ),
			'manage_email_hygiene'    => __( 'Manage Email Hygiene', 'mcp-ai-wpoos-pro' ),
			'prune_crm_messages'      => __( 'Prune CRM Messages', 'mcp-ai-wpoos-pro' ),
			'repair_crm_data'         => __( 'Repair CRM Data', 'mcp-ai-wpoos-pro' ),
			'detect_duplicates'       => __( 'Detect Duplicate Leads', 'mcp-ai-wpoos-pro' ),
			'merge_duplicates'        => __( 'Merge Duplicate Leads', 'mcp-ai-wpoos-pro' ),
		);

		// ---- Phase F: Support Ticket Management (10 tools) ----
		$tools[ __( 'Phase F — Support Ticket Management (10 tools)', 'mcp-ai-wpoos-pro' ) ] = array(
			'create_support_ticket'   => __( 'Create Support Ticket', 'mcp-ai-wpoos-pro' ),
			'get_support_ticket'      => __( 'Get Support Ticket', 'mcp-ai-wpoos-pro' ),
			'list_support_tickets'    => __( 'List Support Tickets', 'mcp-ai-wpoos-pro' ),
			'update_support_ticket'   => __( 'Update Support Ticket', 'mcp-ai-wpoos-pro' ),
			'resolve_support_ticket'  => __( 'Resolve Support Ticket', 'mcp-ai-wpoos-pro' ),
			'reopen_support_ticket'   => __( 'Reopen Support Ticket', 'mcp-ai-wpoos-pro' ),
			'escalate_support_ticket' => __( 'Escalate Support Ticket', 'mcp-ai-wpoos-pro' ),
			'merge_support_tickets'   => __( 'Merge Support Tickets', 'mcp-ai-wpoos-pro' ),
			'classify_support_ticket' => __( 'Classify Support Ticket', 'mcp-ai-wpoos-pro' ),
			'get_ticket_sla_report'   => __( 'Get Ticket SLA Report', 'mcp-ai-wpoos-pro' ),
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
						esc_html__( 'This toolkit provides %d AI-powered tools. Phases A, B, D & E are complete; Phase C has 1 remaining integration item.', 'mcp-ai-wpoos-pro' ),
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
	 * Sanitize CRM toolkit settings before saving.
	 *
	 * Merges submitted form fields into the full stored settings array so that
	 * nested sub-arrays (pipeline stages, integrations, etc.) are never lost.
	 *
	 * @param array $input Submitted settings array keyed by field name.
	 * @return array Sanitized settings array.
	 */
	public function sanitize_settings( $input ) {
		// Start from the existing full options array to preserve nested defaults.
		$existing = get_option( $this->option_name, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Merge with CRM Engine defaults for any missing keys.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$defaults = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		} else {
			$defaults = array();
		}
		$sanitized = array_replace_recursive( $defaults, $existing );

		// --- Lead Scoring & Qualification ---
		if ( isset( $input['qualification_framework'] ) ) {
			$valid_frameworks = array( 'bant', 'meddic', 'champ' );
			if ( in_array( $input['qualification_framework'], $valid_frameworks, true ) ) {
				$sanitized['qualification_framework'] = $input['qualification_framework'];
			}
		}

		if ( isset( $input['hot_score_threshold'] ) ) {
			$sanitized['hot_score_threshold'] = min( 100, max( 0, absint( $input['hot_score_threshold'] ) ) );
		}

		if ( isset( $input['warm_score_threshold'] ) ) {
			$sanitized['warm_score_threshold'] = min( 100, max( 0, absint( $input['warm_score_threshold'] ) ) );
		}

		// --- Routing ---
		if ( isset( $input['routing']['strategy'] ) ) {
			$valid_strategies = array( 'round_robin', 'weighted' );
			if ( in_array( $input['routing']['strategy'], $valid_strategies, true ) ) {
				$sanitized['routing']['strategy'] = $input['routing']['strategy'];
			}
		}

		// --- Consent & Compliance ---
		if ( isset( $input['consent']['require_double_opt_in'] ) ) {
			$sanitized['consent']['require_double_opt_in'] = (bool) $input['consent']['require_double_opt_in'];
		} else {
			// Checkbox: if not in POST, it was unchecked.
			$sanitized['consent']['require_double_opt_in'] = false;
		}

		if ( isset( $input['consent']['physical_address'] ) ) {
			$sanitized['consent']['physical_address'] = sanitize_textarea_field( $input['consent']['physical_address'] );
		}

		// --- Storage & Audit ---
		if ( isset( $input['audit_retention_days'] ) ) {
			$sanitized['audit_retention_days'] = min( 2555, max( 30, absint( $input['audit_retention_days'] ) ) );
		}

		// --- AI Integration ---
		if ( isset( $input['research_assistant'] ) ) {
			if ( 'default' === $input['research_assistant'] ) {
				$sanitized['research_assistant'] = 'default';
			} else {
				$sanitized['research_assistant'] = absint( $input['research_assistant'] );
			}
		}

		// --- Channel Integrations (Phase C) ---
		if ( isset( $input['integrations'] ) && is_array( $input['integrations'] ) ) {
			$integrations = $input['integrations'];

			// SMS provider.
			if ( isset( $integrations['sms_provider'] ) ) {
				$valid_providers                           = array( 'twilio', 'notifylk' );
				$sanitized['integrations']['sms_provider'] = in_array( $integrations['sms_provider'], $valid_providers, true )
					? $integrations['sms_provider']
					: 'twilio';
			}

			// Twilio.
			if ( isset( $integrations['twilio_account_sid_secret'] ) ) {
				$sanitized['integrations']['twilio_account_sid_secret'] = sanitize_text_field( $integrations['twilio_account_sid_secret'] );
			}
			if ( isset( $integrations['twilio_auth_token_secret'] ) ) {
				// Auth tokens must not be mangled by sanitize_text_field — trim only.
				$sanitized['integrations']['twilio_auth_token_secret'] = trim( (string) $integrations['twilio_auth_token_secret'] );
			}
			if ( isset( $integrations['twilio_from_number'] ) ) {
				$sanitized['integrations']['twilio_from_number'] = sanitize_text_field( $integrations['twilio_from_number'] );
			}

			// WhatsApp.
			if ( isset( $integrations['whatsapp_access_token'] ) ) {
				// Access tokens must not be sanitized with sanitize_text_field — trim only.
				$sanitized['integrations']['whatsapp_access_token'] = trim( (string) $integrations['whatsapp_access_token'] );
			}
			if ( isset( $integrations['whatsapp_phone_number_id'] ) ) {
				$sanitized['integrations']['whatsapp_phone_number_id'] = sanitize_text_field( $integrations['whatsapp_phone_number_id'] );
			}
			if ( isset( $integrations['whatsapp_app_secret'] ) ) {
				$sanitized['integrations']['whatsapp_app_secret'] = trim( (string) $integrations['whatsapp_app_secret'] );
			}

			// notify.lk.
			if ( isset( $integrations['notifylk_user_id'] ) ) {
				$sanitized['integrations']['notifylk_user_id'] = sanitize_text_field( $integrations['notifylk_user_id'] );
			}
			if ( isset( $integrations['notifylk_api_key'] ) ) {
				$sanitized['integrations']['notifylk_api_key'] = trim( (string) $integrations['notifylk_api_key'] );
			}
			if ( isset( $integrations['notifylk_sender_id'] ) ) {
				$sanitized['integrations']['notifylk_sender_id'] = sanitize_text_field( $integrations['notifylk_sender_id'] );
			}

			// Gmail default import query.
			if ( isset( $integrations['gmail_default_query'] ) ) {
				$sanitized['integrations']['gmail_default_query'] = sanitize_text_field( $integrations['gmail_default_query'] );
			}

			// Gmail scheduled import settings (since 2.9.0).
			if ( isset( $integrations['gmail_poll_interval'] ) ) {
				$sanitized['integrations']['gmail_poll_interval'] = max( 60, min( 3600, absint( $integrations['gmail_poll_interval'] ) ) );
			}
			if ( isset( $integrations['gmail_max_per_poll'] ) ) {
				$sanitized['integrations']['gmail_max_per_poll'] = max( 1, min( 25, absint( $integrations['gmail_max_per_poll'] ) ) );
			}
			$sanitized['integrations']['gmail_use_history_sync'] = ! empty( $integrations['gmail_use_history_sync'] );
		}

		// --- Optimization settings (since 2.9.0) ---
		if ( isset( $input['optimization'] ) && is_array( $input['optimization'] ) ) {
			if ( isset( $input['optimization']['message_retention_days'] ) ) {
				$sanitized['optimization']['message_retention_days'] = max( 0, min( 730, absint( $input['optimization']['message_retention_days'] ) ) );
			}
			if ( isset( $input['optimization']['audit_max_entries'] ) ) {
				$sanitized['optimization']['audit_max_entries'] = max( 1000, min( 10000, absint( $input['optimization']['audit_max_entries'] ) ) );
			}
		}

		// --- External Sourcing settings (since 2.10.0) ---
		if ( isset( $input['external_sourcing'] ) && is_array( $input['external_sourcing'] ) ) {
			$es = $input['external_sourcing'];

			// Upwork.
			if ( isset( $es['upwork']['default_connection_id'] ) ) {
				$sanitized['external_sourcing']['upwork']['default_connection_id'] = sanitize_text_field( $es['upwork']['default_connection_id'] );
			}
			if ( isset( $es['upwork']['auto_import_as'] ) ) {
				$valid_as = array( 'deal', 'project', 'task' );
				$sanitized['external_sourcing']['upwork']['auto_import_as'] = in_array( $es['upwork']['auto_import_as'], $valid_as, true )
					? $es['upwork']['auto_import_as']
					: 'deal';
			}
			if ( isset( $es['upwork']['auto_import_min_score'] ) ) {
				$sanitized['external_sourcing']['upwork']['auto_import_min_score'] = min( 100, max( 0, absint( $es['upwork']['auto_import_min_score'] ) ) );
			}
			$sanitized['external_sourcing']['upwork']['use_profile_context'] = ! empty( $es['upwork']['use_profile_context'] );

			// LinkedIn.
			if ( isset( $es['linkedin']['default_connection_id'] ) ) {
				$sanitized['external_sourcing']['linkedin']['default_connection_id'] = sanitize_text_field( $es['linkedin']['default_connection_id'] );
			}
			if ( isset( $es['linkedin']['auto_import_as'] ) ) {
				$valid_as = array( 'deal', 'project', 'task' );
				$sanitized['external_sourcing']['linkedin']['auto_import_as'] = in_array( $es['linkedin']['auto_import_as'], $valid_as, true )
					? $es['linkedin']['auto_import_as']
					: 'deal';
			}
			if ( isset( $es['linkedin']['auto_import_min_score'] ) ) {
				$sanitized['external_sourcing']['linkedin']['auto_import_min_score'] = min( 100, max( 0, absint( $es['linkedin']['auto_import_min_score'] ) ) );
			}
			$sanitized['external_sourcing']['linkedin']['use_profile_context'] = ! empty( $es['linkedin']['use_profile_context'] );
			if ( isset( $es['linkedin']['default_search_keywords'] ) ) {
				$sanitized['external_sourcing']['linkedin']['default_search_keywords'] = sanitize_text_field( $es['linkedin']['default_search_keywords'] );
			}
			if ( isset( $es['linkedin']['default_location'] ) ) {
				$sanitized['external_sourcing']['linkedin']['default_location'] = sanitize_text_field( $es['linkedin']['default_location'] );
			}

			// Shared fields.
			if ( isset( $es['ideal_client_profile'] ) ) {
				$sanitized['external_sourcing']['ideal_client_profile'] = sanitize_textarea_field( $es['ideal_client_profile'] );
			}
			if ( isset( $es['default_budget_min'] ) && '' !== $es['default_budget_min'] ) {
				$sanitized['external_sourcing']['default_budget_min'] = absint( $es['default_budget_min'] );
			}
			if ( isset( $es['default_budget_max'] ) && '' !== $es['default_budget_max'] ) {
				$sanitized['external_sourcing']['default_budget_max'] = absint( $es['default_budget_max'] );
			}
			if ( isset( $es['excluded_keywords'] ) ) {
				$sanitized['external_sourcing']['excluded_keywords'] = sanitize_textarea_field( $es['excluded_keywords'] );
			}
		}

		// Clear engine static cache so next read picks up the new values.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			WP_MCP_AI_CRM_Engine::flush_settings_cache();
		}

		// --- Email Hygiene Settings ---
		// These are posted under their own option key and sanitised separately.
		$hygiene_key = WP_MCP_AI_CRM_Engine::HYGIENE_OPTION;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WordPress settings API.
		if ( isset( $_POST[ $hygiene_key ] ) && is_array( $_POST[ $hygiene_key ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
			$hygiene_input     = wp_unslash( $_POST[ $hygiene_key ] );
			$hygiene_sanitized = array();

			// List-type fields (textarea → array via newline split).
			$list_fields = array( 'exclude_list', 'priority_list', 'spam_domains', 'promotional_domains', 'priority_domains', 'promotional_keywords' );
			foreach ( $list_fields as $field ) {
				if ( isset( $hygiene_input[ $field ] ) ) {
					$raw = is_array( $hygiene_input[ $field ] )
						? implode( "\n", $hygiene_input[ $field ] )
						: (string) $hygiene_input[ $field ];

					// Split on newlines, trim, filter empty.
					$lines = explode( "\n", $raw );
					$lines = array_map( 'sanitize_text_field', $lines );
					$lines = array_map( 'trim', $lines );
					$lines = array_filter(
						$lines,
						function ( $l ) {
							return '' !== $l;
						}
					);
					$lines = array_values( $lines );

					$hygiene_sanitized[ $field ] = $lines;
				}
			}

			// Boolean fields.
			$bool_fields = array( 'auto_prune_spam', 'auto_prune_excluded' );
			foreach ( $bool_fields as $field ) {
				$hygiene_sanitized[ $field ] = ! empty( $hygiene_input[ $field ] );
			}

			// Numeric fields.
			if ( isset( $hygiene_input['auto_prune_stale_days'] ) ) {
				$hygiene_sanitized['auto_prune_stale_days'] = absint( $hygiene_input['auto_prune_stale_days'] );
			}

			update_option( $hygiene_key, $hygiene_sanitized, false );
		}

		return $sanitized;
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

// Initialize settings page (guard prevents duplicate registration if loaded more than once).
if ( is_admin() && ! isset( $GLOBALS['wp_mcp_ai_crm_settings_page_initialized'] ) ) {
	$GLOBALS['wp_mcp_ai_crm_settings_page_initialized'] = true;
	new WP_MCP_AI_CRM_Settings_Page();
}
