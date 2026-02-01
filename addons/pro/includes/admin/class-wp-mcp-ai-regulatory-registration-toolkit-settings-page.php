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
		$this->has_research     = true;
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
	 * Register settings.
	 * Override parent to add toolkit-specific configuration fields.
	 */
	public function register_settings() {
		// Call parent to register base fields.
		parent::register_settings();

		// Add regulatory toolkit-specific configuration fields.
		add_settings_field(
			'default_regulatory_authority',
			__( 'Default Regulatory Authority', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_regulatory_authority_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'enable_expiry_alerts',
			__( 'Enable Document Expiry Alerts', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_expiry_alerts_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'expiry_alert_days',
			__( 'Expiry Alert Days', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_expiry_alert_days_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'enable_pdf_generation',
			__( 'Enable PDF Generation', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_pdf_generation_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'enable_excel_export',
			__( 'Enable Excel Export', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_excel_export_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'enable_inci_validation',
			__( 'Enable INCI Validation', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_inci_validation_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'enable_hs_code_validation',
			__( 'Enable HS Code Validation', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_hs_code_validation_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'enable_api_sync',
			__( 'Enable API Sync (Phase 3)', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_api_sync_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'auto_generate_product_code',
			__( 'Auto-Generate Product Code', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_auto_generate_product_code_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);

		add_settings_field(
			'product_code_prefix',
			__( 'Product Code Prefix', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_product_code_prefix_field' ),
			$this->option_name,
			$this->option_name . '_config_section'
		);
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
		// This method intentionally left empty.
		// Configuration fields are rendered via WordPress Settings API
		// in render_configuration_form() from the parent class.
	}

	/**
	 * Render default regulatory authority field.
	 */
	public function render_default_regulatory_authority_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_regulatory_authority'] ) ? $options['default_regulatory_authority'] : 'nmra';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_regulatory_authority]" class="regular-text">
			<option value="nmra" <?php selected( $value, 'nmra' ); ?>><?php esc_html_e( 'NMRA (Sri Lanka)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="mohap" <?php selected( $value, 'mohap' ); ?>><?php esc_html_e( 'MOHAP (UAE)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="sfda" <?php selected( $value, 'sfda' ); ?>><?php esc_html_e( 'SFDA (Saudi Arabia)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="qatar" <?php selected( $value, 'qatar' ); ?>><?php esc_html_e( 'Ministry of Public Health (Qatar)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="kuwait" <?php selected( $value, 'kuwait' ); ?>><?php esc_html_e( 'Ministry of Health (Kuwait)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="oman" <?php selected( $value, 'oman' ); ?>><?php esc_html_e( 'Ministry of Health (Oman)', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="india" <?php selected( $value, 'india' ); ?>><?php esc_html_e( 'CDSCO (India)', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Primary regulatory authority for new registrations', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable expiry alerts field.
	 */
	public function render_enable_expiry_alerts_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_expiry_alerts'] ) ? (bool) $options['enable_expiry_alerts'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_expiry_alerts]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Send notifications for expiring documents and registrations', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render expiry alert days field.
	 */
	public function render_expiry_alert_days_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['expiry_alert_days'] ) ? absint( $options['expiry_alert_days'] ) : 30;

		?>
		<input
			type="number"
			name="<?php echo esc_attr( $this->option_name ); ?>[expiry_alert_days]"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			max="365"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'Send alerts this many days before document/registration expiry', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable PDF generation field.
	 */
	public function render_enable_pdf_generation_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_pdf_generation'] ) ? (bool) $options['enable_pdf_generation'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_pdf_generation]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Generate PDF dossiers, cover letters, and certificates (requires PDFKit NPM package)', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render enable Excel export field.
	 */
	public function render_enable_excel_export_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_excel_export'] ) ? (bool) $options['enable_excel_export'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_excel_export]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Export registration data and reports to Excel (requires ExcelJS NPM package)', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render enable INCI validation field.
	 */
	public function render_enable_inci_validation_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_inci_validation'] ) ? (bool) $options['enable_inci_validation'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_inci_validation]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Validate ingredient names against INCI (International Nomenclature Cosmetic Ingredient) standards', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render enable HS code validation field.
	 */
	public function render_enable_hs_code_validation_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_hs_code_validation'] ) ? (bool) $options['enable_hs_code_validation'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_hs_code_validation]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Validate Harmonized System (HS) codes for import/export classification', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render enable API sync field.
	 */
	public function render_enable_api_sync_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_api_sync'] ) ? (bool) $options['enable_api_sync'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_api_sync]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Sync registration status with NMRA, MOHAP, and SFDA APIs (requires API credentials)', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Coming in Phase 3 - Direct integration with regulatory authority APIs', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render auto-generate product code field.
	 */
	public function render_auto_generate_product_code_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['auto_generate_product_code'] ) ? (bool) $options['auto_generate_product_code'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[auto_generate_product_code]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Automatically generate unique product codes for new products', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render product code prefix field.
	 */
	public function render_product_code_prefix_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['product_code_prefix'] ) ? sanitize_text_field( $options['product_code_prefix'] ) : 'REG';

		?>
		<input
			type="text"
			name="<?php echo esc_attr( $this->option_name ); ?>[product_code_prefix]"
			value="<?php echo esc_attr( $value ); ?>"
			maxlength="10"
			class="regular-text"
		/>
		<p class="description"><?php esc_html_e( 'Prefix for auto-generated product codes (e.g., REG-2024-001)', 'mcp-ai-wpoos-pro' ); ?></p>
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
			'create_reg_product'              => __( 'Create Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'list_reg_products'               => __( 'List Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'get_reg_product'                 => __( 'Get Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'update_reg_product'              => __( 'Update Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'delete_reg_product'              => __( 'Delete Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'search_reg_products'             => __( 'Search Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'duplicate_reg_product'           => __( 'Duplicate Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'validate_reg_product'            => __( 'Validate Regulatory Product', 'mcp-ai-wpoos-pro' ),

			// Registration Management Tools.
			'create_registration'             => __( 'Create Registration', 'mcp-ai-wpoos-pro' ),
			'list_registrations'              => __( 'List Registrations', 'mcp-ai-wpoos-pro' ),
			'get_registration'                => __( 'Get Registration', 'mcp-ai-wpoos-pro' ),
			'update_registration_status'      => __( 'Update Registration Status', 'mcp-ai-wpoos-pro' ),
			'list_expiring_registrations'     => __( 'List Expiring Registrations', 'mcp-ai-wpoos-pro' ),
			'submit_registration'             => __( 'Submit Registration', 'mcp-ai-wpoos-pro' ),
			'approve_registration'            => __( 'Approve Registration', 'mcp-ai-wpoos-pro' ),
			'renew_registration'              => __( 'Renew Registration', 'mcp-ai-wpoos-pro' ),
			'get_registration_timeline'       => __( 'Get Registration Timeline', 'mcp-ai-wpoos-pro' ),
			'list_registrations_by_country'   => __( 'List Registrations by Country', 'mcp-ai-wpoos-pro' ),

			// Document Management Tools.
			'list_reg_documents'              => __( 'List Regulatory Documents', 'mcp-ai-wpoos-pro' ),
			'check_document_expiry'           => __( 'Check Document Expiry', 'mcp-ai-wpoos-pro' ),
			'upload_reg_document'             => __( 'Upload Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'update_reg_document'             => __( 'Update Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'get_reg_document'                => __( 'Get Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'validate_document_checklist'     => __( 'Validate Document Checklist', 'mcp-ai-wpoos-pro' ),
			'generate_submission_pack'        => __( 'Generate Submission Pack', 'mcp-ai-wpoos-pro' ),
			'track_document_version'          => __( 'Track Document Version', 'mcp-ai-wpoos-pro' ),

			// Compliance Tools.
			'add_regulatory_requirement'      => __( 'Add Regulatory Requirement', 'mcp-ai-wpoos-pro' ),
			'get_regulatory_requirements'     => __( 'Get Regulatory Requirements', 'mcp-ai-wpoos-pro' ),
			'check_product_compliance'        => __( 'Check Product Compliance', 'mcp-ai-wpoos-pro' ),
			'validate_inci_ingredients'       => __( 'Validate INCI Ingredients', 'mcp-ai-wpoos-pro' ),
			'check_hs_code'                   => __( 'Check HS Code', 'mcp-ai-wpoos-pro' ),
			'get_regulatory_updates'          => __( 'Get Regulatory Updates', 'mcp-ai-wpoos-pro' ),

			// PDF Generation Tools.
			'generate_pdf_dossier'            => __( 'Generate PDF Dossier', 'mcp-ai-wpoos-pro' ),
			'generate_cover_letter'           => __( 'Generate Cover Letter', 'mcp-ai-wpoos-pro' ),
			'generate_compliance_certificate' => __( 'Generate Compliance Certificate', 'mcp-ai-wpoos-pro' ),

			// API Integration Tools.
			'sync_with_nmra'                  => __( 'Sync with NMRA (Sri Lanka)', 'mcp-ai-wpoos-pro' ),
			'sync_with_mohap'                 => __( 'Sync with MOHAP (UAE)', 'mcp-ai-wpoos-pro' ),
			'sync_with_sfda'                  => __( 'Sync with SFDA (Saudi Arabia)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 * Override parent to add toolkit-specific sanitization.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Sanitize regulatory authority.
		if ( isset( $input['default_regulatory_authority'] ) ) {
			$sanitized['default_regulatory_authority'] = sanitize_text_field( $input['default_regulatory_authority'] );
		}

		// Sanitize boolean fields.
		$boolean_fields = array(
			'enable_expiry_alerts',
			'enable_pdf_generation',
			'enable_excel_export',
			'enable_inci_validation',
			'enable_hs_code_validation',
			'enable_api_sync',
			'auto_generate_product_code',
		);

		foreach ( $boolean_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = (bool) $input[ $field ];
			}
		}

		// Sanitize expiry alert days.
		if ( isset( $input['expiry_alert_days'] ) ) {
			$sanitized['expiry_alert_days'] = absint( $input['expiry_alert_days'] );
			// Ensure it's within valid range.
			if ( $sanitized['expiry_alert_days'] < 1 ) {
				$sanitized['expiry_alert_days'] = 1;
			} elseif ( $sanitized['expiry_alert_days'] > 365 ) {
				$sanitized['expiry_alert_days'] = 365;
			}
		}

		// Sanitize product code prefix.
		if ( isset( $input['product_code_prefix'] ) ) {
			$sanitized['product_code_prefix'] = sanitize_text_field( $input['product_code_prefix'] );
			// Limit to 10 characters.
			$sanitized['product_code_prefix'] = substr( $sanitized['product_code_prefix'], 0, 10 );
		}

		return $sanitized;
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page();
}
