<?php
/**
 * Characterization tests for the Wave F2 architectural-design data layer —
 * the four architectural CPTs and the five engine classes ported from the
 * base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   symbols (classmap-autoloaded); the byte-identical contracts are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/` and
 *   `src/tools/architectural-design/` are asserted in full, including the
 *   slim init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Architectural-design data layer tests.
 */
class Test_Architectural_Design_Data_Layer extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Architectural_Project_CPT'       => 'class-wp-mcp-ai-architectural-project-cpt.php',
			'WP_MCP_AI_Architectural_Drawing_CPT'       => 'class-wp-mcp-ai-architectural-drawing-cpt.php',
			'WP_MCP_AI_Architectural_Specification_CPT' => 'class-wp-mcp-ai-architectural-specification-cpt.php',
			'WP_MCP_AI_Architectural_Precedent_CPT'     => 'class-wp-mcp-ai-architectural-precedent-cpt.php',
			'WP_MCP_AI_Architectural_Engine'            => 'tools/architectural-design/class-wp-mcp-ai-architectural-engine.php',
			'WP_MCP_AI_Architectural_Codes'             => 'tools/architectural-design/class-wp-mcp-ai-architectural-codes.php',
			'WP_MCP_AI_Architectural_Interop'           => 'tools/architectural-design/class-wp-mcp-ai-architectural-interop.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The four architectural CPT post types must register via their
	 * registrar.
	 */
	public function test_architectural_cpt_registrations(): void {
		$this->assertSame( 'mcp_ai_arch_proj', WP_MCP_AI_Architectural_Project_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_arch_draw', WP_MCP_AI_Architectural_Drawing_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_arch_spec', WP_MCP_AI_Architectural_Specification_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_arch_prec', WP_MCP_AI_Architectural_Precedent_CPT::POST_TYPE );

		WP_MCP_AI_Architectural_Project_CPT::register_post_type();
		WP_MCP_AI_Architectural_Drawing_CPT::register_post_type();
		WP_MCP_AI_Architectural_Specification_CPT::register_post_type();
		WP_MCP_AI_Architectural_Precedent_CPT::register_post_type();

		$this->assertTrue( post_type_exists( 'mcp_ai_arch_proj' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_arch_draw' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_arch_spec' ) );
		$this->assertTrue( post_type_exists( 'mcp_ai_arch_prec' ) );
	}

	/**
	 * The engine surface must be byte-identical.
	 */
	public function test_engine_contracts(): void {
		$this->assertIsArray( WP_MCP_AI_Architectural_Codes::get_code_packs() );
		$this->assertIsArray( WP_MCP_AI_Architectural_Codes::get_supported_countries() );
		$this->assertSame( 10.7639104, WP_MCP_AI_Architectural_Engine::sqm_to_sqft( 1.0 ) );
		$this->assertIsArray( WP_MCP_AI_Architectural_Engine::get_currency_rates() );
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
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-project-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-drawing-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-specification-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-architectural-precedent-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/class-wp-mcp-ai-architectural-engine.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/class-wp-mcp-ai-architectural-codes.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/class-wp-mcp-ai-architectural-sustainability.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/class-wp-mcp-ai-architectural-interop.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/class-wp-mcp-ai-architectural-precedents-engine.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/architectural-design/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_architectural_design_tools', 10 );
		$arch_tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 41, $arch_tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Generate_Floor_Plan', $arch_tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Generate_Architectural_Drawing', $arch_tools );
	}
}
