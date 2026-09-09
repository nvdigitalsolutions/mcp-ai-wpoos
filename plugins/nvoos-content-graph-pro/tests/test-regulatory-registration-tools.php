<?php
/**
 * Characterization tests for the Wave F2 regulatory-registration tool batch —
 * the sixty ported tools (59 toolkit-gated + the tree-only import-blueprint
 * tool) plus the base-owned Restrict_From_Chat_Client trait D8-compat copy.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/regulatory-registration/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Regulatory-registration tool batch tests.
 */
class Test_Regulatory_Registration_Tools extends WP_UnitTestCase {

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$create = new WP_MCP_AI_Tool_Create_Reg_Product();
		$this->assertSame( 'create_reg_product', $create->get_slug() );
		$this->assertSame( 'edit_posts', $create->get_required_capability() );

		$hs = new WP_MCP_AI_Tool_Check_HS_Code();
		$this->assertSame( 'check_hs_code', $hs->get_slug() );

		$import = new WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint();
		$this->assertSame( 'import_regulatory_registration_blueprint', $import->get_slug() );
		$this->assertTrue( $import->requires_base_pro() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Reg_Product' => 'tools/regulatory-registration/class-wp-mcp-ai-tool-create-reg-product.php',
			'WP_MCP_AI_Tool_Check_HS_Code'      => 'tools/regulatory-registration/class-wp-mcp-ai-tool-check-hs-code.php',
			'WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint' => 'tools/regulatory-registration/examples/class-wp-mcp-ai-tool-import-regulatory-registration-blueprint.php',
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
	 * The deterministic create-reg-product contract degrades gracefully with
	 * a missing product name.
	 */
	public function test_engine_backed_execute(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Reg_Product();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry all sixty ported
	 * tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_regulatory_registration_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 60, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/class-wp-mcp-ai-tool-create-reg-product.php',
			$tools['WP_MCP_AI_Tool_Create_Reg_Product']
		);
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/examples/class-wp-mcp-ai-tool-import-regulatory-registration-blueprint.php',
			$tools['WP_MCP_AI_Tool_Import_Regulatory_Registration_Blueprint']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/examples/product-registration-specialist.json' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/examples/regulatory-affairs-manager.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/regulatory-registration/init.php';
		wp_mcp_ai_pro_register_regulatory_registration_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['create_reg_product'] ?? null );
		$this->assertNotNull( $parent->all()['check_hs_code'] ?? null );
		$this->assertNotNull( $parent->all()['import_regulatory_registration_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_reg_product' ) );
		$this->assertTrue( $core_tools->has( 'check_hs_code' ) );
		$this->assertTrue( $core_tools->has( 'import_regulatory_registration_blueprint' ) );
	}
}
