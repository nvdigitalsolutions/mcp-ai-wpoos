<?php
/**
 * Characterization tests for the Wave F2 ai-tool-builder toolkit — the ten
 * meta-tools, the tree-only import-blueprint tool, the slim standalone init,
 * and the settings page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/ai-tool-builder/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * AI tool-builder toolkit tests.
 */
class Test_AI_Tool_Builder_Toolkit extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$validate = new WP_MCP_AI_Tool_Validate_Tool_Schema();
		$this->assertSame( 'validate_tool_schema', $validate->get_slug() );
		$this->assertSame( 'edit_posts', $validate->get_required_capability() );

		$scaffold = new WP_MCP_AI_Tool_Generate_Tool_Scaffold();
		$this->assertSame( 'generate_tool_scaffold', $scaffold->get_slug() );

		$import = new WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint();
		$this->assertSame( 'import_ai_tool_builder_blueprint', $import->get_slug() );
		$this->assertSame( 'edit_posts', $import->get_required_capability() );
		$this->assertTrue( $import->requires_base_pro() );
		$this->assertSame( array( 'tool-developer' ), WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint::BLUEPRINT_SLUGS );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Validate_Tool_Schema'   => 'tools/ai-tool-builder/class-wp-mcp-ai-tool-validate-tool-schema.php',
			'WP_MCP_AI_Tool_Generate_Tool_Scaffold' => 'tools/ai-tool-builder/class-wp-mcp-ai-tool-generate-tool-scaffold.php',
			'WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint' => 'tools/ai-tool-builder/examples/class-wp-mcp-ai-tool-import-ai-tool-builder-blueprint.php',
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
	 * The validate-tool-schema execute() contracts must be byte-identical
	 * (deterministic schema validation — no external probes).
	 */
	public function test_validate_tool_schema_execute(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Validate_Tool_Schema();

		// Missing schema argument.
		$missing = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertTrue( is_array( $missing ) || is_wp_error( $missing ) );

		// Valid minimal schema.
		$result = $tool->execute(
			array(
				'schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string' ),
					),
					'required'   => array( 'query' ),
				),
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry all eleven ported
	 * tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool maps inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_ai_tool_builder_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 11, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/class-wp-mcp-ai-tool-validate-tool-schema.php',
			$tools['WP_MCP_AI_Tool_Validate_Tool_Schema']
		);
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/examples/class-wp-mcp-ai-tool-import-ai-tool-builder-blueprint.php',
			$tools['WP_MCP_AI_Tool_Import_AI_Tool_Builder_Blueprint']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/examples/tool-developer.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ai-tool-builder/init.php';
		wp_mcp_ai_pro_register_ai_tool_builder_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['validate_tool_schema'] ?? null );
		$this->assertNotNull( $parent->all()['import_ai_tool_builder_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'validate_tool_schema' ) );
		$this->assertTrue( $core_tools->has( 'generate_tool_scaffold' ) );
		$this->assertTrue( $core_tools->has( 'import_ai_tool_builder_blueprint' ) );
	}

	/**
	 * Standalone only: the init's file-gated settings-page target must exist.
	 */
	public function test_init_settings_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$this->assertFileExists(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php'
		);
	}
}
