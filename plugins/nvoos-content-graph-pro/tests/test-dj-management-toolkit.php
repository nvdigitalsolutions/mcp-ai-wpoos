<?php
/**
 * Characterization tests for the Wave F2 dj-management toolkit — the slimmed
 * standalone init plus the twenty gated/tree-only tools and the import
 * blueprint tool ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/dj-management/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * DJ-management toolkit tests.
 */
class Test_Dj_Management_Toolkit extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$trending = new WP_MCP_AI_Tool_Get_Trending_Tracks();
		$this->assertSame( 'get_trending_tracks', $trending->get_slug() );
		$this->assertSame( 'read', $trending->get_required_capability() );

		$rotation = new WP_MCP_AI_Tool_Update_Playlist_Rotation();
		$this->assertSame( 'update_playlist_rotation', $rotation->get_slug() );

		$import = new WP_MCP_AI_Tool_Import_DJ_Management_Blueprint();
		$this->assertSame( 'import_dj_management_blueprint', $import->get_slug() );

		$jukebox = new WP_MCP_AI_Tool_Generate_Jukebox_Music();
		$this->assertSame( 'generate_jukebox_music', $jukebox->get_slug() );
		$this->assertSame( 'edit_posts', $jukebox->get_required_capability() );

		$jukebox_status = new WP_MCP_AI_Tool_Check_Jukebox_Status();
		$this->assertSame( 'check_jukebox_status', $jukebox_status->get_slug() );
		$this->assertSame( 'edit_posts', $jukebox_status->get_required_capability() );

		$this->assertSame( 20, WP_MCP_AI_Jukebox_Service::DEFAULT_SAMPLE_LENGTH );
		$this->assertSame( 60, WP_MCP_AI_Jukebox_Service::MAX_SAMPLE_LENGTH );
		$this->assertSame( '5b_lyrics', WP_MCP_AI_Jukebox_Service::DEFAULT_MODEL );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Get_Trending_Tracks'      => 'tools/dj-management/class-wp-mcp-ai-tool-get-trending-tracks.php',
			'WP_MCP_AI_Tool_Update_Playlist_Rotation' => 'tools/dj-management/class-wp-mcp-ai-tool-update-playlist-rotation.php',
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
	 * The get-trending-tracks execute() reports the disabled-toolkit state
	 * through the byte-identical envelope (the base ships the same
	 * `success => false` shape).
	 */
	public function test_trending_execute_disabled_toolkit(): void {
		$tool   = new WP_MCP_AI_Tool_Get_Trending_Tracks();
		$result = $tool->execute( array(), array() );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Standalone only: the init's tool filter must carry all twenty-three
	 * ported tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the DJ tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_dj_management_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 23, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Trending_Tracks', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-get-trending-tracks.php',
			$tools['WP_MCP_AI_Tool_Get_Trending_Tracks']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_DJ_Management_Blueprint', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/class-wp-mcp-ai-tool-generate-jukebox-music.php',
			$tools['WP_MCP_AI_Tool_Generate_Jukebox_Music']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Check_Jukebox_Status', $tools );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/examples/event-booking-coordinator.json' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/examples/dj-business-manager.json' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/dj-management/init.php';
		wp_mcp_ai_pro_register_dj_management_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['get_trending_tracks'] ?? null );
		$this->assertNotNull( $parent->all()['import_dj_management_blueprint'] ?? null );
		$this->assertNotNull( $parent->all()['generate_jukebox_music'] ?? null );
		$this->assertNotNull( $parent->all()['check_jukebox_status'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_trending_tracks' ) );
		$this->assertTrue( $core_tools->has( 'import_dj_management_blueprint' ) );
		$this->assertTrue( $core_tools->has( 'generate_jukebox_music' ) );
		$this->assertTrue( $core_tools->has( 'check_jukebox_status' ) );
	}
}
