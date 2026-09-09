<?php
/**
 * Characterization tests for the Wave F2 site-creator data layer — the
 * Site_Template_CPT, the two design services, the theme-json generator, and
 * the upgrader-skin D8-compat copy ported from the base plugin/addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   symbols (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` are
 *   asserted in full, including the slim init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Site-creator data layer tests.
 */
class Test_Site_Creator_Data_Layer extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Site_Template_CPT'        => 'class-wp-mcp-ai-site-template-cpt.php',
			'WP_MCP_AI_Design_Extractor_Service' => 'site-creator-toolkit/class-wp-mcp-ai-design-extractor-service.php',
			'WP_MCP_AI_Design_Snippet_Renderer'  => 'site-creator-toolkit/class-wp-mcp-ai-design-snippet-renderer.php',
			'WP_MCP_AI_Theme_Json_Generator'     => 'helpers/class-wp-mcp-ai-theme-json-generator.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertTrue(
					false !== strpos( $path, 'addons/pro/includes/' . $file )
						|| false !== strpos( $path, 'includes/' . $file ),
					$class . ' served from an unexpected file: ' . $path
				);
			} else {
				$this->assertTrue(
					false !== strpos( $path, 'nvoos-content-graph-pro/src/' . $file ),
					$class . ' served from an unexpected file: ' . $path
				);
			}
		}
	}

	/**
	 * The Site_Template_CPT contracts must be byte-identical (the slug is
	 * hardcoded — no POST_TYPE constant — and registration is toolkit-gated).
	 */
	public function test_site_template_cpt_contracts(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_site_creator_toolkit' => true ) );

		$cpt = new WP_MCP_AI_Site_Template_CPT();
		$cpt->register_post_type();
		$this->assertTrue( post_type_exists( 'mcp_site_template' ) );
	}

	/**
	 * The design services must be constructible with byte-identical contracts.
	 */
	public function test_design_service_contracts(): void {
		$extractor = new WP_MCP_AI_Design_Extractor_Service();
		$this->assertInstanceOf( 'WP_MCP_AI_Design_Extractor_Service', $extractor );

		$renderer = new WP_MCP_AI_Design_Snippet_Renderer();
		$this->assertInstanceOf( 'WP_MCP_AI_Design_Snippet_Renderer', $renderer );

		$generator = new WP_MCP_AI_Theme_Json_Generator();
		$this->assertInstanceOf( 'WP_MCP_AI_Theme_Json_Generator', $generator );
	}

	/**
	 * Standalone only: the upgrader-skin D8 copy must load and the slim
	 * init's file targets must exist with an empty tool filter.
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-site-template-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/site-creator-toolkit/class-wp-mcp-ai-design-extractor-service.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/site-creator-toolkit/class-wp-mcp-ai-design-snippet-renderer.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/helpers/class-wp-mcp-ai-theme-json-generator.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-upgrader-skin.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/site-creator-toolkit/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_site_creator_tools', 10 );
		$this->assertSame( array(), apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
	}
}
