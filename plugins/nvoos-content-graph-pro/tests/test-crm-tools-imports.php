<?php
/**
 * Characterization tests for the Wave F2 CRM imports + connect tool batch —
 * the ported tools (import-crm-csv, connect-to-external-crm,
 * import-crm-blueprint).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/compliance/` + `src/tools/crm/examples/` are asserted
 *   in full, including the ecosystem registrations and the
 *   blueprint-installer degradation.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM imports + connect tool batch tests.
 */
class Test_Crm_Tools_Imports extends WP_UnitTestCase {

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
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Import_CRM_Csv'          => 'tools/crm/compliance/class-wp-mcp-ai-tool-import-crm-csv.php',
			'WP_MCP_AI_Tool_Connect_To_External_Crm' => 'tools/crm/compliance/class-wp-mcp-ai-tool-connect-to-external-crm.php',
			'WP_MCP_AI_Tool_Import_CRM_Blueprint'    => 'tools/crm/examples/class-wp-mcp-ai-tool-import-crm-blueprint.php',
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
	 * The three tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Import_CRM_Csv'          => 'import_crm_csv',
			'WP_MCP_AI_Tool_Connect_To_External_Crm' => 'connect_to_external_crm',
			'WP_MCP_AI_Tool_Import_CRM_Blueprint'    => 'import_crm_blueprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}

		$connect = new WP_MCP_AI_Tool_Connect_To_External_Crm();
		$this->assertSame( 'manage_options', $connect->get_required_capability() );

		$csv = new WP_MCP_AI_Tool_Import_CRM_Csv();
		$this->assertSame( 'edit_posts', $csv->get_required_capability() );

		$blueprint = new WP_MCP_AI_Tool_Import_CRM_Blueprint();
		$this->assertSame( 'edit_posts', $blueprint->get_required_capability() );

		// The blueprint tool's examples dir must resolve per-mode.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame(
				WP_MCP_AI_PRO_PATH . 'includes/tools/crm/examples',
				$blueprint::BLUEPRINTS_DIR
			);
		} else {
			$this->assertSame(
				NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/examples',
				$blueprint::BLUEPRINTS_DIR
			);
		}
	}

	/**
	 * Standalone only: the orchestration blueprint installer has landed
	 * (Wave F2 PM reports batch), so the CRM blueprint import must now
	 * resolve the installer and install the asset instead of degrading.
	 */
	public function test_blueprint_installer_resolves_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the Pro addon serves the blueprint installer.' );
		}

		$tool   = new WP_MCP_AI_Tool_Import_CRM_Blueprint();
		$result = $tool->execute(
			array( 'blueprint' => 'agency-account-manager' ),
			array( 'user_id' => 1 )
		);
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['assistant_id'] );
		$this->assertSame( 'mcp_ai_assistant', get_post( $result['assistant_id'] )->post_type );
	}

	/**
	 * Standalone only: the init's tool filter must carry the imports batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_CRM_Csv', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Connect_To_External_Crm', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_CRM_Blueprint', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the imports
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
		$this->assertNotNull( $parent->all()['import_crm_csv'] ?? null );
		$this->assertNotNull( $parent->all()['import_crm_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'import_crm_csv' ) );
		$this->assertTrue( $core_tools->has( 'connect_to_external_crm' ) );
		$this->assertTrue( $core_tools->has( 'import_crm_blueprint' ) );
	}
}
