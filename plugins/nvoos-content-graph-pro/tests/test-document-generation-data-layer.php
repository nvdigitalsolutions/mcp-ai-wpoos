<?php
/**
 * Characterization tests for the Wave F2 document-generation data layer —
 * the Document Template CPT, the QMS/audit performance-optimization
 * manager, and the HTML formatter utility ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/`
 *   are asserted in full, including the slim init's file-gate targets and
 *   the standalone-only tool wiring scaffolding.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Document-generation data layer tests.
 */
class Test_Document_Generation_Data_Layer extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Document_Template_CPT'     => 'class-wp-mcp-ai-document-template-cpt.php',
			'WP_MCP_AI_Document_Gen_Optimization' => 'tools/document-generation/class-wp-mcp-ai-document-gen-optimization.php',
			'WP_MCP_AI_HTML_Formatter'            => 'tools/document-generation/class-wp-mcp-ai-html-formatter.php',
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
	 * The Document Template CPT must register the byte-identical post type.
	 */
	public function test_document_template_cpt_registration(): void {
		$this->assertSame( 'mcp_ai_doc_tpl', WP_MCP_AI_Document_Template_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_doc_tpl_cat', WP_MCP_AI_Document_Template_CPT::TAXONOMY_CATEGORY );

		WP_MCP_AI_Document_Template_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Document_Template_CPT', 'register_post_type' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Document_Template_CPT', 'register_taxonomy' ) ) );

		// The byte-identical registration callback must register the CPT.
		WP_MCP_AI_Document_Template_CPT::register_post_type();
		$this->assertTrue( post_type_exists( 'mcp_ai_doc_tpl' ) );
	}

	/**
	 * The optimization manager must expose the byte-identical prune hook and
	 * wire its hooks when the toolkit is enabled.
	 */
	public function test_optimization_contracts(): void {
		$this->assertSame( 'wp_mcp_ai_dg_weekly_audit_prune', WP_MCP_AI_Document_Gen_Optimization::AUDIT_PRUNE_HOOK );

		update_option( 'wp_mcp_ai_settings', array( 'enable_document_generation_toolkit' => true ) );
		WP_MCP_AI_Document_Gen_Optimization::init();

		$this->assertNotFalse( has_action( 'wp_mcp_ai_dg_weekly_audit_prune', array( 'WP_MCP_AI_Document_Gen_Optimization', 'prune_audit_log' ) ) );
		$this->assertSame( 40, has_action( 'init', array( 'WP_MCP_AI_Document_Gen_Optimization', 'maybe_schedule' ) ) );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * The HTML formatter must produce deterministic paragraph and table
	 * output.
	 */
	public function test_formatter_contracts(): void {
		$formatter = new WP_MCP_AI_HTML_Formatter();

		$this->assertSame( '<p>Hello</p>', $formatter->text_to_html( 'Hello', array() ) );

		$table = $formatter->create_table(
			array(
				array( 'Name', 'Value' ),
				array( 'A', '1' ),
			),
			array()
		);
		$this->assertStringStartsWith( '<table>', $table );
		$this->assertStringContainsString( '<th>Name</th>', $table );
		$this->assertStringContainsString( '<td>A</td>', $table );
		$this->assertStringEndsWith( '</table>', $table );
	}

	/**
	 * Standalone only: the slim init's file targets must exist and the tool
	 * filter must carry the thirty-four ported tools (the tool batch landed
	 * with the following sub-cluster).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-document-template-cpt.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-document-gen-optimization.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-html-formatter.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_document_generation_tools', 10 );
		$this->assertCount( 34, apply_filters( 'wp_mcp_ai_pro_tools', array() ) );
	}
}
