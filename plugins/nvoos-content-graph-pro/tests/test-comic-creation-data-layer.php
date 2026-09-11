<?php
/**
 * Characterization tests for the Wave F2 comic-creation data layer — the
 * base-owned D8-compat Logger copy and the four comic CPTs ported from the
 * base plugin.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base plugin/base Pro addon own
 *   the symbols (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the slim init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Comic-creation data layer tests.
 */
class Test_Comic_Creation_Data_Layer extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Logger'              => 'class-wp-mcp-ai-logger.php',
			'WP_MCP_AI_Comic_CPT'           => 'class-wp-mcp-ai-comic-cpt.php',
			'WP_MCP_AI_Comic_Panel_CPT'     => 'class-wp-mcp-ai-comic-panel-cpt.php',
			'WP_MCP_AI_Comic_Character_CPT' => 'class-wp-mcp-ai-comic-character-cpt.php',
			'WP_MCP_AI_Comic_Script_CPT'    => 'class-wp-mcp-ai-comic-script-cpt.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'includes/' . $file, $path, $class );
			} else {
				// Standalone: the addon copy serves — except when the monorepo
				// root classmap answers first (real standalone installs have
				// no root vendor, so the addon copy serves there).
				$this->assertTrue(
					false !== strpos( $path, 'nvoos-content-graph-pro/src/' . $file )
						|| false !== strpos( $path, 'includes/' . $file ),
					$class . ' served from an unexpected file: ' . $path
				);
			}
		}
	}

	/**
	 * The Logger constants must be byte-identical.
	 */
	public function test_logger_contracts(): void {
		$this->assertSame( '[NV oOS]', WP_MCP_AI_Logger::PREFIX );
		$this->assertSame( 'wp_mcp_ai_recent_errors', WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		$this->assertSame( 'wp_mcp_ai_recent_activity', WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		$this->assertSame( 'critical', WP_MCP_AI_Logger::LEVEL_CRITICAL );
	}

	/**
	 * The four comic CPT post types must register via their registrar.
	 */
	public function test_comic_cpt_registrations(): void {
		$this->assertSame( 'mcp_ai_comic', WP_MCP_AI_Comic_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_comic_panel', WP_MCP_AI_Comic_Panel_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_comic_char', WP_MCP_AI_Comic_Character_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_comic_script', WP_MCP_AI_Comic_Script_CPT::POST_TYPE );

		WP_MCP_AI_Comic_CPT::register_post_type();
		WP_MCP_AI_Comic_Panel_CPT::register_post_type();
		WP_MCP_AI_Comic_Character_CPT::register_post_type();
		WP_MCP_AI_Comic_Script_CPT::register_post_type();

		$this->assertTrue( post_type_exists( 'mcp_ai_comic' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_comic_panel' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_comic_char' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_comic_script' ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist and the tool
	 * filter must start empty (fills with the tool batch).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-panel-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-character-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-comic-script-cpt.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/comic-creation/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_comic_creation_tools', 10 );
		$comic_tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 13, $comic_tools );
		foreach (
			array(
				'WP_MCP_AI_Tool_Generate_Comic_Script',
				'WP_MCP_AI_Tool_Breakdown_Comic_Panels',
				'WP_MCP_AI_Tool_Generate_Character_Sheet',
				'WP_MCP_AI_Tool_Generate_Comic_Panel',
				'WP_MCP_AI_Tool_Create_Comic_Layout',
				'WP_MCP_AI_Tool_Add_Speech_Bubbles',
				'WP_MCP_AI_Tool_Export_Comic_Cbz',
				'WP_MCP_AI_Tool_Colorize_Comic_Panel',
				'WP_MCP_AI_Tool_Ink_Comic_Panel',
				'WP_MCP_AI_Tool_Letter_Comic_Panel',
				'WP_MCP_AI_Tool_Upscale_Comic_Page',
				'WP_MCP_AI_Tool_Apply_Comic_Style',
				'WP_MCP_AI_Tool_Import_Comic_Creation_Blueprint',
			) as $comic_class
		) {
			$this->assertArrayHasKey( $comic_class, $comic_tools );
		}
	}
}
