<?php
/**
 * Characterization tests for the Wave F2 video-production toolkit — the
 * slimmed standalone init plus the seventeen gated tools ported from the
 * base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   stub execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/video-production/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Video-production toolkit tests.
 */
class Test_Video_Production_Toolkit extends WP_UnitTestCase {

	/**
	 * The gated tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$trim = new WP_MCP_AI_Tool_Trim_Video();
		$this->assertSame( 'trim_video', $trim->get_slug() );
		$this->assertSame( 'Trim Video', $trim->get_name() );
		$this->assertSame( 'upload_files', $trim->get_required_capability() );
		$this->assertSame( array(), $trim->get_parameters_schema()['required'] );
		$this->assertSame( true, $trim->get_capability_flags()['video_editing'] );

		// The read-only queue tools drop to the `read` capability.
		$queued = new WP_MCP_AI_Tool_Get_Queued_Videos();
		$this->assertSame( 'get_queued_videos', $queued->get_slug() );
		$this->assertSame( 'read', $queued->get_required_capability() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Trim_Video'        => 'tools/video-production/class-wp-mcp-ai-tool-trim-video.php',
			'WP_MCP_AI_Tool_Get_Queued_Videos' => 'tools/video-production/class-wp-mcp-ai-tool-get-queued-videos.php',
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
	 * The trim-video execute() stub must return the canonical success
	 * envelope (the FFmpeg-backed implementation stays deferred — the base
	 * ships the same stub).
	 */
	public function test_trim_video_execute_stub(): void {
		$tool   = new WP_MCP_AI_Tool_Trim_Video();
		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'FFmpeg', $result['message'] );
	}

	/**
	 * The seventeen gated tool files must all exist standalone (the four
	 * always-on exec-service tools stay deferred until the D8 +
	 * video-services slice).
	 */
	public function test_gated_tool_files_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base addon ships the files.' );
		}

		$files = array(
			'class-wp-mcp-ai-tool-create-video-from-images.php',
			'class-wp-mcp-ai-tool-add-watermark-to-video.php',
			'class-wp-mcp-ai-tool-generate-video-captions.php',
			'class-wp-mcp-ai-tool-merge-videos.php',
			'class-wp-mcp-ai-tool-trim-video.php',
			'class-wp-mcp-ai-tool-resize-video-resolution.php',
			'class-wp-mcp-ai-tool-adjust-video-speed.php',
			'class-wp-mcp-ai-tool-compress-video.php',
			'class-wp-mcp-ai-tool-convert-video-format.php',
			'class-wp-mcp-ai-tool-optimize-for-platform.php',
			'class-wp-mcp-ai-tool-extract-video-metadata.php',
			'class-wp-mcp-ai-tool-generate-video-thumbnails.php',
			'class-wp-mcp-ai-tool-get-queued-videos.php',
			'class-wp-mcp-ai-tool-get-videos-without-thumbnails.php',
			'class-wp-mcp-ai-tool-get-videos-without-transcripts.php',
			'class-wp-mcp-ai-tool-upload-video-batch.php',
			'class-wp-mcp-ai-tool-transcribe-video.php',
		);
		foreach ( $files as $file ) {
			$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/' . $file );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry all seventeen
	 * ported tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the video tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/init.php';
		// Re-arm the filter added inside the init's guard block — the WP test
		// framework may have restored an earlier hook snapshot.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_video_production_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 17, $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Trim_Video', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-trim-video.php',
			$tools['WP_MCP_AI_Tool_Trim_Video']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Transcribe_Video', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/init.php';
		wp_mcp_ai_pro_register_video_production_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['trim_video'] ?? null );
		$this->assertNotNull( $parent->all()['get_queued_videos'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'trim_video' ) );
		$this->assertTrue( $core_tools->has( 'get_queued_videos' ) );
	}
}
