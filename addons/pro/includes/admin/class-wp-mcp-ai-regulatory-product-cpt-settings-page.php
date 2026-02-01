<?php
/**
 * Regulatory Product CPT Settings Page
 *
 * Settings page for the Regulatory Product custom post type with Overview, Settings, and Tools tabs.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Regulatory Product CPT Settings Page Class
 *
 * Provides settings interface for the Regulatory Product toolkit with
 * overview information and tools listing.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Regulatory_Product_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->cpt_slug     = 'mcp_ai_reg_product';
		$this->page_slug    = 'regulatory-product-settings';
		$this->page_title   = __( 'Regulatory Product Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title   = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->option_name  = 'wp_mcp_ai_regulatory_product_settings';
		$this->settings_key = 'enable_regulatory_registration_toolkit';

		parent::__construct();
	}

	/**
	 * Render overview tab.
	 *
	 * @since 1.2.0
	 */
	protected function render_overview_tab() {
		?>
		<h2><?php esc_html_e( 'Regulatory Registration Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
		
		<p><?php esc_html_e( 'Comprehensive regulatory product registration and compliance management system for multi-country regulatory submissions (Sri Lanka NMRA, UAE MOHAP, Saudi SFDA, and more).', 'mcp-ai-wpoos-pro' ); ?></p>

		<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Product Registration Management: Track products, registrations, and documentation across multiple countries', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Document Management: Organize and track regulatory documents, expiry dates, and versions', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Compliance Validation: Validate INCI ingredients, HS codes, and regulatory requirements', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'PDF Generation: Generate regulatory dossiers, cover letters, and compliance certificates', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Multi-Country Support: Manage registrations for Sri Lanka, UAE, Saudi Arabia, Qatar, Kuwait, Oman, India', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Registration Timeline Tracking: Monitor application status from submission to approval', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Expiry Notifications: Track document and registration renewal dates', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'API Integration: Sync with NMRA, MOHAP, and SFDA regulatory authorities', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Tool Categories', 'mcp-ai-wpoos-pro' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Product Management: 8 tools for CRUD operations and validation', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Registration Management: 10 tools for tracking and managing registrations', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Document Management: 8 tools for regulatory documentation', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'Compliance: 6 tools for validation and regulatory checks', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'PDF Generation: 3 tools for dossiers, letters, certificates', 'mcp-ai-wpoos-pro' ); ?></li>
			<li><?php esc_html_e( 'API Integration: 3 tools for authority system sync', 'mcp-ai-wpoos-pro' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @since 1.2.0
	 * @return array Tools list with slugs and names.
	 */
	protected function get_tools_list() {
		return array(
			// Product Management Tools (8).
			'create_reg_product'              => __( 'Create Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'list_reg_products'               => __( 'List Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'get_reg_product'                 => __( 'Get Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'update_reg_product'              => __( 'Update Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'delete_reg_product'              => __( 'Delete Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'search_reg_products'             => __( 'Search Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'duplicate_reg_product'           => __( 'Duplicate Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'validate_reg_product'            => __( 'Validate Regulatory Product', 'mcp-ai-wpoos-pro' ),

			// Registration Management Tools (10).
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

			// Document Management Tools (8).
			'list_reg_documents'              => __( 'List Regulatory Documents', 'mcp-ai-wpoos-pro' ),
			'check_document_expiry'           => __( 'Check Document Expiry', 'mcp-ai-wpoos-pro' ),
			'upload_reg_document'             => __( 'Upload Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'update_reg_document'             => __( 'Update Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'get_reg_document'                => __( 'Get Regulatory Document', 'mcp-ai-wpoos-pro' ),
			'validate_document_checklist'     => __( 'Validate Document Checklist', 'mcp-ai-wpoos-pro' ),
			'generate_submission_pack'        => __( 'Generate Submission Pack', 'mcp-ai-wpoos-pro' ),
			'track_document_version'          => __( 'Track Document Version', 'mcp-ai-wpoos-pro' ),

			// Compliance Tools (6).
			'add_regulatory_requirement'      => __( 'Add Regulatory Requirement', 'mcp-ai-wpoos-pro' ),
			'get_regulatory_requirements'     => __( 'Get Regulatory Requirements', 'mcp-ai-wpoos-pro' ),
			'check_product_compliance'        => __( 'Check Product Compliance', 'mcp-ai-wpoos-pro' ),
			'validate_inci_ingredients'       => __( 'Validate INCI Ingredients', 'mcp-ai-wpoos-pro' ),
			'check_hs_code'                   => __( 'Check HS Code', 'mcp-ai-wpoos-pro' ),
			'get_regulatory_updates'          => __( 'Get Regulatory Updates', 'mcp-ai-wpoos-pro' ),

			// PDF Generation Tools (3).
			'generate_pdf_dossier'            => __( 'Generate PDF Dossier', 'mcp-ai-wpoos-pro' ),
			'generate_cover_letter'           => __( 'Generate Cover Letter', 'mcp-ai-wpoos-pro' ),
			'generate_compliance_certificate' => __( 'Generate Compliance Certificate', 'mcp-ai-wpoos-pro' ),

			// API Integration Tools (3).
			'sync_with_nmra'                  => __( 'Sync with NMRA (Sri Lanka)', 'mcp-ai-wpoos-pro' ),
			'sync_with_mohap'                 => __( 'Sync with MOHAP (UAE)', 'mcp-ai-wpoos-pro' ),
			'sync_with_sfda'                  => __( 'Sync with SFDA (Saudi Arabia)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get settings fields.
	 *
	 * @since 1.2.0
	 * @return array Settings fields configuration.
	 */
	protected function get_settings_fields() {
		return array(
			array(
				'id'          => 'assistant_id',
				'label'       => __( 'Assistant', 'mcp-ai-wpoos-pro' ),
				'type'        => 'assistant_select',
				'description' => __( 'Select the AI assistant to use for regulatory registration tasks.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}

// Initialize settings page.
new WP_MCP_AI_Regulatory_Product_Settings_Page();
