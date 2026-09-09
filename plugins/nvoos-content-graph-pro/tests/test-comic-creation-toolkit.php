<?php
/**
 * Characterization tests for the Wave F2 comic-creation tool batch — the
 * twelve gated tools and the tree-only import-blueprint tool ported from the
 * base Pro addon onto the D8-compat interface/Logger copies.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   deterministic execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/comic-creation/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Comic-creation tool batch tests.
 */
class Test_Comic_Creation_Toolkit extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$script = new WP_MCP_AI_Tool_Generate_Comic_Script();
		$this->assertSame( 'generate_comic_script', $script->get_slug() );
		$this->assertSame( 'edit_posts', $script->get_required_capability() );

		$upscale = new WP_MCP_AI_Tool_Upscale_Comic_Page();
		$this->assertSame( 'upscale_comic_page', $upscale->get_slug() );
		$this->assertSame( 'edit_posts', $upscale->get_required_capability() );

		$import = new WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint();
		$this->assertSame( 'import_comic_creation_blueprint', $import->get_slug() );
		$this->assertSame( 'edit_posts', $import->get_required_capability() );
		$this->assertTrue( $import->requires_base_pro() );
		$this->assertIsBool( WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint::is_available() );
		$this->assertSame( array( 'comic-artist' ), WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint::BLUEPRINT_SLUGS );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Generate_Comic_Script' => 'tools/comic-creation/class-wp-mcp-ai-tool-generate-comic-script.php',
			'WP_MCP_AI_Tool_Upscale_Comic_Page'    => 'tools/comic-creation/class-wp-mcp-ai-tool-upscale-comic-page.php',
			'WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint' => 'tools/comic-creation/examples/class-wp-mcp-ai-tool-import-comic-creation-blueprint.php',
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
	 * The generate-comic-script execute() is deterministic (simulated script
	 * generator — no external probes): capability gate, premise gate, and a
	 * full success cycle creating an `mcp_ai_comic_script` post.
	 */
	public function test_generate_comic_script_execute(): void {
		WP_MCP_AI_Comic_Script_CPT::register_post_type();

		$tool = new WP_MCP_AI_Tool_Generate_Comic_Script();

		// Forbidden without a capable user.
		$this->assertWPError( $tool->execute( array(), array() ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		// Missing premise.
		$missing = $tool->execute( array( 'premise' => '   ' ), array( 'user_id' => $user_id ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_premise', $missing->get_error_code() );

		// Success cycle.
		$result = $tool->execute(
			array(
				'premise'     => 'A detective in a cyberpunk city discovers a conspiracy.',
				'genre'       => 'noir',
				'panel_count' => 6,
			),
			array( 'user_id' => $user_id )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'noir', $result['data']['genre'] );
		$this->assertSame( 6, $result['data']['panel_count'] );
		$this->assertIsInt( $result['data']['script_id'] );
		$post = get_post( $result['data']['script_id'] );
		$this->assertSame( 'mcp_ai_comic_script', $post->post_type );
		$this->assertSame( $user_id, (int) $post->post_author );
	}

	/**
	 * Standalone only: the init's tool filter must carry all thirteen ported
	 * tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the comic tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_comic_creation_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 13, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/class-wp-mcp-ai-tool-generate-comic-script.php',
			$tools['WP_MCP_AI_Tool_Generate_Comic_Script']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Apply_Comic_Style', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/examples/class-wp-mcp-ai-tool-import-comic-creation-blueprint.php',
			$tools['WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/examples/comic-artist.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/init.php';
		wp_mcp_ai_pro_register_comic_creation_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['generate_comic_script'] ?? null );
		$this->assertNotNull( $parent->all()['import_comic_creation_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'generate_comic_script' ) );
		$this->assertTrue( $core_tools->has( 'import_comic_creation_blueprint' ) );
		$this->assertTrue( $core_tools->has( 'upscale_comic_page' ) );
	}
}
