<?php
/**
 * class-wp-mcp-ai-member-settings-page.php (ecosystem port — Wave F4, healthcare admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-member-settings-page.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (incl. the `defined( 'WP_MCP_AI_PRO_VERSION' )`
 * base-mode gates — the addon constant is always defined standalone, so the ternary yields
 * the addon version); the base-owned `__DIR__` trait requires and the
 * cpt-settings-base/research-add-base requires resolve from the addon's already-ported
 * `src/admin/` copies.
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
 * Member Settings Page
 */
class WP_MCP_AI_Member_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_member_settings';
		$this->post_type   = 'mcp_ai_member';
		$this->page_title  = __( 'Member Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
		$this->page_slug   = 'member-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add member-specific settings.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'nvoos-content-graph-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_pet_members',
			__( 'Enable Pet Members', 'nvoos-content-graph-pro' ),
			array( $this, 'render_enable_pet_members_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for member management', 'nvoos-content-graph-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create family members and pets using AI assistance.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable pet members field.
	 */
	public function render_enable_pet_members_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_pet_members'] ) ? (bool) $options['enable_pet_members'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_pet_members]"
				id="enable_pet_members"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable pet member management', 'nvoos-content-graph-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can manage pet health records in addition to human family members.', 'nvoos-content-graph-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Member Management Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered health and wellness member management for families and pets. Track health records, medications, allergies, checkups, prescriptions, and structured vital sign measurements with comprehensive AI assistance.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Family Members: Manage health records for all family members', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Pet Health: Track health records for family pets', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Medical Records: Store and manage comprehensive medical records', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Medications: Track prescriptions and medication schedules', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Allergies: Monitor allergies and adverse reactions', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Checkups: Schedule and track routine health checkups', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Vital Signs CCT: Log and trend blood pressure, heart rate, SpO2, temperature, glucose, and kidney indicators (eGFR, creatinine, BUN, K⁺, Na⁺, phosphorus, albumin) in the JetEngine CCT', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-assisted member profile creation', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Consolidate & Add: Unified view with Vital Signs import tab', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list for this CPT.
	 *
	 * Returns all Health & Wellness toolkit tools grouped by category,
	 * covering all USCDI data classes and document generation tools.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Member management (USCDI: Patient Demographics — FHIR Patient).
			'create_member'                => __( 'Create Member', 'nvoos-content-graph-pro' ),
			'list_members'                 => __( 'List Members', 'nvoos-content-graph-pro' ),
			'get_member'                   => __( 'Get Member', 'nvoos-content-graph-pro' ),
			'update_member'                => __( 'Update Member', 'nvoos-content-graph-pro' ),
			'delete_member'                => __( 'Delete Member', 'nvoos-content-graph-pro' ),
			// Policy/insurance management (USCDI: Insurance Coverage — FHIR Coverage).
			'create_policy'                => __( 'Create Policy', 'nvoos-content-graph-pro' ),
			'list_policies'                => __( 'List Policies', 'nvoos-content-graph-pro' ),
			'get_policy'                   => __( 'Get Policy', 'nvoos-content-graph-pro' ),
			'update_policy'                => __( 'Update Policy', 'nvoos-content-graph-pro' ),
			'delete_policy'                => __( 'Delete Policy', 'nvoos-content-graph-pro' ),
			'search_policies'              => __( 'Search Policies', 'nvoos-content-graph-pro' ),
			'research_policy'              => __( 'Research Policy', 'nvoos-content-graph-pro' ),
			// Prescription management (USCDI: Medications — FHIR MedicationStatement/NDC/RxNorm).
			'create_prescription'          => __( 'Create Prescription', 'nvoos-content-graph-pro' ),
			'list_prescriptions'           => __( 'List Prescriptions', 'nvoos-content-graph-pro' ),
			'get_prescription'             => __( 'Get Prescription', 'nvoos-content-graph-pro' ),
			'update_prescription'          => __( 'Update Prescription', 'nvoos-content-graph-pro' ),
			'delete_prescription'          => __( 'Delete Prescription', 'nvoos-content-graph-pro' ),
			'search_prescriptions'         => __( 'Search Prescriptions', 'nvoos-content-graph-pro' ),
			// Medical record management (USCDI: Problems/Conditions — FHIR Condition/ICD-10).
			'create_medical_record'        => __( 'Create Medical Record', 'nvoos-content-graph-pro' ),
			'list_medical_records'         => __( 'List Medical Records', 'nvoos-content-graph-pro' ),
			'get_medical_record'           => __( 'Get Medical Record', 'nvoos-content-graph-pro' ),
			'update_medical_record'        => __( 'Update Medical Record', 'nvoos-content-graph-pro' ),
			'delete_medical_record'        => __( 'Delete Medical Record', 'nvoos-content-graph-pro' ),
			'search_medical_records'       => __( 'Search Medical Records', 'nvoos-content-graph-pro' ),
			// Checkup/appointment management (USCDI: Encounters — FHIR Encounter).
			'create_checkup'               => __( 'Create Checkup', 'nvoos-content-graph-pro' ),
			'list_checkups'                => __( 'List Checkups', 'nvoos-content-graph-pro' ),
			'get_checkup'                  => __( 'Get Checkup', 'nvoos-content-graph-pro' ),
			'update_checkup'               => __( 'Update Checkup', 'nvoos-content-graph-pro' ),
			'delete_checkup'               => __( 'Delete Checkup', 'nvoos-content-graph-pro' ),
			'get_upcoming_checkups'        => __( 'Get Upcoming Checkups', 'nvoos-content-graph-pro' ),
			// Allergy management (USCDI: Allergies & Intolerances — FHIR AllergyIntolerance).
			'create_allergy'               => __( 'Create Allergy', 'nvoos-content-graph-pro' ),
			'list_allergies'               => __( 'List Allergies', 'nvoos-content-graph-pro' ),
			'get_allergy'                  => __( 'Get Allergy', 'nvoos-content-graph-pro' ),
			'update_allergy'               => __( 'Update Allergy', 'nvoos-content-graph-pro' ),
			'delete_allergy'               => __( 'Delete Allergy', 'nvoos-content-graph-pro' ),
			// Specialized health & wellness tools.
			'get_member_health_summary'    => __( 'Get Member Health Summary', 'nvoos-content-graph-pro' ),
			'get_medication_schedule'      => __( 'Get Medication Schedule', 'nvoos-content-graph-pro' ),
			'generate_health_chart'        => __( 'Generate Health Chart', 'nvoos-content-graph-pro' ),
			'create_health_reminder'       => __( 'Create Health Reminder', 'nvoos-content-graph-pro' ),
			// Immunizations (USCDI: Immunizations — FHIR Immunization).
			'track_vaccinations'           => __( 'Track Vaccinations', 'nvoos-content-graph-pro' ),
			// Daily health metrics logging.
			'log_health_metrics'           => __( 'Log Health Metrics', 'nvoos-content-graph-pro' ),
			// Vital signs CCT (USCDI: Vital Signs — FHIR Observation/LOINC).
			'log_vital_signs'              => __( 'Log Vital Signs (CCT)', 'nvoos-content-graph-pro' ),
			// Data operations & interoperability.
			'import_vitals'                => __( 'Import Vitals', 'nvoos-content-graph-pro' ),
			'export_fhir_data'             => __( 'Export FHIR Data', 'nvoos-content-graph-pro' ),
			'manage_care_plan'             => __( 'Manage Care Plan', 'nvoos-content-graph-pro' ),
			'compile_health_research_data' => __( 'Compile Health Research Data', 'nvoos-content-graph-pro' ),
			// AI-assisted data entry (agentic flow — USCDI-aligned completeness guidance).
			'guide_health_record_creation' => __( 'Guide Health Record Creation', 'nvoos-content-graph-pro' ),
			'parse_health_information'     => __( 'Parse Health Information', 'nvoos-content-graph-pro' ),
			// Document processing tools.
			'extract_pdf_text'             => __( 'Extract PDF Text', 'nvoos-content-graph-pro' ),
			'pro_pdf_document'             => __( 'Pro PDF Document', 'nvoos-content-graph-pro' ),
			'pro_word_document'            => __( 'Pro Word Document', 'nvoos-content-graph-pro' ),
			'pro_excel_document'           => __( 'Pro Excel Document', 'nvoos-content-graph-pro' ),
			'generate_pdf'                 => __( 'Generate PDF', 'nvoos-content-graph-pro' ),
			'generate_word'                => __( 'Generate Word', 'nvoos-content-graph-pro' ),
			'generate_excel'               => __( 'Generate Excel', 'nvoos-content-graph-pro' ),
			'html_to_pdf'                  => __( 'HTML to PDF', 'nvoos-content-graph-pro' ),
			'merge_pdfs'                   => __( 'Merge PDFs', 'nvoos-content-graph-pro' ),
			'add_watermark_to_pdf'         => __( 'Add Watermark to PDF', 'nvoos-content-graph-pro' ),
			'excel_data_import'            => __( 'Excel Data Import', 'nvoos-content-graph-pro' ),
			'excel_data_export'            => __( 'Excel Data Export', 'nvoos-content-graph-pro' ),
			'generate_invoice_pdf'         => __( 'Generate Invoice PDF', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		?>
		<p>
			<?php
			esc_html_e(
				'Configure AI assistance settings for Health & Wellness member management. Select an AI assistant to help with creating and managing family member and pet health records.',
				'nvoos-content-graph-pro'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add member-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		if ( isset( $input['enable_pet_members'] ) ) {
			$sanitized['enable_pet_members'] = (bool) $input['enable_pet_members'];
		} else {
			$sanitized['enable_pet_members'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Member_Settings_Page();
