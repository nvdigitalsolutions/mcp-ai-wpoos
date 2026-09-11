<?php
/**
 * Characterization tests for the Wave F2 site-creator tool batch — the five
 * always-on Pro tools, the twenty-seven loader tools, and the tree-only
 * import-blueprint tool ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/site-creator-toolkit/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Site-creator tool batch tests.
 */
class Test_Site_Creator_Tools extends WP_UnitTestCase {

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$research = new WP_MCP_AI_Tool_Research_Site_Best_Practices();
		$this->assertSame( 'research_site_best_practices', $research->get_slug() );
		$this->assertSame( 'edit_posts', $research->get_required_capability() );

		$creator = new WP_MCP_AI_Pro_Tool_Site_Creator();
		$this->assertSame( 'site_creator', $creator->get_slug() );

		$update = new WP_MCP_AI_Pro_Tool_Update_Option();
		$this->assertSame( 'update_option', $update->get_slug() );
		$this->assertSame( 'manage_options', $update->get_required_capability() );

		$import = new WP_MCP_AI_Tool_Import_Site_Creator_Blueprint();
		$this->assertSame( 'import_site_creator_blueprint', $import->get_slug() );
		$this->assertTrue( $import->requires_base_pro() );
		$this->assertIsBool( WP_MCP_AI_Tool_Import_Site_Creator_Blueprint::is_available() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Research_Site_Best_Practices'  => 'tools/site-creator-toolkit/class-wp-mcp-ai-tool-research-site-best-practices.php',
			'WP_MCP_AI_Pro_Tool_Site_Creator'              => 'tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-site-creator.php',
			'WP_MCP_AI_Pro_Tool_Update_Option'             => 'tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-update-option.php',
			'WP_MCP_AI_Tool_Import_Site_Creator_Blueprint' => 'tools/site-creator-toolkit/examples/class-wp-mcp-ai-tool-import-site-creator-blueprint.php',
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
	 * The deterministic update-option execute() contract writes a
	 * `wp_mcp_ai_`-prefixed option and returns the canonical success
	 * envelope.
	 */
	public function test_engine_backed_execute(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_site_creator'               => true,
				'site_creator_allow_option_updates' => true,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Update_Option();
		$result = $tool->execute(
			array(
				'option_name'  => 'wp_mcp_ai_test_port_sc',
				'option_value' => 'test-value',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		delete_option( 'wp_mcp_ai_test_port_sc' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Standalone only: the init's tool filter must carry all thirty-three
	 * ported tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_site_creator_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 33, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/class-wp-mcp-ai-tool-research-site-best-practices.php',
			$tools['WP_MCP_AI_Tool_Research_Site_Best_Practices']
		);
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/class-wp-mcp-ai-pro-tool-site-creator.php',
			$tools['WP_MCP_AI_Pro_Tool_Site_Creator']
		);
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/examples/class-wp-mcp-ai-tool-import-site-creator-blueprint.php',
			$tools['WP_MCP_AI_Tool_Import_Site_Creator_Blueprint']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/examples/wordpress-site-builder.json' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/examples/remote-site-administrator.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/init.php';
		wp_mcp_ai_pro_register_site_creator_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['site_creator'] ?? null );
		$this->assertNotNull( $parent->all()['update_option'] ?? null );
		$this->assertNotNull( $parent->all()['research_site_best_practices'] ?? null );
		$this->assertNotNull( $parent->all()['import_site_creator_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'site_creator' ) );
		$this->assertTrue( $core_tools->has( 'update_option' ) );
		$this->assertTrue( $core_tools->has( 'import_site_creator_blueprint' ) );
	}
}
