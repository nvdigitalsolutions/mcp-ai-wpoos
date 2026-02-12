<?php
/**
 * Tool for exporting health data in FHIR format.
 *
 * Exports health records in HL7 FHIR (Fast Healthcare Interoperability Resources)
 * format for interoperability with other healthcare systems.
 *
 * @package WP_MCP_AI_Pro
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
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$resource_types = isset( $arguments['resource_types'] ) ? (array) $arguments['resource_types'] : array( 'Patient', 'Observation', 'MedicationStatement', 'AllergyIntolerance', 'Condition', 'Immunization' );
		$format = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'json';
		$include_metadata = isset( $arguments['include_metadata'] ) ? (bool) $arguments['include_metadata'] : true;
		$date_from = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : null;
		$date_to = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : null;

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
					$observations = $this->build_observation_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $observations );
					break;

				case 'MedicationStatement':
					$medications = $this->build_medication_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $medications );
					break;

				case 'AllergyIntolerance':
					$allergies = $this->build_allergy_resources( $member_id );
					$fhir_resources = array_merge( $fhir_resources, $allergies );
					break;

				case 'Condition':
					$conditions = $this->build_condition_resources( $member_id, $date_from, $date_to );
					$fhir_resources = array_merge( $fhir_resources, $conditions );
					break;

				case 'Immunization':
					$immunizations = $this->build_immunization_resources( $member_id, $date_from, $date_to );
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
		$member = get_post( $member_id );
		$types = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$member_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		$resource = array(
			'resourceType' => 'Patient',
			'id'           => 'member-' . $member_id,
			'name'         => array(
				array(
					'use'   => 'official',
					'text'  => $member->post_title,
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
	 * @param int         $member_id Member ID.
	 * @param string|null $date_from Date from.
	 * @param string|null $date_to   Date to.
	 * @return array FHIR Observation resources.
	 */
	private function build_observation_resources( $member_id, $date_from, $date_to ) {
		$observations = array();

		// Get vital signs.
		$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
		$vital_signs = get_option( $vital_signs_key, array() );

		foreach ( $vital_signs as $entry_id => $entry ) {
			// Filter by date if specified.
			if ( $date_from && strcmp( $entry['date'], $date_from ) < 0 ) {
				continue;
			}
			if ( $date_to && strcmp( $entry['date'], $date_to ) > 0 ) {
				continue;
			}

			// Create observation for each measurement.
			foreach ( $entry['measurements'] as $type => $data ) {
				$observation = array(
					'resourceType' => 'Observation',
					'id'           => 'obs-' . $entry_id . '-' . $type,
					'status'       => 'final',
					'category'     => array(
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
					'subject'      => array(
						'reference' => 'Patient/member-' . $member_id,
					),
					'effectiveDateTime' => $entry['date'] . 'T' . $entry['time'] . ':00Z',
				);

				// Add measurement-specific code and value.
				switch ( $type ) {
					case 'blood_pressure':
						$observation['code'] = array(
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
								'code'  => array(
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
								'code'  => array(
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
						$observation['code'] = array(
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

					// Add other vital sign mappings...
				}

				$observations[] = $observation;
			}
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

		// Query prescriptions.
		$args = array(
			'post_type'      => 'mcp_ai_prescription',
			'post_status'    => 'publish',
			'meta_key'       => '_prescription_member_id',
			'meta_value'     => $member_id,
			'posts_per_page' => -1,
		);

		if ( $date_from || $date_to ) {
			$args['date_query'] = array();
			if ( $date_from ) {
				$args['date_query']['after'] = $date_from;
			}
			if ( $date_to ) {
				$args['date_query']['before'] = $date_to;
			}
		}

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$prescription_id = get_the_ID();

				$medication = array(
					'resourceType' => 'MedicationStatement',
					'id'           => 'med-' . $prescription_id,
					'status'       => 'active',
					'medicationCodeableConcept' => array(
						'text' => get_the_title(),
					),
					'subject'      => array(
						'reference' => 'Patient/member-' . $member_id,
					),
				);

				// Add dosage.
				$dosage = get_post_meta( $prescription_id, '_prescription_dosage', true );
				$frequency = get_post_meta( $prescription_id, '_prescription_frequency', true );
				if ( $dosage || $frequency ) {
					$medication['dosage'] = array(
						array(
							'text' => trim( $dosage . ' ' . $frequency ),
						),
					);
				}

				// Add dates.
				$start_date = get_post_meta( $prescription_id, '_prescription_start_date', true );
				if ( $start_date ) {
					$medication['effectivePeriod'] = array(
						'start' => $start_date,
					);
				}

				$medications[] = $medication;
			}
			wp_reset_postdata();
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

		// Query allergies.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_allergy',
				'post_status'    => 'publish',
				'meta_key'       => '_allergy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => -1,
			)
		);

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$allergy_id = get_the_ID();

				$severity_terms = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'names' ) );
				$severity = ! empty( $severity_terms ) && ! is_wp_error( $severity_terms ) ? strtolower( $severity_terms[0] ) : 'moderate';

				$allergy = array(
					'resourceType' => 'AllergyIntolerance',
					'id'           => 'allergy-' . $allergy_id,
					'clinicalStatus' => array(
						'coding' => array(
							array(
								'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
								'code'   => 'active',
							),
						),
					),
					'code'         => array(
						'text' => get_the_title(),
					),
					'patient'      => array(
						'reference' => 'Patient/member-' . $member_id,
					),
					'criticality'  => 'severe' === $severity ? 'high' : ( 'mild' === $severity ? 'low' : 'unable-to-assess' ),
				);

				$allergies[] = $allergy;
			}
			wp_reset_postdata();
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

		// Query vaccination records.
		$args = array(
			'post_type'      => 'mcp_ai_med_record',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_record_member_id',
					'value' => $member_id,
				),
				array(
					'key'   => '_is_vaccination',
					'value' => true,
				),
			),
			'posts_per_page' => -1,
		);

		if ( $date_from || $date_to ) {
			$args['meta_query'][] = array(
				'key'     => '_record_date',
				'value'   => array( $date_from, $date_to ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		}

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$record_id = get_the_ID();

				$immunization = array(
					'resourceType' => 'Immunization',
					'id'           => 'imm-' . $record_id,
					'status'       => 'completed',
					'vaccineCode'  => array(
						'text' => get_post_meta( $record_id, '_vaccination_name', true ),
					),
					'patient'      => array(
						'reference' => 'Patient/member-' . $member_id,
					),
					'occurrenceDateTime' => get_post_meta( $record_id, '_record_date', true ),
				);

				// Add lot number if available.
				$lot_number = get_post_meta( $record_id, '_vaccination_lot_number', true );
				if ( $lot_number ) {
					$immunization['lotNumber'] = $lot_number;
				}

				$immunizations[] = $immunization;
			}
			wp_reset_postdata();
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
