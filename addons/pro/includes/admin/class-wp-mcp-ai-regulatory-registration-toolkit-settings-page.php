<?php
/**
 * Regulatory Registration Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Regulatory Registration Toolkit Settings Page Class
 */
class WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'regulatory_registration';
		$this->toolkit_name     = __( 'Regulatory Registration Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_regulatory_registration_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-regulatory-registration-toolkit-settings';
		$this->parent_slug      = 'edit.php?post_type=mcp_ai_reg_product';
		$this->has_research     = false;
		$this->has_remote_sites = false;
		$this->icon             = 'dashicons-shield-alt';

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
			<h2><?php esc_html_e( 'Regulatory Registration Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive regulatory product registration and compliance management system for multi-country regulatory submissions (Sri Lanka NMRA, UAE MOHAP, Saudi SFDA, and more).', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Product Registration Management: Track products, registrations, and documentation across multiple countries', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Document Management: Organize and track regulatory documents, expiry dates, and versions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Compliance Validation: Validate INCI ingredients, HS codes, and regulatory requirements', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'PDF Generation: Generate regulatory dossiers, cover letters, and compliance certificates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Multi-Country Support: Manage registrations for Sri Lanka, UAE, Saudi Arabia, Qatar, Kuwait, Oman, India', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Registration Timeline Tracking: Monitor application status from submission to approval', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Expiry Notifications: Track document and registration renewal dates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'API Integration: Sync with NMRA, MOHAP, and SFDA regulatory authorities (Phase 3)', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'NPM Package Enhancements', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'This toolkit leverages the following NPM packages for enhanced functionality:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><strong>pdfkit</strong> - Generate professional PDF regulatory dossiers, cover letters, and certificates</li>
				<li><strong>exceljs</strong> - Create Excel reports for registration tracking and compliance documentation</li>
				<li><strong>docx</strong> - Generate Word documents for submission packages and regulatory forms</li>
				<li><strong>csv-parse/csv-stringify</strong> - Import/export product data and registration information</li>
				<li><strong>validator</strong> - Validate regulatory data including INCI ingredients, HS codes, and email addresses</li>
			</ul>

			<h3><?php esc_html_e( 'Quick Start', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Enable the toolkit in Settings → NV oOS → Tools & Features', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Configure country-specific regulatory authorities in the Configuration tab', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Add products via Products → Add New or use AI Research & Add', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Create registrations and upload required documents', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Track registration status and generate submission packages', 'mcp-ai-wpoos-pro' ); ?></li>
			</ol>

			<h3><?php esc_html_e( 'Additional Resources', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-registration-dashboard' ) ); ?>"><?php esc_html_e( 'Registration Dashboard', 'mcp-ai-wpoos-pro' ); ?></a> - <?php esc_html_e( 'View all registrations and their status', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_product' ) ); ?>"><?php esc_html_e( 'Manage Products', 'mcp-ai-wpoos-pro' ); ?></a> - <?php esc_html_e( 'View and manage registered products', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_document' ) ); ?>"><?php esc_html_e( 'Manage Documents', 'mcp-ai-wpoos-pro' ); ?></a> - <?php esc_html_e( 'View and manage regulatory documents', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_country' ) ); ?>"><?php esc_html_e( 'Configure Countries', 'mcp-ai-wpoos-pro' ); ?></a> - <?php esc_html_e( 'Manage country regulatory requirements', 'mcp-ai-wpoos-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Regulatory Registration Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Regulatory Authority', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_regulatory_authority" class="regular-text">
							<option value="nmra"><?php esc_html_e( 'NMRA (Sri Lanka)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="mohap"><?php esc_html_e( 'MOHAP (UAE)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="sfda"><?php esc_html_e( 'SFDA (Saudi Arabia)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="qatar"><?php esc_html_e( 'Ministry of Public Health (Qatar)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="kuwait"><?php esc_html_e( 'Ministry of Health (Kuwait)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="oman"><?php esc_html_e( 'Ministry of Health (Oman)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="india"><?php esc_html_e( 'CDSCO (India)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Primary regulatory authority for new registrations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Document Expiry Alerts', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_expiry_alerts" value="1" checked />
							<?php esc_html_e( 'Send notifications for expiring documents and registrations', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Expiry Alert Days', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="expiry_alert_days" value="30" min="1" max="365" class="small-text" />
						<p class="description"><?php esc_html_e( 'Send alerts this many days before document/registration expiry', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable PDF Generation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_pdf_generation" value="1" checked />
							<?php esc_html_e( 'Generate PDF dossiers, cover letters, and certificates (requires PDFKit NPM package)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Excel Export', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_excel_export" value="1" checked />
							<?php esc_html_e( 'Export registration data and reports to Excel (requires ExcelJS NPM package)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable INCI Validation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_inci_validation" value="1" checked />
							<?php esc_html_e( 'Validate ingredient names against INCI (International Nomenclature Cosmetic Ingredient) standards', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable HS Code Validation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_hs_code_validation" value="1" checked />
							<?php esc_html_e( 'Validate Harmonized System (HS) codes for import/export classification', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable API Sync (Phase 3)', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_api_sync" value="1" />
							<?php esc_html_e( 'Sync registration status with NMRA, MOHAP, and SFDA APIs (requires API credentials)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Coming in Phase 3 - Direct integration with regulatory authority APIs', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Generate Product Code', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_generate_product_code" value="1" checked />
							<?php esc_html_e( 'Automatically generate unique product codes for new products', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Product Code Prefix', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="product_code_prefix" value="REG" maxlength="10" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Prefix for auto-generated product codes (e.g., REG-2024-001)', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Add settings submenu page.
	 * Override parent to customize menu title.
	 */
	public function add_settings_page() {
		add_submenu_page(
			$this->parent_slug,
			$this->toolkit_name . ' ' . __( 'Settings', 'mcp-ai-wpoos-pro' ),
			__( 'Settings', 'mcp-ai-wpoos-pro' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Product Management Tools.
			'create_reg_product'           => __( 'Create Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'list_reg_products'            => __( 'List Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'get_reg_product'              => __( 'Get Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'update_reg_product'           => __( 'Update Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'delete_reg_product'           => __( 'Delete Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'search_reg_products'          => __( 'Search Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'duplicate_reg_product'        => __( 'Duplicate Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'validate_reg_product'         => __( 'Validate Regulatory Product', 'mcp-ai-wpoos-pro' ),

			// Registration Management Tools.
			'create_registration'          => __( 'Create Registration', 'mcp-ai-wpoos-pro' ),
			'list_registrations'           => __( 'List Registrations', 'mcp-ai-wpoos-pro' ),
			'get_registration'             => __( 'Get Registration', 'mcp-ai-wpoos-pro' ),
			'update_registration_status'   => __( 'Update Registration Status', 'mcp-ai-wpoos-pro' ),
			'list_expiring_registrations'  => __( 'List Expiring Registrations', 'mcp-ai-wpoos-pro' ),
			'submit_registration'          => __( 'Submit Registration', 'mcp-ai-wpoos-pro' ),
			'approve_registration'         => __( 'Approve Registration', 'mcp-ai-wpoos-pro' ),
			'renew_registration'           => __( 'Renew Registration', 'mcp-ai-wpoos-pro' ),
			'get_registration_timeline'    => __( 'Get Registration Timeline', 'mcp-ai-wpoos-pro' ),
			'list_registrations_by_country' => __( 'List Registrations by Country', 'mcp-ai-wpoos-pro' ),

			// Document Management Tools.
			'list_reg_documents'           => __( 'List Regulatory Documents', 'mcp-ai-wpoos-pro' ),
			'check_document_expiry'        => __( 'Check Document Expiry', 'mcp-ai-wpoos-pro' ),
			'upload_reg_document'          => __( 'Upload Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'update_reg_document'          => __( 'Update Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'get_reg_document'             => __( 'Get Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'validate_document_checklist'  => __( 'Validate Document Checklist', 'mcp-ai-wpoos-pro' ),
			'generate_submission_pack'     => __( 'Generate Submission Pack', 'mcp-ai-wpoos-pro' ),
			'track_document_version'       => __( 'Track Document Version', 'mcp-ai-wpoos-pro' ),

			// Compliance Tools.
			'add_regulatory_requirement'   => __( 'Add Regulatory Requirement', 'mcp-ai-wpoos-pro' ),
			'get_regulatory_requirements'  => __( 'Get Regulatory Requirements', 'mcp-ai-wpoos-pro' ),
			'check_product_compliance'     => __( 'Check Product Compliance', 'mcp-ai-wpoos-pro' ),
			'validate_inci_ingredients'    => __( 'Validate INCI Ingredients', 'mcp-ai-wpoos-pro' ),
			'check_hs_code'                => __( 'Check HS Code', 'mcp-ai-wpoos-pro' ),
			'get_regulatory_updates'       => __( 'Get Regulatory Updates', 'mcp-ai-wpoos-pro' ),

			// PDF Generation Tools.
			'generate_pdf_dossier'         => __( 'Generate PDF Dossier', 'mcp-ai-wpoos-pro' ),
			'generate_cover_letter'        => __( 'Generate Cover Letter', 'mcp-ai-wpoos-pro' ),
			'generate_compliance_certificate' => __( 'Generate Compliance Certificate', 'mcp-ai-wpoos-pro' ),

			// API Integration Tools.
			'sync_with_nmra'               => __( 'Sync with NMRA (Sri Lanka)', 'mcp-ai-wpoos-pro' ),
			'sync_with_mohap'              => __( 'Sync with MOHAP (UAE)', 'mcp-ai-wpoos-pro' ),
			'sync_with_sfda'               => __( 'Sync with SFDA (Saudi Arabia)', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page();
}
