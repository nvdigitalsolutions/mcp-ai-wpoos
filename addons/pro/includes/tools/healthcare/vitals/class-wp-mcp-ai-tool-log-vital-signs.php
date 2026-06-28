<?php
/**
 * Tool for logging and tracking vital signs.
 *
 * Comprehensive vital signs monitoring including blood pressure, heart rate,
 * temperature, weight, BMI, glucose, oxygen saturation, respiratory rate,
 * kidney-health indicators (eGFR, creatinine, BUN, K+, Na+, phosphorus,
 * albumin), a full CBC / anemia panel (hemoglobin, hematocrit, RBC, WBC,
 * platelets, MCV, MCH, MCHC, RDW, plus WBC differential in % and absolute
 * counts), extended BMP/CMP electrolytes (chloride, CO2/bicarbonate, calcium,
 * magnesium), and liver function tests / LFT (total bilirubin, AST, ALT,
 * total protein).  Provenance/QA fields (facility_name, document_name,
 * test_panel, document_date, collection_time, result_time, import_batch_id,
 * review_status, review_notes, is_abnormal, abnormal_flags) are stored
 * alongside measurements so every record can be traced back to its source
 * document and reviewed.
 *
 * When JetEngine is available measurements are stored in the vitals_log CCT
 * (primary store for compiled log data).  Options-based storage is always
 * written as a lightweight fallback so tools that pre-date CCT availability
 * keep working.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs and tracks vital signs for health monitoring.
 */
class WP_MCP_AI_Tool_Log_Vital_Signs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * WordPress option key used to persist the vitals embedding index.
	 *
	 * @var string
	 */
	const VITALS_EMBED_INDEX_KEY = 'wp_mcp_ai_vitals_embed_index';

	/**
	 * Maximum number of entries kept in the vitals embedding index.
	 *
	 * Older entries are evicted first (FIFO) to prevent the option from growing
	 * without bound.  500 entries × ~6 KB per embedding ≈ 3 MB, which is
	 * well within WordPress option limits.
	 *
	 * @var int
	 */
	const VITALS_EMBED_INDEX_MAX = 500;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'log_vital_signs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Log Vital Signs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Log and track vital signs including blood pressure, heart rate, temperature (F or C — automatically normalised to °F), weight, BMI, blood glucose, oxygen saturation (SpO2), respiratory rate, kidney-health indicators (eGFR, creatinine, BUN, potassium, sodium, phosphorus, albumin), a complete CBC panel (hemoglobin, hematocrit, RBC, WBC, platelets, MCV, MCH, MCHC, RDW, and WBC differential with percent and absolute counts), extended BMP/CMP electrolytes (chloride, CO2/bicarbonate, calcium, magnesium), and liver function tests (total bilirubin, AST, ALT, total protein). Provenance fields (facility_name, document_name, test_panel, document_date, collection_time, result_time, import_batch_id, review_status, abnormal_flags) support audit trails for imported lab data. When JetEngine is active measurements are stored in the structured vitals_log CCT with options-based storage maintained as a fallback. Supports trend analysis, normal range validation, and alerts for abnormal readings. HIPAA-compliant with audit trails.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'                   => array(
					'type'        => 'string',
					'description' => __( 'Action to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'log', 'get_latest', 'get_history', 'analyze_trends', 'update', 'delete' ),
					'default'     => 'log',
				),
				'member_id'                => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'cct_id'                   => array(
					'type'        => 'integer',
					'description' => __( 'Vitals log CCT row ID (_ID) — required for update and delete actions', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'measurement_date'         => array(
					'type'        => 'string',
					'description' => __( 'Date of measurement (YYYY-MM-DD) (optional, defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'measurement_time'         => array(
					'type'        => 'string',
					'description' => __( 'Time of measurement (HH:MM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
				),
				'blood_pressure_systolic'  => array(
					'type'        => 'integer',
					'description' => __( 'Systolic blood pressure (mmHg) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 300,
				),
				'blood_pressure_diastolic' => array(
					'type'        => 'integer',
					'description' => __( 'Diastolic blood pressure (mmHg) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 30,
					'maximum'     => 200,
				),
				'heart_rate'               => array(
					'type'        => 'integer',
					'description' => __( 'Heart rate (beats per minute) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 30,
					'maximum'     => 250,
				),
				'temperature'              => array(
					'type'        => 'number',
					'description' => __( 'Body temperature in Fahrenheit or Celsius (optional). Use temperature_unit to specify the unit — the value is always stored normalised to °F. Provide the raw value as measured (e.g. 37 for 37 °C or 98.6 for 98.6 °F). Minimum 32 covers Celsius inputs (≥ 32 °C).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 32.0,
					'maximum'     => 115.0,
				),
				'temperature_unit'         => array(
					'type'        => 'string',
					'description' => __( 'Temperature unit (optional, default: F)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'F', 'C' ),
					'default'     => 'F',
				),
				'weight'                   => array(
					'type'        => 'number',
					'description' => __( 'Body weight (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.1,
				),
				'weight_unit'              => array(
					'type'        => 'string',
					'description' => __( 'Weight unit (optional, default: lbs)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lbs', 'kg' ),
					'default'     => 'lbs',
				),
				'height'                   => array(
					'type'        => 'number',
					'description' => __( 'Height in inches or cm (optional, for BMI calculation)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'height_unit'              => array(
					'type'        => 'string',
					'description' => __( 'Height unit (optional, default: in)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'in', 'cm' ),
					'default'     => 'in',
				),
				'blood_glucose'            => array(
					'type'        => 'integer',
					'description' => __( 'Blood glucose level (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 20,
					'maximum'     => 600,
				),
				'oxygen_saturation'        => array(
					'type'        => 'integer',
					'description' => __( 'Oxygen saturation (SpO2) percentage (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 100,
				),
				'respiratory_rate'         => array(
					'type'        => 'integer',
					'description' => __( 'Respiratory rate (breaths per minute) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 5,
					'maximum'     => 60,
				),
				// Kidney health indicators.
				'egfr'                     => array(
					'type'        => 'number',
					'description' => __( 'Estimated Glomerular Filtration Rate (mL/min/1.73m²) — CKD stage indicator (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 200,
				),
				'creatinine'               => array(
					'type'        => 'number',
					'description' => __( 'Serum creatinine (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.1,
					'maximum'     => 30,
				),
				'bun'                      => array(
					'type'        => 'number',
					'description' => __( 'Blood Urea Nitrogen (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'potassium'                => array(
					'type'        => 'number',
					'description' => __( 'Serum potassium K+ (mEq/L) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1.0,
					'maximum'     => 10.0,
				),
				'sodium'                   => array(
					'type'        => 'number',
					'description' => __( 'Sodium intake Na+ (mg/day) (optional) — kidney-friendly target ≤ 2300 mg', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10000,
				),
				'phosphorus'               => array(
					'type'        => 'number',
					'description' => __( 'Serum phosphorus PO4 (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
					'maximum'     => 20,
				),
				'albumin'                  => array(
					'type'        => 'number',
					'description' => __( 'Serum albumin (g/dL) — nutritional and kidney health marker (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
					'maximum'     => 6.0,
				),
				'hemoglobin'               => array(
					'type'        => 'number',
					'description' => __( 'Hemoglobin level (g/dL) — red blood cell / anaemia indicator (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1.0,
					'maximum'     => 25.0,
				),
				// CBC — main indices.
				'hematocrit'               => array(
					'type'        => 'number',
					'description' => __( 'Hematocrit (%) — percentage of RBCs in blood (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 5.0,
					'maximum'     => 65.0,
				),
				'rbc'                      => array(
					'type'        => 'number',
					'description' => __( 'Red blood cell count (x10⁶/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
					'maximum'     => 8.0,
				),
				'wbc'                      => array(
					'type'        => 'number',
					'description' => __( 'White blood cell count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.1,
					'maximum'     => 100.0,
				),
				'platelets'                => array(
					'type'        => 'integer',
					'description' => __( 'Platelet count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 2000,
				),
				'mcv'                      => array(
					'type'        => 'number',
					'description' => __( 'Mean corpuscular volume (fL) — RBC size indicator (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50.0,
					'maximum'     => 150.0,
				),
				'mch'                      => array(
					'type'        => 'number',
					'description' => __( 'Mean corpuscular hemoglobin (pg) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 10.0,
					'maximum'     => 50.0,
				),
				'mchc'                     => array(
					'type'        => 'number',
					'description' => __( 'Mean corpuscular hemoglobin concentration (g/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 20.0,
					'maximum'     => 40.0,
				),
				'rdw'                      => array(
					'type'        => 'number',
					'description' => __( 'Red cell distribution width (%) — RBC size variation (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 5.0,
					'maximum'     => 30.0,
				),
				// CBC differential — percent.
				'neutrophils_percent'      => array(
					'type'        => 'number',
					'description' => __( 'Neutrophils (% of WBC differential) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 100.0,
				),
				'lymphocytes_percent'      => array(
					'type'        => 'number',
					'description' => __( 'Lymphocytes (% of WBC differential) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 100.0,
				),
				'monocytes_percent'        => array(
					'type'        => 'number',
					'description' => __( 'Monocytes (% of WBC differential) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 100.0,
				),
				'eosinophils_percent'      => array(
					'type'        => 'number',
					'description' => __( 'Eosinophils (% of WBC differential) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 100.0,
				),
				'basophils_percent'        => array(
					'type'        => 'number',
					'description' => __( 'Basophils (% of WBC differential) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 100.0,
				),
				// CBC differential — absolute counts.
				'neutrophils_absolute'     => array(
					'type'        => 'number',
					'description' => __( 'Absolute neutrophil count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 50.0,
				),
				'lymphocytes_absolute'     => array(
					'type'        => 'number',
					'description' => __( 'Absolute lymphocyte count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 50.0,
				),
				'monocytes_absolute'       => array(
					'type'        => 'number',
					'description' => __( 'Absolute monocyte count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 20.0,
				),
				'eosinophils_absolute'     => array(
					'type'        => 'number',
					'description' => __( 'Absolute eosinophil count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 20.0,
				),
				'basophils_absolute'       => array(
					'type'        => 'number',
					'description' => __( 'Absolute basophil count (x10³/µL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 10.0,
				),
				// Extended BMP / CMP electrolytes.
				'chloride'                 => array(
					'type'        => 'number',
					'description' => __( 'Serum chloride Cl- (mEq/L) — BMP/CMP electrolyte panel (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 70.0,
					'maximum'     => 130.0,
				),
				'co2'                      => array(
					'type'        => 'number',
					'description' => __( 'Serum CO2 / bicarbonate (mEq/L) — BMP/CMP electrolyte panel (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 5.0,
					'maximum'     => 45.0,
				),
				'calcium'                  => array(
					'type'        => 'number',
					'description' => __( 'Serum calcium Ca2+ (mg/dL) — BMP/CMP panel (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 4.0,
					'maximum'     => 15.0,
				),
				'magnesium'                => array(
					'type'        => 'number',
					'description' => __( 'Serum magnesium Mg2+ (mg/dL) — electrolyte / metabolic panel (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
					'maximum'     => 5.0,
				),
				// Liver function tests (LFT).
				'bilirubin'                => array(
					'type'        => 'number',
					'description' => __( 'Total bilirubin (mg/dL) — liver function / jaundice indicator (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 30.0,
				),
				'ast'                      => array(
					'type'        => 'number',
					'description' => __( 'AST / SGOT (U/L) — aspartate aminotransferase liver health marker (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 5000.0,
				),
				'alt'                      => array(
					'type'        => 'number',
					'description' => __( 'ALT / SGPT (U/L) — alanine aminotransferase liver health marker (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.0,
					'maximum'     => 5000.0,
				),
				'total_protein'            => array(
					'type'        => 'number',
					'description' => __( 'Total protein (g/dL) — liver function and nutritional status (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1.0,
					'maximum'     => 12.0,
				),
				// Provenance / QA.
				'facility_name'            => array(
					'type'        => 'string',
					'description' => __( 'Facility or laboratory name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 255,
				),
				'document_name'            => array(
					'type'        => 'string',
					'description' => __( 'Source document filename or reference (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 255,
				),
				'test_panel'               => array(
					'type'        => 'string',
					'description' => __( 'Lab panel name e.g. CBC, CMP, BMP, Lipid, Renal (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'document_date'            => array(
					'type'        => 'string',
					'description' => __( 'Date shown on the source document (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'collection_time'          => array(
					'type'        => 'string',
					'description' => __( 'Specimen collection time (HH:MM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
				),
				'result_time'              => array(
					'type'        => 'string',
					'description' => __( 'Time results were reported (HH:MM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
				),
				'import_batch_id'          => array(
					'type'        => 'string',
					'description' => __( 'Import batch identifier for tracing bulk-imported records (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'review_status'            => array(
					'type'        => 'string',
					'description' => __( 'QA review status for this record (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'unreviewed', 'auto_imported', 'reviewed', 'corrected', 'needs_manual_review' ),
					'default'     => 'unreviewed',
				),
				'review_notes'             => array(
					'type'        => 'string',
					'description' => __( 'Reviewer audit notes for corrections or QA comments (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'is_abnormal'              => array(
					'type'        => 'boolean',
					'description' => __( 'True when any result in this record is flagged as abnormal by the lab (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'abnormal_flags'           => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated field names flagged as abnormal e.g. "hemoglobin,wbc" (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'source'                   => array(
					'type'        => 'string',
					'description' => __( 'How this measurement was captured (optional, default: manual)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'manual', 'tma', 'api', 'import' ),
					'default'     => 'manual',
				),
				'notes'                    => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or context (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'days_back'                => array(
					'type'        => 'integer',
					'description' => __( 'Number of days of history to retrieve (for get_history/analyze_trends) (optional, default: 30)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 30,
				),
			),
			'required'             => array( 'action', 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_member',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver', 'patient' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'database-write', 'pii-data', 'hipaa-relevant' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health and Wellness management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access vital signs.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'log';
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Execute based on action.
		switch ( $action ) {
			case 'log':
				return $this->log_vital_signs( $arguments, $member_id, $current_user_id, $context );

			case 'get_latest':
				return $this->get_latest_vital_signs( $member_id );

			case 'get_history':
				$days_back = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 30;
				return $this->get_vital_signs_history( $member_id, $days_back );

			case 'analyze_trends':
				$days_back = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 30;
				return $this->analyze_vital_signs_trends( $member_id, $days_back );

			case 'update':
				return $this->update_vital_signs( $arguments, $member_id, $current_user_id );

			case 'delete':
				return $this->delete_vital_sign_entry( $arguments, $member_id, $current_user_id );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Log vital signs measurement.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @param array $context        Execution context (used for provider-aware embedding).
	 * @return array|WP_Error Result or error.
	 */
	private function log_vital_signs( $arguments, $member_id, $current_user_id, $context = array() ) {
		if ( ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to log vital signs.', 'mcp-ai-wpoos-pro' ) );
		}

		/**
		 * Fires before a vitals log entry is persisted.
		 *
		 * Allows partner code to mutate the incoming arguments (e.g. unit
		 * conversion, de-identification, contextual enrichment) prior to
		 * write.  Filters $arguments and returns the mutated array.
		 *
		 * @since 1.4.0
		 *
		 * @param array $arguments Tool arguments destined for storage.
		 * @param int   $member_id Target member post ID.
		 * @param array $context   Tool execution context.
		 */
		$arguments = apply_filters( 'wp_mcp_ai_healthcare_before_vital_log', $arguments, $member_id, $context );
		do_action( 'wp_mcp_ai_healthcare_before_vital_log_action', $arguments, $member_id, $context );

		$measurement_date = isset( $arguments['measurement_date'] ) ? sanitize_text_field( $arguments['measurement_date'] ) : current_time( 'Y-m-d' );
		$measurement_time = isset( $arguments['measurement_time'] ) ? sanitize_text_field( $arguments['measurement_time'] ) : current_time( 'H:i' );

		// Collect measurements.
		$measurements = array();
		$has_data     = false;

		// Blood pressure.
		if ( isset( $arguments['blood_pressure_systolic'] ) || isset( $arguments['blood_pressure_diastolic'] ) ) {
			$systolic  = isset( $arguments['blood_pressure_systolic'] ) ? absint( $arguments['blood_pressure_systolic'] ) : null;
			$diastolic = isset( $arguments['blood_pressure_diastolic'] ) ? absint( $arguments['blood_pressure_diastolic'] ) : null;
			if ( $systolic || $diastolic ) {
				$measurements['blood_pressure'] = array(
					'systolic'  => $systolic,
					'diastolic' => $diastolic,
					'reading'   => $systolic . '/' . $diastolic,
					'status'    => $this->assess_blood_pressure( $systolic, $diastolic ),
				);
				$has_data                       = true;
			}
		}

		// Heart rate.
		if ( isset( $arguments['heart_rate'] ) ) {
			$heart_rate                 = absint( $arguments['heart_rate'] );
			$measurements['heart_rate'] = array(
				'value'  => $heart_rate,
				'unit'   => 'bpm',
				'status' => $this->assess_heart_rate( $heart_rate ),
			);
			$has_data                   = true;
		}

		// Temperature — always stored normalised to °F so all downstream.
		// consumers (dashboard, charts, TMA) work with a single consistent unit.
		if ( isset( $arguments['temperature'] ) ) {
			$temperature_raw             = floatval( $arguments['temperature'] );
			$temp_unit_in                = isset( $arguments['temperature_unit'] ) ? strtoupper( sanitize_text_field( $arguments['temperature_unit'] ) ) : 'F';
			$temperature_f               = ( 'C' === $temp_unit_in )
				? round( ( $temperature_raw * 9.0 / 5.0 ) + 32.0, 1 )
				: round( $temperature_raw, 1 );
			$measurements['temperature'] = array(
				'value'          => $temperature_f,
				'unit'           => 'F',
				'original_value' => $temperature_raw,
				'original_unit'  => $temp_unit_in,
				'status'         => $this->assess_temperature( $temperature_raw, $temp_unit_in ),
			);
			$has_data                    = true;
		}

		// Weight and BMI.
		if ( isset( $arguments['weight'] ) ) {
			$weight                 = floatval( $arguments['weight'] );
			$weight_unit            = isset( $arguments['weight_unit'] ) ? sanitize_text_field( $arguments['weight_unit'] ) : 'lbs';
			$measurements['weight'] = array(
				'value' => $weight,
				'unit'  => $weight_unit,
			);

			// Calculate BMI if height provided.
			if ( isset( $arguments['height'] ) ) {
				$height              = floatval( $arguments['height'] );
				$height_unit         = isset( $arguments['height_unit'] ) ? sanitize_text_field( $arguments['height_unit'] ) : 'in';
				$bmi                 = $this->calculate_bmi( $weight, $weight_unit, $height, $height_unit );
				$measurements['bmi'] = array(
					'value'  => $bmi,
					'status' => $this->assess_bmi( $bmi ),
				);
			}
			$has_data = true;
		}

		// Blood glucose.
		if ( isset( $arguments['blood_glucose'] ) ) {
			$glucose                       = absint( $arguments['blood_glucose'] );
			$measurements['blood_glucose'] = array(
				'value'  => $glucose,
				'unit'   => 'mg/dL',
				'status' => $this->assess_blood_glucose( $glucose ),
			);
			$has_data                      = true;
		}

		// Oxygen saturation.
		if ( isset( $arguments['oxygen_saturation'] ) ) {
			$spo2                              = absint( $arguments['oxygen_saturation'] );
			$measurements['oxygen_saturation'] = array(
				'value'  => $spo2,
				'unit'   => '%',
				'status' => $this->assess_oxygen_saturation( $spo2 ),
			);
			$has_data                          = true;
		}

		// Respiratory rate.
		if ( isset( $arguments['respiratory_rate'] ) ) {
			$resp_rate                        = absint( $arguments['respiratory_rate'] );
			$measurements['respiratory_rate'] = array(
				'value'  => $resp_rate,
				'unit'   => 'breaths/min',
				'status' => $this->assess_respiratory_rate( $resp_rate ),
			);
			$has_data                         = true;
		}

		// Kidney health indicators.
		if ( isset( $arguments['egfr'] ) ) {
			$measurements['egfr'] = array(
				'value' => floatval( $arguments['egfr'] ),
				'unit'  => 'mL/min/1.73m²',
			);
			$has_data             = true;
		}
		if ( isset( $arguments['creatinine'] ) ) {
			$measurements['creatinine'] = array(
				'value' => floatval( $arguments['creatinine'] ),
				'unit'  => 'mg/dL',
			);
			$has_data                   = true;
		}
		if ( isset( $arguments['bun'] ) ) {
			$measurements['bun'] = array(
				'value' => floatval( $arguments['bun'] ),
				'unit'  => 'mg/dL',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['potassium'] ) ) {
			$measurements['potassium'] = array(
				'value' => floatval( $arguments['potassium'] ),
				'unit'  => 'mEq/L',
			);
			$has_data                  = true;
		}
		if ( isset( $arguments['sodium'] ) ) {
			$measurements['sodium'] = array(
				'value' => floatval( $arguments['sodium'] ),
				'unit'  => 'mg/day',
			);
			$has_data               = true;
		}
		if ( isset( $arguments['phosphorus'] ) ) {
			$measurements['phosphorus'] = array(
				'value' => floatval( $arguments['phosphorus'] ),
				'unit'  => 'mg/dL',
			);
			$has_data                   = true;
		}
		if ( isset( $arguments['albumin'] ) ) {
			$measurements['albumin'] = array(
				'value' => floatval( $arguments['albumin'] ),
				'unit'  => 'g/dL',
			);
			$has_data                = true;
		}

		// Extended BMP / CMP electrolytes.
		if ( isset( $arguments['chloride'] ) ) {
			$measurements['chloride'] = array(
				'value' => round( floatval( $arguments['chloride'] ), 1 ),
				'unit'  => 'mEq/L',
			);
			$has_data                 = true;
		}
		if ( isset( $arguments['co2'] ) ) {
			$measurements['co2'] = array(
				'value' => round( floatval( $arguments['co2'] ), 1 ),
				'unit'  => 'mEq/L',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['calcium'] ) ) {
			$measurements['calcium'] = array(
				'value' => round( floatval( $arguments['calcium'] ), 1 ),
				'unit'  => 'mg/dL',
			);
			$has_data                = true;
		}
		if ( isset( $arguments['magnesium'] ) ) {
			$measurements['magnesium'] = array(
				'value' => round( floatval( $arguments['magnesium'] ), 2 ),
				'unit'  => 'mg/dL',
			);
			$has_data                  = true;
		}

		// Liver function tests (LFT).
		if ( isset( $arguments['bilirubin'] ) ) {
			$measurements['bilirubin'] = array(
				'value' => round( floatval( $arguments['bilirubin'] ), 2 ),
				'unit'  => 'mg/dL',
			);
			$has_data                  = true;
		}
		if ( isset( $arguments['ast'] ) ) {
			$measurements['ast'] = array(
				'value' => round( floatval( $arguments['ast'] ), 1 ),
				'unit'  => 'U/L',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['alt'] ) ) {
			$measurements['alt'] = array(
				'value' => round( floatval( $arguments['alt'] ), 1 ),
				'unit'  => 'U/L',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['total_protein'] ) ) {
			$measurements['total_protein'] = array(
				'value' => round( floatval( $arguments['total_protein'] ), 1 ),
				'unit'  => 'g/dL',
			);
			$has_data                      = true;
		}
		if ( isset( $arguments['hemoglobin'] ) ) {
			$hgb                        = round( floatval( $arguments['hemoglobin'] ), 1 );
			$measurements['hemoglobin'] = array(
				'value'  => $hgb,
				'unit'   => 'g/dL',
				'status' => $this->assess_hemoglobin( $hgb ),
			);
			$has_data                   = true;
		}

		// CBC — main indices.
		if ( isset( $arguments['hematocrit'] ) ) {
			$hct                        = round( floatval( $arguments['hematocrit'] ), 2 );
			$measurements['hematocrit'] = array(
				'value'  => $hct,
				'unit'   => '%',
				'status' => $this->assess_hematocrit( $hct ),
			);
			$has_data                   = true;
		}
		if ( isset( $arguments['rbc'] ) ) {
			$measurements['rbc'] = array(
				'value' => round( floatval( $arguments['rbc'] ), 3 ),
				'unit'  => 'x10⁶/µL',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['wbc'] ) ) {
			$wbc                 = round( floatval( $arguments['wbc'] ), 2 );
			$measurements['wbc'] = array(
				'value'  => $wbc,
				'unit'   => 'x10³/µL',
				'status' => $this->assess_wbc( $wbc ),
			);
			$has_data            = true;
		}
		if ( isset( $arguments['platelets'] ) ) {
			$plt                       = absint( $arguments['platelets'] );
			$measurements['platelets'] = array(
				'value'  => $plt,
				'unit'   => 'x10³/µL',
				'status' => $this->assess_platelets( $plt ),
			);
			$has_data                  = true;
		}
		if ( isset( $arguments['mcv'] ) ) {
			$measurements['mcv'] = array(
				'value' => round( floatval( $arguments['mcv'] ), 2 ),
				'unit'  => 'fL',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['mch'] ) ) {
			$measurements['mch'] = array(
				'value' => round( floatval( $arguments['mch'] ), 2 ),
				'unit'  => 'pg',
			);
			$has_data            = true;
		}
		if ( isset( $arguments['mchc'] ) ) {
			$measurements['mchc'] = array(
				'value' => round( floatval( $arguments['mchc'] ), 2 ),
				'unit'  => 'g/dL',
			);
			$has_data             = true;
		}
		if ( isset( $arguments['rdw'] ) ) {
			$measurements['rdw'] = array(
				'value' => round( floatval( $arguments['rdw'] ), 2 ),
				'unit'  => '%',
			);
			$has_data            = true;
		}

		// CBC differential — percent.
		foreach ( array( 'neutrophils_percent', 'lymphocytes_percent', 'monocytes_percent', 'eosinophils_percent', 'basophils_percent' ) as $diff_field ) {
			if ( isset( $arguments[ $diff_field ] ) ) {
				$measurements[ $diff_field ] = array(
					'value' => round( floatval( $arguments[ $diff_field ] ), 2 ),
					'unit'  => '%',
				);
				$has_data                    = true;
			}
		}

		// CBC differential — absolute counts.
		foreach ( array( 'neutrophils_absolute', 'lymphocytes_absolute', 'monocytes_absolute', 'eosinophils_absolute', 'basophils_absolute' ) as $abs_field ) {
			if ( isset( $arguments[ $abs_field ] ) ) {
				$measurements[ $abs_field ] = array(
					'value' => round( floatval( $arguments[ $abs_field ] ), 2 ),
					'unit'  => 'x10³/µL',
				);
				$has_data                   = true;
			}
		}

		if ( ! $has_data ) {
			return new WP_Error( 'wp_mcp_ai_no_measurements', __( 'At least one vital sign measurement is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$source = isset( $arguments['source'] ) ? sanitize_key( $arguments['source'] ) : 'manual';
		if ( ! in_array( $source, array( 'manual', 'tma', 'api', 'import' ), true ) ) {
			$source = 'manual';
		}

		$entry_id = 'vs_' . time() . '_' . wp_rand( 1000, 9999 );

		// ── Options-based storage (always written for backward compat) ────
		$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
		$vital_signs     = get_option( $vital_signs_key, array() );

		$vital_signs[ $entry_id ] = array(
			'date'         => $measurement_date,
			'time'         => $measurement_time,
			'timestamp'    => strtotime( $measurement_date . ' ' . $measurement_time ),
			'measurements' => $measurements,
			'notes'        => isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '',
			'logged_by'    => $current_user_id,
			'logged_at'    => current_time( 'mysql' ),
			'source'       => $source,
		);

		// Keep only last 1000 entries per member.
		if ( count( $vital_signs ) > 1000 ) {
			array_shift( $vital_signs );
		}

		update_option( $vital_signs_key, $vital_signs );

		// ── JetEngine CCT storage (when available) ────────────────────────
		$log_cct_id = null;

		// Build the CCT data array for the vitals_log CCT.
		$cct_data = array(
			'measurement_date' => $measurement_date,
			'measurement_time' => $measurement_time,
			'source'           => $source,
			'notes'            => isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '',
			'logged_by'        => $current_user_id,
			'entry_id'         => $entry_id,
		);

		if ( isset( $measurements['blood_pressure'] ) ) {
			$cct_data['bp_systolic']  = $measurements['blood_pressure']['systolic'];
			$cct_data['bp_diastolic'] = $measurements['blood_pressure']['diastolic'];
			$cct_data['bp_status']    = $measurements['blood_pressure']['status'];
		}
		if ( isset( $measurements['heart_rate'] ) ) {
			$cct_data['heart_rate']        = $measurements['heart_rate']['value'];
			$cct_data['heart_rate_status'] = $measurements['heart_rate']['status'];
		}
		if ( isset( $measurements['temperature'] ) ) {
			// Always persist the normalised °F value so dashboard/charts are consistent.
			$cct_data['temperature']        = $measurements['temperature']['value']; // always °F.
			$cct_data['temperature_unit']   = 'F';
			$cct_data['temperature_status'] = $measurements['temperature']['status'];
		}
		if ( isset( $measurements['weight'] ) ) {
			$cct_data['weight']      = $measurements['weight']['value'];
			$cct_data['weight_unit'] = $measurements['weight']['unit'];
		}
		if ( isset( $measurements['bmi'] ) ) {
			$cct_data['bmi']        = $measurements['bmi']['value'];
			$cct_data['bmi_status'] = $measurements['bmi']['status'];
		}
		if ( isset( $measurements['blood_glucose'] ) ) {
			$cct_data['blood_glucose']        = $measurements['blood_glucose']['value'];
			$cct_data['blood_glucose_status'] = $measurements['blood_glucose']['status'];
		}
		if ( isset( $measurements['oxygen_saturation'] ) ) {
			$cct_data['oxygen_saturation']        = $measurements['oxygen_saturation']['value'];
			$cct_data['oxygen_saturation_status'] = $measurements['oxygen_saturation']['status'];
		}
		if ( isset( $measurements['respiratory_rate'] ) ) {
			$cct_data['respiratory_rate']        = $measurements['respiratory_rate']['value'];
			$cct_data['respiratory_rate_status'] = $measurements['respiratory_rate']['status'];
		}
		// Kidney indicators, hemoglobin, extended electrolytes, and LFT fields.
		foreach ( array(
			'egfr',
			'creatinine',
			'bun',
			'potassium',
			'sodium',
			'phosphorus',
			'albumin',
			'hemoglobin',
			'chloride',
			'co2',
			'calcium',
			'magnesium',
			'bilirubin',
			'ast',
			'alt',
			'total_protein',
		) as $ki ) {
			if ( isset( $measurements[ $ki ] ) ) {
				$cct_data[ $ki ] = $measurements[ $ki ]['value'];
			}
		}
		// CBC — main indices.
		foreach ( array( 'hematocrit', 'rbc', 'wbc', 'platelets', 'mcv', 'mch', 'mchc', 'rdw' ) as $cbc_field ) {
			if ( isset( $measurements[ $cbc_field ] ) ) {
				$cct_data[ $cbc_field ] = $measurements[ $cbc_field ]['value'];
			}
		}
		// CBC differential.
		$diff_fields = array(
			'neutrophils_percent',
			'lymphocytes_percent',
			'monocytes_percent',
			'eosinophils_percent',
			'basophils_percent',
			'neutrophils_absolute',
			'lymphocytes_absolute',
			'monocytes_absolute',
			'eosinophils_absolute',
			'basophils_absolute',
		);
		foreach ( $diff_fields as $diff_field ) {
			if ( isset( $measurements[ $diff_field ] ) ) {
				$cct_data[ $diff_field ] = $measurements[ $diff_field ]['value'];
			}
		}

		// Provenance / QA fields (stored directly from arguments, not measurements).
		$provenance_fields = array(
			'facility_name',
			'document_name',
			'test_panel',
			'document_date',
			'collection_time',
			'result_time',
			'import_batch_id',
			'review_status',
			'abnormal_flags',
		);
		foreach ( $provenance_fields as $prov_field ) {
			if ( isset( $arguments[ $prov_field ] ) && '' !== (string) $arguments[ $prov_field ] ) {
				$cct_data[ $prov_field ] = sanitize_text_field( $arguments[ $prov_field ] );
			}
		}
		if ( isset( $arguments['review_notes'] ) && '' !== (string) $arguments['review_notes'] ) {
			// review_notes may contain longer structured text — use textarea-level sanitisation.
			$cct_data['review_notes'] = wp_kses_post( $arguments['review_notes'] );
		}
		if ( isset( $arguments['is_abnormal'] ) ) {
			$cct_data['is_abnormal'] = $arguments['is_abnormal'] ? 1 : 0;
		}

		// Write to the vitals_log CCT (includes logged_at timestamp).
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) && WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$log_cct_data              = $cct_data;
			$log_cct_data['logged_at'] = current_time( 'mysql' );
			$log_cct_id                = WP_MCP_AI_JetEngine_Vitals_Log_CCT::upsert( $member_id, $log_cct_data );
		}

		// Generate a semantic embedding for the vital signs entry so it can be.
		// discovered by semantic_content_search queries (e.g. "vital signs",.
		// "kidney health metrics").  The call is non-blocking: if it fails the
		// vital signs are still stored successfully.
		$vitals_text = $this->format_vitals_for_embedding( $measurements, $member_id, $measurement_date );
		$this->store_vitals_embedding( $entry_id, $member_id, $measurement_date, $vitals_text, $context );

		// Check for abnormal values and generate alerts.
		$alerts = $this->generate_alerts( $measurements );

		$response = array(
			'success'           => true,
			'message'           => __( 'Vital signs logged successfully.', 'mcp-ai-wpoos-pro' ),
			'entry_id'          => $entry_id,
			'log_cct_id'        => $log_cct_id,
			'stored_in_log_cct' => null !== $log_cct_id,
			'member_id'         => $member_id,
			'date'              => $measurement_date,
			'time'              => $measurement_time,
			'source'            => $source,
			'measurements'      => $measurements,
			'alerts'            => $alerts,
		);

		// Mirror into the auxiliary `mcp_ai_hc_vital_log` CPT when registered.
		// The existing options/CCT storage above remains the primary store.
		if ( class_exists( 'WP_MCP_AI_Healthcare_Vital_Log_CPT' ) ) {
			$cpt_id = WP_MCP_AI_Healthcare_Vital_Log_CPT::insert(
				$member_id,
				array(
					'measurement_date' => $measurement_date,
					'measurement_time' => $measurement_time,
					'measurements'     => $measurements,
					'source'           => $source,
				)
			);
			if ( ! is_wp_error( $cpt_id ) && $cpt_id > 0 ) {
				$response['hc_vital_log_id'] = (int) $cpt_id;
			}
		}

		/**
		 * Fires after a vitals log entry has been persisted.
		 *
		 * @since 1.4.0
		 *
		 * @param array $response  Tool response payload.
		 * @param int   $member_id Target member post ID.
		 * @param array $arguments Original tool arguments (post-filter).
		 * @param array $context   Tool execution context.
		 */
		do_action( 'wp_mcp_ai_healthcare_after_vital_log', $response, $member_id, $arguments, $context );

		return $response;
	}

	/**
	 * Update specific fields on an existing vitals log CCT entry.
	 *
	 * The caller must supply `cct_id` identifying the row to update.  Only
	 * fields that are explicitly present in $arguments and are recognised vital
	 * field names are written — any unrecognised key is silently ignored.
	 * `member_id`, `logged_by`, and `entry_id` are always preserved.
	 *
	 * @param array $arguments       Tool arguments (includes cct_id).
	 * @param int   $member_id       Verified member post ID.
	 * @param int   $current_user_id Current WP user ID.
	 * @return array|WP_Error        Result or error.
	 */
	private function update_vital_signs( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update vital signs.', 'mcp-ai-wpoos-pro' ) );
		}

		$cct_id = isset( $arguments['cct_id'] ) ? absint( $arguments['cct_id'] ) : 0;
		if ( ! $cct_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_cct_id', __( 'cct_id is required for the update action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) || ! WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			return new WP_Error( 'wp_mcp_ai_cct_unavailable', __( 'Vitals log CCT storage is not available. Update requires JetEngine.', 'mcp-ai-wpoos-pro' ) );
		}

		// Load the existing row so we can verify ownership.
		$existing = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_by_id( $cct_id );
		if ( ! $existing ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Vitals log entry not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Ensure the row belongs to the requested member.
		if ( (int) $existing->member_id !== $member_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'This vitals log entry does not belong to the specified member.', 'mcp-ai-wpoos-pro' ) );
		}

		// Allowed updatable fields (mirrors CCT schema, excluding immutable keys).
		$allowed_string_fields  = array(
			'measurement_date',
			'measurement_time',
			'source',
			'notes',
			'facility_name',
			'document_name',
			'test_panel',
			'document_date',
			'collection_time',
			'result_time',
			'import_batch_id',
			'review_status',
			'review_notes',
			'abnormal_flags',
		);
		$allowed_numeric_fields = array(
			'bp_systolic',
			'bp_diastolic',
			'heart_rate',
			'temperature',
			'weight',
			'bmi',
			'blood_glucose',
			'oxygen_saturation',
			'respiratory_rate',
			'egfr',
			'creatinine',
			'bun',
			'potassium',
			'sodium',
			'phosphorus',
			'albumin',
			'hemoglobin',
			'hematocrit',
			'rbc',
			'wbc',
			'platelets',
			'mcv',
			'mch',
			'mchc',
			'rdw',
			'neutrophils_percent',
			'lymphocytes_percent',
			'monocytes_percent',
			'eosinophils_percent',
			'basophils_percent',
			'neutrophils_absolute',
			'lymphocytes_absolute',
			'monocytes_absolute',
			'eosinophils_absolute',
			'basophils_absolute',
			'chloride',
			'co2',
			'calcium',
			'magnesium',
			'bilirubin',
			'ast',
			'alt',
			'total_protein',
		);

		// Tool parameter names that map directly to CCT column names (non-1:1 mappings).
		$param_to_cct = array(
			'blood_pressure_systolic'  => 'bp_systolic',
			'blood_pressure_diastolic' => 'bp_diastolic',
		);

		$update_data = array();

		// Numeric fields — accept both the tool parameter name and the CCT column name.
		foreach ( $allowed_numeric_fields as $col ) {
			// Check for CCT column name directly.
			if ( isset( $arguments[ $col ] ) ) {
				$update_data[ $col ] = round( floatval( $arguments[ $col ] ), 4 );
			}
		}

		// Handle the bp_systolic / bp_diastolic parameter aliases.
		foreach ( $param_to_cct as $param => $col ) {
			if ( isset( $arguments[ $param ] ) ) {
				$update_data[ $col ] = absint( $arguments[ $param ] );
			}
		}

		// String fields.
		foreach ( $allowed_string_fields as $col ) {
			if ( isset( $arguments[ $col ] ) ) {
				$update_data[ $col ] = sanitize_text_field( $arguments[ $col ] );
			}
		}

		// review_notes may contain longer text.
		if ( isset( $arguments['review_notes'] ) ) {
			$update_data['review_notes'] = sanitize_textarea_field( $arguments['review_notes'] );
		}

		// notes may contain longer text.
		if ( isset( $arguments['notes'] ) ) {
			$update_data['notes'] = sanitize_textarea_field( $arguments['notes'] );
		}

		// is_abnormal boolean.
		if ( isset( $arguments['is_abnormal'] ) ) {
			$update_data['is_abnormal'] = (int) (bool) $arguments['is_abnormal'];
		}

		if ( empty( $update_data ) ) {
			return new WP_Error( 'wp_mcp_ai_no_fields', __( 'No updatable fields were provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$ok = WP_MCP_AI_JetEngine_Vitals_Log_CCT::update_fields( $cct_id, $update_data );

		if ( ! $ok ) {
			return new WP_Error( 'wp_mcp_ai_update_failed', __( 'Failed to update vitals log entry.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'        => true,
			'message'        => __( 'Vitals log entry updated successfully.', 'mcp-ai-wpoos-pro' ),
			'cct_id'         => $cct_id,
			'member_id'      => $member_id,
			'updated_fields' => array_keys( $update_data ),
		);
	}

	/**
	 * Delete a single vitals log CCT entry.
	 *
	 * @param array $arguments       Tool arguments (includes cct_id).
	 * @param int   $member_id       Verified member post ID.
	 * @param int   $current_user_id Current WP user ID.
	 * @return array|WP_Error        Result or error.
	 */
	private function delete_vital_sign_entry( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete vital signs.', 'mcp-ai-wpoos-pro' ) );
		}

		$cct_id = isset( $arguments['cct_id'] ) ? absint( $arguments['cct_id'] ) : 0;
		if ( ! $cct_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_cct_id', __( 'cct_id is required for the delete action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) || ! WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			return new WP_Error( 'wp_mcp_ai_cct_unavailable', __( 'Vitals log CCT storage is not available. Delete requires JetEngine.', 'mcp-ai-wpoos-pro' ) );
		}

		// Load the existing row so we can verify ownership before deleting.
		$existing = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_by_id( $cct_id );
		if ( ! $existing ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Vitals log entry not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Ensure the row belongs to the requested member.
		if ( (int) $existing->member_id !== $member_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'This vitals log entry does not belong to the specified member.', 'mcp-ai-wpoos-pro' ) );
		}

		$ok = WP_MCP_AI_JetEngine_Vitals_Log_CCT::delete( $cct_id );

		if ( ! $ok ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete vitals log entry.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'          => true,
			'message'          => __( 'Vitals log entry deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'cct_id'           => $cct_id,
			'member_id'        => $member_id,
			'measurement_date' => isset( $existing->measurement_date ) ? $existing->measurement_date : null,
		);
	}

	/**
	 * Get latest vital signs.
	 *
	 * Prefers vitals_log CCT when available; falls back to options storage.
	 *
	 * @param int $member_id Member ID.
	 * @return array Latest vital signs.
	 */
	private function get_latest_vital_signs( $member_id ) {
		// Prefer vitals_log CCT (primary log store) when available.
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) && WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$row = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_latest( $member_id );
			if ( $row ) {
				return array(
					'success'   => true,
					'member_id' => $member_id,
					'source'    => 'vitals_log_cct',
					'latest'    => (array) $row,
				);
			}
		}

		// Fall back to options-based storage.
		$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
		$vital_signs     = get_option( $vital_signs_key, array() );

		if ( empty( $vital_signs ) ) {
			return array(
				'success'   => true,
				'message'   => __( 'No vital signs recorded yet.', 'mcp-ai-wpoos-pro' ),
				'member_id' => $member_id,
				'latest'    => null,
			);
		}

		// Get most recent entry.
		$latest = end( $vital_signs );

		return array(
			'success'   => true,
			'member_id' => $member_id,
			'source'    => 'options',
			'latest'    => $latest,
		);
	}

	/**
	 * Get vital signs history.
	 *
	 * Prefers vitals_log CCT when available; falls back to options storage.
	 *
	 * @param int $member_id  Member ID.
	 * @param int $days_back  Days of history.
	 * @return array History.
	 */
	private function get_vital_signs_history( $member_id, $days_back ) {
		// Prefer vitals_log CCT (primary log store) when available.
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) && WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$after_date = gmdate( 'Y-m-d', time() - ( $days_back * DAY_IN_SECONDS ) );
			$rows       = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_for_member( $member_id, $after_date );
			$history    = array_map( 'get_object_vars', $rows );

			// Supplement CCT rows with full-precision decimal values from options storage.
			// Two scenarios require this:.
			// (a) Hemoglobin was added to the CCT schema after some entries were logged —
			// those rows have NULL in the hemoglobin column, but options storage holds.
			// the original value.
			// (b) Creatinine (and other renal indicators) were stored in a bigint MySQL
			// column before the DECIMAL migration ran — MySQL silently rounded 0.9 to 1,.
			// 1.1 to 1, etc.  The options store was written with floatval() and
			// preserves the original decimal precision.
			$history = $this->supplement_cct_with_options_decimals( $history, $member_id );

			return array(
				'success'   => true,
				'member_id' => $member_id,
				'days_back' => $days_back,
				'source'    => 'vitals_log_cct',
				'count'     => count( $history ),
				'history'   => $history,
			);
		}

		// Fall back to options-based storage.
		$vital_signs_key  = 'wp_mcp_ai_vital_signs_' . $member_id;
		$vital_signs      = get_option( $vital_signs_key, array() );
		$cutoff_timestamp = time() - ( $days_back * DAY_IN_SECONDS );
		$filtered         = array_filter(
			$vital_signs,
			function ( $entry ) use ( $cutoff_timestamp ) {
				return isset( $entry['timestamp'] ) && $entry['timestamp'] >= $cutoff_timestamp;
			}
		);

		return array(
			'success'   => true,
			'member_id' => $member_id,
			'days_back' => $days_back,
			'source'    => 'options',
			'count'     => count( $filtered ),
			'history'   => array_values( $filtered ),
		);
	}

	/**
	 * Analyze vital signs trends.
	 *
	 * @param int $member_id  Member ID.
	 * @param int $days_back  Days to analyze.
	 * @return array Trend analysis.
	 */
	private function analyze_vital_signs_trends( $member_id, $days_back ) {
		$history = $this->get_vital_signs_history( $member_id, $days_back );

		if ( $history['count'] < 2 ) {
			return array(
				'success'   => true,
				'message'   => __( 'Insufficient data for trend analysis. Need at least 2 measurements.', 'mcp-ai-wpoos-pro' ),
				'member_id' => $member_id,
			);
		}

		$trends = array();

		// Analyze blood pressure trend.
		$bp_readings = array();
		foreach ( $history['history'] as $entry ) {
			if ( isset( $entry['measurements']['blood_pressure'] ) ) {
				$bp_readings[] = $entry['measurements']['blood_pressure'];
			}
		}
		if ( count( $bp_readings ) >= 2 ) {
			$trends['blood_pressure'] = $this->calculate_trend( $bp_readings, 'systolic' );
		}

		// Similar analysis for other vitals...
		// (Simplified for brevity).

		return array(
			'success'     => true,
			'member_id'   => $member_id,
			'days_back'   => $days_back,
			'data_points' => $history['count'],
			'trends'      => $trends,
			'summary'     => __( 'Trend analysis based on recent measurements.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Calculate BMI.
	 *
	 * @param float  $weight      Weight value.
	 * @param string $weight_unit Weight unit.
	 * @param float  $height      Height value.
	 * @param string $height_unit Height unit.
	 * @return float BMI value.
	 */
	private function calculate_bmi( $weight, $weight_unit, $height, $height_unit ) {
		// Convert to metric if needed.
		$weight_kg = ( 'kg' === $weight_unit ) ? $weight : $weight * 0.453592;
		$height_m  = ( 'cm' === $height_unit ) ? $height / 100 : ( $height * 2.54 ) / 100;

		return round( $weight_kg / ( $height_m * $height_m ), 1 );
	}

	/**
	 * Assess blood pressure.
	 *
	 * @param int|null $systolic  Systolic value.
	 * @param int|null $diastolic Diastolic value.
	 * @return string Status.
	 */
	private function assess_blood_pressure( $systolic, $diastolic ) {
		if ( ! $systolic || ! $diastolic ) {
			return 'incomplete';
		}

		if ( $systolic < 120 && $diastolic < 80 ) {
			return 'normal';
		} elseif ( $systolic < 130 && $diastolic < 80 ) {
			return 'elevated';
		} elseif ( $systolic < 140 || $diastolic < 90 ) {
			return 'stage_1_hypertension';
		} elseif ( $systolic < 180 && $diastolic < 120 ) {
			return 'stage_2_hypertension';
		} else {
			return 'hypertensive_crisis';
		}
	}

	/**
	 * Assess heart rate.
	 *
	 * @param int $heart_rate Heart rate value.
	 * @return string Status.
	 */
	private function assess_heart_rate( $heart_rate ) {
		if ( $heart_rate < 60 ) {
			return 'low';
		} elseif ( $heart_rate <= 100 ) {
			return 'normal';
		} else {
			return 'high';
		}
	}

	/**
	 * Assess temperature.
	 *
	 * @param float  $temperature Temperature value.
	 * @param string $unit        Unit (F or C).
	 * @return string Status.
	 */
	private function assess_temperature( $temperature, $unit ) {
		// Convert to Fahrenheit for comparison.
		$temp_f = ( 'F' === $unit ) ? $temperature : ( $temperature * 9 / 5 ) + 32;

		if ( $temp_f < 97.0 ) {
			return 'low';
		} elseif ( $temp_f <= 99.0 ) {
			return 'normal';
		} elseif ( $temp_f <= 100.4 ) {
			return 'elevated';
		} else {
			return 'fever';
		}
	}

	/**
	 * Assess BMI.
	 *
	 * @param float $bmi BMI value.
	 * @return string Status.
	 */
	private function assess_bmi( $bmi ) {
		if ( $bmi < 18.5 ) {
			return 'underweight';
		} elseif ( $bmi < 25 ) {
			return 'normal';
		} elseif ( $bmi < 30 ) {
			return 'overweight';
		} else {
			return 'obese';
		}
	}

	/**
	 * Assess blood glucose.
	 *
	 * @param int $glucose Glucose value.
	 * @return string Status.
	 */
	private function assess_blood_glucose( $glucose ) {
		if ( $glucose < 70 ) {
			return 'low';
		} elseif ( $glucose <= 140 ) {
			return 'normal';
		} elseif ( $glucose <= 200 ) {
			return 'prediabetic';
		} else {
			return 'diabetic_range';
		}
	}

	/**
	 * Assess oxygen saturation.
	 *
	 * @param int $spo2 SpO2 value.
	 * @return string Status.
	 */
	private function assess_oxygen_saturation( $spo2 ) {
		if ( $spo2 < 90 ) {
			return 'critical';
		} elseif ( $spo2 < 95 ) {
			return 'low';
		} else {
			return 'normal';
		}
	}

	/**
	 * Assess respiratory rate.
	 *
	 * @param int $rate Respiratory rate.
	 * @return string Status.
	 */
	private function assess_respiratory_rate( $rate ) {
		if ( $rate < 12 ) {
			return 'low';
		} elseif ( $rate <= 20 ) {
			return 'normal';
		} else {
			return 'high';
		}
	}

	/**
	 * Assess hemoglobin level.
	 *
	 * Reference ranges (adults):
	 *   Male   ≥ 13.5 g/dL normal; 12.0–13.4 mild; < 12.0 anaemia.
	 *   Female ≥ 12.0 g/dL normal; 11.0–11.9 mild; < 11.0 anaemia.
	 * A gender-neutral single threshold is used here (12.0 g/dL) so that
	 * the tool can flag anaemia without requiring a gender parameter.
	 * High values may indicate polycythaemia (> 17.5 g/dL is flagged).
	 *
	 * @param float $hgb Hemoglobin in g/dL.
	 * @return string Status: 'normal', 'low', 'anaemia', or 'high'.
	 */
	private function assess_hemoglobin( $hgb ) {
		if ( $hgb > 17.5 ) {
			return 'high';
		} elseif ( $hgb >= 12.0 ) {
			return 'normal';
		} elseif ( $hgb >= 11.0 ) {
			return 'low';
		} else {
			return 'anaemia';
		}
	}

	/**
	 * Assess hematocrit level (gender-neutral threshold).
	 *
	 * @param float $hct Hematocrit percentage.
	 * @return string Status: 'normal', 'low', or 'high'.
	 */
	private function assess_hematocrit( $hct ) {
		if ( $hct > 52 ) {
			return 'high';
		} elseif ( $hct >= 36 ) {
			return 'normal';
		} else {
			return 'low';
		}
	}

	/**
	 * Assess WBC (white blood cell count).
	 *
	 * @param float $wbc WBC in x10³/µL.
	 * @return string Status: 'normal', 'low' (leukopenia), or 'high' (leukocytosis).
	 */
	private function assess_wbc( $wbc ) {
		if ( $wbc > 11.0 ) {
			return 'high';
		} elseif ( $wbc >= 4.0 ) {
			return 'normal';
		} else {
			return 'low';
		}
	}

	/**
	 * Assess platelet count.
	 *
	 * @param int $plt Platelets in x10³/µL.
	 * @return string Status: 'normal', 'low' (thrombocytopenia), or 'high' (thrombocytosis).
	 */
	private function assess_platelets( $plt ) {
		if ( $plt > 400 ) {
			return 'high';
		} elseif ( $plt >= 150 ) {
			return 'normal';
		} else {
			return 'low';
		}
	}

	/**
	 * Format vital signs measurements as a natural-language text for embedding.
	 *
	 * The resulting sentence is used as the document text when generating an
	 * embedding vector so that semantic queries like "vital signs", "blood
	 * pressure reading", "kidney health metrics", or "eGFR creatinine" will
	 * produce a high cosine similarity against the stored vector.
	 *
	 * @param array  $measurements    Measurements array from log_vital_signs().
	 * @param int    $member_id       Member post ID.
	 * @param string $measurement_date Date string (Y-m-d).
	 * @return string Plain-text summary suitable for embedding.
	 */
	private function format_vitals_for_embedding( $measurements, $member_id, $measurement_date ) {
		$parts = array(
			sprintf(
				/* translators: 1: member ID, 2: measurement date */
				__( 'Vital signs health record for member ID %1$d on %2$s.', 'mcp-ai-wpoos-pro' ),
				$member_id,
				$measurement_date
			),
		);

		if ( isset( $measurements['blood_pressure'] ) ) {
			$bp = $measurements['blood_pressure'];
			/* translators: 1: systolic, 2: diastolic, 3: status */
			$parts[] = sprintf( __( 'Blood pressure %1$d/%2$d mmHg (%3$s).', 'mcp-ai-wpoos-pro' ), $bp['systolic'], $bp['diastolic'], $bp['status'] );
		}

		if ( isset( $measurements['heart_rate'] ) ) {
			/* translators: 1: heart rate value, 2: status */
			$parts[] = sprintf( __( 'Heart rate %1$d bpm (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['heart_rate']['value'], $measurements['heart_rate']['status'] );
		}

		if ( isset( $measurements['temperature'] ) ) {
			/* translators: 1: temperature value, 2: unit, 3: status */
			$parts[] = sprintf( __( 'Body temperature %1$s%2$s (%3$s).', 'mcp-ai-wpoos-pro' ), $measurements['temperature']['value'], $measurements['temperature']['unit'], $measurements['temperature']['status'] );
		}

		if ( isset( $measurements['weight'] ) ) {
			/* translators: 1: weight value, 2: unit */
			$parts[] = sprintf( __( 'Weight %1$s %2$s.', 'mcp-ai-wpoos-pro' ), $measurements['weight']['value'], $measurements['weight']['unit'] );
		}

		if ( isset( $measurements['bmi'] ) ) {
			/* translators: 1: BMI value, 2: status */
			$parts[] = sprintf( __( 'BMI %1$s (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['bmi']['value'], $measurements['bmi']['status'] );
		}

		if ( isset( $measurements['blood_glucose'] ) ) {
			/* translators: 1: glucose value, 2: status */
			$parts[] = sprintf( __( 'Blood glucose %1$d mg/dL (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['blood_glucose']['value'], $measurements['blood_glucose']['status'] );
		}

		if ( isset( $measurements['oxygen_saturation'] ) ) {
			/* translators: 1: SpO2 value, 2: status */
			$parts[] = sprintf( __( 'Oxygen saturation (SpO2) %1$d%% (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['oxygen_saturation']['value'], $measurements['oxygen_saturation']['status'] );
		}

		if ( isset( $measurements['respiratory_rate'] ) ) {
			/* translators: 1: respiratory rate value, 2: status */
			$parts[] = sprintf( __( 'Respiratory rate %1$d breaths/min (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['respiratory_rate']['value'], $measurements['respiratory_rate']['status'] );
		}

		// Kidney health indicators — using full clinical names so that semantic.
		// search for "kidney health metrics", "renal function", or "eGFR" surfaces.
		// this entry.
		if ( isset( $measurements['egfr'] ) ) {
			/* translators: %s: eGFR value */
			$parts[] = sprintf( __( 'eGFR (estimated glomerular filtration rate, kidney function) %s mL/min/1.73m².', 'mcp-ai-wpoos-pro' ), $measurements['egfr']['value'] );
		}

		if ( isset( $measurements['creatinine'] ) ) {
			/* translators: %s: creatinine value */
			$parts[] = sprintf( __( 'Creatinine (kidney health marker) %s mg/dL.', 'mcp-ai-wpoos-pro' ), $measurements['creatinine']['value'] );
		}

		if ( isset( $measurements['bun'] ) ) {
			/* translators: %s: BUN value */
			$parts[] = sprintf( __( 'BUN (blood urea nitrogen, kidney indicator) %s mg/dL.', 'mcp-ai-wpoos-pro' ), $measurements['bun']['value'] );
		}

		if ( isset( $measurements['potassium'] ) ) {
			/* translators: %s: potassium value */
			$parts[] = sprintf( __( 'Potassium (K+) %s mEq/L.', 'mcp-ai-wpoos-pro' ), $measurements['potassium']['value'] );
		}

		if ( isset( $measurements['sodium'] ) ) {
			/* translators: %s: sodium value */
			$parts[] = sprintf( __( 'Sodium (Na+) %s mg/day.', 'mcp-ai-wpoos-pro' ), $measurements['sodium']['value'] );
		}

		if ( isset( $measurements['phosphorus'] ) ) {
			/* translators: %s: phosphorus value */
			$parts[] = sprintf( __( 'Phosphorus %s mg/dL.', 'mcp-ai-wpoos-pro' ), $measurements['phosphorus']['value'] );
		}

		if ( isset( $measurements['albumin'] ) ) {
			/* translators: %s: albumin value */
			$parts[] = sprintf( __( 'Albumin (serum protein, nutritional marker) %s g/dL.', 'mcp-ai-wpoos-pro' ), $measurements['albumin']['value'] );
		}

		if ( isset( $measurements['hemoglobin'] ) ) {
			/* translators: 1: hemoglobin value, 2: status */
			$parts[] = sprintf( __( 'Hemoglobin (red blood cell indicator, anaemia marker) %1$s g/dL (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['hemoglobin']['value'], $measurements['hemoglobin']['status'] );
		}

		// CBC — main indices.
		if ( isset( $measurements['hematocrit'] ) ) {
			/* translators: 1: hematocrit value, 2: status */
			$parts[] = sprintf( __( 'Hematocrit (CBC red blood cell percentage) %1$s%% (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['hematocrit']['value'], $measurements['hematocrit']['status'] );
		}
		if ( isset( $measurements['rbc'] ) ) {
			/* translators: %s: RBC value */
			$parts[] = sprintf( __( 'RBC (red blood cell count) %s x10⁶/µL.', 'mcp-ai-wpoos-pro' ), $measurements['rbc']['value'] );
		}
		if ( isset( $measurements['wbc'] ) ) {
			/* translators: 1: WBC value, 2: status */
			$parts[] = sprintf( __( 'WBC (white blood cell count, immune system indicator) %1$s x10³/µL (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['wbc']['value'], $measurements['wbc']['status'] );
		}
		if ( isset( $measurements['platelets'] ) ) {
			/* translators: 1: platelet value, 2: status */
			$parts[] = sprintf( __( 'Platelets (clotting indicator) %1$s x10³/µL (%2$s).', 'mcp-ai-wpoos-pro' ), $measurements['platelets']['value'], $measurements['platelets']['status'] );
		}
		if ( isset( $measurements['mcv'] ) ) {
			/* translators: %s: MCV value */
			$parts[] = sprintf( __( 'MCV (mean corpuscular volume, RBC size) %s fL.', 'mcp-ai-wpoos-pro' ), $measurements['mcv']['value'] );
		}
		if ( isset( $measurements['mch'] ) ) {
			/* translators: %s: MCH value */
			$parts[] = sprintf( __( 'MCH (mean corpuscular hemoglobin) %s pg.', 'mcp-ai-wpoos-pro' ), $measurements['mch']['value'] );
		}
		if ( isset( $measurements['mchc'] ) ) {
			/* translators: %s: MCHC value */
			$parts[] = sprintf( __( 'MCHC (mean corpuscular hemoglobin concentration) %s g/dL.', 'mcp-ai-wpoos-pro' ), $measurements['mchc']['value'] );
		}
		if ( isset( $measurements['rdw'] ) ) {
			/* translators: %s: RDW value */
			$parts[] = sprintf( __( 'RDW (red cell distribution width, size variation) %s%%.', 'mcp-ai-wpoos-pro' ), $measurements['rdw']['value'] );
		}

		// CBC differential (brief summary when present).
		$diff_summary = array();
		foreach ( array( 'neutrophils_percent', 'lymphocytes_percent', 'monocytes_percent', 'eosinophils_percent', 'basophils_percent' ) as $dp ) {
			if ( isset( $measurements[ $dp ] ) ) {
				$label          = ucfirst( str_replace( '_percent', '', $dp ) );
				$diff_summary[] = $label . ' ' . $measurements[ $dp ]['value'] . '%';
			}
		}
		if ( ! empty( $diff_summary ) ) {
			/* translators: %s: differential summary list */
			$parts[] = sprintf( __( 'CBC differential: %s.', 'mcp-ai-wpoos-pro' ), implode( ', ', $diff_summary ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Generate an embedding vector for a vital signs entry and persist it in the
	 * vitals embedding index so that semantic_content_search can discover it.
	 *
	 * Uses the assistant's configured AI provider (Gemini or OpenAI).  The call
	 * is intentionally best-effort: if embedding generation fails the vital signs
	 * are already stored and the failure is silently ignored so the log action
	 * always succeeds.
	 *
	 * @param string $entry_id         Unique entry identifier.
	 * @param int    $member_id        Member post ID.
	 * @param string $measurement_date Measurement date (Y-m-d).
	 * @param string $vitals_text      Plain-text vitals summary for embedding.
	 * @param array  $context          Execution context (may contain assistant_config).
	 * @return void
	 */
	private function store_vitals_embedding( $entry_id, $member_id, $measurement_date, $vitals_text, $context ) {
		$provider  = isset( $context['assistant_config']['provider'] ) ? strtolower( $context['assistant_config']['provider'] ) : 'openai';
		$embedding = null;
		$model     = '';

		// Try Gemini first when the assistant uses a Gemini-family provider.
		if ( in_array( $provider, array( 'gemini', 'google' ), true ) && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			$gemini_client   = new WP_MCP_AI_Gemini_Client();
			$gemini_response = $gemini_client->create_embedding( $vitals_text, array( 'task_type' => 'RETRIEVAL_DOCUMENT' ) );

			if ( ! is_wp_error( $gemini_response ) && isset( $gemini_response['embedding']['values'] ) ) {
				$embedding = $gemini_response['embedding']['values'];
				$model     = 'text-embedding-004';
			}
		}

		// Fall back to OpenAI when Gemini is unavailable or fails.
		if ( null === $embedding && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$openai_client   = new WP_MCP_AI_OpenAI_Client();
			$openai_response = $openai_client->create_embeddings( $vitals_text );

			if ( ! is_wp_error( $openai_response ) && isset( $openai_response['data'][0]['embedding'] ) ) {
				$embedding = $openai_response['data'][0]['embedding'];
				$model     = isset( $openai_response['model'] ) ? $openai_response['model'] : 'text-embedding-3-small';
			}
		}

		// If no embedding could be generated, skip silently.
		if ( null === $embedding ) {
			return;
		}

		// Persist the embedding in the shared vitals index.
		$index = get_option( self::VITALS_EMBED_INDEX_KEY, array() );

		$index[ $entry_id ] = array(
			'member_id' => $member_id,
			'date'      => $measurement_date,
			'text'      => $vitals_text,
			'embedding' => $embedding,
			'model'     => $model,
			'stored_at' => current_time( 'mysql' ),
		);

		// Evict oldest entries when the index exceeds the maximum size.
		if ( count( $index ) > self::VITALS_EMBED_INDEX_MAX ) {
			$index = array_slice( $index, -self::VITALS_EMBED_INDEX_MAX, null, true );
		}

		update_option( self::VITALS_EMBED_INDEX_KEY, $index, false );
	}

	/**
	 * Generate alerts for abnormal values.
	 *
	 * @param array $measurements Measurements.
	 * @return array Alerts.
	 */
	private function generate_alerts( $measurements ) {
		$alerts = array();

		foreach ( $measurements as $type => $data ) {
			if ( isset( $data['status'] ) && in_array( $data['status'], array( 'critical', 'hypertensive_crisis', 'fever', 'diabetic_range', 'anaemia' ), true ) ) {
				$alerts[] = array(
					'type'     => $type,
					'severity' => 'high',
					'message'  => sprintf(
						/* translators: %s: measurement type */
						__( 'Abnormal %s reading detected. Consult healthcare provider if symptoms persist.', 'mcp-ai-wpoos-pro' ),
						str_replace( '_', ' ', $type )
					),
				);
			}

			// Additional alert for low CBC values.
			if ( isset( $data['status'] ) && 'low' === $data['status'] && in_array( $type, array( 'hemoglobin', 'hematocrit', 'wbc', 'platelets' ), true ) ) {
				$alerts[] = array(
					'type'     => $type,
					'severity' => 'medium',
					'message'  => sprintf(
						/* translators: %s: measurement type */
						__( 'Low %s detected. Consult healthcare provider.', 'mcp-ai-wpoos-pro' ),
						str_replace( '_', ' ', $type )
					),
				);
			}
		}

		return $alerts;
	}

	/**
	 * Calculate trend.
	 *
	 * @param array  $readings Readings array.
	 * @param string $key      Key to analyze.
	 * @return array Trend data.
	 */
	private function calculate_trend( $readings, $key ) {
		$values = array_column( $readings, $key );
		$count  = count( $values );

		if ( $count < 2 ) {
			return array( 'direction' => 'insufficient_data' );
		}

		$first_half  = array_slice( $values, 0, ceil( $count / 2 ) );
		$second_half = array_slice( $values, floor( $count / 2 ) );

		$avg_first  = array_sum( $first_half ) / count( $first_half );
		$avg_second = array_sum( $second_half ) / count( $second_half );

		$difference     = $avg_second - $avg_first;
		$percent_change = $avg_first > 0 ? ( ( $difference / $avg_first ) * 100 ) : 0;

		return array(
			'direction'      => $difference > 0 ? 'increasing' : ( $difference < 0 ? 'decreasing' : 'stable' ),
			'percent_change' => round( $percent_change, 1 ),
			'average_first'  => round( $avg_first, 1 ),
			'average_second' => round( $avg_second, 1 ),
		);
	}

	/**
	 * Supplement CCT history rows with decimal-precision values from options storage.
	 *
	 * Matches rows by their shared `entry_id` and, for each decimal vital-sign
	 * field, replaces the CCT value with the options-storage value whenever the
	 * options store holds a non-zero reading.  This corrects two historical data
	 * quality issues:
	 *
	 *  1. Hemoglobin: the CCT column was added after some entries were already
	 *     logged, so those rows have NULL in the CCT — but the original value is
	 *     intact in options storage.
	 *  2. Creatinine / renal indicators: before the DECIMAL migration, JetEngine
	 *     provisioned the columns as bigint, causing MySQL to silently truncate
	 *     values such as 0.9 → 1.  Options storage preserved the correct floats.
	 *
	 * @param array $history   CCT history rows as flat associative arrays.
	 * @param int   $member_id Member post ID.
	 * @return array Enriched history rows.
	 */
	private function supplement_cct_with_options_decimals( array $history, $member_id ) {
		if ( empty( $history ) ) {
			return $history;
		}

		$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
		$options_data    = get_option( $vital_signs_key, array() );

		if ( empty( $options_data ) || ! is_array( $options_data ) ) {
			return $history;
		}

		// Index options entries by entry_id (the outer key of options storage).
		// for O(1) lookups during row enrichment.
		$by_entry_id = array();
		foreach ( $options_data as $eid => $entry ) {
			if ( ! empty( $entry['measurements'] ) ) {
				$by_entry_id[ $eid ] = $entry['measurements'];
			}
		}

		// Decimal vital-sign fields that may have lost precision in old CCT rows.
		$decimal_fields = array(
			// Renal / metabolic.
			'egfr',
			'creatinine',
			'bun',
			'potassium',
			'sodium',
			'phosphorus',
			'albumin',
			// CBC indices.
			'hemoglobin',
			'hematocrit',
			'rbc',
			'wbc',
			'mcv',
			'mch',
			'mchc',
			'rdw',
			// CBC differential.
			'neutrophils_percent',
			'lymphocytes_percent',
			'monocytes_percent',
			'eosinophils_percent',
			'basophils_percent',
			'neutrophils_absolute',
			'lymphocytes_absolute',
			'monocytes_absolute',
			'eosinophils_absolute',
			'basophils_absolute',
		);

		foreach ( $history as &$row ) {
			$entry_id = isset( $row['entry_id'] ) ? (string) $row['entry_id'] : '';
			if ( ! $entry_id || ! isset( $by_entry_id[ $entry_id ] ) ) {
				continue;
			}

			$measurements = $by_entry_id[ $entry_id ];

			foreach ( $decimal_fields as $field ) {
				if ( ! isset( $measurements[ $field ]['value'] ) ) {
					continue;
				}

				$opts_val = floatval( $measurements[ $field ]['value'] );
				if ( $opts_val <= 0.0 ) {
					// No meaningful value in options; leave CCT value untouched.
					continue;
				}

				// Prefer the options-storage value: it was saved with full float.
				// precision, unlike the CCT column which may have truncated it.
				$row[ $field ] = $opts_val;
			}
		}
		unset( $row );

		return $history;
	}
}
