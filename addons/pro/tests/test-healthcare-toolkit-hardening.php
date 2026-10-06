<?php
/**
 * Regression tests for the healthcare toolkit hardening pass.
 *
 * Covers the failure classes fixed in the healthcare toolkit:
 *  - string-assuming parsing of provider responses: interpret_imaging_study
 *    concatenated `choices[0].message.content` straight into the disclaimer
 *    — Gemini (and OpenAI-compatible gateways such as vLLM) return that field
 *    as an array of parts, producing literal "Array" text (or a fatal) in the
 *    interpretation,
 *  - dead audit logging: export_fhir_data and the wellness migration init
 *    called the nonexistent wp_mcp_ai_log_activity() global, so the HIPAA
 *    audit trail silently never recorded,
 *  - unguarded third-party response fields: extract_clinical_entities fed a
 *    non-array OpenMed `entities` field into foreach, and deidentify passed an
 *    undefined `deidentified_text` index into its completion action.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group healthcare
 * @group pro
 */

/**
 * Healthcare toolkit hardening test case.
 */
class Test_Healthcare_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Load the classes under test.
		$base = WP_MCP_AI_PRO_PATH . 'includes/';

		if ( ! class_exists( 'WP_MCP_AI_Imaging_Capabilities' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-capabilities.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Imaging_Audit_Log' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-audit-log.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			require_once $base . 'class-wp-mcp-ai-imaging-study-cpt.php';
			WP_MCP_AI_Imaging_Study_CPT::init();
		}
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			require_once $base . 'tools/healthcare/class-wp-mcp-ai-healthcare-engine.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			require_once $base . 'tools/healthcare/class-wp-mcp-ai-healthcare-audit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_OpenMed_Client' ) ) {
			require_once $base . 'tools/healthcare/class-wp-mcp-ai-openmed-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Interpret_Imaging_Study' ) ) {
			require_once $base . 'tools/healthcare/imaging/class-wp-mcp-ai-tool-interpret-imaging-study.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_FHIR_Data' ) ) {
			require_once $base . 'tools/healthcare/interop/class-wp-mcp-ai-tool-export-fhir-data.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Extract_Clinical_Entities' ) ) {
			require_once $base . 'tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Deidentify_Health_Record' ) ) {
			require_once $base . 'tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php';
		}

		// Grant the imaging view capability to the administrator.
		WP_MCP_AI_Imaging_Capabilities::add_caps();

		// Neutralise environment-provided keys so provider selection is
		// driven solely by the wp_mcp_ai_settings option in these tests.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'OPENAI_API_KEY' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'GEMINI_API_KEY' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'ANTHROPIC_API_KEY' );

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		delete_option( 'wp_mcp_ai_credentials' );
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_openmed_settings' );
		delete_option( 'wp_mcp_ai_healthcare_audit_log' );
		delete_option( WP_MCP_AI_Imaging_Audit_Log::OPTION_KEY );

		// Reset the OpenMed client singleton so settings are re-read.
		$ref = new ReflectionProperty( 'WP_MCP_AI_OpenMed_Client', 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Drop HTTP mocks between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Invoke a private/protected method on an object.
	 *
	 * @param object $target    Target object.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( $target, $method, array $arguments ) {
		$reflection = new ReflectionClass( $target );
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );
		return $method_ref->invokeArgs( $target, $arguments );
	}

	/**
	 * Build a mocked HTTP response carrying Gemini array-of-parts content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function gemini_parts_mock() {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array(
								'parts' => array(
									array(
										'text' => 'Part one.',
									),
									array(
										'text' => 'Part two.',
									),
								),
							),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array(),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Build a mocked HTTP response carrying OpenAI string content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function openai_string_mock() {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'id'      => 'chatcmpl-test',
					'object'  => 'chat.completion',
					'created' => time(),
					'model'   => 'gpt-4.1',
					'choices' => array(
						array(
							'index'   => 0,
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Plain text content.',
							),
						),
					),
					'usage'   => array(
						'prompt_tokens'     => 10,
						'completion_tokens' => 5,
						'total_tokens'      => 15,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Build a mocked HTTP response carrying an empty string content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function openai_empty_mock() {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'id'      => 'chatcmpl-test',
					'object'  => 'chat.completion',
					'created' => time(),
					'model'   => 'gpt-4.1',
					'choices' => array(
						array(
							'index'   => 0,
							'message' => array(
								'role'    => 'assistant',
								'content' => '',
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Create an imaging study post for interpretation tests.
	 *
	 * @return string Study UID.
	 */
	private function create_study() {
		$uid = '1.2.3.4.hardening.test';
		$id  = WP_MCP_AI_Imaging_Study_CPT::create(
			array(
				'study_instance_uid' => $uid,
				'modality'           => 'CT',
				'study_date'         => '20240601',
				'study_description'  => 'Hardening test study',
			)
		);
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to create study fixture: ' . $id->get_error_message() );
		}
		return $uid;
	}

	// =========================================================================
	// interpret_imaging_study — response flattening.
	// =========================================================================

	/**
	 * The flatten helper must handle every provider response shape.
	 */
	public function test_interpret_flatten_handles_shapes() {
		$tool = new WP_MCP_AI_Tool_Interpret_Imaging_Study();

		// String content (OpenAI-style).
		$this->assertSame(
			'Plain text.',
			$this->invoke_private(
				$tool,
				'flatten_response_content',
				array( array( 'choices' => array( array( 'message' => array( 'content' => 'Plain text.' ) ) ) ) )
			)
		);

		// Array-of-parts content (Gemini normalize_response).
		$this->assertSame(
			'Part one.Part two.',
			$this->invoke_private(
				$tool,
				'flatten_response_content',
				array(
					array(
						'choices' => array(
							array(
								'message' => array(
									'content' => array(
										array(
											'type' => 'text',
											'text' => 'Part one.',
										),
										array(
											'type' => 'text',
											'text' => 'Part two.',
										),
									),
								),
							),
						),
					),
				)
			)
		);

		// Array-of-strings content.
		$this->assertSame(
			'AB',
			$this->invoke_private(
				$tool,
				'flatten_response_content',
				array( array( 'choices' => array( array( 'message' => array( 'content' => array( 'A', 'B' ) ) ) ) ) )
			)
		);

		// Missing content yields ''.
		$this->assertSame( '', $this->invoke_private( $tool, 'flatten_response_content', array( array() ) ) );

		// Non-array result yields ''.
		$this->assertSame( '', $this->invoke_private( $tool, 'flatten_response_content', array( null ) ) );

		// Non-string / non-array content yields ''.
		$this->assertSame(
			'',
			$this->invoke_private(
				$tool,
				'flatten_response_content',
				array( array( 'choices' => array( array( 'message' => array( 'content' => 42 ) ) ) ) )
			)
		);
	}

	/**
	 * Interpret_imaging_study must flatten Gemini array-of-parts content into
	 * the interpretation instead of concatenating the literal "Array".
	 */
	public function test_interpret_flattens_gemini_array_content() {
		$this->create_study();
		update_option( 'wp_mcp_ai_settings', array( 'gemini_api_key' => 'gsk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array( 'study_uid' => '1.2.3.4.hardening.test' ) );

		remove_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertIsString( $result['interpretation'] );
		$this->assertStringContainsString( 'Part one.Part two.', $result['interpretation'] );
		$this->assertStringNotContainsString( 'Array', $result['interpretation'] );
	}

	/**
	 * Interpret_imaging_study must pass OpenAI string content through.
	 */
	public function test_interpret_passes_string_content() {
		$this->create_study();
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array( 'study_uid' => '1.2.3.4.hardening.test' ) );

		remove_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsString( $result['interpretation'] );
		$this->assertStringContainsString( 'Plain text content.', $result['interpretation'] );
	}

	/**
	 * Interpret_imaging_study must return the honest empty-response error when
	 * the provider returns no content.
	 */
	public function test_interpret_returns_error_on_empty_content() {
		$this->create_study();
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'openai_empty_mock' ), 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Interpret_Imaging_Study();
		$result = $tool->execute( array( 'study_uid' => '1.2.3.4.hardening.test' ) );

		remove_filter( 'pre_http_request', array( $this, 'openai_empty_mock' ), 10 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'imaging_ai_empty_response', $result->get_error_code() );
	}

	// =========================================================================
	// export_fhir_data — HIPAA audit trail.
	// =========================================================================

	/**
	 * Export_fhir_data must record the export in the unified PHI audit ledger.
	 *
	 * Regression: the previous implementation called the nonexistent global
	 * wp_mcp_ai_log_activity(), so the HIPAA audit trail silently never fired.
	 */
	public function test_fhir_export_records_audit_entry() {
		$member_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_member',
				'post_title'  => 'Jane Doe',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $member_id, '_member_date_of_birth', '1990-05-12' );

		$tool   = new WP_MCP_AI_Tool_Export_FHIR_Data();
		$result = $tool->execute(
			array(
				'member_id'      => $member_id,
				'resource_types' => array( 'Patient' ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$entries = WP_MCP_AI_Healthcare_Audit::recent();
		$this->assertNotEmpty( $entries );

		$found = false;
		foreach ( $entries as $entry ) {
			if ( 'fhir_data_export' === $entry['event'] ) {
				$found = true;
				$this->assertSame( 'member', $entry['resource_type'] );
				$this->assertSame( (string) $member_id, $entry['resource_id'] );
			}
		}
		$this->assertTrue( $found, 'Expected a fhir_data_export audit entry, none recorded.' );
	}

	// =========================================================================
	// OpenMed-facing tools — third-party response field guards.
	// =========================================================================

	/**
	 * Configure the OpenMed client against a mockable endpoint.
	 *
	 * @return void
	 */
	private function configure_openmed() {
		update_option(
			'wp_mcp_ai_openmed_settings',
			array( 'service_url' => 'https://openmed.test' )
		);

		$ref = new ReflectionProperty( 'WP_MCP_AI_OpenMed_Client', 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Extract_clinical_entities must degrade to an empty entity list when
	 * OpenMed returns a non-array `entities` field.
	 */
	public function test_extract_clinical_entities_handles_non_array_entities() {
		$this->configure_openmed();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'entities' => 'not-an-array' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Extract_Clinical_Entities();
		$result = $tool->execute( array( 'text' => 'Patient presents with fever.' ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( array(), $result['data']['entities'] );
		$this->assertSame( 0, $result['data']['entity_count'] );
	}

	/**
	 * Deidentify_health_record must fire its completion action with an empty
	 * string when OpenMed omits `deidentified_text`.
	 */
	public function test_deidentify_handles_missing_deidentified_text() {
		$this->configure_openmed();

		// Grant the tool's capability to the admin user.
		$user = get_userdata( $this->admin_user );
		$user->add_cap( 'deidentify_phi' );
		wp_set_current_user( $this->admin_user );

		$captured = 'unset';
		add_action(
			'wp_mcp_ai_after_health_record_deidentified',
			static function ( $deidentified_text ) use ( &$captured ) {
				$captured = $deidentified_text;
			}
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'entities_found' => 2 ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Deidentify_Health_Record();
		$result = $tool->execute( array( 'text' => 'Jane Doe, 42, seen today.' ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( '', $result['data']['deidentified_text'] );
		$this->assertSame( '', $captured );
	}
}
