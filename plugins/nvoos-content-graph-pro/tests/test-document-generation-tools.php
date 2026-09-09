<?php
/**
 * Characterization tests for the Wave F2 document-generation tool batch —
 * the thirty-four ported tools (11 always-on email/QMS, 21 toolkit-gated,
 * 2 tree-only OCR, and the tree-only import-blueprint tool) plus the three
 * ported services and the self-hosted-OCR D8-compat copy.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces and the
 *   graceful execute() contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/document-generation/` are asserted in full, including the
 *   standalone-only init filter and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Document-generation tool batch tests.
 */
class Test_Document_Generation_Tools extends WP_UnitTestCase {

	/**
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$pdf = new WP_MCP_AI_Tool_Generate_PDF();
		$this->assertSame( 'generate_pdf', $pdf->get_slug() );

		$email = new WP_MCP_AI_Tool_Generate_Email_Template();
		$this->assertSame( 'generate_email_template', $email->get_slug() );

		$qms = new WP_MCP_AI_Tool_QMS_Create_Controlled_Document();
		$this->assertSame( 'qms_create_controlled_document', $qms->get_slug() );
		$this->assertSame( 'edit_posts', $qms->get_required_capability() );

		$import = new WP_MCP_AI_Tool_Import_Document_Generation_Blueprint();
		$this->assertSame( 'import_document_generation_blueprint', $import->get_slug() );
		$this->assertIsBool( WP_MCP_AI_Tool_Import_Document_Generation_Blueprint::is_available() );
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Generate_PDF' => 'tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php',
			'WP_MCP_AI_Tool_QMS_Create_Controlled_Document' => 'tools/document-generation/class-wp-mcp-ai-tool-qms-create-controlled-document.php',
			'WP_MCP_AI_Tool_Import_Document_Generation_Blueprint' => 'tools/document-generation/examples/class-wp-mcp-ai-tool-import-document-generation-blueprint.php',
			'WP_MCP_AI_OCR_Service'       => 'services/class-wp-mcp-ai-ocr-service.php',
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
	 * The deterministic QMS create contract degrades gracefully with a
	 * missing title in the no-external-input environment.
	 */
	public function test_engine_backed_execute(): void {
		$tool   = new WP_MCP_AI_Tool_QMS_Create_Controlled_Document();
		$result = $tool->execute(
			array(
				'title'       => '',
				'document_id' => 'DOC-001',
			),
			array( 'user_id' => 0 )
		);
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry all thirty-four
	 * ported tools with the addon's file paths.
	 */
	public function test_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_document_generation_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertCount( 34, $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php',
			$tools['WP_MCP_AI_Tool_Generate_PDF']
		);
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-create-controlled-document.php',
			$tools['WP_MCP_AI_Tool_QMS_Create_Controlled_Document']
		);
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Pro_Batch_OCR', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Pro_Unlimited_OCR', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/examples/class-wp-mcp-ai-tool-import-document-generation-blueprint.php',
			$tools['WP_MCP_AI_Tool_Import_Document_Generation_Blueprint']
		);
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/examples/document-production-specialist.json' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'bin/generate-pdf.bundle.js' );
		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'node-services/pdf-extract-service.js' );
	}

	/**
	 * Standalone only: the ecosystem registration must register the ported
	 * tools into the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/init.php';
		wp_mcp_ai_pro_register_document_generation_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['generate_pdf'] ?? null );
		$this->assertNotNull( $parent->all()['qms_create_controlled_document'] ?? null );
		$this->assertNotNull( $parent->all()['import_document_generation_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'generate_pdf' ) );
		$this->assertTrue( $core_tools->has( 'generate_email_template' ) );
		$this->assertTrue( $core_tools->has( 'import_document_generation_blueprint' ) );
	}
}
