<?php
/**
 * Tool for exporting health data in FHIR format.
 *
 * Exports health records in HL7 FHIR (Fast Healthcare Interoperability Resources)
 * format for interoperability with other healthcare systems.
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
 * Exports health data in FHIR-compliant format.
 */
class WP_MCP_AI_Tool_Export_FHIR_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_fhir_data';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export FHIR Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export member health records in HL7 FHIR (Fast Healthcare Interoperability Resources) format for seamless interoperability with other healthcare systems and EHRs. Supports FHIR R4 standard with JSON format. Includes Patient, Observation, MedicationStatement, AllergyIntolerance, Condition, and Immunization resources. HIPAA-compliant with audit trails.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to export data for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'resource_types'   => array(
					'type'        => 'array',
					'description' => __( 'FHIR resource types to export (optional, defaults to all)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'Patient', 'Observation', 'MedicationStatement', 'AllergyIntolerance', 'Condition', 'Immunization', 'Encounter' ),
					),
					'default'     => array( 'Patient', 'Observation', 'MedicationStatement', 'AllergyIntolerance', 'Condition', 'Immunization' ),
				),
				'format'           => array(
					'type'        => 'string',
					'description' => __( 'Export format (optional, default: json)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'json', 'bundle' ),
					'default'     => 'json',
				),
				'include_metadata' => array(
					'type'        => 'boolean',
					'description' => __( 'Include metadata and references (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'date_from'        => array(
					'type'        => 'string',
					'description' => __( 'Include only records from this date onwards (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'date_to'          => array(
					'type'        => 'string',
					'description' => __( 'Include only records up to this date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
			),
			'required'             => array( 'member_id' ),
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
			'profession_tags'       => array( 'healthcare_provider', 'health_informatics' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'pii-data', 'hipaa-relevant', 'data-export' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export health data.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id        = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$resource_types   = isset( $arguments['resource_types'] ) ? (array) $arguments['resource_types'] : array( 'Patient', 'Observation', 'MedicationStatement', 'AllergyIntolerance', 'Condition', 'Immunization' );
		$format           = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'json';
		$include_metadata = isset( $arguments['include_metadata'] ) ? (bool) $arguments['include_metadata'] : true;
		$date_from        = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : null;
		$date_to          = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : null;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build FHIR resources.
		$fhir_resources = array();

		foreach ( $resource_types as $resource_type ) {
			switch ( $resource_type ) {
				case 'Patient':
					$fhir_resources[] = $this->build_patient_resource( $member_id, $include_metadata );
					break;

				case 'Observation':
					$observations   = $this->build_observation_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $observations );
					break;

				case 'MedicationStatement':
					$medications    = $this->build_medication_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $medications );
					break;

				case 'AllergyIntolerance':
					$allergies      = $this->build_allergy_resources( $member_id );
					$fhir_resources = array_merge( $fhir_resources, $allergies );
					break;

				case 'Condition':
					$conditions     = $this->build_condition_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $conditions );
					break;

				case 'Immunization':
					$immunizations  = $this->build_immunization_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $immunizations );
					break;
			}
		}

		// Format output.
		if ( 'bundle' === $format ) {
			$output = $this->create_fhir_bundle( $fhir_resources, $include_metadata );
		} else {
			$output = $fhir_resources;
		}

		// Log export activity for HIPAA compliance.
		if ( function_exists( 'wp_mcp_ai_log_activity' ) ) {
			wp_mcp_ai_log_activity(
				'fhir_data_export',
				sprintf(
					'Exported FHIR data for member %d (%d resources)',
					$member_id,
					count( $fhir_resources )
				)
			);
		}

		return array(
			'success'        => true,
			'message'        => __( 'Health data exported successfully in FHIR format.', 'mcp-ai-wpoos-pro' ),
			'member_id'      => $member_id,
			'member_name'    => $member->post_title,
			'fhir_version'   => 'R4',
			'format'         => $format,
			'resource_count' => count( $fhir_resources ),
			'resource_types' => $resource_types,
			'export_date'    => current_time( 'c' ),
			'data'           => $output,
		);
	}

	/**
	 * Build Patient FHIR resource.
	 *
	 * @param int  $member_id        Member ID.
	 * @param bool $include_metadata Include metadata.
	 * @return array FHIR Patient resource.
	 */
	private function build_patient_resource( $member_id, $include_metadata ) {
		$member      = get_post( $member_id );
		$types       = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$member_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		$resource = array(
			'resourceType' => 'Patient',
			'id'           => 'member-' . $member_id,
			'name'         => array(
				array(
					'use'  => 'official',
					'text' => $member->post_title,
				),
			),
		);

		// Add birth date.
		$birth_date = get_post_meta( $member_id, '_member_date_of_birth', true );
		if ( $birth_date ) {
			$resource['birthDate'] = $birth_date;
		}

		// Add gender.
		$gender = get_post_meta( $member_id, '_member_gender', true );
		if ( $gender ) {
			$resource['gender'] = strtolower( $gender );
		}

		// Add contact info.
		$email = get_post_meta( $member_id, '_member_email', true );
		$phone = get_post_meta( $member_id, '_member_phone', true );
		if ( $email || $phone ) {
			$resource['telecom'] = array();
			if ( $email ) {
				$resource['telecom'][] = array(
					'system' => 'email',
					'value'  => $email,
				);
			}
			if ( $phone ) {
				$resource['telecom'][] = array(
					'system' => 'phone',
					'value'  => $phone,
				);
			}
		}

		// Add metadata if requested.
		if ( $include_metadata ) {
			$resource['meta'] = array(
				'lastUpdated' => gmdate( 'c', strtotime( $member->post_modified ) ),
			);
		}

		return $resource;
	}

	/**
	 * Build Observation FHIR resources (vital signs).
	 *
	 * Prefers the vitals_log CCT (primary store) when JetEngine is active;
	 * falls back to the options-based store when the CCT table is unavailable.
	 *
	 * @param int         $member_id Member ID.
	 * @param string|null $date_from Date from (YYYY-MM-DD).
	 * @param string|null $date_to   Date to   (YYYY-MM-DD).
	 * @return array FHIR Observation resources.
	 */
	private function build_observation_resources( $member_id, $date_from, $date_to ) {
		$observations = array();

		// ── vitals_log CCT (primary source) ───────────────────────────────
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_Log_CCT' ) && WP_MCP_AI_JetEngine_Vitals_Log_CCT::table_exists() ) {
			$after_date = $date_from ? $date_from : '';
			$rows       = WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_for_member( $member_id, $after_date );

			foreach ( $rows as $row ) {
				if ( $date_to && isset( $row->measurement_date ) && strcmp( $row->measurement_date, $date_to ) > 0 ) {
					continue;
				}
				$obs = $this->build_observations_from_vitals_log_row( $member_id, $row );
				foreach ( $obs as $o ) {
					$observations[] = $o;
				}
			}

			return $observations;
		}

		// ── Options-based fallback ────────────────────────────────────────
		$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
		$vital_signs     = get_option( $vital_signs_key, array() );

		foreach ( $vital_signs as $entry_id => $entry ) {
			if ( $date_from && strcmp( $entry['date'], $date_from ) < 0 ) {
				continue;
			}
			if ( $date_to && strcmp( $entry['date'], $date_to ) > 0 ) {
				continue;
			}

			foreach ( $entry['measurements'] as $type => $data ) {
				$observation = array(
					'resourceType'      => 'Observation',
					'id'                => 'obs-' . $entry_id . '-' . $type,
					'status'            => 'final',
					'category'          => array(
						array(
							'coding' => array(
								array(
									'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
									'code'    => 'vital-signs',
									'display' => 'Vital Signs',
								),
							),
						),
					),
					'subject'           => array(
						'reference' => 'Patient/member-' . $member_id,
					),
					'effectiveDateTime' => $entry['date'] . 'T' . $entry['time'] . ':00Z',
				);

				switch ( $type ) {
					case 'blood_pressure':
						$observation['code']      = array(
							'coding' => array(
								array(
									'system'  => 'http://loinc.org',
									'code'    => '85354-9',
									'display' => 'Blood pressure panel',
								),
							),
						);
						$observation['component'] = array(
							array(
								'code'          => array(
									'coding' => array(
										array(
											'system'  => 'http://loinc.org',
											'code'    => '8480-6',
											'display' => 'Systolic blood pressure',
										),
									),
								),
								'valueQuantity' => array(
									'value'  => $data['systolic'],
									'unit'   => 'mmHg',
									'system' => 'http://unitsofmeasure.org',
									'code'   => 'mm[Hg]',
								),
							),
							array(
								'code'          => array(
									'coding' => array(
										array(
											'system'  => 'http://loinc.org',
											'code'    => '8462-4',
											'display' => 'Diastolic blood pressure',
										),
									),
								),
								'valueQuantity' => array(
									'value'  => $data['diastolic'],
									'unit'   => 'mmHg',
									'system' => 'http://unitsofmeasure.org',
									'code'   => 'mm[Hg]',
								),
							),
						);
						break;

					case 'heart_rate':
						$observation['code']          = array(
							'coding' => array(
								array(
									'system'  => 'http://loinc.org',
									'code'    => '8867-4',
									'display' => 'Heart rate',
								),
							),
						);
						$observation['valueQuantity'] = array(
							'value'  => $data['value'],
							'unit'   => 'beats/minute',
							'system' => 'http://unitsofmeasure.org',
							'code'   => '/min',
						);
						break;
				}

				$observations[] = $observation;
			}
		}

		return $observations;
	}

	/**
	 * Convert a single vitals_log CCT row into one or more FHIR Observation resources.
	 *
	 * The vitals_log CCT stores all measurements as flat columns on a single row
	 * (e.g. bp_systolic, bp_diastolic, heart_rate …).  This helper emits one
	 * Observation per logical measurement group so that the resulting FHIR Bundle
	 * is fully compliant with the R4 Observation profile.
	 *
	 * @param int    $member_id Member post ID.
	 * @param object $row       CCT row from WP_MCP_AI_JetEngine_Vitals_Log_CCT::get_for_member().
	 * @return array            Array of FHIR Observation resource arrays.
	 */
	private function build_observations_from_vitals_log_row( $member_id, $row ) {
		$observations = array();
		$row          = (object) $row;

		$date        = ! empty( $row->measurement_date ) ? $row->measurement_date : '';
		$time        = ! empty( $row->measurement_time ) ? $row->measurement_time : '00:00';
		$effective   = $date . 'T' . $time . ':00Z';
		$row_id = ! empty( $row->{'_ID'} ) ? (int) $row->{'_ID'} : 0;
		$subject_ref = array( 'reference' => 'Patient/member-' . $member_id );

		$category = array(
			array(
				'coding' => array(
					array(
						'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
						'code'    => 'vital-signs',
						'display' => 'Vital Signs',
					),
				),
			),
		);

		// Blood pressure panel (only when both systolic and diastolic present).
		if ( ! empty( $row->bp_systolic ) && ! empty( $row->bp_diastolic ) ) {
			$observations[] = array(
				'resourceType'      => 'Observation',
				'id'                => 'obs-vl-' . $row_id . '-bp',
				'status'            => 'final',
				'category'          => $category,
				'code'              => array(
					'coding' => array(
						array(
							'system'  => 'http://loinc.org',
							'code'    => '85354-9',
							'display' => 'Blood pressure panel',
						),
					),
				),
				'subject'           => $subject_ref,
				'effectiveDateTime' => $effective,
				'component'         => array(
					array(
						'code'          => array(
							'coding' => array(
								array(
									'system'  => 'http://loinc.org',
									'code'    => '8480-6',
									'display' => 'Systolic blood pressure',
								),
							),
						),
						'valueQuantity' => array(
							'value'  => (float) $row->bp_systolic,
							'unit'   => 'mmHg',
							'system' => 'http://unitsofmeasure.org',
							'code'   => 'mm[Hg]',
						),
					),
					array(
						'code'          => array(
							'coding' => array(
								array(
									'system'  => 'http://loinc.org',
									'code'    => '8462-4',
									'display' => 'Diastolic blood pressure',
								),
							),
						),
						'valueQuantity' => array(
							'value'  => (float) $row->bp_diastolic,
							'unit'   => 'mmHg',
							'system' => 'http://unitsofmeasure.org',
							'code'   => 'mm[Hg]',
						),
					),
				),
			);
		}

		// Simple scalar vital-sign observations.
		$scalar_map = array(
			'heart_rate'        => array(
				'loinc'   => '8867-4',
				'display' => 'Heart rate',
				'unit'    => 'beats/minute',
				'ucum'    => '/min',
			),
			'temperature'       => array(
				'loinc'   => '8310-5',
				'display' => 'Body temperature',
				'unit'    => 'degF',
				'ucum'    => '[degF]',
			),
			'weight'            => array(
				'loinc'   => '29463-7',
				'display' => 'Body weight',
				'unit'    => 'lbs',
				'ucum'    => '[lb_av]',
			),
			'bmi'               => array(
				'loinc'   => '39156-5',
				'display' => 'Body mass index',
				'unit'    => 'kg/m2',
				'ucum'    => 'kg/m2',
			),
			'blood_glucose'     => array(
				'loinc'   => '2339-0',
				'display' => 'Glucose [Mass/volume] in Blood',
				'unit'    => 'mg/dL',
				'ucum'    => 'mg/dL',
			),
			'oxygen_saturation' => array(
				'loinc'   => '59408-5',
				'display' => 'Oxygen saturation',
				'unit'    => '%',
				'ucum'    => '%',
			),
			'respiratory_rate'  => array(
				'loinc'   => '9279-1',
				'display' => 'Respiratory rate',
				'unit'    => 'breaths/min',
				'ucum'    => '/min',
			),
			'egfr'              => array(
				'loinc'   => '33914-3',
				'display' => 'eGFR',
				'unit'    => 'mL/min/1.73m2',
				'ucum'    => 'mL/min/{1.73_m2}',
			),
			'creatinine'        => array(
				'loinc'   => '38483-4',
				'display' => 'Creatinine [Mass/volume] in Blood',
				'unit'    => 'mg/dL',
				'ucum'    => 'mg/dL',
			),
			'bun'               => array(
				'loinc'   => '3094-0',
				'display' => 'Urea nitrogen [Mass/volume] in Serum or Plasma',
				'unit'    => 'mg/dL',
				'ucum'    => 'mg/dL',
			),
			'potassium'         => array(
				'loinc'   => '2823-3',
				'display' => 'Potassium [Moles/volume] in Serum or Plasma',
				'unit'    => 'mEq/L',
				'ucum'    => 'meq/L',
			),
			'sodium'            => array(
				'loinc'   => '2951-2',
				'display' => 'Sodium [Moles/volume] in Serum or Plasma',
				'unit'    => 'mEq/L',
				'ucum'    => 'meq/L',
			),
			'phosphorus'        => array(
				'loinc'   => '2777-1',
				'display' => 'Phosphate [Mass/volume] in Serum or Plasma',
				'unit'    => 'mg/dL',
				'ucum'    => 'mg/dL',
			),
			'albumin'           => array(
				'loinc'   => '1751-7',
				'display' => 'Albumin [Mass/volume] in Serum or Plasma',
				'unit'    => 'g/dL',
				'ucum'    => 'g/dL',
			),
		);

		foreach ( $scalar_map as $field => $meta ) {
			if ( ! isset( $row->$field ) || '' === (string) $row->$field ) {
				continue;
			}
			$observations[] = array(
				'resourceType'      => 'Observation',
				'id'                => 'obs-vl-' . $row_id . '-' . $field,
				'status'            => 'final',
				'category'          => $category,
				'code'              => array(
					'coding' => array(
						array(
							'system'  => 'http://loinc.org',
							'code'    => $meta['loinc'],
							'display' => $meta['display'],
						),
					),
				),
				'subject'           => $subject_ref,
				'effectiveDateTime' => $effective,
				'valueQuantity'     => array(
					'value'  => (float) $row->$field,
					'unit'   => $meta['unit'],
					'system' => 'http://unitsofmeasure.org',
					'code'   => $meta['ucum'],
				),
			);
		}

		return $observations;
	}

	/**
	 * Build MedicationStatement FHIR resources.
	 *
	 * @param int         $member_id Member ID.
	 * @param string|null $date_from Date from.
	 * @param string|null $date_to   Date to.
	 * @return array FHIR MedicationStatement resources.
	 */
	private function build_medication_resources( $member_id, $date_from, $date_to ) {
		$medications = array();

		$query_args = array(
			'post_type'   => 'mcp_ai_prescription',
			'post_status' => 'publish',
			'meta_key'    => '_prescription_member_id',
			'meta_value'  => $member_id,
		);

		if ( $date_from || $date_to ) {
			$query_args['date_query'] = array();
			if ( $date_from ) {
				$query_args['date_query']['after'] = $date_from;
			}
			if ( $date_to ) {
				$query_args['date_query']['before'] = $date_to;
			}
		}

		$max = class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' )
			? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_fhir_data', 0, 1000 )
			: 1000;

		$iterator = class_exists( 'WP_MCP_AI_Batch_Iterator' )
			? new WP_MCP_AI_Batch_Iterator( 'fhir_export_medications', array( 'max_items' => $max ) )
			: null;

		if ( null === $iterator ) {
			$query_args['posts_per_page'] = $max;
			$prescription_ids             = get_posts( array_merge( $query_args, array( 'fields' => 'ids' ) ) );
			$batches                      = array( $prescription_ids );
		} else {
			$query_args['fields'] = 'ids';
			$batches              = $iterator->paged_iterate( $query_args );
		}

		foreach ( $batches as $batch ) {
			foreach ( $batch as $prescription_id ) {
				$prescription = get_post( $prescription_id );
				if ( ! $prescription ) {
					continue;
				}

				$medication = array(
					'resourceType'              => 'MedicationStatement',
					'id'                        => 'med-' . $prescription_id,
					'status'                    => 'active',
					'medicationCodeableConcept' => array(
						'text' => $prescription->post_title,
					),
					'subject'                   => array(
						'reference' => 'Patient/member-' . $member_id,
					),
				);

				$dosage    = get_post_meta( $prescription_id, '_prescription_dosage', true );
				$frequency = get_post_meta( $prescription_id, '_prescription_frequency', true );
				if ( $dosage || $frequency ) {
					$medication['dosage'] = array(
						array(
							'text' => trim( $dosage . ' ' . $frequency ),
						),
					);
				}

				$start_date = get_post_meta( $prescription_id, '_prescription_start_date', true );
				if ( $start_date ) {
					$medication['effectivePeriod'] = array(
						'start' => $start_date,
					);
				}

				$medications[] = $medication;
			}
		}

		if ( null !== $iterator ) {
			$iterator->complete();
		}

		return $medications;
	}

	/**
	 * Build AllergyIntolerance FHIR resources.
	 *
	 * @param int $member_id Member ID.
	 * @return array FHIR AllergyIntolerance resources.
	 */
	private function build_allergy_resources( $member_id ) {
		$allergies = array();

		$max = class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' )
			? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_fhir_data', 0, 1000 )
			: 1000;

		$query_args = array(
			'post_type'   => 'mcp_ai_allergy',
			'post_status' => 'publish',
			'meta_key'    => '_allergy_member_id',
			'meta_value'  => $member_id,
			'fields'      => 'ids',
		);

		if ( class_exists( 'WP_MCP_AI_Batch_Iterator' ) ) {
			$iterator = new WP_MCP_AI_Batch_Iterator( 'fhir_export_allergies', array( 'max_items' => $max ) );
			$batches  = $iterator->paged_iterate( $query_args );
		} else {
			$query_args['posts_per_page'] = $max;
			$iterator                     = null;
			$batches                      = array( get_posts( $query_args ) );
		}

		foreach ( $batches as $batch ) {
			foreach ( $batch as $allergy_id ) {
				$post = get_post( $allergy_id );
				if ( ! $post ) {
					continue;
				}

				$severity_terms = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'names' ) );
				$severity       = ! empty( $severity_terms ) && ! is_wp_error( $severity_terms ) ? strtolower( $severity_terms[0] ) : 'moderate';

				$allergy = array(
					'resourceType'   => 'AllergyIntolerance',
					'id'             => 'allergy-' . $allergy_id,
					'clinicalStatus' => array(
						'coding' => array(
							array(
								'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
								'code'   => 'active',
							),
						),
					),
					'code'           => array(
						'text' => $post->post_title,
					),
					'patient'        => array(
						'reference' => 'Patient/member-' . $member_id,
					),
					'criticality'    => 'severe' === $severity ? 'high' : ( 'mild' === $severity ? 'low' : 'unable-to-assess' ),
				);

				$allergies[] = $allergy;
			}
		}

		if ( null !== $iterator ) {
			$iterator->complete();
		}

		return $allergies;
	}

	/**
	 * Build Condition FHIR resources.
	 *
	 * @param int         $member_id Member ID.
	 * @param string|null $date_from Date from.
	 * @param string|null $date_to   Date to.
	 * @return array FHIR Condition resources.
	 */
	private function build_condition_resources( $member_id, $date_from, $date_to ) {
		// Simplified: Extract conditions from medical records.
		return array();
	}

	/**
	 * Build Immunization FHIR resources.
	 *
	 * @param int         $member_id Member ID.
	 * @param string|null $date_from Date from.
	 * @param string|null $date_to   Date to.
	 * @return array FHIR Immunization resources.
	 */
	private function build_immunization_resources( $member_id, $date_from, $date_to ) {
		$immunizations = array();

		$query_args = array(
			'post_type'   => 'mcp_ai_med_record',
			'post_status' => 'publish',
			'fields'      => 'ids',
			'meta_query'  => array(
				array(
					'key'   => '_record_member_id',
					'value' => $member_id,
				),
				array(
					'key'   => '_is_vaccination',
					'value' => true,
				),
			),
		);

		if ( $date_from || $date_to ) {
			$query_args['meta_query'][] = array(
				'key'     => '_record_date',
				'value'   => array( $date_from, $date_to ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		}

		$max = class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' )
			? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'export_fhir_data', 0, 1000 )
			: 1000;

		if ( class_exists( 'WP_MCP_AI_Batch_Iterator' ) ) {
			$iterator = new WP_MCP_AI_Batch_Iterator( 'fhir_export_immunizations', array( 'max_items' => $max ) );
			$batches  = $iterator->paged_iterate( $query_args );
		} else {
			$query_args['posts_per_page'] = $max;
			$iterator                     = null;
			$batches                      = array( get_posts( $query_args ) );
		}

		foreach ( $batches as $batch ) {
			foreach ( $batch as $record_id ) {
				$immunization = array(
					'resourceType'       => 'Immunization',
					'id'                 => 'imm-' . $record_id,
					'status'             => 'completed',
					'vaccineCode'        => array(
						'text' => get_post_meta( $record_id, '_vaccination_name', true ),
					),
					'patient'            => array(
						'reference' => 'Patient/member-' . $member_id,
					),
					'occurrenceDateTime' => get_post_meta( $record_id, '_record_date', true ),
				);

				$lot_number = get_post_meta( $record_id, '_vaccination_lot_number', true );
				if ( $lot_number ) {
					$immunization['lotNumber'] = $lot_number;
				}

				$immunizations[] = $immunization;
			}
		}

		if ( null !== $iterator ) {
			$iterator->complete();
		}

		return $immunizations;
	}

	/**
	 * Create FHIR Bundle.
	 *
	 * @param array $resources        FHIR resources.
	 * @param bool  $include_metadata Include metadata.
	 * @return array FHIR Bundle.
	 */
	private function create_fhir_bundle( $resources, $include_metadata ) {
		$bundle = array(
			'resourceType' => 'Bundle',
			'type'         => 'collection',
			'timestamp'    => current_time( 'c' ),
			'total'        => count( $resources ),
			'entry'        => array(),
		);

		foreach ( $resources as $resource ) {
			$bundle['entry'][] = array(
				'resource' => $resource,
			);
		}

		if ( $include_metadata ) {
			$bundle['id'] = 'bundle-' . uniqid();
		}

		return $bundle;
	}
}
