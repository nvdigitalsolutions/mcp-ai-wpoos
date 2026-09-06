<?php
/**
 * Tests for Phase E — Healthcare Interoperability & assistant blueprints.
 *
 * @package WP_MCP_AI_Pro
 */

$base = dirname( __DIR__, 4 );
require_once $base . '/includes/interfaces/interface-wp-mcp-ai-tool.php';

$interop_dir = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/interop';
foreach ( array(
	'class-wp-mcp-ai-tool-import-fhir-bundle.php',
	'class-wp-mcp-ai-tool-export-ccda-document.php',
	'class-wp-mcp-ai-tool-import-hl7v2-message.php',
	'class-wp-mcp-ai-tool-connect-to-ehr.php',
) as $file ) {
	$file_path = $interop_dir . '/' . $file;
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

/**
 * Phase E interop tools test case.
 */
class Test_Healthcare_Interop extends WP_UnitTestCase {

	/**
	 * Set up administrator and CPTs.
	 */
	public function setUp(): void {
		parent::setUp();
		// mcp_ai_vaccination_record is intentionally not registered here: its
		// 25-character name exceeds the 20-character post-type limit, and the
		// interop tools only insert/query it (wp_insert_post and get_posts work
		// against unregistered post types, so no test needs the registration).
		foreach ( array( 'mcp_ai_member', 'mcp_ai_med_record', 'mcp_ai_allergy', 'mcp_ai_prescription' ) as $cpt ) {
			if ( ! post_type_exists( $cpt ) ) {
				register_post_type(
					$cpt,
					array(
						'public' => false,
						'label'  => $cpt,
					)
				);
			}
		}
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
	}

	/** Tear down test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Tool_Connect_To_EHR::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Bundle import upserts patient + linked resources.
	 */
	public function test_import_fhir_bundle_upserts_resources() {
		$bundle = array(
			'resourceType' => 'Bundle',
			'type'         => 'collection',
			'entry'        => array(
				array(
					'resource' => array(
						'resourceType' => 'Patient',
						'identifier'   => array( array( 'value' => 'MRN-1001' ) ),
						'name'         => array(
							array(
								'family' => 'Doe',
								'given'  => array( 'Jane' ),
							),
						),
						'birthDate'    => '1990-05-12',
						'gender'       => 'female',
					),
				),
				array(
					'resource' => array(
						'resourceType' => 'AllergyIntolerance',
						'code'         => array( 'text' => 'Peanut' ),
					),
				),
				array(
					'resource' => array(
						'resourceType'              => 'MedicationStatement',
						'medicationCodeableConcept' => array( 'text' => 'Lisinopril 10mg' ),
					),
				),
				array(
					'resource' => array(
						'resourceType' => 'Condition',
						'code'         => array( 'text' => 'Hypertension' ),
					),
				),
				// Unknown resourceType should be skipped without erroring.
				array(
					'resource' => array(
						'resourceType' => 'CarePlan',
					),
				),
			),
		);
		$tool   = new WP_MCP_AI_Tool_Import_FHIR_Bundle();
		$result = $tool->execute( array( 'bundle' => $bundle ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['member_id'] );
		$this->assertContains( 'CarePlan', $result['skipped'] );
		$member = get_post( $result['member_id'] );
		$this->assertSame( 'mcp_ai_member', $member->post_type );
		$this->assertSame( 'MRN-1001', get_post_meta( $member->ID, '_member_mrn', true ) );
		$this->assertSame( 'Jane', get_post_meta( $member->ID, '_member_first_name', true ) );
	}

	/**
	 * Non-bundle JSON is rejected.
	 */
	public function test_import_fhir_bundle_rejects_non_bundle() {
		$tool = new WP_MCP_AI_Tool_Import_FHIR_Bundle();
		$err  = $tool->execute( array( 'bundle' => array( 'resourceType' => 'Patient' ) ) );
		$this->assertWPError( $err );
		$this->assertSame( 'wp_mcp_ai_fhir_not_bundle', $err->get_error_code() );
	}

	/**
	 * Dry run does not persist.
	 */
	public function test_import_fhir_bundle_dry_run_does_not_persist() {
		$bundle = array(
			'resourceType' => 'Bundle',
			'entry'        => array(
				array(
					'resource' => array(
						'resourceType' => 'Patient',
						'identifier'   => array( array( 'value' => 'DRY-1' ) ),
						'name'         => array( array( 'family' => 'Test' ) ),
					),
				),
			),
		);
		$tool   = new WP_MCP_AI_Tool_Import_FHIR_Bundle();
		$result = $tool->execute(
			array(
				'bundle'  => $bundle,
				'dry_run' => true,
			)
		);
		$this->assertTrue( $result['dry_run'] );
		$existing = get_posts(
			array(
				'post_type'      => 'mcp_ai_member',
				'posts_per_page' => 5,
				'meta_key'       => '_member_mrn',
				'meta_value'     => 'DRY-1',
				'fields'         => 'ids',
			)
		);
		$this->assertEmpty( $existing );
	}

	/**
	 * CCDA export emits well-formed XML with sections.
	 */
	public function test_export_ccda_document_emits_xml() {
		$member_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_member',
				'post_title'  => 'Smith, John',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $member_id, '_member_first_name', 'John' );
		update_post_meta( $member_id, '_member_last_name', 'Smith' );
		update_post_meta( $member_id, '_member_date_of_birth', '1985-03-22' );
		update_post_meta( $member_id, '_member_gender', 'male' );
		update_post_meta( $member_id, '_member_mrn', 'MRN-2002' );

		$allergy_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_allergy',
				'post_title'  => 'Penicillin',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $allergy_id, '_allergy_member_id', $member_id );

		$tool   = new WP_MCP_AI_Tool_Export_CCDA_Document();
		$result = $tool->execute( array( 'member_id' => $member_id ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '<ClinicalDocument', $result['xml'] );
		$this->assertStringContainsString( 'Penicillin', $result['xml'] );
		$this->assertStringContainsString( 'MRN-2002', $result['xml'] );
		$this->assertSame( 1, $result['sections']['allergies'] );

		// XML must parse.
		$dom    = new DOMDocument();
		$loaded = $dom->loadXML( $result['xml'] );
		$this->assertTrue( (bool) $loaded );
	}

	/**
	 * CCDA filter hook can mutate output.
	 */
	public function test_export_ccda_document_filter_runs() {
		$member_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_member',
				'post_title'  => 'Filter Patient',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $member_id, '_member_first_name', 'Filter' );
		update_post_meta( $member_id, '_member_last_name', 'Patient' );

		add_filter(
			'wp_mcp_ai_healthcare_ccda_document',
			function ( $xml ) {
				return $xml . '<!-- filtered -->';
			}
		);
		$tool   = new WP_MCP_AI_Tool_Export_CCDA_Document();
		$result = $tool->execute( array( 'member_id' => $member_id ) );
		remove_all_filters( 'wp_mcp_ai_healthcare_ccda_document' );
		$this->assertStringContainsString( '<!-- filtered -->', $result['xml'] );
	}

	/**
	 * HL7 v2 ADT^A04 creates a member.
	 */
	public function test_import_hl7v2_adt_creates_member() {
		$msg    = "MSH|^~\\&|HIS|HOSP|EHR|VENDOR|202605011200||ADT^A04|MSG0001|P|2.5\r";
		$msg   .= "PID|1||MRN-3003^^^HOSP||Jones^Robert^A||19770714|M\r";
		$tool   = new WP_MCP_AI_Tool_Import_HL7v2_Message();
		$result = $tool->execute( array( 'message' => $msg ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'ADT_A04', $result['message_type'] );
		$this->assertGreaterThan( 0, $result['member_id'] );
		$this->assertSame( 'MRN-3003', get_post_meta( $result['member_id'], '_member_mrn', true ) );
		$this->assertSame( 'Robert', get_post_meta( $result['member_id'], '_member_first_name', true ) );
	}

	/**
	 * HL7 v2 ORU^R01 records OBX observations.
	 */
	public function test_import_hl7v2_oru_records_observations() {
		$msg    = "MSH|^~\\&|LAB|HOSP|EHR|VENDOR|202605011200||ORU^R01|MSG0002|P|2.5\r";
		$msg   .= "PID|1||MRN-3004||Doe^Mary\r";
		$msg   .= "OBX|1|NM|718-7^Hemoglobin^LN||13.5|g/dL|||||F\r";
		$msg   .= "OBX|2|NM|4544-3^Hematocrit^LN||40.2|%|||||F\r";
		$tool   = new WP_MCP_AI_Tool_Import_HL7v2_Message();
		$result = $tool->execute( array( 'message' => $msg ) );
		$this->assertSame( 'ORU_R01', $result['message_type'] );
		$this->assertSame( 2, $result['observations'] );
	}

	/**
	 * Empty messages are rejected.
	 */
	public function test_import_hl7v2_rejects_empty() {
		$tool = new WP_MCP_AI_Tool_Import_HL7v2_Message();
		$err  = $tool->execute( array( 'message' => "    \n   " ) );
		$this->assertWPError( $err );
		$this->assertSame( 'wp_mcp_ai_hl7v2_empty', $err->get_error_code() );
	}

	/**
	 * Missing MSH is rejected.
	 */
	public function test_import_hl7v2_rejects_no_msh() {
		$tool = new WP_MCP_AI_Tool_Import_HL7v2_Message();
		$err  = $tool->execute( array( 'message' => "PID|1||MRN-NO-MSH||Foo^Bar\r" ) );
		$this->assertWPError( $err );
		$this->assertSame( 'wp_mcp_ai_hl7v2_no_msh', $err->get_error_code() );
	}

	/**
	 * EHR connect saves and redacts secrets.
	 */
	public function test_connect_to_ehr_save_and_redact() {
		$tool   = new WP_MCP_AI_Tool_Connect_To_EHR();
		$result = $tool->execute(
			array(
				'action'        => 'configure',
				'vendor'        => 'epic',
				'fhir_base_url' => 'https://fhir.epic.example.com/api/FHIR/R4',
				'token_url'     => 'https://fhir.epic.example.com/oauth2/token',
				'client_id'     => 'client-id-x',
				'client_secret' => 'super-secret',
				'scope'         => 'system/Patient.read',
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( '[redacted]', $result['connection']['client_secret'] );
		$this->assertSame( 'client-id-x', $result['connection']['client_id'] );

		$get = $tool->execute(
			array(
				'action' => 'get',
				'vendor' => 'epic',
			)
		);
		$this->assertTrue( $get['connection']['configured'] );
	}

	/**
	 * EHR test action surfaces a missing token_url.
	 */
	public function test_connect_to_ehr_test_requires_token_url() {
		$tool = new WP_MCP_AI_Tool_Connect_To_EHR();
		$tool->execute(
			array(
				'action'        => 'configure',
				'vendor'        => 'cerner',
				'fhir_base_url' => 'https://fhir.cerner.example.com/r4',
				'client_id'     => 'cid',
				'client_secret' => 'sec',
			)
		);
		$err = $tool->execute(
			array(
				'action' => 'test',
				'vendor' => 'cerner',
			)
		);
		$this->assertWPError( $err );
		$this->assertSame( 'wp_mcp_ai_ehr_missing_token_url', $err->get_error_code() );
	}

	/**
	 * EHR disconnect removes the connection.
	 */
	public function test_connect_to_ehr_disconnect() {
		$tool = new WP_MCP_AI_Tool_Connect_To_EHR();
		$tool->execute(
			array(
				'action'        => 'configure',
				'vendor'        => 'generic',
				'fhir_base_url' => 'https://example.org/fhir',
				'client_id'     => 'x',
				'client_secret' => 'y',
			)
		);
		$tool->execute(
			array(
				'action' => 'disconnect',
				'vendor' => 'generic',
			)
		);
		$get = $tool->execute(
			array(
				'action' => 'get',
				'vendor' => 'generic',
			)
		);
		$this->assertFalse( $get['connection']['configured'] );
	}

	/**
	 * Non-admins cannot manage EHR connections.
	 */
	public function test_connect_to_ehr_requires_admin() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$tool = new WP_MCP_AI_Tool_Connect_To_EHR();
		$err  = $tool->execute(
			array(
				'action' => 'get',
				'vendor' => 'epic',
			)
		);
		$this->assertWPError( $err );
		$this->assertSame( 'wp_mcp_ai_forbidden', $err->get_error_code() );
	}

	/**
	 * All four assistant blueprints exist and are valid JSON with the expected slugs.
	 */
	public function test_phase_e_assistant_blueprints_are_valid() {
		$dir      = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/examples';
		$expected = array(
			'general-clinic.json'          => 'healthcare-general-clinic',
			'veterinary-practice.json'     => 'healthcare-veterinary-practice',
			'personal-health-tracker.json' => 'healthcare-personal-health-tracker',
			'radiology-review.json'        => 'healthcare-radiology-review',
		);
		foreach ( $expected as $file => $blueprint_id ) {
			$path = $dir . '/' . $file;
			$this->assertFileExists( $path, 'Missing blueprint ' . $file );
			$json = json_decode( (string) file_get_contents( $path ), true );
			$this->assertIsArray( $json, 'Invalid JSON in ' . $file );
			$this->assertSame( $blueprint_id, $json['blueprint_id'] );
			$this->assertNotEmpty( $json['meta_input']['_wp_mcp_ai_tools'] );
			$this->assertNotEmpty( $json['meta_input']['_wp_mcp_ai_system_prompt'] );
		}
	}
}
