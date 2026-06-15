<?php
/**
 * Healthcare Toolkit FHIR R4 Resource Builders
 *
 * Lightweight, dependency-free FHIR R4 resource builders used by the
 * `export_fhir_data` tool today and by the future `import_fhir_bundle` tool.
 * Builders return associative arrays that match the FHIR JSON
 * representation; callers serialise via `wp_json_encode()`.
 *
 * The builders intentionally keep their input loose (associative arrays)
 * so they can be reused by REST controllers, AI tools, and CLI exporters
 * without coupling to any specific CPT or storage backend.
 *
 * Every resource is run through the
 * `wp_mcp_ai_healthcare_fhir_resource` filter just before returning so
 * partners can inject extensions, profile URLs, or custom identifiers.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FHIR R4 resource builders.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Healthcare_FHIR {

	/**
	 * FHIR version emitted by the builders.
	 */
	const FHIR_VERSION = '4.0.1';

	/**
	 * Build a Patient resource.
	 *
	 * @param array $data Patient data.
	 *     @type string|int $id          Logical id (e.g. member post id).
	 *     @type string     $given_name  Given name.
	 *     @type string     $family_name Family name.
	 *     @type string     $gender      'male'|'female'|'other'|'unknown'.
	 *     @type string     $birth_date  ISO date (YYYY-MM-DD).
	 *     @type string     $mrn         Optional Medical Record Number.
	 *
	 * @return array
	 */
	public static function build_patient( array $data ) {
		$resource = array(
			'resourceType' => 'Patient',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'identifier'   => array(),
			'name'         => array(),
		);

		if ( ! empty( $data['mrn'] ) ) {
			$resource['identifier'][] = array(
				'system' => 'urn:oid:2.16.840.1.113883.4.1', // Common MRN OID placeholder.
				'value'  => sanitize_text_field( (string) $data['mrn'] ),
			);
		}

		if ( ! empty( $data['family_name'] ) || ! empty( $data['given_name'] ) ) {
			$name = array( 'use' => 'official' );
			if ( ! empty( $data['family_name'] ) ) {
				$name['family'] = sanitize_text_field( (string) $data['family_name'] );
			}
			if ( ! empty( $data['given_name'] ) ) {
				$name['given'] = array( sanitize_text_field( (string) $data['given_name'] ) );
			}
			$resource['name'][] = $name;
		}

		if ( ! empty( $data['gender'] ) ) {
			$gender = strtolower( (string) $data['gender'] );
			if ( in_array( $gender, array( 'male', 'female', 'other', 'unknown' ), true ) ) {
				$resource['gender'] = $gender;
			}
		}

		if ( ! empty( $data['birth_date'] ) ) {
			$resource['birthDate'] = self::sanitize_date( (string) $data['birth_date'] );
		}

		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build an Observation resource (vitals, labs, simple measurements).
	 *
	 * @param array $data Observation data.
	 *     @type string|int $id          Logical id.
	 *     @type string     $patient_id  Patient logical id.
	 *     @type string     $loinc_code  LOINC code (e.g. '8867-4').
	 *     @type string     $display     Display string for the code.
	 *     @type float      $value       Numeric value.
	 *     @type string     $unit        Unit display string.
	 *     @type string     $unit_code   UCUM code.
	 *     @type string     $effective   ISO 8601 instant.
	 *     @type string     $status      'final' (default), 'amended', etc.
	 *
	 * @return array
	 */
	public static function build_observation( array $data ) {
		$resource = array(
			'resourceType' => 'Observation',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'status'       => sanitize_key( $data['status'] ?? 'final' ),
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
			'code'         => array(
				'coding' => array(
					array(
						'system'  => 'http://loinc.org',
						'code'    => sanitize_text_field( (string) ( $data['loinc_code'] ?? '' ) ),
						'display' => sanitize_text_field( (string) ( $data['display'] ?? '' ) ),
					),
				),
			),
		);

		if ( ! empty( $data['patient_id'] ) ) {
			$resource['subject'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}

		if ( ! empty( $data['effective'] ) ) {
			$resource['effectiveDateTime'] = self::sanitize_instant( (string) $data['effective'] );
		}

		if ( isset( $data['value'] ) && is_numeric( $data['value'] ) ) {
			$resource['valueQuantity'] = array(
				'value'  => (float) $data['value'],
				'unit'   => sanitize_text_field( (string) ( $data['unit'] ?? '' ) ),
				'system' => 'http://unitsofmeasure.org',
				'code'   => sanitize_text_field( (string) ( $data['unit_code'] ?? '' ) ),
			);
		}

		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build a Condition resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_condition( array $data ) {
		$resource = array(
			'resourceType' => 'Condition',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'code'         => self::build_codeable_concept( $data['code'] ?? array(), 'http://hl7.org/fhir/sid/icd-10-cm' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['subject'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['onset_date'] ) ) {
			$resource['onsetDateTime'] = self::sanitize_date( (string) $data['onset_date'] );
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build a MedicationRequest resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_medication_request( array $data ) {
		$resource = array(
			'resourceType'              => 'MedicationRequest',
			'id'                        => self::sanitize_id( $data['id'] ?? '' ),
			'status'                    => sanitize_key( $data['status'] ?? 'active' ),
			'intent'                    => sanitize_key( $data['intent'] ?? 'order' ),
			'medicationCodeableConcept' => self::build_codeable_concept( $data['medication'] ?? array(), 'http://www.nlm.nih.gov/research/umls/rxnorm' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['subject'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['dosage_text'] ) ) {
			$resource['dosageInstruction'] = array(
				array( 'text' => sanitize_text_field( (string) $data['dosage_text'] ) ),
			);
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build an AllergyIntolerance resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_allergy_intolerance( array $data ) {
		$resource = array(
			'resourceType' => 'AllergyIntolerance',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'code'         => self::build_codeable_concept( $data['code'] ?? array(), 'http://snomed.info/sct' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['patient'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['criticality'] ) ) {
			$crit = sanitize_key( $data['criticality'] );
			if ( in_array( $crit, array( 'low', 'high', 'unable-to-assess' ), true ) ) {
				$resource['criticality'] = $crit;
			}
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build an Encounter resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_encounter( array $data ) {
		$resource = array(
			'resourceType' => 'Encounter',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'status'       => sanitize_key( $data['status'] ?? 'finished' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['subject'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['period_start'] ) || ! empty( $data['period_end'] ) ) {
			$period = array();
			if ( ! empty( $data['period_start'] ) ) {
				$period['start'] = self::sanitize_instant( (string) $data['period_start'] );
			}
			if ( ! empty( $data['period_end'] ) ) {
				$period['end'] = self::sanitize_instant( (string) $data['period_end'] );
			}
			$resource['period'] = $period;
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build an Immunization resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_immunization( array $data ) {
		$resource = array(
			'resourceType' => 'Immunization',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'status'       => sanitize_key( $data['status'] ?? 'completed' ),
			'vaccineCode'  => self::build_codeable_concept( $data['vaccine'] ?? array(), 'http://hl7.org/fhir/sid/cvx' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['patient'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['occurrence'] ) ) {
			$resource['occurrenceDateTime'] = self::sanitize_instant( (string) $data['occurrence'] );
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build an ImagingStudy resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_imaging_study( array $data ) {
		$resource = array(
			'resourceType' => 'ImagingStudy',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'status'       => sanitize_key( $data['status'] ?? 'available' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['subject'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['study_uid'] ) ) {
			$resource['identifier'] = array(
				array(
					'system' => 'urn:dicom:uid',
					'value'  => 'urn:oid:' . sanitize_text_field( (string) $data['study_uid'] ),
				),
			);
		}
		if ( ! empty( $data['modality'] ) ) {
			$resource['modality'] = array(
				array(
					'system' => 'http://dicom.nema.org/resources/ontology/DCM',
					'code'   => sanitize_text_field( (string) $data['modality'] ),
				),
			);
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build a DiagnosticReport resource.
	 *
	 * @param array $data Resource data.
	 * @return array
	 */
	public static function build_diagnostic_report( array $data ) {
		$resource = array(
			'resourceType' => 'DiagnosticReport',
			'id'           => self::sanitize_id( $data['id'] ?? '' ),
			'status'       => sanitize_key( $data['status'] ?? 'final' ),
			'code'         => self::build_codeable_concept( $data['code'] ?? array(), 'http://loinc.org' ),
		);
		if ( ! empty( $data['patient_id'] ) ) {
			$resource['subject'] = array(
				'reference' => 'Patient/' . self::sanitize_id( $data['patient_id'] ),
			);
		}
		if ( ! empty( $data['conclusion'] ) ) {
			$resource['conclusion'] = sanitize_textarea_field( (string) $data['conclusion'] );
		}
		return self::filter_resource( $resource, $data );
	}

	/**
	 * Build a Bundle wrapper around a list of resources.
	 *
	 * @param array  $resources Resources to include.
	 * @param string $type      Bundle type ('collection' default).
	 * @return array
	 */
	public static function build_bundle( array $resources, $type = 'collection' ) {
		$entries = array();
		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) || empty( $resource['resourceType'] ) ) {
				continue;
			}
			$entries[] = array(
				'resource' => $resource,
			);
		}
		$bundle = array(
			'resourceType' => 'Bundle',
			'type'         => sanitize_key( $type ),
			'entry'        => $entries,
		);
		/**
		 * Filter the resolved Bundle.
		 *
		 * @param array $bundle    Bundle resource.
		 * @param array $resources Source resources.
		 */
		return apply_filters( 'wp_mcp_ai_healthcare_fhir_resource', $bundle, array( 'resourceType' => 'Bundle' ) );
	}

	/*
	---------------------------------------------------------------------
	 * Internal helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Build a CodeableConcept from { code, display } or { coding: [ … ] } data.
	 *
	 * @param array  $code           Source data.
	 * @param string $default_system Default system URL when none supplied.
	 * @return array
	 */
	private static function build_codeable_concept( array $code, $default_system ) {
		if ( isset( $code['coding'] ) && is_array( $code['coding'] ) ) {
			return $code;
		}
		$coding = array();
		if ( ! empty( $code['code'] ) ) {
			$coding[] = array(
				'system'  => sanitize_text_field( (string) ( $code['system'] ?? $default_system ) ),
				'code'    => sanitize_text_field( (string) $code['code'] ),
				'display' => sanitize_text_field( (string) ( $code['display'] ?? '' ) ),
			);
		}
		$concept = array();
		if ( ! empty( $coding ) ) {
			$concept['coding'] = $coding;
		}
		if ( ! empty( $code['text'] ) ) {
			$concept['text'] = sanitize_text_field( (string) $code['text'] );
		}
		return $concept;
	}

	/**
	 * Run a resource through the `wp_mcp_ai_healthcare_fhir_resource` filter.
	 *
	 * @param array $resource Resource array.
	 * @param array $source   Source data passed to the builder.
	 * @return array
	 */
	private static function filter_resource( array $resource, array $source ) {
		/**
		 * Filter a FHIR resource just before serialisation.
		 *
		 * @param array $resource Resource array.
		 * @param array $source   Original input data passed to the builder.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_healthcare_fhir_resource', $resource, $source );
		return is_array( $filtered ) ? $filtered : $resource;
	}

	/**
	 * Sanitize a logical id.
	 *
	 * FHIR ids are restricted to A-Za-z0-9-. with a max length of 64.
	 *
	 * @param mixed $id Raw id.
	 * @return string
	 */
	private static function sanitize_id( $id ) {
		$id = (string) $id;
		$id = preg_replace( '/[^A-Za-z0-9\-.]/', '-', $id );
		$id = is_string( $id ) ? $id : '';
		if ( strlen( $id ) > 64 ) {
			$id = substr( $id, 0, 64 );
		}
		return $id;
	}

	/**
	 * Sanitize an ISO date (YYYY-MM-DD).
	 *
	 * @param string $date Raw value.
	 * @return string
	 */
	private static function sanitize_date( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		$ts = strtotime( $date );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	/**
	 * Sanitize an ISO 8601 instant.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_instant( $value ) {
		$ts = strtotime( (string) $value );
		return $ts ? gmdate( 'c', $ts ) : '';
	}
}
