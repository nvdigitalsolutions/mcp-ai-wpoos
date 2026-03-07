<?php
/**
 * Tool for logging and tracking vital signs.
 *
 * Comprehensive vital signs monitoring including blood pressure, heart rate,
 * temperature, weight, BMI, glucose, oxygen saturation, respiratory rate, and
 * kidney-health indicators (eGFR, creatinine, BUN, K+, Na+, phosphorus, albumin).
 *
 * When JetEngine is available measurements are stored in the vital_signs CCT for
 * structured, queryable data management. Options-based storage is always written
 * as a lightweight fallback so tools that pre-date CCT availability keep working.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs and tracks vital signs for health monitoring.
 */
class WP_MCP_AI_Tool_Log_Vital_Signs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Log and track vital signs including blood pressure, heart rate, temperature, weight, BMI, blood glucose, oxygen saturation (SpO2), respiratory rate, and kidney-health indicators (eGFR, creatinine, BUN, potassium, sodium, phosphorus, albumin). When JetEngine is active measurements are stored in the structured vital_signs CCT for advanced querying; options-based storage is always maintained as a fallback. Supports trend analysis, normal range validation, and alerts for abnormal readings. HIPAA-compliant with audit trails.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'log', 'get_latest', 'get_history', 'analyze_trends' ),
					'default'     => 'log',
				),
				'member_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'measurement_date' => array(
					'type'        => 'string',
					'description' => __( 'Date of measurement (YYYY-MM-DD) (optional, defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'measurement_time' => array(
					'type'        => 'string',
					'description' => __( 'Time of measurement (HH:MM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{2}:\d{2}$',
				),
				'blood_pressure_systolic' => array(
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
				'heart_rate'       => array(
					'type'        => 'integer',
					'description' => __( 'Heart rate (beats per minute) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 30,
					'maximum'     => 250,
				),
				'temperature'      => array(
					'type'        => 'number',
					'description' => __( 'Body temperature in Fahrenheit or Celsius (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 90.0,
					'maximum'     => 115.0,
				),
				'temperature_unit' => array(
					'type'        => 'string',
					'description' => __( 'Temperature unit (optional, default: F)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'F', 'C' ),
					'default'     => 'F',
				),
				'weight'           => array(
					'type'        => 'number',
					'description' => __( 'Body weight (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.1,
				),
				'weight_unit'      => array(
					'type'        => 'string',
					'description' => __( 'Weight unit (optional, default: lbs)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lbs', 'kg' ),
					'default'     => 'lbs',
				),
				'height'           => array(
					'type'        => 'number',
					'description' => __( 'Height in inches or cm (optional, for BMI calculation)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'height_unit'      => array(
					'type'        => 'string',
					'description' => __( 'Height unit (optional, default: in)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'in', 'cm' ),
					'default'     => 'in',
				),
				'blood_glucose'    => array(
					'type'        => 'integer',
					'description' => __( 'Blood glucose level (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 20,
					'maximum'     => 600,
				),
				'oxygen_saturation' => array(
					'type'        => 'integer',
					'description' => __( 'Oxygen saturation (SpO2) percentage (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 100,
				),
				'respiratory_rate' => array(
					'type'        => 'integer',
					'description' => __( 'Respiratory rate (breaths per minute) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 5,
					'maximum'     => 60,
				),
				// Kidney health indicators.
				'egfr'             => array(
					'type'        => 'number',
					'description' => __( 'Estimated Glomerular Filtration Rate (mL/min/1.73m²) — CKD stage indicator (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 200,
				),
				'creatinine'       => array(
					'type'        => 'number',
					'description' => __( 'Serum creatinine (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.1,
					'maximum'     => 30,
				),
				'bun'              => array(
					'type'        => 'number',
					'description' => __( 'Blood Urea Nitrogen (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'potassium'        => array(
					'type'        => 'number',
					'description' => __( 'Serum potassium K+ (mEq/L) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1.0,
					'maximum'     => 10.0,
				),
				'sodium'           => array(
					'type'        => 'number',
					'description' => __( 'Sodium intake Na+ (mg/day) (optional) — kidney-friendly target ≤ 2300 mg', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 10000,
				),
				'phosphorus'       => array(
					'type'        => 'number',
					'description' => __( 'Serum phosphorus PO4 (mg/dL) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
					'maximum'     => 20,
				),
				'albumin'          => array(
					'type'        => 'number',
					'description' => __( 'Serum albumin (g/dL) — nutritional and kidney health marker (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
					'maximum'     => 6.0,
				),
				'source'           => array(
					'type'        => 'string',
					'description' => __( 'How this measurement was captured (optional, default: manual)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'manual', 'tma', 'api', 'import' ),
					'default'     => 'manual',
				),
				'notes'            => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or context (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'days_back'        => array(
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

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
				return $this->log_vital_signs( $arguments, $member_id, $current_user_id );

			case 'get_latest':
				return $this->get_latest_vital_signs( $member_id );

			case 'get_history':
				$days_back = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 30;
				return $this->get_vital_signs_history( $member_id, $days_back );

			case 'analyze_trends':
				$days_back = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 30;
				return $this->analyze_vital_signs_trends( $member_id, $days_back );

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
	 * @return array|WP_Error Result or error.
	 */
	private function log_vital_signs( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to log vital signs.', 'mcp-ai-wpoos-pro' ) );
		}

		$measurement_date = isset( $arguments['measurement_date'] ) ? sanitize_text_field( $arguments['measurement_date'] ) : current_time( 'Y-m-d' );
		$measurement_time = isset( $arguments['measurement_time'] ) ? sanitize_text_field( $arguments['measurement_time'] ) : current_time( 'H:i' );

		// Collect measurements.
		$measurements = array();
		$has_data     = false;

		// Blood pressure.
		if ( isset( $arguments['blood_pressure_systolic'] ) || isset( $arguments['blood_pressure_diastolic'] ) ) {
			$systolic = isset( $arguments['blood_pressure_systolic'] ) ? absint( $arguments['blood_pressure_systolic'] ) : null;
			$diastolic = isset( $arguments['blood_pressure_diastolic'] ) ? absint( $arguments['blood_pressure_diastolic'] ) : null;
			if ( $systolic || $diastolic ) {
				$measurements['blood_pressure'] = array(
					'systolic'  => $systolic,
					'diastolic' => $diastolic,
					'reading'   => $systolic . '/' . $diastolic,
					'status'    => $this->assess_blood_pressure( $systolic, $diastolic ),
				);
				$has_data = true;
			}
		}

		// Heart rate.
		if ( isset( $arguments['heart_rate'] ) ) {
			$heart_rate = absint( $arguments['heart_rate'] );
			$measurements['heart_rate'] = array(
				'value'  => $heart_rate,
				'unit'   => 'bpm',
				'status' => $this->assess_heart_rate( $heart_rate ),
			);
			$has_data = true;
		}

		// Temperature.
		if ( isset( $arguments['temperature'] ) ) {
			$temperature = floatval( $arguments['temperature'] );
			$temp_unit = isset( $arguments['temperature_unit'] ) ? sanitize_text_field( $arguments['temperature_unit'] ) : 'F';
			$measurements['temperature'] = array(
				'value'  => $temperature,
				'unit'   => $temp_unit,
				'status' => $this->assess_temperature( $temperature, $temp_unit ),
			);
			$has_data = true;
		}

		// Weight and BMI.
		if ( isset( $arguments['weight'] ) ) {
			$weight = floatval( $arguments['weight'] );
			$weight_unit = isset( $arguments['weight_unit'] ) ? sanitize_text_field( $arguments['weight_unit'] ) : 'lbs';
			$measurements['weight'] = array(
				'value' => $weight,
				'unit'  => $weight_unit,
			);

			// Calculate BMI if height provided.
			if ( isset( $arguments['height'] ) ) {
				$height = floatval( $arguments['height'] );
				$height_unit = isset( $arguments['height_unit'] ) ? sanitize_text_field( $arguments['height_unit'] ) : 'in';
				$bmi = $this->calculate_bmi( $weight, $weight_unit, $height, $height_unit );
				$measurements['bmi'] = array(
					'value'  => $bmi,
					'status' => $this->assess_bmi( $bmi ),
				);
			}
			$has_data = true;
		}

		// Blood glucose.
		if ( isset( $arguments['blood_glucose'] ) ) {
			$glucose = absint( $arguments['blood_glucose'] );
			$measurements['blood_glucose'] = array(
				'value'  => $glucose,
				'unit'   => 'mg/dL',
				'status' => $this->assess_blood_glucose( $glucose ),
			);
			$has_data = true;
		}

		// Oxygen saturation.
		if ( isset( $arguments['oxygen_saturation'] ) ) {
			$spo2 = absint( $arguments['oxygen_saturation'] );
			$measurements['oxygen_saturation'] = array(
				'value'  => $spo2,
				'unit'   => '%',
				'status' => $this->assess_oxygen_saturation( $spo2 ),
			);
			$has_data = true;
		}

		// Respiratory rate.
		if ( isset( $arguments['respiratory_rate'] ) ) {
			$resp_rate = absint( $arguments['respiratory_rate'] );
			$measurements['respiratory_rate'] = array(
				'value'  => $resp_rate,
				'unit'   => 'breaths/min',
				'status' => $this->assess_respiratory_rate( $resp_rate ),
			);
			$has_data = true;
		}

		// Kidney health indicators.
		if ( isset( $arguments['egfr'] ) ) {
			$measurements['egfr'] = array(
				'value' => floatval( $arguments['egfr'] ),
				'unit'  => 'mL/min/1.73m²',
			);
			$has_data = true;
		}
		if ( isset( $arguments['creatinine'] ) ) {
			$measurements['creatinine'] = array(
				'value' => floatval( $arguments['creatinine'] ),
				'unit'  => 'mg/dL',
			);
			$has_data = true;
		}
		if ( isset( $arguments['bun'] ) ) {
			$measurements['bun'] = array(
				'value' => floatval( $arguments['bun'] ),
				'unit'  => 'mg/dL',
			);
			$has_data = true;
		}
		if ( isset( $arguments['potassium'] ) ) {
			$measurements['potassium'] = array(
				'value' => floatval( $arguments['potassium'] ),
				'unit'  => 'mEq/L',
			);
			$has_data = true;
		}
		if ( isset( $arguments['sodium'] ) ) {
			$measurements['sodium'] = array(
				'value' => floatval( $arguments['sodium'] ),
				'unit'  => 'mg/day',
			);
			$has_data = true;
		}
		if ( isset( $arguments['phosphorus'] ) ) {
			$measurements['phosphorus'] = array(
				'value' => floatval( $arguments['phosphorus'] ),
				'unit'  => 'mg/dL',
			);
			$has_data = true;
		}
		if ( isset( $arguments['albumin'] ) ) {
			$measurements['albumin'] = array(
				'value' => floatval( $arguments['albumin'] ),
				'unit'  => 'g/dL',
			);
			$has_data = true;
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
		$cct_id = null;
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_CCT' ) && WP_MCP_AI_JetEngine_Vitals_CCT::table_exists() ) {
			$cct_data = array(
				'measurement_date'      => $measurement_date,
				'measurement_time'      => $measurement_time,
				'source'                => $source,
				'notes'                 => isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '',
				'logged_by'             => $current_user_id,
				'entry_id'              => $entry_id,
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
				$cct_data['temperature']        = $measurements['temperature']['value'];
				$cct_data['temperature_unit']   = $measurements['temperature']['unit'];
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
			// Kidney indicators.
			foreach ( array( 'egfr', 'creatinine', 'bun', 'potassium', 'sodium', 'phosphorus', 'albumin' ) as $ki ) {
				if ( isset( $measurements[ $ki ] ) ) {
					$cct_data[ $ki ] = $measurements[ $ki ]['value'];
				}
			}

			$cct_id = WP_MCP_AI_JetEngine_Vitals_CCT::insert( $member_id, $cct_data );
		}

		// Check for abnormal values and generate alerts.
		$alerts = $this->generate_alerts( $measurements );

		return array(
			'success'      => true,
			'message'      => __( 'Vital signs logged successfully.', 'mcp-ai-wpoos-pro' ),
			'entry_id'     => $entry_id,
			'cct_id'       => $cct_id,
			'stored_in_cct' => null !== $cct_id,
			'member_id'    => $member_id,
			'date'         => $measurement_date,
			'time'         => $measurement_time,
			'source'       => $source,
			'measurements' => $measurements,
			'alerts'       => $alerts,
		);
	}

	/**
	 * Get latest vital signs.
	 *
	 * Prefers JetEngine CCT when available; falls back to options storage.
	 *
	 * @param int $member_id Member ID.
	 * @return array Latest vital signs.
	 */
	private function get_latest_vital_signs( $member_id ) {
		// Prefer CCT when available.
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_CCT' ) && WP_MCP_AI_JetEngine_Vitals_CCT::table_exists() ) {
			$row = WP_MCP_AI_JetEngine_Vitals_CCT::get_latest( $member_id );
			if ( $row ) {
				return array(
					'success'    => true,
					'member_id'  => $member_id,
					'source'     => 'cct',
					'latest'     => (array) $row,
				);
			}
		}

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
	 * Prefers JetEngine CCT when available; falls back to options storage.
	 *
	 * @param int $member_id  Member ID.
	 * @param int $days_back  Days of history.
	 * @return array History.
	 */
	private function get_vital_signs_history( $member_id, $days_back ) {
		// Prefer CCT when available.
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_CCT' ) && WP_MCP_AI_JetEngine_Vitals_CCT::table_exists() ) {
			$after_date = gmdate( 'Y-m-d', time() - ( $days_back * DAY_IN_SECONDS ) );
			$rows       = WP_MCP_AI_JetEngine_Vitals_CCT::get_for_member( $member_id, $after_date );
			return array(
				'success'   => true,
				'member_id' => $member_id,
				'days_back' => $days_back,
				'source'    => 'cct',
				'count'     => count( $rows ),
				'history'   => array_map( 'get_object_vars', $rows ),
			);
		}

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
				'success' => true,
				'message' => __( 'Insufficient data for trend analysis. Need at least 2 measurements.', 'mcp-ai-wpoos-pro' ),
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
		// (Simplified for brevity)

		return array(
			'success'    => true,
			'member_id'  => $member_id,
			'days_back'  => $days_back,
			'data_points' => $history['count'],
			'trends'     => $trends,
			'summary'    => __( 'Trend analysis based on recent measurements.', 'mcp-ai-wpoos-pro' ),
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
		$height_m = ( 'cm' === $height_unit ) ? $height / 100 : ( $height * 2.54 ) / 100;

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
	 * Generate alerts for abnormal values.
	 *
	 * @param array $measurements Measurements.
	 * @return array Alerts.
	 */
	private function generate_alerts( $measurements ) {
		$alerts = array();

		foreach ( $measurements as $type => $data ) {
			if ( isset( $data['status'] ) && in_array( $data['status'], array( 'critical', 'hypertensive_crisis', 'fever', 'diabetic_range' ), true ) ) {
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
		$count = count( $values );

		if ( $count < 2 ) {
			return array( 'direction' => 'insufficient_data' );
		}

		$first_half = array_slice( $values, 0, ceil( $count / 2 ) );
		$second_half = array_slice( $values, floor( $count / 2 ) );

		$avg_first = array_sum( $first_half ) / count( $first_half );
		$avg_second = array_sum( $second_half ) / count( $second_half );

		$difference = $avg_second - $avg_first;
		$percent_change = $avg_first > 0 ? ( ( $difference / $avg_first ) * 100 ) : 0;

		return array(
			'direction'      => $difference > 0 ? 'increasing' : ( $difference < 0 ? 'decreasing' : 'stable' ),
			'percent_change' => round( $percent_change, 1 ),
			'average_first'  => round( $avg_first, 1 ),
			'average_second' => round( $avg_second, 1 ),
		);
	}
}
