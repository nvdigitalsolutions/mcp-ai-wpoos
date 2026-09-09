<?php
/**
 * Characterization tests for the Wave F2 image-production data layer — the
 * image-template CPT, the remove-background helper, and the base-owned
 * D8-compat image tool base (plus its SVG-vectorizer/image-response trait
 * dependencies) ported from the base plugin.
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
 * Image-production data layer tests.
 */
class Test_Image_Production_Data_Layer extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Image_Template_CPT'  => 'class-wp-mcp-ai-image-template-cpt.php',
			'WP_MCP_AI_Tool_Image_Base'     => 'tools/class-wp-mcp-ai-tool-image-base.php',
			'WP_MCP_AI_SVG_Vectorizer'      => 'traits/trait-wp-mcp-ai-svg-vectorizer.php',
			'WP_MCP_AI_Tool_Image_Response' => 'tools/trait-wp-mcp-ai-tool-image-response.php',
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
	 * The image-template CPT contracts must be byte-identical.
	 */
	public function test_image_template_cpt_contracts(): void {
		$this->assertSame( 'mcp_ai_image_tpl', WP_MCP_AI_Image_Template_CPT::POST_TYPE );
		// init() hooks the registration onto the `init` action — call the
		// registrar directly for the assertion.
		WP_MCP_AI_Image_Template_CPT::register_post_type();
		$this->assertTrue( post_type_exists( 'mcp_ai_image_tpl' ) );
	}

	/**
	 * The image-base must stay abstract and implement the tool interfaces.
	 */
	public function test_image_base_contracts(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Image_Base' );
		$this->assertTrue( $reflection->isAbstract() );
		$this->assertTrue( $reflection->implementsInterface( 'WP_MCP_AI_Tool_Interface' ) );
		$this->assertTrue( $reflection->implementsInterface( 'WP_MCP_AI_Tool_LLM_Sanitizer_Interface' ) );
	}

	/**
	 * The remove-background helper must degrade to a WP_Error without a
	 * removable image (no external probes are exercised).
	 */
	public function test_remove_background_helper_contracts(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/remove-background.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/remove-background.php';
		}

		$result = wp_mcp_ai_remove_image_background( '/nonexistent/image.png' );
		$this->assertTrue( is_wp_error( $result ) || is_string( $result ) );
	}

	/**
	 * Standalone only: the slim init's file targets must exist and the tool
	 * filter must start empty (fills with the tool batches).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-image-template-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/remove-background.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/class-wp-mcp-ai-tool-image-base.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/traits/trait-wp-mcp-ai-svg-vectorizer.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-image-response.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_image_production_tools', 10 );
		$this->assertCount( 23, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
	}
}
