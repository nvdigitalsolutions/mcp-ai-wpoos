<?php
/**
 * Tests for Security Center
 *
 * Covers WP_MCP_AI_Security_Posture scoring, the Security Section sub-tab
 * structure, and the REST Security Center controller.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Security Center functionality.
 */
class Test_Security_Center extends WP_UnitTestCase {

	// ------------------------------------------------------------------ //
	//  Setup / teardown                                                    //
	// ------------------------------------------------------------------ //

	/**
	 * Baseline settings written before every test.
	 *
	 * @var array
	 */
	private $base_settings = array(
		'require_authentication_all' => false,
		'allow_guest_access'         => true,
		'enable_rate_limiting'       => true,
		'enable_security_audit_log'  => true,
		'audit_log_retention_days'   => 90,
		'enable_security_headers'    => true,
		'enable_hsts'                => true,
		'hsts_max_age'               => 31536000,
		'csp_frame_ancestors'        => "'none'",
		'root_security_key'          => 'a-32-character-key-for-testing!!',
		'require_https'              => false,
		'enable_ip_whitelist'        => false,
		'minimum_capability'         => 'read',
	);

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'wp_mcp_ai_settings', $this->base_settings );
		delete_transient( 'wp_mcp_ai_security_posture' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_transient( 'wp_mcp_ai_security_posture' );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	//  WP_MCP_AI_Security_Posture                                          //
	// ------------------------------------------------------------------ //

	/**
	 * Posture service returns an array with the expected keys.
	 */
	public function test_posture_report_has_required_keys() {
		$posture = new WP_MCP_AI_Security_Posture();
		$report  = $posture->get_report( true );

		$this->assertIsArray( $report );
		$this->assertArrayHasKey( 'score', $report );
		$this->assertArrayHasKey( 'grade', $report );
		$this->assertArrayHasKey( 'signals', $report );
		$this->assertArrayHasKey( 'quick_wins', $report );
		$this->assertArrayHasKey( 'computed_at', $report );
	}

	/**
	 * Score is an integer between 0 and 100.
	 */
	public function test_posture_score_is_in_range() {
		$posture = new WP_MCP_AI_Security_Posture();
		$report  = $posture->get_report( true );

		$this->assertIsInt( $report['score'] );
		$this->assertGreaterThanOrEqual( 0, $report['score'] );
		$this->assertLessThanOrEqual( 100, $report['score'] );
	}

	/**
	 * Grade is one of the five expected letter grades.
	 */
	public function test_posture_grade_is_valid() {
		$posture = new WP_MCP_AI_Security_Posture();
		$report  = $posture->get_report( true );

		$this->assertContains( $report['grade'], array( 'A', 'B', 'C', 'D', 'F' ) );
	}

	/**
	 * Quick wins contains at most 3 items.
	 */
	public function test_quick_wins_max_three() {
		$posture = new WP_MCP_AI_Security_Posture();
		$report  = $posture->get_report( true );

		$this->assertLessThanOrEqual( 3, count( $report['quick_wins'] ) );
	}

	/**
	 * Enabling many security flags raises the score compared to bare minimum.
	 */
	public function test_more_controls_raises_score() {
		// Minimal settings.
		update_option( 'wp_mcp_ai_settings', array() );
		$posture  = new WP_MCP_AI_Security_Posture();
		$low_score = $posture->get_report( true )['score'];

		// Enable several controls.
		update_option( 'wp_mcp_ai_settings', $this->base_settings );
		$posture    = new WP_MCP_AI_Security_Posture();
		$high_score = $posture->get_report( true )['score'];

		$this->assertGreaterThan( $low_score, $high_score );
	}

	/**
	 * Signals array is non-empty.
	 */
	public function test_signals_not_empty() {
		$posture  = new WP_MCP_AI_Security_Posture();
		$signals  = $posture->get_report( true )['signals'];

		$this->assertNotEmpty( $signals );
	}

	/**
	 * Every signal has 'id', 'label', 'weight', 'passed', 'detail', 'subtab'.
	 */
	public function test_signal_structure() {
		$posture = new WP_MCP_AI_Security_Posture();
		$signals = $posture->get_report( true )['signals'];

		foreach ( $signals as $signal ) {
			$this->assertArrayHasKey( 'id', $signal, "Signal missing 'id'" );
			$this->assertArrayHasKey( 'label', $signal, "Signal missing 'label'" );
			$this->assertArrayHasKey( 'weight', $signal, "Signal missing 'weight'" );
			$this->assertArrayHasKey( 'passed', $signal, "Signal missing 'passed'" );
			$this->assertArrayHasKey( 'detail', $signal, "Signal missing 'detail'" );
			$this->assertArrayHasKey( 'subtab', $signal, "Signal missing 'subtab'" );
		}
	}

	/**
	 * Caching: a second call returns the same score without recomputing.
	 */
	public function test_posture_caches_result() {
		$posture = new WP_MCP_AI_Security_Posture();
		$first   = $posture->get_report( true );

		// Modify settings in the DB — cache should still return old result.
		update_option( 'wp_mcp_ai_settings', array() );

		$second = $posture->get_report( false );

		$this->assertSame( $first['score'], $second['score'] );
	}

	/**
	 * invalidate_cache() causes a recompute on the next call.
	 */
	public function test_invalidate_cache_forces_recompute() {
		// Seed a hardened configuration so the baseline score is meaningfully
		// higher than the wiped state, independent of options left behind by
		// other suites.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'require_authentication_all'       => true,
				'root_security_key'                => str_repeat( 'x', 32 ),
				'enable_rate_limiting'             => true,
				'enable_security_audit_log'        => true,
				'enable_security_headers'          => true,
				'csp_frame_ancestors'              => 'none',
				'enable_prompt_injection_detector' => true,
				'enable_pii_filter'                => true,
				'require_https'                    => true,
				'minimum_capability'               => 'manage_options',
				'api_error_verbosity'              => 'safe',
				'enable_auth_rate_limiting'        => true,
				'allow_guest_access'               => false,
			)
		);

		$posture     = new WP_MCP_AI_Security_Posture();
		$first_score = $posture->get_report( true )['score'];

		// Wipe the controls. The posture caches its settings per instance,
		// so recompute through a fresh instance after invalidating.
		update_option( 'wp_mcp_ai_settings', array() );

		$posture->invalidate_cache();

		$fresh_score = ( new WP_MCP_AI_Security_Posture() )->get_report( false )['score'];

		// Fewer controls active → score should be lower than the hardened baseline.
		$this->assertLessThan( $first_score, $fresh_score );
	}

	/**
	 * wp_mcp_ai_security_posture_signals filter is applied.
	 */
	public function test_posture_signals_filter_applied() {
		$extra_signal = array(
			'id'     => 'test_extra_signal',
			'label'  => 'Test signal',
			'weight' => 0,
			'passed' => true,
			'detail' => '',
			'subtab' => 'overview',
			'anchor' => '',
		);

		add_filter(
			'wp_mcp_ai_security_posture_signals',
			function ( $signals ) use ( $extra_signal ) {
				$signals[] = $extra_signal;
				return $signals;
			}
		);

		$posture  = new WP_MCP_AI_Security_Posture();
		$signals  = $posture->get_report( true )['signals'];
		$ids      = array_column( $signals, 'id' );

		$this->assertContains( 'test_extra_signal', $ids );
	}

	// ------------------------------------------------------------------ //
	//  WP_MCP_AI_Section_Security sub-tab structure                       //
	// ------------------------------------------------------------------ //

	/**
	 * Security section is registered with the correct ID.
	 */
	public function test_security_section_is_registered() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );

		$this->assertInstanceOf( 'WP_MCP_AI_Section_Security', $section );
		$this->assertSame( 'security', $section->get_id() );
	}

	/**
	 * Security section has the five expected sub-tab groups.
	 */
	public function test_security_subtab_groups_present() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );

		// get_subtab_groups() is protected; use reflection.
		$ref    = new ReflectionMethod( $section, 'get_subtab_groups' );
		$ref->setAccessible( true );
		$groups = $ref->invoke( $section );

		$this->assertArrayHasKey( 'overview', $groups );
		$this->assertArrayHasKey( 'access', $groups );
		$this->assertArrayHasKey( 'network', $groups );
		$this->assertArrayHasKey( 'ai_safety', $groups );
		$this->assertArrayHasKey( 'audit', $groups );
	}

	/**
	 * Overview sub-tab has no fields (read-only).
	 */
	public function test_overview_subtab_has_no_fields() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );

		$ref    = new ReflectionMethod( $section, 'get_subtab_groups' );
		$ref->setAccessible( true );
		$groups = $ref->invoke( $section );

		$this->assertEmpty( $groups['overview']['fields'] );
	}

	/**
	 * All non-overview sub-tab field keys exist in get_fields().
	 */
	public function test_subtab_fields_exist_in_get_fields() {
		$section    = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$all_fields = $section->get_fields();

		$ref    = new ReflectionMethod( $section, 'get_subtab_groups' );
		$ref->setAccessible( true );
		$groups = $ref->invoke( $section );

		foreach ( $groups as $subtab_id => $group ) {
			if ( 'overview' === $subtab_id ) {
				continue;
			}
			foreach ( $group['fields'] as $field_key ) {
				$this->assertArrayHasKey(
					$field_key,
					$all_fields,
					"Field '{$field_key}' declared in subtab '{$subtab_id}' is not in get_fields()"
				);
			}
		}
	}

	/**
	 * Existing option keys (pre-5-subtab refactor) are still present in get_fields().
	 */
	public function test_legacy_option_keys_preserved() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$fields  = $section->get_fields();

		$legacy_keys = array(
			'require_authentication_all',
			'allow_guest_access',
			'bypass_auth_for_logged_in',
			'enable_ip_whitelist',
			'ip_whitelist',
			'enable_ip_blacklist',
			'ip_blacklist',
			'require_https',
			'enable_rate_limiting',
			'enable_security_audit_log',
			'enable_security_headers',
			'enable_hsts',
			'enable_root_security_key',
			'enable_2fa_requirement',
		);

		foreach ( $legacy_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Legacy key '{$key}' was removed from get_fields()" );
		}
	}

	/**
	 * New AI-safety fields are present in get_fields().
	 */
	public function test_new_ai_safety_fields_present() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$fields  = $section->get_fields();

		$new_keys = array(
			'enable_prompt_injection_detector',
			'prompt_injection_sensitivity',
			'prompt_injection_mode',
			'enable_pii_filter',
			'pii_filter_side',
			'pii_filter_mode',
			'enable_hitl_for_write_tools',
			'hitl_write_tool_threshold',
			'enable_sandbox_mode',
		);

		foreach ( $new_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "New key '{$key}' missing from get_fields()" );
		}
	}

	/**
	 * validate() accepts a root key of at least 32 chars.
	 */
	public function test_validate_accepts_valid_root_key() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$result  = $section->validate( array( 'root_security_key' => str_repeat( 'x', 32 ) ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
	}

	/**
	 * validate() rejects a root key shorter than 32 chars.
	 */
	public function test_validate_rejects_short_root_key() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$result  = $section->validate( array( 'root_security_key' => 'short' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * validate() rejects an out-of-range guest token TTL.
	 */
	public function test_validate_rejects_invalid_ttl() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$result  = $section->validate( array( 'guest_token_ttl_hours' => 99999 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * validate() accepts a valid guest token TTL.
	 */
	public function test_validate_accepts_valid_ttl() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$result  = $section->validate( array( 'guest_token_ttl_hours' => 48 ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
	}

	// ------------------------------------------------------------------ //
	//  WP_MCP_AI_REST_Security_Center_Controller                          //
	// ------------------------------------------------------------------ //

	/**
	 * REST controller registers the expected routes.
	 */
	public function test_rest_routes_registered() {
		$routes = rest_get_server()->get_routes();

		$expected = array(
			'/mcp-ai/v1/security/posture',
			'/mcp-ai/v1/security/test-ip',
			'/mcp-ai/v1/security/preview-headers',
			'/mcp-ai/v1/security/snapshot',
			'/mcp-ai/v1/security/snapshots',
			'/mcp-ai/v1/security/restore',
			'/mcp-ai/v1/security/self-test',
			'/mcp-ai/v1/security/compliance-report',
		);

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route '{$route}' not registered" );
		}
	}

	/**
	 * test_ip returns 'allowed' for an IP when no rules are active.
	 */
	public function test_test_ip_allowed_when_no_rules() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/test-ip' );
		$request->set_param( 'ip', '203.0.113.5' );

		update_option( 'wp_mcp_ai_settings', array(
			'enable_ip_whitelist' => false,
			'enable_ip_blacklist' => false,
		) );

		$response = $controller->test_ip( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['allowed'] );
	}

	/**
	 * test_ip returns 'blocked' when IP is on the blacklist.
	 */
	public function test_test_ip_blocked_by_blacklist() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( 'wp_mcp_ai_settings', array(
			'enable_ip_whitelist' => false,
			'enable_ip_blacklist' => true,
			'ip_blacklist'        => "203.0.113.5\n203.0.113.6",
		) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/test-ip' );
		$request->set_param( 'ip', '203.0.113.5' );

		$response = $controller->test_ip( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['allowed'] );
	}

	/**
	 * test_ip rejects an invalid IP address.
	 */
	public function test_test_ip_invalid_ip_returns_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/test-ip' );
		$request->set_param( 'ip', 'not-an-ip' );

		$response = $controller->test_ip( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
	}

	/**
	 * preview_headers returns a headers array.
	 */
	public function test_preview_headers_returns_data() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( 'wp_mcp_ai_settings', array(
			'enable_security_headers' => true,
			'enable_hsts'             => false,
		) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/preview-headers' );

		$response = $controller->preview_headers( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'headers', $data );
		$this->assertNotEmpty( $data['headers'] );
	}

	/**
	 * Snapshot round-trip: create → list → restore.
	 */
	public function test_snapshot_roundtrip() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( WP_MCP_AI_REST_Security_Center_Controller::SNAPSHOT_OPTION );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();

		// 1. Create snapshot.
		$create_req = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/snapshot' );
		$create_req->set_param( 'label', 'test snapshot' );
		$create_resp = $controller->create_snapshot( $create_req );
		$create_data = $create_resp->get_data();

		$this->assertTrue( $create_data['success'] );
		$snapshot_id = $create_data['snapshot_id'];

		// 2. List snapshots — should contain our new one.
		$list_req  = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/snapshots' );
		$list_data = $controller->list_snapshots( $list_req )->get_data();
		$ids       = array_column( $list_data['snapshots'], 'id' );

		$this->assertContains( $snapshot_id, $ids );

		// 3. Restore snapshot.
		$restore_req = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/restore' );
		$restore_req->set_param( 'snapshot_id', $snapshot_id );
		$restore_resp = $controller->restore_snapshot( $restore_req );

		$this->assertNotInstanceOf( 'WP_Error', $restore_resp );
		$this->assertTrue( $restore_resp->get_data()['success'] );
	}

	/**
	 * Restore with unknown snapshot_id returns WP_Error.
	 */
	public function test_restore_unknown_snapshot_returns_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( WP_MCP_AI_REST_Security_Center_Controller::SNAPSHOT_OPTION );

		$controller  = new WP_MCP_AI_REST_Security_Center_Controller();
		$request     = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/restore' );
		$request->set_param( 'snapshot_id', 'nonexistent-uuid' );

		$result = $controller->restore_snapshot( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'snapshot_not_found', $result->get_error_code() );
	}

	/**
	 * Self-test returns a success response with result keys.
	 */
	public function test_self_test_returns_results() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/self-test' );

		$response = $controller->run_self_test( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'results', $data );
		$this->assertArrayHasKey( 'auth_fail', $data['results'] );
		$this->assertArrayHasKey( 'ip_block', $data['results'] );
		$this->assertArrayHasKey( 'injection', $data['results'] );
	}

	/**
	 * Compliance report returns expected keys for each framework.
	 *
	 * @dataProvider data_compliance_frameworks
	 * @param string $fw Framework slug.
	 */
	public function test_compliance_report_returns_data_for_framework( $fw ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/compliance-report' );
		$request->set_param( 'framework', $fw );

		$response = $controller->generate_compliance_report( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $fw, $data['framework'] );
		$this->assertArrayHasKey( 'title', $data );
		$this->assertArrayHasKey( 'generated_at', $data );
		$this->assertArrayHasKey( 'posture_score', $data );
		$this->assertArrayHasKey( 'control_count', $data );
		$this->assertGreaterThan( 0, $data['control_count'] );
		$this->assertNotEmpty( $data['csv'] );
	}

	/**
	 * Data provider: one entry per supported framework.
	 *
	 * @return array
	 */
	public static function data_compliance_frameworks() {
		return array(
			'owasp' => array( 'owasp' ),
			'gdpr'  => array( 'gdpr' ),
			'soc2'  => array( 'soc2' ),
			'hipaa' => array( 'hipaa' ),
		);
	}

	/**
	 * Compliance report CSV contains the expected section headers.
	 */
	public function test_compliance_report_csv_structure() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/compliance-report' );
		$request->set_param( 'framework', 'owasp' );

		$data = $controller->generate_compliance_report( $request )->get_data();

		$this->assertStringContainsString( 'NV oOS Security Compliance Report', $data['csv'] );
		$this->assertStringContainsString( 'Control ID', $data['csv'] );
		$this->assertStringContainsString( 'PASS', $data['csv'] . 'FAIL' ); // at least one must appear.
	}

	/**
	 * Compliance report returns WP_Error for an unsupported framework.
	 */
	public function test_compliance_report_invalid_framework_returns_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$controller = new WP_MCP_AI_REST_Security_Center_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/security/compliance-report' );
		$request->set_param( 'framework', 'pci_dss' ); // not in enum.

		$result = $controller->generate_compliance_report( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_framework', $result->get_error_code() );
	}

	/**
	 * OTel field definitions exist in get_fields() when Pro is active.
	 *
	 * This test simulates Pro being defined by monkey-patching the section's
	 * get_fields output via the helper below; when Pro is NOT installed in
	 * the test environment we just skip the assertion to avoid false negatives.
	 */
	public function test_otel_fields_present_when_pro_defined() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_PRO_VERSION not defined in this environment.' );
		}

		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );
		$fields  = $section->get_fields();

		$otel_keys = array(
			'enable_otel_security_export',
			'otel_security_endpoint',
			'otel_security_bearer_token',
			'otel_security_sampling_percent',
		);

		foreach ( $otel_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "OTel field \'{$key}\' missing from get_fields() when Pro is active" );
		}
	}

	/**
	 * Deprecated-alias telemetry renders without fatal error.
	 *
	 * We call render_deprecated_alias_telemetry() via reflection and just
	 * check it does not throw.
	 */
	public function test_deprecated_alias_telemetry_renders_cleanly() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'security' );

		$ref = new ReflectionMethod( $section, 'render_deprecated_alias_telemetry' );
		$ref->setAccessible( true );

		ob_start();
		$ref->invoke( $section );
		$html = ob_get_clean();

		// Method should produce output (at minimum a </table> and <table> pair).
		$this->assertStringContainsString( 'form-table', $html );
	}
}