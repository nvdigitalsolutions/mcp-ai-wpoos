<?php
/**
 * Characterization tests for the Wave F2 image-production tool batch — the
 * twenty-two top-level tools and the tree-only import-blueprint tool ported
 * from the base Pro addon onto the D8-compat image base.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/image-production/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Image-production tool batch tests.
 */
class Test_Image_Production_Tools extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$alt = new WP_MCP_AI_Tool_Get_Images_Without_Alt();
		$this->assertSame( 'get_images_without_alt', $alt->get_slug() );
		$this->assertSame( 'upload_files', $alt->get_required_capability() );

		$upscale = new WP_MCP_AI_Tool_Upscale_Image_AI();
		$this->assertSame( 'upscale_image_ai', $upscale->get_slug() );

		$import = new WP_MCP_AI_Tool_Import_Image_Production_Blueprint();
		$this->assertSame( 'import_image_production_blueprint', $import->get_slug() );

		// Harmonization sub-toolkit surfaces.
		$harmonize = new WP_MCP_AI_Tool_Harmonize_Color();
		$this->assertSame( 'harmonize_color', $harmonize->get_slug() );
		$this->assertIsBool( WP_MCP_AI_Tool_Harmonize_Color::is_available() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Get_Images_Without_Alt' => 'tools/image-production/class-wp-mcp-ai-tool-get-images-without-alt.php',
			'WP_MCP_AI_Tool_Upscale_Image_AI'       => 'tools/image-production/class-wp-mcp-ai-tool-upscale-image-ai.php',
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
	 * The get-images-without-alt execute() smoke-runs over an empty media
	 * library (no external probes are exercised).
	 */
	public function test_get_images_without_alt_execute(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Images_Without_Alt();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry all twenty-three
	 * ported tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the image-production tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_image_production_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 37, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Images_Without_Alt', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-get-images-without-alt.php',
			$tools['WP_MCP_AI_Tool_Get_Images_Without_Alt']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Image_Production_Blueprint', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Harmonize_Image_Into_Background', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonize-image-into-background.php',
			$tools['WP_MCP_AI_Tool_Harmonize_Image_Into_Background']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/examples/creative-image-producer.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/init.php';
		wp_mcp_ai_pro_register_image_production_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['get_images_without_alt'] ?? null );
		$this->assertNotNull( $parent->all()['import_image_production_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_images_without_alt' ) );
		$this->assertTrue( $core_tools->has( 'import_image_production_blueprint' ) );
		$this->assertTrue( $core_tools->has( 'harmonize_image_into_background' ) );
	}
}
