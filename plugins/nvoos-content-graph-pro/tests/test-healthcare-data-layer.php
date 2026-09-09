<?php
/**
 * Characterization tests for the Wave F4 healthcare toolkit data layer —
 * the shared engine classes (Engine/Codes/FHIR/Audit/Capabilities/OpenMed/
 * Optimization), the wellness + imaging + vitals data classes, the imaging
 * REST controller, the JetEngine vitals CCT, the BC shim, and the
 * medical-record migration ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/`
 *   are asserted in full, including the slim inits' file-gate targets and
 *   the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare data layer tests.
 */
class Test_Healthcare_Data_Layer extends WP_UnitTestCase {

	/**
	 * The eighteen ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Healthcare_Engine'                => 'tools/healthcare/class-wp-mcp-ai-healthcare-engine.php',
			'WP_MCP_AI_Healthcare_Codes'                 => 'tools/healthcare/class-wp-mcp-ai-healthcare-codes.php',
			'WP_MCP_AI_Healthcare_FHIR'                  => 'tools/healthcare/class-wp-mcp-ai-healthcare-fhir.php',
			'WP_MCP_AI_Healthcare_Audit'                 => 'tools/healthcare/class-wp-mcp-ai-healthcare-audit.php',
			'WP_MCP_AI_Healthcare_Capabilities'          => 'tools/healthcare/class-wp-mcp-ai-healthcare-capabilities.php',
			'WP_MCP_AI_OpenMed_Client'                   => 'tools/healthcare/class-wp-mcp-ai-openmed-client.php',
			'WP_MCP_AI_Healthcare_Optimization'          => 'tools/healthcare/class-wp-mcp-ai-healthcare-optimization.php',
			'WP_MCP_AI_Healthcare_Vaccination_Schedules' => 'tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vaccination-schedules.php',
			'WP_MCP_AI_Healthcare_Vital_Log_CPT'         => 'tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vital-log-cpt.php',
			'WP_MCP_AI_Health_Wellness_CPT'              => 'class-wp-mcp-ai-health-wellness-cpt.php',
			'WP_MCP_AI_Health_Wellness_Meta_Boxes'       => 'class-wp-mcp-ai-health-wellness-meta-boxes.php',
			'WP_MCP_AI_Imaging_Capabilities'             => 'class-wp-mcp-ai-imaging-capabilities.php',
			'WP_MCP_AI_Imaging_Audit_Log'                => 'class-wp-mcp-ai-imaging-audit-log.php',
			'WP_MCP_AI_DICOM_Metadata'                   => 'class-wp-mcp-ai-dicom-metadata.php',
			'WP_MCP_AI_Imaging_Study_CPT'                => 'class-wp-mcp-ai-imaging-study-cpt.php',
			'WP_MCP_AI_Imaging_REST_Controller'          => 'class-wp-mcp-ai-imaging-rest-controller.php',
			'WP_MCP_AI_JetEngine_Vitals_Log_CCT'         => 'class-wp-mcp-ai-jetengine-vitals-log-cct.php',
			'WP_MCP_AI_Migrate_Medical_Record_Post_Type' => 'migrations/class-wp-mcp-ai-migrate-medical-record-post-type.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The engine conversion constants and helpers must be byte-identical.
	 */
	public function test_engine_contracts(): void {
		$this->assertSame( 'wp_mcp_ai_healthcare_settings', WP_MCP_AI_Healthcare_Engine::SETTINGS_OPTION );
		$this->assertEqualsWithDelta( 2.2046226218, WP_MCP_AI_Healthcare_Engine::LB_PER_KG, 0.0000000001 );
		$this->assertEqualsWithDelta( 1.0, WP_MCP_AI_Healthcare_Engine::lb_to_kg( 2.2046226218 ), 0.0000001 );
		$this->assertSame( 32.0, WP_MCP_AI_Healthcare_Engine::c_to_f( 0 ) );
		$this->assertSame( 0.0, WP_MCP_AI_Healthcare_Engine::f_to_c( 32 ) );
		$this->assertNull( WP_MCP_AI_Healthcare_Engine::bmi( 0, 0 ) );
		$this->assertEqualsWithDelta( 25.0, WP_MCP_AI_Healthcare_Engine::bmi( 90.25, 190.0 ), 0.0001 );
	}

	/**
	 * The wellness CPT constants must be byte-identical.
	 */
	public function test_wellness_cpt_constants(): void {
		$this->assertSame( 'mcp_ai_member', WP_MCP_AI_Health_Wellness_CPT::MEMBER_POST_TYPE );
		$this->assertSame( 'mcp_ai_policy', WP_MCP_AI_Health_Wellness_CPT::POLICY_POST_TYPE );
		$this->assertSame( 'mcp_ai_med_record', WP_MCP_AI_Health_Wellness_CPT::MEDICAL_RECORD_POST_TYPE );
		$this->assertSame( 'mcp_ai_checkup', WP_MCP_AI_Health_Wellness_CPT::CHECKUP_POST_TYPE );
		$this->assertSame( 'mcp_ai_prescription', WP_MCP_AI_Health_Wellness_CPT::PRESCRIPTION_POST_TYPE );
		$this->assertSame( 'mcp_ai_allergy', WP_MCP_AI_Health_Wellness_CPT::ALLERGY_POST_TYPE );
	}

	/**
	 * The wellness CPT init must wire the registration hooks and register
	 * the six post types (settings-gated).
	 */
	public function test_wellness_cpt_registration(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_health_wellness_management' => true ) );

		WP_MCP_AI_Health_Wellness_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Health_Wellness_CPT', 'register_post_types' ) ) );

		WP_MCP_AI_Health_Wellness_CPT::register_post_types();
		$this->assertTrue( post_type_exists( 'mcp_ai_member' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_policy' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_med_record' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_checkup' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_prescription' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_allergy' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The imaging data contracts must be byte-identical.
	 */
	public function test_imaging_contracts(): void {
		$this->assertSame( 'mcp_ai_imaging_study', WP_MCP_AI_Imaging_Study_CPT::POST_TYPE );
		$this->assertSame(
			array(
				'view_medical_imaging',
				'upload_medical_imaging',
				'delete_medical_imaging',
				'manage_medical_imaging',
			),
			WP_MCP_AI_Imaging_Capabilities::CAPABILITIES
		);

		WP_MCP_AI_Imaging_Study_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Imaging_Study_CPT', 'register_post_type' ) ) );
		WP_MCP_AI_Imaging_Study_CPT::register_post_type();
		$this->assertTrue( post_type_exists( 'mcp_ai_imaging_study' ) );
	}

	/**
	 * The vital-log CPT constant must be byte-identical.
	 */
	public function test_vital_log_contract(): void {
		$this->assertSame( 'mcp_ai_hc_vital_log', WP_MCP_AI_Healthcare_Vital_Log_CPT::POST_TYPE );

		WP_MCP_AI_Healthcare_Vital_Log_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Healthcare_Vital_Log_CPT', 'register' ) ) );
	}

	/**
	 * The FHIR builders must produce the byte-identical resource shapes.
	 */
	public function test_fhir_contracts(): void {
		$this->assertSame( '4.0.1', WP_MCP_AI_Healthcare_FHIR::FHIR_VERSION );

		$patient = WP_MCP_AI_Healthcare_FHIR::build_patient(
			array(
				'id'          => 'p-1',
				'mrn'         => 'MRN-42',
				'family_name' => 'Doe',
				'given_name'  => 'Jane',
				'gender'      => 'female',
			)
		);
		$this->assertSame( 'Patient', $patient['resourceType'] );
		$this->assertSame( 'p-1', $patient['id'] );
		$this->assertSame( 'MRN-42', $patient['identifier'][0]['value'] );
		$this->assertSame( 'Doe', $patient['name'][0]['family'] );
		$this->assertSame( 'female', $patient['gender'] );
	}

	/**
	 * The code-pack registry must validate registered codes and return null
	 * for unknown packs/codes.
	 */
	public function test_codes_contracts(): void {
		$packs = WP_MCP_AI_Healthcare_Codes::get_packs();
		$this->assertIsArray( $packs );
		$this->assertNotEmpty( WP_MCP_AI_Healthcare_Codes::default_pack_id() );

		$this->assertFalse( WP_MCP_AI_Healthcare_Codes::validate_code( 'missing-pack', 'x' ) );
		$this->assertNull( WP_MCP_AI_Healthcare_Codes::lookup( 'missing-pack', 'x' ) );
		$this->assertNull( WP_MCP_AI_Healthcare_Codes::system_url( 'missing-pack' ) );
	}

	/**
	 * The medical-record migration status contract must report the
	 * byte-identical shape on an empty environment.
	 */
	public function test_migration_status_contract(): void {
		$status = WP_MCP_AI_Migrate_Medical_Record_Post_Type::get_status();
		$this->assertArrayHasKey( 'needs_migration', $status );
		$this->assertArrayHasKey( 'migration_completed', $status );
	}

	/**
	 * Standalone only: the slim init's file targets must exist, the tool
	 * filter must carry the eleven wellness CRUD batch-1 tools (the further
	 * healthcare tool batches land with the following sub-clusters), and the
	 * wellness global helpers must load.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base healthcare init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/imaging-init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/wellness/init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/healthcare-imaging-toolkit-init.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/migrations/class-wp-mcp-ai-migrate-medical-record-post-type.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/healthcare/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_healthcare_tools', 10 );
		$this->assertCount( 11, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_healthcare_ecosystem_tools' ) );

		// The wellness sub-init loads unconditionally with the unified
		// bootstrap and declares the byte-identical global helpers.
		$this->assertTrue( function_exists( 'wp_mcp_ai_get_member_id_by_user_id' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_health_cpt_read_audit' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_enqueue_health_wellness_management_admin_styles' ) );
	}
}
