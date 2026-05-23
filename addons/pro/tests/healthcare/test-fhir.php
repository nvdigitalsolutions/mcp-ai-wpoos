<?php
/**
 * Tests for the unified Healthcare Toolkit FHIR R4 builders.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Healthcare_FHIR' ) ) {
	$fhir_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/class-wp-mcp-ai-healthcare-fhir.php';
	if ( file_exists( $fhir_path ) ) {
		require_once $fhir_path;
	}
}

/**
 * Test case for WP_MCP_AI_Healthcare_FHIR.
 */
class Test_Healthcare_FHIR extends WP_UnitTestCase {

	/**
	 * Drop filter registrations between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_healthcare_fhir_resource' );
		parent::tearDown();
	}

	/**
	 * Build a minimal Patient resource.
	 */
	public function test_build_patient() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_patient(
			array(
				'id'          => 42,
				'given_name'  => 'Alice',
				'family_name' => 'Doe',
				'gender'      => 'female',
				'birth_date'  => '1980-04-15',
				'mrn'         => 'MRN-12345',
			)
		);
		$this->assertSame( 'Patient', $resource['resourceType'] );
		$this->assertSame( '42', $resource['id'] );
		$this->assertSame( 'female', $resource['gender'] );
		$this->assertSame( '1980-04-15', $resource['birthDate'] );
		$this->assertSame( 'Doe', $resource['name'][0]['family'] );
		$this->assertSame( array( 'Alice' ), $resource['name'][0]['given'] );
		$this->assertSame( 'MRN-12345', $resource['identifier'][0]['value'] );
	}

	/**
	 * Invalid gender values are dropped.
	 */
	public function test_patient_gender_validation() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_patient(
			array(
				'id'     => 1,
				'gender' => 'invalid',
			)
		);
		$this->assertArrayNotHasKey( 'gender', $resource );
	}

	/**
	 * Build an Observation resource with a numeric value.
	 */
	public function test_build_observation_numeric() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_observation(
			array(
				'id'         => 'obs-1',
				'patient_id' => 42,
				'loinc_code' => '8867-4',
				'display'    => 'Heart rate',
				'value'      => 72,
				'unit'       => 'bpm',
				'unit_code'  => '/min',
				'effective'  => '2026-01-15T10:30:00Z',
			)
		);
		$this->assertSame( 'Observation', $resource['resourceType'] );
		$this->assertSame( 'final', $resource['status'] );
		$this->assertSame( '8867-4', $resource['code']['coding'][0]['code'] );
		$this->assertSame( 'Heart rate', $resource['code']['coding'][0]['display'] );
		$this->assertSame( 'Patient/42', $resource['subject']['reference'] );
		$this->assertSame( 72.0, $resource['valueQuantity']['value'] );
		$this->assertSame( 'bpm', $resource['valueQuantity']['unit'] );
	}

	/**
	 * Conditions reference Patient/{id}.
	 */
	public function test_build_condition() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_condition(
			array(
				'id'         => 'cond-1',
				'patient_id' => 42,
				'code'       => array(
					'code'    => 'I10',
					'display' => 'Hypertension',
				),
				'onset_date' => '2023-05-01',
			)
		);
		$this->assertSame( 'Condition', $resource['resourceType'] );
		$this->assertSame( 'I10', $resource['code']['coding'][0]['code'] );
		$this->assertSame( 'http://hl7.org/fhir/sid/icd-10-cm', $resource['code']['coding'][0]['system'] );
		$this->assertSame( '2023-05-01', $resource['onsetDateTime'] );
	}

	/**
	 * MedicationRequest defaults status/intent and uses RxNorm.
	 */
	public function test_build_medication_request() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_medication_request(
			array(
				'id'          => 'mr-1',
				'patient_id'  => 42,
				'medication'  => array(
					'code'    => '6809',
					'display' => 'Metformin',
				),
				'dosage_text' => '500 mg twice daily',
			)
		);
		$this->assertSame( 'MedicationRequest', $resource['resourceType'] );
		$this->assertSame( 'active', $resource['status'] );
		$this->assertSame( 'order', $resource['intent'] );
		$this->assertSame(
			'http://www.nlm.nih.gov/research/umls/rxnorm',
			$resource['medicationCodeableConcept']['coding'][0]['system']
		);
		$this->assertSame( '500 mg twice daily', $resource['dosageInstruction'][0]['text'] );
	}

	/**
	 * AllergyIntolerance criticality is validated.
	 */
	public function test_build_allergy_intolerance() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_allergy_intolerance(
			array(
				'id'          => 'al-1',
				'patient_id'  => 42,
				'code'        => array(
					'code'    => '227037002',
					'display' => 'Peanut',
				),
				'criticality' => 'high',
			)
		);
		$this->assertSame( 'AllergyIntolerance', $resource['resourceType'] );
		$this->assertSame( 'high', $resource['criticality'] );

		$bad = WP_MCP_AI_Healthcare_FHIR::build_allergy_intolerance(
			array(
				'id'          => 'al-2',
				'patient_id'  => 42,
				'criticality' => 'extreme',
			)
		);
		$this->assertArrayNotHasKey( 'criticality', $bad );
	}

	/**
	 * Encounter period is well-formed.
	 */
	public function test_build_encounter_period() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_encounter(
			array(
				'id'           => 'enc-1',
				'patient_id'   => 42,
				'period_start' => '2026-01-15T10:00:00Z',
				'period_end'   => '2026-01-15T10:45:00Z',
			)
		);
		$this->assertSame( 'Encounter', $resource['resourceType'] );
		$this->assertNotEmpty( $resource['period']['start'] );
		$this->assertNotEmpty( $resource['period']['end'] );
	}

	/**
	 * ImagingStudy carries DICOM identifiers and modality.
	 */
	public function test_build_imaging_study() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_imaging_study(
			array(
				'id'         => 'img-1',
				'patient_id' => 42,
				'study_uid'  => '1.2.840.113619.2.1.1.322987881.621.736170080.681',
				'modality'   => 'CT',
			)
		);
		$this->assertSame( 'ImagingStudy', $resource['resourceType'] );
		$this->assertSame( 'urn:dicom:uid', $resource['identifier'][0]['system'] );
		$this->assertStringContainsString( 'urn:oid:1.2.840', $resource['identifier'][0]['value'] );
		$this->assertSame( 'CT', $resource['modality'][0]['code'] );
	}

	/**
	 * Build a Bundle from heterogeneous resources.
	 */
	public function test_build_bundle() {
		$bundle = WP_MCP_AI_Healthcare_FHIR::build_bundle(
			array(
				WP_MCP_AI_Healthcare_FHIR::build_patient( array( 'id' => 1 ) ),
				WP_MCP_AI_Healthcare_FHIR::build_observation(
					array(
						'id'         => 'obs',
						'patient_id' => 1,
						'loinc_code' => '8867-4',
						'value'      => 70,
					)
				),
				'not-a-resource',
			)
		);
		$this->assertSame( 'Bundle', $bundle['resourceType'] );
		$this->assertSame( 'collection', $bundle['type'] );
		// Two valid entries, the string was discarded.
		$this->assertCount( 2, $bundle['entry'] );
	}

	/**
	 * Filter can mutate a resource just before serialisation.
	 */
	public function test_resource_filter() {
		add_filter(
			'wp_mcp_ai_healthcare_fhir_resource',
			static function ( $resource ) {
				if ( isset( $resource['resourceType'] ) && 'Patient' === $resource['resourceType'] ) {
					$resource['meta'] = array( 'profile' => array( 'http://example.com/StructureDefinition/MyPatient' ) );
				}
				return $resource;
			}
		);
		$resource = WP_MCP_AI_Healthcare_FHIR::build_patient( array( 'id' => 1 ) );
		$this->assertSame(
			'http://example.com/StructureDefinition/MyPatient',
			$resource['meta']['profile'][0]
		);
	}

	/**
	 * Logical id is sanitised to FHIR's allowed character set.
	 */
	public function test_id_sanitisation() {
		$resource = WP_MCP_AI_Healthcare_FHIR::build_patient( array( 'id' => 'abc/def?ghi' ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-.]+$/', $resource['id'] );
	}
}
