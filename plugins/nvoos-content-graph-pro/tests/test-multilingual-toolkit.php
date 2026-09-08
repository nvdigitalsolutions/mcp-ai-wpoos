<?php
/**
 * Characterization tests for the Wave F2 multilingual toolkit — the slimmed
 * standalone init, the language-detection service, the ten gated tools, and
 * the tree-only import-blueprint tool ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/multilingual/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Multilingual toolkit tests.
 */
class Test_Multilingual_Toolkit extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$detect = new WP_MCP_AI_Tool_Detect_Content_Language();
		$this->assertSame( 'detect_content_language', $detect->get_slug() );
		$this->assertSame( 'edit_posts', $detect->get_required_capability() );

		$import = new WP_MCP_AI_Tool_Import_Multilingual_Blueprint();
		$this->assertSame( 'import_multilingual_blueprint', $import->get_slug() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Detect_Content_Language' => 'tools/multilingual/class-wp-mcp-ai-tool-detect-content-language.php',
			'WP_MCP_AI_Language_Detection_Service'   => 'services/class-wp-mcp-ai-language-detection-service.php',
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
	 * The detect-content-language execute() contracts degrade gracefully with
	 * no input (no service probes are exercised).
	 */
	public function test_detect_language_execute_contracts(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Detect_Content_Language();

		$no_input = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $no_input );
		$this->assertSame( 'no_text', $no_input->get_error_code() );

		$ghost_post = $tool->execute( array( 'post_id' => 999999 ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $ghost_post );
		$this->assertSame( 'post_not_found', $ghost_post->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry all eleven ported
	 * tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the multilingual tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/init.php';
		// Re-arm the filter added inside the init's guard block — the WP test
		// framework may have restored an earlier hook snapshot.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_multilingual_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 11, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Detect_Content_Language', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/class-wp-mcp-ai-tool-detect-content-language.php',
			$tools['WP_MCP_AI_Tool_Detect_Content_Language']
		);
		// The tree-only import tool is carried standalone (the base registers
		// it nowhere — CRM CC-extras precedent).
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Multilingual_Blueprint', $tools );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/examples/translation-localization-manager.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/multilingual/init.php';
		wp_mcp_ai_pro_register_multilingual_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['detect_content_language'] ?? null );
		$this->assertNotNull( $parent->all()['import_multilingual_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'detect_content_language' ) );
		$this->assertTrue( $core_tools->has( 'import_multilingual_blueprint' ) );
	}
}
