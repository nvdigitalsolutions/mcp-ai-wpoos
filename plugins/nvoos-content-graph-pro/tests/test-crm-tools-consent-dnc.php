<?php
/**
 * Characterization tests for the Wave F2 CRM consent/DNC tool batch — the
 * ported compliance tools (record/revoke consent, process-opt-out,
 * check-dnc-status, get-consent-audit).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/compliance/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM consent/DNC tool batch tests.
 */
class Test_Crm_Tools_Consent_Dnc extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * Enable the CRM toolkit flag in the shared settings option.
	 *
	 * @return void
	 */
	private function enable_crm_toolkit(): void {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The five ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Record_Consent'    => 'tools/crm/compliance/class-wp-mcp-ai-tool-record-consent.php',
			'WP_MCP_AI_Tool_Revoke_Consent'    => 'tools/crm/compliance/class-wp-mcp-ai-tool-revoke-consent.php',
			'WP_MCP_AI_Tool_Process_Opt_Out'   => 'tools/crm/compliance/class-wp-mcp-ai-tool-process-opt-out.php',
			'WP_MCP_AI_Tool_Check_Dnc_Status'  => 'tools/crm/compliance/class-wp-mcp-ai-tool-check-dnc-status.php',
			'WP_MCP_AI_Tool_Get_Consent_Audit' => 'tools/crm/compliance/class-wp-mcp-ai-tool-get-consent-audit.php',
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
	 * The five tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Record_Consent'    => 'record_consent',
			'WP_MCP_AI_Tool_Revoke_Consent'    => 'revoke_consent',
			'WP_MCP_AI_Tool_Process_Opt_Out'   => 'process_opt_out',
			'WP_MCP_AI_Tool_Check_Dnc_Status'  => 'check_dnc_status',
			'WP_MCP_AI_Tool_Get_Consent_Audit' => 'get_consent_audit',
		);

		$manage_options = array(
			'WP_MCP_AI_Tool_Revoke_Consent',
			'WP_MCP_AI_Tool_Get_Consent_Audit',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = in_array( $class, $manage_options, true ) ? 'manage_options' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the consent batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Record_Consent', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Process_Opt_Out', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Consent_Audit', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/compliance/class-wp-mcp-ai-tool-check-dnc-status.php',
			$tools['WP_MCP_AI_Tool_Check_Dnc_Status']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the consent
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['record_consent'] ?? null );
		$this->assertNotNull( $parent->all()['get_consent_audit'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'record_consent' ) );
		$this->assertTrue( $core_tools->has( 'revoke_consent' ) );
		$this->assertTrue( $core_tools->has( 'process_opt_out' ) );
		$this->assertTrue( $core_tools->has( 'check_dnc_status' ) );
		$this->assertTrue( $core_tools->has( 'get_consent_audit' ) );
	}
}
