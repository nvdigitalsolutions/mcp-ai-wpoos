<?php
/**
 * CRM & Email Marketing Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
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
		$this->has_research     = true;
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
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'CRM & Email Marketing Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive customer relationship management and email marketing toolkit powered by modern NPM packages. Includes contact management, email campaigns, lead tracking, and marketing automation.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Contact Management: Create, import, export, and manage CRM contacts with validation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Email Campaigns: Create and send responsive email campaigns with MJML templates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Lead Tracking: Score leads, track conversions, and manage sales pipeline', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Email Validation: RFC 5322 compliance, MX record checking, disposable email detection', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'CSV Import/Export: Bulk contact operations with auto-field mapping', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'SMTP Integration: Advanced email sending with nodemailer (OAuth2, attachments)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Phone Validation: International phone number validation and formatting', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Calendar Integration: Generate .ics calendar files for campaign events', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

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
			<p><?php esc_html_e( 'For detailed integration guide, architecture patterns, and best practices, see:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><code>addons/pro/docs/CRM_EMAIL_MARKETING_GUIDE.md</code> - Comprehensive integration guide</li>
				<li><code>addons/pro/docs/NPM_PACKAGE_OPPORTUNITIES.md</code> - Package documentation</li>
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
			<h2><?php esc_html_e( 'CRM & Email Marketing Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Lead Score', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="default_lead_score" value="0" min="0" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Initial lead score for new contacts (0-100)', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Email Validation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_email_validation" value="1" checked />
							<?php esc_html_e( 'Validate email addresses (RFC 5322, MX records)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Phone Validation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_phone_validation" value="1" checked />
							<?php esc_html_e( 'Validate phone numbers (international format)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Block Disposable Emails', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="block_disposable_emails" value="1" />
							<?php esc_html_e( 'Block disposable/temporary email addresses', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'SMTP Configuration', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<p><?php esc_html_e( 'Configure SMTP settings for nodemailer integration:', 'mcp-ai-wpoos-pro' ); ?></p>
						<input type="text" name="smtp_host" placeholder="smtp.example.com" class="regular-text" /><br />
						<input type="number" name="smtp_port" placeholder="587" class="small-text" min="1" max="65535" />
						<p class="description"><?php esc_html_e( 'SMTP host and port for email sending', 'mcp-ai-wpoos-pro' ); ?></p>
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
							<strong><?php echo 'cct' === $storage_type ? __( 'JetEngine CCT', 'mcp-ai-wpoos-pro' ) : __( 'WordPress CPT', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php if ( 'cct' === $storage_type ) : ?>
								<br /><span style="color: green;">✓ Using JetEngine Custom Content Types for enhanced performance</span>
							<?php else : ?>
								<br /><span style="color: blue;">○ Using WordPress Custom Post Types (standard storage)</span>
							<?php endif; ?>
						</p>
						<p class="description"><?php esc_html_e( 'Storage backend is automatically selected based on JetEngine availability', 'mcp-ai-wpoos-pro' ); ?></p>
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
			'manage_crm_contact'       => __( 'Manage CRM Contact', 'mcp-ai-wpoos-pro' ),
			'import_contacts_csv'      => __( 'Import Contacts from CSV (Coming Soon)', 'mcp-ai-wpoos-pro' ),
			'export_contacts_csv'      => __( 'Export Contacts to CSV (Coming Soon)', 'mcp-ai-wpoos-pro' ),
			'validate_email'           => __( 'Validate Email Address (Service)', 'mcp-ai-wpoos-pro' ),
			'validate_phone'           => __( 'Validate Phone Number (Service)', 'mcp-ai-wpoos-pro' ),
			'send_email_nodemailer'    => __( 'Send Email via Nodemailer (Service)', 'mcp-ai-wpoos-pro' ),
			'generate_email_template'  => __( 'Generate Email Template (MJML)', 'mcp-ai-wpoos-pro' ),
			'create_email_campaign'    => __( 'Create Email Campaign (Coming Soon)', 'mcp-ai-wpoos-pro' ),
			'segment_contacts'         => __( 'Segment Contacts (Coming Soon)', 'mcp-ai-wpoos-pro' ),
			'calculate_lead_score'     => __( 'Calculate Lead Score (Coming Soon)', 'mcp-ai-wpoos-pro' ),
			'track_email_engagement'   => __( 'Track Email Engagement (Coming Soon)', 'mcp-ai-wpoos-pro' ),
			'generate_calendar_invite' => __( 'Generate Calendar Invite (Service)', 'mcp-ai-wpoos-pro' ),
		);
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
