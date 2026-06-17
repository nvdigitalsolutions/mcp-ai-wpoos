<?php
/**
 * Tool for importing vital-sign measurements from industry-standard formats.
 *
 * Supports:
 *  - FHIR R4 JSON (Bundle with Observation resources, or a single Observation).
 *    LOINC codes are mapped to vitals_log CCT field names following HL7 FHIR R4
 *    guidelines (https://www.hl7.org/fhir/observation.html).
 *  - Generic CSV with flexible column-name mapping (see CSV_COLUMN_MAP below).
 *    Compatible with exports from Apple Health (via Health Auto Export), Google
 *    Fit CSV, CommonHealth, and most EHR patient portals.
 *
 * All rows are written to the vitals_log CCT (when JetEngine is active) and to
 * the options-based fallback storage so chart queries always find the data.
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
 * Performs the operation.
 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
 * import_vitals tool implementation.
 */
class WP_MCP_AI_Tool_Import_Vitals implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * LOINC code → vitals_log CCT field name.
	 *
	 * Sources: HL7 FHIR R4 common vital-signs profile + LOINC database.
	 */
	const LOINC_MAP = array(
		'8480-6'  => 'blood_pressure_systolic',   // BP systolic.
		'8462-4'  => 'blood_pressure_diastolic',  // BP diastolic.
		'8867-4'  => 'heart_rate',
		'8310-5'  => 'temperature',
		'29463-7' => 'weight',
		'39156-5' => 'bmi',
		'59408-5' => 'oxygen_saturation',
		'9279-1'  => 'respiratory_rate',
		'2339-0'  => 'blood_glucose',             // Glucose (capillary blood).
		'14743-9' => 'blood_glucose',             // Glucose (post-meal).
		'15074-8' => 'blood_glucose',             // Glucose (plasma).
		'33914-3' => 'egfr',                      // eGFR (CKD-EPI).
		'62238-1' => 'egfr',                      // eGFR (MDRD).
		'38483-4' => 'creatinine',
		'2160-0'  => 'creatinine',                // Creatinine (serum).
		'3094-0'  => 'bun',                       // BUN / blood urea nitrogen.
		'6299-2'  => 'bun',
		'2823-3'  => 'potassium',
		'2951-2'  => 'sodium',
		'2777-1'  => 'phosphorus',
		'1751-7'  => 'albumin',
		// ── CBC — hemoglobin ──────────────────────────────────────────────
		'718-7'   => 'hemoglobin',                // Hemoglobin [Mass/volume] in Blood.
		'59260-8' => 'hemoglobin',                // Hemoglobin [Moles/volume] in Blood.
		'20509-6' => 'hemoglobin',                // Hemoglobin [Mass/volume] by calculation.
		// ── CBC — main indices ────────────────────────────────────────────.
		'4544-3'  => 'hematocrit',                // Hematocrit [Volume Fraction] by Automated count.
		'71829-6' => 'hematocrit',                // Hematocrit by Centrifugation.
		'788-0'   => 'rbc',                       // Erythrocytes [#/volume] by Automated count.
		'6690-2'  => 'wbc',                       // Leukocytes [#/volume] by Automated count.
		'777-3'   => 'platelets',                 // Platelets [#/volume] by Automated count.
		'787-2'   => 'mcv',                       // MCV [Entitic volume] by Automated count.
		'785-6'   => 'mch',                       // MCH [Entitic mass] by Automated count.
		'786-4'   => 'mchc',                      // MCHC [Mass/volume] by Automated count.
		'21000-5' => 'rdw',                       // Erythrocyte distribution width [Ratio] by Automated count.
		// ── CBC differential — percent ────────────────────────────────────.
		'770-8'   => 'neutrophils_percent',       // Neutrophils/100 leukocytes by Automated count.
		'736-9'   => 'lymphocytes_percent',       // Lymphocytes/100 leukocytes by Automated count.
		'5905-5'  => 'monocytes_percent',         // Monocytes/100 leukocytes by Automated count.
		'713-8'   => 'eosinophils_percent',       // Eosinophils/100 leukocytes by Automated count.
		'706-2'   => 'basophils_percent',         // Basophils/100 leukocytes by Automated count.
		// ── CBC differential — absolute counts ───────────────────────────.
		'751-8'   => 'neutrophils_absolute',      // Neutrophils [#/volume] by Automated count.
		'731-0'   => 'lymphocytes_absolute',      // Lymphocytes [#/volume] by Automated count.
		'742-7'   => 'monocytes_absolute',        // Monocytes [#/volume] by Automated count.
		'711-2'   => 'eosinophils_absolute',      // Eosinophils [#/volume] by Automated count.
		'704-7'   => 'basophils_absolute',        // Basophils [#/volume] by Automated count.
		// ── Extended BMP / CMP electrolytes ──────────────────────────────.
		'2075-0'  => 'chloride',                  // Chloride [Moles/volume] in Serum or Plasma.
		'1963-8'  => 'co2',                       // Bicarbonate [Moles/volume] in Serum or Plasma (CO2).
		'17861-6' => 'calcium',                   // Calcium [Mass/volume] in Serum or Plasma.
		'19123-9' => 'magnesium',                 // Magnesium [Mass/volume] in Serum or Plasma.
		// ── Liver function tests (LFT) ────────────────────────────────────.
		'1975-2'  => 'bilirubin',                 // Bilirubin.total [Mass/volume] in Serum or Plasma.
		'1920-8'  => 'ast',                       // Aspartate aminotransferase [Enzymatic activity/volume] in Serum or Plasma.
		'1742-6'  => 'alt',                       // Alanine aminotransferase [Enzymatic activity/volume] in Serum or Plasma.
		'2885-2'  => 'total_protein',             // Protein [Mass/volume] in Serum or Plasma.
	);

	/**
	 * Flexible CSV column-name aliases → CCT field name.
	 *
	 * Keys are lower-cased, stripped column headers.  The first matching alias
	 * for each group wins.
	 */
	const CSV_COLUMN_MAP = array(
		'date'                        => 'measurement_date',
		'measurement_date'            => 'measurement_date',
		'measurement date'            => 'measurement_date',
		'time'                        => 'measurement_time',
		'measurement_time'            => 'measurement_time',
		'measurement time'            => 'measurement_time',
		'bp_systolic'                 => 'blood_pressure_systolic',
		'systolic'                    => 'blood_pressure_systolic',
		'systolic blood pressure'     => 'blood_pressure_systolic',
		'systolic bp'                 => 'blood_pressure_systolic',
		'bp systolic'                 => 'blood_pressure_systolic',
		'bp_diastolic'                => 'blood_pressure_diastolic',
		'diastolic'                   => 'blood_pressure_diastolic',
		'diastolic blood pressure'    => 'blood_pressure_diastolic',
		'diastolic bp'                => 'blood_pressure_diastolic',
		'bp diastolic'                => 'blood_pressure_diastolic',
		'heart_rate'                  => 'heart_rate',
		'heart rate'                  => 'heart_rate',
		'hr'                          => 'heart_rate',
		'pulse'                       => 'heart_rate',
		'pulse rate'                  => 'heart_rate',
		'bpm'                         => 'heart_rate',
		'temperature'                 => 'temperature',
		'temp'                        => 'temperature',
		'body temperature'            => 'temperature',
		'temperature_unit'            => 'temperature_unit',
		'temp_unit'                   => 'temperature_unit',
		'temp unit'                   => 'temperature_unit',
		'weight'                      => 'weight',
		'body weight'                 => 'weight',
		'weight_unit'                 => 'weight_unit',
		'weight unit'                 => 'weight_unit',
		'bmi'                         => 'bmi',
		'body mass index'             => 'bmi',
		'blood_glucose'               => 'blood_glucose',
		'blood glucose'               => 'blood_glucose',
		'glucose'                     => 'blood_glucose',
		'blood sugar'                 => 'blood_glucose',
		'glucose (mg/dl)'             => 'blood_glucose',
		'oxygen_saturation'           => 'oxygen_saturation',
		'oxygen saturation'           => 'oxygen_saturation',
		'spo2'                        => 'oxygen_saturation',
		'o2 saturation'               => 'oxygen_saturation',
		'respiratory_rate'            => 'respiratory_rate',
		'respiratory rate'            => 'respiratory_rate',
		'respiration rate'            => 'respiratory_rate',
		'breaths per minute'          => 'respiratory_rate',
		'egfr'                        => 'egfr',
		'egfr (ml/min/1.73m2)'        => 'egfr',
		'estimated gfr'               => 'egfr',
		'creatinine'                  => 'creatinine',
		'creatinine (mg/dl)'          => 'creatinine',
		'serum creatinine'            => 'creatinine',
		'bun'                         => 'bun',
		'blood urea nitrogen'         => 'bun',
		'bun (mg/dl)'                 => 'bun',
		'potassium'                   => 'potassium',
		'k+'                          => 'potassium',
		'potassium (meq/l)'           => 'potassium',
		'sodium'                      => 'sodium',
		'na+'                         => 'sodium',
		'sodium (meq/l)'              => 'sodium',
		'sodium (mg/day)'             => 'sodium',
		'phosphorus'                  => 'phosphorus',
		'phosphate'                   => 'phosphorus',
		'phosphorus (mg/dl)'          => 'phosphorus',
		'albumin'                     => 'albumin',
		'albumin (g/dl)'              => 'albumin',
		'serum albumin'               => 'albumin',
		'notes'                       => 'notes',
		'note'                        => 'notes',
		'comments'                    => 'notes',
		'comment'                     => 'notes',
		'source'                      => 'source',
		// ── CBC — hemoglobin ──────────────────────────────────────────────
		'hemoglobin'                  => 'hemoglobin',
		'hgb'                         => 'hemoglobin',
		'haemoglobin'                 => 'hemoglobin',
		'hemoglobin (g/dl)'           => 'hemoglobin',
		'hgb (g/dl)'                  => 'hemoglobin',
		// ── CBC — main indices ────────────────────────────────────────────
		'hematocrit'                  => 'hematocrit',
		'hct'                         => 'hematocrit',
		'haematocrit'                 => 'hematocrit',
		'hematocrit (%)'              => 'hematocrit',
		'rbc'                         => 'rbc',
		'red blood cells'             => 'rbc',
		'red blood cell count'        => 'rbc',
		'rbc (x10^6/ul)'              => 'rbc',
		'wbc'                         => 'wbc',
		'white blood cells'           => 'wbc',
		'white blood cell count'      => 'wbc',
		'leukocytes'                  => 'wbc',
		'wbc (x10^3/ul)'              => 'wbc',
		'platelets'                   => 'platelets',
		'plt'                         => 'platelets',
		'platelet count'              => 'platelets',
		'platelets (x10^3/ul)'        => 'platelets',
		'mcv'                         => 'mcv',
		'mean corpuscular volume'     => 'mcv',
		'mcv (fl)'                    => 'mcv',
		'mch'                         => 'mch',
		'mean corpuscular hemoglobin' => 'mch',
		'mch (pg)'                    => 'mch',
		'mchc'                        => 'mchc',
		'mchc (g/dl)'                 => 'mchc',
		'rdw'                         => 'rdw',
		'red cell distribution width' => 'rdw',
		'rdw (%)'                     => 'rdw',
		// ── CBC differential — percent ────────────────────────────────────
		'neutrophils_percent'         => 'neutrophils_percent',
		'neutrophils %'               => 'neutrophils_percent',
		'neut %'                      => 'neutrophils_percent',
		'neutrophils (%)'             => 'neutrophils_percent',
		'lymphocytes_percent'         => 'lymphocytes_percent',
		'lymphocytes %'               => 'lymphocytes_percent',
		'lymph %'                     => 'lymphocytes_percent',
		'lymphocytes (%)'             => 'lymphocytes_percent',
		'monocytes_percent'           => 'monocytes_percent',
		'monocytes %'                 => 'monocytes_percent',
		'mono %'                      => 'monocytes_percent',
		'monocytes (%)'               => 'monocytes_percent',
		'eosinophils_percent'         => 'eosinophils_percent',
		'eosinophils %'               => 'eosinophils_percent',
		'eos %'                       => 'eosinophils_percent',
		'eosinophils (%)'             => 'eosinophils_percent',
		'basophils_percent'           => 'basophils_percent',
		'basophils %'                 => 'basophils_percent',
		'baso %'                      => 'basophils_percent',
		'basophils (%)'               => 'basophils_percent',
		// ── CBC differential — absolute counts ───────────────────────────
		'neutrophils_absolute'        => 'neutrophils_absolute',
		'neutrophils absolute'        => 'neutrophils_absolute',
		'neut abs'                    => 'neutrophils_absolute',
		'neutrophils (x10^3/ul)'      => 'neutrophils_absolute',
		'lymphocytes_absolute'        => 'lymphocytes_absolute',
		'lymphocytes absolute'        => 'lymphocytes_absolute',
		'lymph abs'                   => 'lymphocytes_absolute',
		'lymphocytes (x10^3/ul)'      => 'lymphocytes_absolute',
		'monocytes_absolute'          => 'monocytes_absolute',
		'monocytes absolute'          => 'monocytes_absolute',
		'mono abs'                    => 'monocytes_absolute',
		'monocytes (x10^3/ul)'        => 'monocytes_absolute',
		'eosinophils_absolute'        => 'eosinophils_absolute',
		'eosinophils absolute'        => 'eosinophils_absolute',
		'eos abs'                     => 'eosinophils_absolute',
		'eosinophils (x10^3/ul)'      => 'eosinophils_absolute',
		'basophils_absolute'          => 'basophils_absolute',
		'basophils absolute'          => 'basophils_absolute',
		'baso abs'                    => 'basophils_absolute',
		'basophils (x10^3/ul)'        => 'basophils_absolute',
		// ── Extended BMP / CMP electrolytes ──────────────────────────────
		'chloride'                    => 'chloride',
		'cl-'                         => 'chloride',
		'chloride (meq/l)'            => 'chloride',
		'co2'                         => 'co2',
		'bicarbonate'                 => 'co2',
		'hco3'                        => 'co2',
		'co2 (meq/l)'                 => 'co2',
		'calcium'                     => 'calcium',
		'ca'                          => 'calcium',
		'calcium (mg/dl)'             => 'calcium',
		'magnesium'                   => 'magnesium',
		'mg'                          => 'magnesium',
		'magnesium (mg/dl)'           => 'magnesium',
		// ── Liver function tests (LFT) ────────────────────────────────────
		'bilirubin'                   => 'bilirubin',
		'total bilirubin'             => 'bilirubin',
		'tbili'                       => 'bilirubin',
		'bilirubin (mg/dl)'           => 'bilirubin',
		'ast'                         => 'ast',
		'aspartate aminotransferase'  => 'ast',
		'sgot'                        => 'ast',
		'ast (u/l)'                   => 'ast',
		'alt'                         => 'alt',
		'alanine aminotransferase'    => 'alt',
		'sgpt'                        => 'alt',
		'alt (u/l)'                   => 'alt',
		'total_protein'               => 'total_protein',
		'total protein'               => 'total_protein',
		'protein'                     => 'total_protein',
		'total protein (g/dl)'        => 'total_protein',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_vitals';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Vitals', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import vital-sign measurements into the vitals_log CCT from industry-standard formats: FHIR R4 JSON (HL7 Observation Bundle with LOINC codes), CSV (flexible column mapping compatible with Apple Health, Google Fit, CommonHealth, and most EHR portal exports), or a pre-structured JSON array of CCT records (field names matching the vitals_log schema — the simplest format when an AI assistant has already prepared the payload). Supports dry-run validation and returns a per-row import summary.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => 'WordPress post ID of the mcp_ai_member to import vitals for (required).',
				),
				'format'    => array(
					'type'        => 'string',
					'enum'        => array( 'fhir_json', 'csv', 'json' ),
					'default'     => 'csv',
					'description' => 'Import format. "fhir_json" accepts HL7 FHIR R4 JSON (Bundle or single Observation). "csv" accepts a comma-separated text with flexible header names. "json" accepts a JSON array of record objects whose keys match the vitals_log CCT field names (bp_systolic, bp_diastolic, heart_rate, oxygen_saturation, respiratory_rate, egfr, creatinine, bun, potassium, sodium, phosphorus, albumin, etc.) — use this format when the AI has already prepared a structured payload.',
				),
				'data'      => array(
					'type'        => 'string',
					'description' => 'Raw file content as a string — the JSON or CSV text to import.',
				),
				'source'    => array(
					'type'        => 'string',
					'default'     => 'import',
					'description' => 'Source label written to the vitals_log CCT (e.g. "apple_health", "emr", "import").',
				),
				'dry_run'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, validate and return the parsed rows without saving anything.',
				),
			),
			'required'   => array( 'member_id', 'data' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capabilities() {
		return array( 'write', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */

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
			'profession_tags'       => array( 'healthcare_provider', 'health_informatics' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'pii-data', 'hipaa-relevant', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Enforce capability — importing PHI/vitals requires elevated permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to import vitals data.', 'mcp-ai-wpoos-pro' )
			);
		}

		$member_id = absint( $arguments['member_id'] ?? 0 );
		if ( ! $member_id ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_missing_member',
				__( 'member_id is required and must be a positive integer.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify the member post exists.
		$member_post = get_post( $member_id );
		if ( ! $member_post || 'mcp_ai_member' !== $member_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_member_not_found',
				sprintf( __( 'No mcp_ai_member found with ID %d.', 'mcp-ai-wpoos-pro' ), $member_id )
			);
		}

		$data    = trim( $arguments['data'] ?? '' );
		$format  = sanitize_key( $arguments['format'] ?? 'csv' );
		$source  = sanitize_text_field( $arguments['source'] ?? 'import' );
		$dry_run = ! empty( $arguments['dry_run'] );

		if ( '' === $data ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_empty_data',
				__( 'data is required and cannot be empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse rows according to format.
		switch ( $format ) {
			case 'fhir_json':
				$result = $this->parse_fhir_json( $data );
				break;
			case 'json':
				$result = $this->parse_json_array( $data );
				break;
			case 'csv':
			default:
				$result = $this->parse_csv( $data );
				break;
		}

		if ( ! $result['success'] ) {
			return $result;
		}

		$rows   = $result['rows'];
		$errors = $result['parse_errors'] ?? array();

		if ( empty( $rows ) ) {
			return array(
				'success'      => true,
				'imported'     => 0,
				'skipped'      => 0,
				'dry_run'      => $dry_run,
				'parse_errors' => $errors,
				'message'      => __( 'No valid vital-sign rows found in the supplied data.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( $dry_run ) {
			return array(
				'success'      => true,
				'dry_run'      => true,
				'row_count'    => count( $rows ),
				'sample_rows'  => array_slice( $rows, 0, 5 ),
				'parse_errors' => $errors,
				'message'      => sprintf(
					/* translators: %d: number of rows parsed */
					__( 'Dry run: %d rows parsed successfully. No data was saved.', 'mcp-ai-wpoos-pro' ),
					count( $rows )
				),
			);
		}

		// Save rows.
		$imported    = 0;
		$skipped     = 0;
		$save_errors = array();

		$has_log_cct = class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' )
			&& WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists();

		$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
		$options_store   = get_option( $vital_signs_key, array() );

		foreach ( $rows as $row_index => $row ) {
			try {
				$cct_data = array(
					'measurement_date' => $row['measurement_date'] ?? gmdate( 'Y-m-d' ),
					// Empty string (not '00:00') so the upsert can distinguish.
					// timed from untimed records and store them as separate rows.
					'measurement_time' => isset( $row['measurement_time'] ) ? trim( (string) $row['measurement_time'] ) : '',
					'source'           => ! empty( $row['source'] ) ? sanitize_text_field( $row['source'] ) : $source,
					'notes'            => ! empty( $row['notes'] ) ? wp_kses_post( $row['notes'] ) : '',
					'logged_by'        => get_current_user_id(),
					'logged_at'        => current_time( 'mysql' ),
				);

				if ( 'json' === $format ) {
					// JSON rows already carry CCT field names directly — copy.
					// numeric fields without going through the intermediate map.
					$direct_fields = class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' )
						? WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_numeric_vital_fields()
						: array(
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

					foreach ( $direct_fields as $field ) {
						if ( isset( $row[ $field ] ) && '' !== (string) $row[ $field ] ) {
							$cct_data[ $field ] = (float) $row[ $field ];
						}
					}
				} else {
					// CSV / FHIR: map intermediate field names to CCT field names.
					$numeric_fields = array(
						'blood_pressure_systolic'  => 'bp_systolic',
						'blood_pressure_diastolic' => 'bp_diastolic',
						'heart_rate'               => 'heart_rate',
						'temperature'              => 'temperature',
						'weight'                   => 'weight',
						'bmi'                      => 'bmi',
						'blood_glucose'            => 'blood_glucose',
						'oxygen_saturation'        => 'oxygen_saturation',
						'respiratory_rate'         => 'respiratory_rate',
						'egfr'                     => 'egfr',
						'creatinine'               => 'creatinine',
						'bun'                      => 'bun',
						'potassium'                => 'potassium',
						'sodium'                   => 'sodium',
						'phosphorus'               => 'phosphorus',
						'albumin'                  => 'albumin',
						// CBC — hemoglobin and main indices.
						'hemoglobin'               => 'hemoglobin',
						'hematocrit'               => 'hematocrit',
						'rbc'                      => 'rbc',
						'wbc'                      => 'wbc',
						'platelets'                => 'platelets',
						'mcv'                      => 'mcv',
						'mch'                      => 'mch',
						'mchc'                     => 'mchc',
						'rdw'                      => 'rdw',
						// CBC differential — percent.
						'neutrophils_percent'      => 'neutrophils_percent',
						'lymphocytes_percent'      => 'lymphocytes_percent',
						'monocytes_percent'        => 'monocytes_percent',
						'eosinophils_percent'      => 'eosinophils_percent',
						'basophils_percent'        => 'basophils_percent',
						// CBC differential — absolute counts.
						'neutrophils_absolute'     => 'neutrophils_absolute',
						'lymphocytes_absolute'     => 'lymphocytes_absolute',
						'monocytes_absolute'       => 'monocytes_absolute',
						'eosinophils_absolute'     => 'eosinophils_absolute',
						'basophils_absolute'       => 'basophils_absolute',
						// Extended BMP / CMP electrolytes.
						'chloride'                 => 'chloride',
						'co2'                      => 'co2',
						'calcium'                  => 'calcium',
						'magnesium'                => 'magnesium',
						// Liver function tests (LFT).
						'bilirubin'                => 'bilirubin',
						'ast'                      => 'ast',
						'alt'                      => 'alt',
						'total_protein'            => 'total_protein',
					);

					foreach ( $numeric_fields as $parsed_key => $cct_key ) {
						if ( isset( $row[ $parsed_key ] ) && '' !== (string) $row[ $parsed_key ] ) {
							$cct_data[ $cct_key ] = (float) $row[ $parsed_key ];
						}
					}
				}

				if ( ! empty( $row['temperature_unit'] ) ) {
					$cct_data['temperature_unit'] = strtoupper( sanitize_text_field( $row['temperature_unit'] ) );
				}
				if ( ! empty( $row['weight_unit'] ) ) {
					$cct_data['weight_unit'] = sanitize_text_field( $row['weight_unit'] );
				}

				// Write to vitals_log CCT (upsert consolidates partial same-day rows).
				if ( $has_log_cct ) {
					$inserted = WP_MCP_AI_JetEngine_Vitals_Log_CCT::upsert( $member_id, $cct_data );
					if ( ! $inserted ) {
						$save_errors[] = sprintf(
							/* translators: %d: row index */
							__( 'Row %d: CCT upsert failed.', 'mcp-ai-wpoos-pro' ),
							$row_index + 1
						);
						++$skipped;
						continue;
					}
				}

				// Also write to options-based fallback.
				$options_entry              = $cct_data;
				$options_entry['timestamp'] = time();
				$options_store[]            = $options_entry;

				++$imported;
			} catch ( Exception $e ) {
				$save_errors[] = sprintf(
					/* translators: 1: row index, 2: exception message */
					__( 'Row %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$row_index + 1,
					$e->getMessage()
				);
				++$skipped;
			}
		}

		// Persist options store (cap at 500 entries — same limit as vitals embed index).
		if ( count( $options_store ) > 500 ) {
			$options_store = array_slice( $options_store, -500 );
		}
		update_option( $vital_signs_key, $options_store );

		return array(
			'success'      => true,
			'dry_run'      => false,
			'imported'     => $imported,
			'skipped'      => $skipped,
			'parse_errors' => $errors,
			'save_errors'  => $save_errors,
			'member_id'    => $member_id,
			'format'       => $format,
			'message'      => sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Import complete: %1$d row(s) saved, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
				$imported,
				$skipped
			),
		);
	}

	// ── Parsers ──────────────────────────────────────────────────────────────

	/**
	 * Parse a JSON array of pre-structured CCT record objects.
	 *
	 * Accepts a JSON string that is either:
	 *  - An array of record objects: [ { "member_id": 2976, "bp_systolic": 105, … }, … ]
	 *  - A single record object: { "member_id": 2976, "bp_systolic": 105, … }
	 *
	 * Keys must match vitals_log CCT field names (bp_systolic, bp_diastolic,
	 * heart_rate, oxygen_saturation, respiratory_rate, egfr, creatinine, bun,
	 * potassium, sodium, phosphorus, albumin, measurement_date, measurement_time,
	 * source, notes, etc.).  The `member_id` field in each record is silently
	 * ignored — the tool-level member_id parameter is authoritative.
	 *
	 * @param string $json Raw JSON string (array of record objects or single object).
	 * @return array {success, rows, parse_errors}.
	 */
	private function parse_json_array( $json ) {
		$decoded = json_decode( $json, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_invalid_json',
				__( 'Invalid JSON: could not decode the supplied data.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Accept both a JSON array and a single object.
		if ( is_array( $decoded ) && array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
			// Associative array (single object) — wrap in a list.
			$decoded = array( $decoded );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_invalid_json_structure',
				__( 'JSON data must be an array of record objects or a single record object.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Numeric CCT field names plus standard meta fields that may appear.
		$numeric_cct_fields = class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' )
			? WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_numeric_vital_fields()
			: array(
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

		$allowed_text_fields = array( 'measurement_date', 'measurement_time', 'source', 'notes', 'temperature_unit', 'weight_unit' );

		$rows   = array();
		$errors = array();

		foreach ( $decoded as $record_index => $record ) {
			if ( ! is_array( $record ) ) {
				$errors[] = sprintf(
					/* translators: %d: record index */
					__( 'Record %d is not a valid object — skipped.', 'mcp-ai-wpoos-pro' ),
					$record_index + 1
				);
				continue;
			}

			$row = array();

			foreach ( $record as $key => $val ) {
				$key = sanitize_key( $key );

				// member_id is authoritative from the tool parameter — skip.
				if ( 'member_id' === $key ) {
					continue;
				}

				if ( '' === (string) $val || null === $val ) {
					continue;
				}

				if ( in_array( $key, $numeric_cct_fields, true ) ) {
					$row[ $key ] = $val; // Kept as-is; cast to float in execute().
				} elseif ( in_array( $key, $allowed_text_fields, true ) ) {
					$row[ $key ] = $val;
				}
				// Unrecognised keys are silently dropped.
			}

			if ( empty( $row ) ) {
				$errors[] = sprintf(
					/* translators: %d: record index */
					__( 'Record %d: no recognisable CCT fields found — skipped.', 'mcp-ai-wpoos-pro' ),
					$record_index + 1
				);
				continue;
			}

			// Normalise date.
			if ( ! empty( $row['measurement_date'] ) ) {
				$row['measurement_date'] = $this->normalise_date( $row['measurement_date'] );
			} else {
				$row['measurement_date'] = gmdate( 'Y-m-d' );
			}

			$rows[] = $row;
		}

		return array(
			'success'      => true,
			'rows'         => $rows,
			'parse_errors' => $errors,
		);
	}

	/**
	 * Parse a FHIR R4 JSON string (Bundle or single Observation).
	 *
	 * @param string $json Raw JSON string.
	 * @return array {success, rows, parse_errors}.
	 */
	private function parse_fhir_json( $json ) {
		$decoded = json_decode( $json, true );
		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_invalid_fhir_json',
				__( 'Invalid JSON: could not decode the supplied data.', 'mcp-ai-wpoos-pro' )
			);
		}

		$observations = array();
		$errors       = array();

		$resource_type = $decoded['resourceType'] ?? '';

		if ( 'Bundle' === $resource_type ) {
			foreach ( $decoded['entry'] ?? array() as $entry ) {
				$res = $entry['resource'] ?? array();
				if ( 'Observation' === ( $res['resourceType'] ?? '' ) ) {
					$observations[] = $res;
				}
			}
		} elseif ( 'Observation' === $resource_type ) {
			$observations[] = $decoded;
		} else {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_invalid_fhir_resource',
				__( 'FHIR JSON must be a Bundle or a single Observation resource.', 'mcp-ai-wpoos-pro' )
			);
		}

		$rows = array();
		foreach ( $observations as $obs_index => $obs ) {
			$row = $this->map_fhir_observation( $obs, $obs_index, $errors );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		return array(
			'success'      => true,
			'rows'         => $rows,
			'parse_errors' => $errors,
		);
	}

	/**
	 * Map a single FHIR Observation to a row array.
	 *
	 * @param array $obs       Decoded Observation resource.
	 * @param int   $obs_index Zero-based index (for error messages).
	 * @param array &$errors    Accumulator for non-fatal parse warnings.
	 * @return array|false Row array or false if the observation could not be mapped.
	 */
	private function map_fhir_observation( array $obs, $obs_index, array &$errors ) {
		$row = array();

		// Extract measurement date from effectiveDateTime or effectivePeriod.start.
		$effective = $obs['effectiveDateTime']
			?? ( $obs['effectivePeriod']['start'] ?? null );
		if ( $effective ) {
			$row['measurement_date'] = substr( $effective, 0, 10 );
			if ( strlen( $effective ) >= 16 ) {
				$row['measurement_time'] = substr( $effective, 11, 5 );
			}
		}

		// Determine LOINC code(s).
		$loinc_codes = array();
		foreach ( $obs['code']['coding'] ?? array() as $coding ) {
			if ( ! empty( $coding['code'] ) ) {
				$loinc_codes[] = $coding['code'];
			}
		}

		// ── Handle blood-pressure panel (code 55284-4 / 85354-9) ──────────
		if ( ! empty( $obs['component'] ) ) {
			foreach ( $obs['component'] as $component ) {
				foreach ( $component['code']['coding'] ?? array() as $coding ) {
					$code  = $coding['code'] ?? '';
					$field = self::LOINC_MAP[ $code ] ?? null;
					if ( $field ) {
						$value = $component['valueQuantity']['value'] ?? null;
						if ( null !== $value ) {
							$row[ $field ] = $value;
						}
					}
				}
			}
			return $row ? $row : false;
		}

		// ── Single-value observations ─────────────────────────────────────
		$field = null;
		foreach ( $loinc_codes as $code ) {
			$field = self::LOINC_MAP[ $code ] ?? null;
			if ( $field ) {
				break;
			}
		}

		if ( ! $field ) {
			$errors[] = sprintf(
				/* translators: 1: observation index, 2: LOINC codes */
				__( 'Observation %1$d: unrecognised LOINC code(s) %2$s — skipped.', 'mcp-ai-wpoos-pro' ),
				$obs_index + 1,
				implode( ', ', $loinc_codes )
			);
			return false;
		}

		$value = $obs['valueQuantity']['value'] ?? null;
		if ( null === $value ) {
			$errors[] = sprintf(
				/* translators: 1: observation index, 2: field name */
				__( 'Observation %1$d (%2$s): no valueQuantity.value found — skipped.', 'mcp-ai-wpoos-pro' ),
				$obs_index + 1,
				$field
			);
			return false;
		}

		$row[ $field ] = $value;

		// Capture unit for temperature / weight.
		if ( in_array( $field, array( 'temperature', 'weight' ), true ) ) {
			$unit = $obs['valueQuantity']['unit'] ?? $obs['valueQuantity']['code'] ?? '';
			if ( $unit ) {
				$row[ $field . '_unit' ] = $unit;
			}
		}

		return $row ? $row : false;
	}

	/**
	 * Parse a CSV string into rows using the flexible column-name map.
	 *
	 * @param string $csv Raw CSV text.
	 * @return array {success, rows, parse_errors}.
	 */
	private function parse_csv( $csv ) {
		// Normalize line endings.
		$csv   = str_replace( array( "\r\n", "\r" ), "\n", trim( $csv ) );
		$lines = explode( "\n", $csv );

		if ( empty( $lines ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_vitals_empty_csv',
				__( 'CSV data is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Parse header row.
		$raw_headers = str_getcsv( array_shift( $lines ) );
		$headers     = array_map(
			function ( $h ) {
				return strtolower( trim( $h ) );
			},
			$raw_headers
		);

		// Map each header position to a CCT field (or null if unrecognised).
		$field_map = array();
		foreach ( $headers as $col_index => $header ) {
			$field_map[ $col_index ] = self::CSV_COLUMN_MAP[ $header ] ?? null;
		}

		$rows   = array();
		$errors = array();

		foreach ( $lines as $line_index => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$values = str_getcsv( $line );
			$row    = array();

			foreach ( $field_map as $col_index => $field ) {
				if ( null === $field ) {
					continue; // Unrecognised column — silently skip.
				}
				$raw = trim( $values[ $col_index ] ?? '' );
				if ( '' === $raw ) {
					continue;
				}
				$row[ $field ] = $raw;
			}

			// Require at least a date and one numeric vital.
			if ( empty( $row ) ) {
				$errors[] = sprintf(
					/* translators: %d: CSV line number */
					__( 'Line %d: no recognisable columns — skipped.', 'mcp-ai-wpoos-pro' ),
					$line_index + 2
				);
				continue;
			}

			// Normalise date format (accept YYYY-MM-DD, MM/DD/YYYY, DD/MM/YYYY).
			if ( ! empty( $row['measurement_date'] ) ) {
				$row['measurement_date'] = $this->normalise_date( $row['measurement_date'] );
			} else {
				$row['measurement_date'] = gmdate( 'Y-m-d' );
			}

			$rows[] = $row;
		}

		return array(
			'success'      => true,
			'rows'         => $rows,
			'parse_errors' => $errors,
		);
	}

	/**
	 * Attempt to parse common date string variants into Y-m-d format.
	 *
	 * @param string $raw Raw date string.
	 * @return string Y-m-d string, or the original value when parsing fails.
	 */
	private function normalise_date( $raw ) {
		$raw = trim( $raw );

		// Already Y-m-d.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw ) ) {
			return substr( $raw, 0, 10 );
		}

		// MM/DD/YYYY or M/D/YYYY.
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d', $m[3], $m[1], $m[2] );
		}

		// DD-Mon-YYYY (e.g. 09-Feb-2026).
		if ( preg_match( '/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/', $raw, $m ) ) {
			$ts = strtotime( $raw );
			if ( $ts ) {
				return gmdate( 'Y-m-d', $ts );
			}
		}

		// Attempt generic strtotime parse.
		$ts = strtotime( $raw );
		if ( $ts ) {
			return gmdate( 'Y-m-d', $ts );
		}

		return $raw;
	}
}
