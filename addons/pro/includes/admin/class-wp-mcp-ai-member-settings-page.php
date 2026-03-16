<?php
/**
 * Member Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Health & Wellness Member (Family & Pets) Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Member Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
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
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_pet_members',
			__( 'Enable Pet Members', 'mcp-ai-wpoos-pro' ),
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
			<?php esc_html_e( 'Enable the Research & Add page for member management', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create family members and pets using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
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
			<?php esc_html_e( 'Enable pet member management', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can manage pet health records in addition to human family members.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Member Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered health and wellness member management for families and pets. Track health records, medications, allergies, checkups, prescriptions, and structured vital sign measurements with comprehensive AI assistance.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Family Members: Manage health records for all family members', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Pet Health: Track health records for family pets', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Medical Records: Store and manage comprehensive medical records', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Medications: Track prescriptions and medication schedules', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Allergies: Monitor allergies and adverse reactions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Checkups: Schedule and track routine health checkups', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Vital Signs CCT: Log and trend blood pressure, heart rate, SpO2, temperature, glucose, and kidney indicators (eGFR, creatinine, BUN, K⁺, Na⁺, phosphorus, albumin) in the JetEngine CCT', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-assisted member profile creation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Consolidate & Add: Unified view with Vital Signs import tab', 'mcp-ai-wpoos-pro' ); ?></li>
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
			'create_member'                => __( 'Create Member', 'mcp-ai-wpoos-pro' ),
			'list_members'                 => __( 'List Members', 'mcp-ai-wpoos-pro' ),
			'get_member'                   => __( 'Get Member', 'mcp-ai-wpoos-pro' ),
			'update_member'                => __( 'Update Member', 'mcp-ai-wpoos-pro' ),
			'delete_member'                => __( 'Delete Member', 'mcp-ai-wpoos-pro' ),
			// Policy/insurance management (USCDI: Insurance Coverage — FHIR Coverage).
			'create_policy'                => __( 'Create Policy', 'mcp-ai-wpoos-pro' ),
			'list_policies'                => __( 'List Policies', 'mcp-ai-wpoos-pro' ),
			'get_policy'                   => __( 'Get Policy', 'mcp-ai-wpoos-pro' ),
			'update_policy'                => __( 'Update Policy', 'mcp-ai-wpoos-pro' ),
			'delete_policy'                => __( 'Delete Policy', 'mcp-ai-wpoos-pro' ),
			'search_policies'              => __( 'Search Policies', 'mcp-ai-wpoos-pro' ),
			'research_policy'              => __( 'Research Policy', 'mcp-ai-wpoos-pro' ),
			// Prescription management (USCDI: Medications — FHIR MedicationStatement/NDC/RxNorm).
			'create_prescription'          => __( 'Create Prescription', 'mcp-ai-wpoos-pro' ),
			'list_prescriptions'           => __( 'List Prescriptions', 'mcp-ai-wpoos-pro' ),
			'get_prescription'             => __( 'Get Prescription', 'mcp-ai-wpoos-pro' ),
			'update_prescription'          => __( 'Update Prescription', 'mcp-ai-wpoos-pro' ),
			'delete_prescription'          => __( 'Delete Prescription', 'mcp-ai-wpoos-pro' ),
			'search_prescriptions'         => __( 'Search Prescriptions', 'mcp-ai-wpoos-pro' ),
			// Medical record management (USCDI: Problems/Conditions — FHIR Condition/ICD-10).
			'create_medical_record'        => __( 'Create Medical Record', 'mcp-ai-wpoos-pro' ),
			'list_medical_records'         => __( 'List Medical Records', 'mcp-ai-wpoos-pro' ),
			'get_medical_record'           => __( 'Get Medical Record', 'mcp-ai-wpoos-pro' ),
			'update_medical_record'        => __( 'Update Medical Record', 'mcp-ai-wpoos-pro' ),
			'delete_medical_record'        => __( 'Delete Medical Record', 'mcp-ai-wpoos-pro' ),
			'search_medical_records'       => __( 'Search Medical Records', 'mcp-ai-wpoos-pro' ),
			// Checkup/appointment management (USCDI: Encounters — FHIR Encounter).
			'create_checkup'               => __( 'Create Checkup', 'mcp-ai-wpoos-pro' ),
			'list_checkups'                => __( 'List Checkups', 'mcp-ai-wpoos-pro' ),
			'get_checkup'                  => __( 'Get Checkup', 'mcp-ai-wpoos-pro' ),
			'update_checkup'               => __( 'Update Checkup', 'mcp-ai-wpoos-pro' ),
			'delete_checkup'               => __( 'Delete Checkup', 'mcp-ai-wpoos-pro' ),
			'get_upcoming_checkups'        => __( 'Get Upcoming Checkups', 'mcp-ai-wpoos-pro' ),
			// Allergy management (USCDI: Allergies & Intolerances — FHIR AllergyIntolerance).
			'create_allergy'               => __( 'Create Allergy', 'mcp-ai-wpoos-pro' ),
			'list_allergies'               => __( 'List Allergies', 'mcp-ai-wpoos-pro' ),
			'get_allergy'                  => __( 'Get Allergy', 'mcp-ai-wpoos-pro' ),
			'update_allergy'               => __( 'Update Allergy', 'mcp-ai-wpoos-pro' ),
			'delete_allergy'               => __( 'Delete Allergy', 'mcp-ai-wpoos-pro' ),
			// Specialized health & wellness tools.
			'get_member_health_summary'    => __( 'Get Member Health Summary', 'mcp-ai-wpoos-pro' ),
			'get_medication_schedule'      => __( 'Get Medication Schedule', 'mcp-ai-wpoos-pro' ),
			'generate_health_chart'        => __( 'Generate Health Chart', 'mcp-ai-wpoos-pro' ),
			'create_health_reminder'       => __( 'Create Health Reminder', 'mcp-ai-wpoos-pro' ),
			// Immunizations (USCDI: Immunizations — FHIR Immunization).
			'track_vaccinations'           => __( 'Track Vaccinations', 'mcp-ai-wpoos-pro' ),
			// Daily health metrics logging.
			'log_health_metrics'           => __( 'Log Health Metrics', 'mcp-ai-wpoos-pro' ),
			// Vital signs CCT (USCDI: Vital Signs — FHIR Observation/LOINC).
			'log_vital_signs'              => __( 'Log Vital Signs (CCT)', 'mcp-ai-wpoos-pro' ),
			// Data operations & interoperability.
			'import_vitals'                => __( 'Import Vitals', 'mcp-ai-wpoos-pro' ),
			'export_fhir_data'             => __( 'Export FHIR Data', 'mcp-ai-wpoos-pro' ),
			'manage_care_plan'             => __( 'Manage Care Plan', 'mcp-ai-wpoos-pro' ),
			'compile_health_research_data' => __( 'Compile Health Research Data', 'mcp-ai-wpoos-pro' ),
			// AI-assisted data entry (agentic flow — USCDI-aligned completeness guidance).
			'guide_health_record_creation' => __( 'Guide Health Record Creation', 'mcp-ai-wpoos-pro' ),
			'parse_health_information'     => __( 'Parse Health Information', 'mcp-ai-wpoos-pro' ),
			// Document processing tools.
			'extract_pdf_text'             => __( 'Extract PDF Text', 'mcp-ai-wpoos-pro' ),
			'pro_pdf_document'             => __( 'Pro PDF Document', 'mcp-ai-wpoos-pro' ),
			'pro_word_document'            => __( 'Pro Word Document', 'mcp-ai-wpoos-pro' ),
			'pro_excel_document'           => __( 'Pro Excel Document', 'mcp-ai-wpoos-pro' ),
			'generate_pdf'                 => __( 'Generate PDF', 'mcp-ai-wpoos-pro' ),
			'generate_word'                => __( 'Generate Word', 'mcp-ai-wpoos-pro' ),
			'generate_excel'               => __( 'Generate Excel', 'mcp-ai-wpoos-pro' ),
			'html_to_pdf'                  => __( 'HTML to PDF', 'mcp-ai-wpoos-pro' ),
			'merge_pdfs'                   => __( 'Merge PDFs', 'mcp-ai-wpoos-pro' ),
			'add_watermark_to_pdf'         => __( 'Add Watermark to PDF', 'mcp-ai-wpoos-pro' ),
			'excel_data_import'            => __( 'Excel Data Import', 'mcp-ai-wpoos-pro' ),
			'excel_data_export'            => __( 'Excel Data Export', 'mcp-ai-wpoos-pro' ),
			'generate_invoice_pdf'         => __( 'Generate Invoice PDF', 'mcp-ai-wpoos-pro' ),
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
				'mcp-ai-wpoos-pro'
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
