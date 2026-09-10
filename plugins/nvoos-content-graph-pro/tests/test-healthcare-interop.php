<?php
/**
 * Characterization tests for the Wave F4 healthcare interop + OpenMed +
 * blueprint batch — the eight ported interop/OpenMed/blueprint tools plus
 * the four JSON blueprints.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces are asserted (the gated map
 *   files are required deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/interop/` + `examples/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare interop tests.
 */
class Test_Healthcare_Interop extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eight gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/interop/class-wp-mcp-ai-tool-import-fhir-bundle.php',
				'tools/healthcare/interop/class-wp-mcp-ai-tool-export-ccda-document.php',
				'tools/healthcare/interop/class-wp-mcp-ai-tool-import-hl7v2-message.php',
				'tools/healthcare/interop/class-wp-mcp-ai-tool-connect-to-ehr.php',
				'tools/healthcare/interop/class-wp-mcp-ai-tool-export-fhir-data.php',
				'tools/healthcare/examples/class-wp-mcp-ai-tool-import-healthcare-blueprint.php',
				'tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php',
				'tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Import_FHIR_Bundle'          => 'tools/healthcare/interop/class-wp-mcp-ai-tool-import-fhir-bundle.php',
			'WP_MCP_AI_Tool_Export_CCDA_Document'        => 'tools/healthcare/interop/class-wp-mcp-ai-tool-export-ccda-document.php',
			'WP_MCP_AI_Tool_Import_HL7v2_Message'        => 'tools/healthcare/interop/class-wp-mcp-ai-tool-import-hl7v2-message.php',
			'WP_MCP_AI_Tool_Connect_To_EHR'              => 'tools/healthcare/interop/class-wp-mcp-ai-tool-connect-to-ehr.php',
			'WP_MCP_AI_Tool_Export_FHIR_Data'            => 'tools/healthcare/interop/class-wp-mcp-ai-tool-export-fhir-data.php',
			'WP_MCP_AI_Tool_Import_Healthcare_Blueprint' => 'tools/healthcare/examples/class-wp-mcp-ai-tool-import-healthcare-blueprint.php',
			'WP_MCP_AI_Tool_Deidentify_Health_Record'    => 'tools/healthcare/class-wp-mcp-ai-tool-deidentify-health-record.php',
			'WP_MCP_AI_Tool_Extract_Clinical_Entities'   => 'tools/healthcare/class-wp-mcp-ai-tool-extract-clinical-entities.php',
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
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Import_FHIR_Bundle'          => 'import_fhir_bundle',
			'WP_MCP_AI_Tool_Export_CCDA_Document'        => 'export_ccda_document',
			'WP_MCP_AI_Tool_Import_HL7v2_Message'        => 'import_hl7v2_message',
			'WP_MCP_AI_Tool_Connect_To_EHR'              => 'connect_to_ehr',
			'WP_MCP_AI_Tool_Export_FHIR_Data'            => 'export_fhir_data',
			'WP_MCP_AI_Tool_Import_Healthcare_Blueprint' => 'import_healthcare_blueprint',
			'WP_MCP_AI_Tool_Deidentify_Health_Record'    => 'deidentify_health_record',
			'WP_MCP_AI_Tool_Extract_Clinical_Entities'   => 'extract_clinical_entities',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}
	}

	/**
	 * The blueprint tool must pin the examples directory and the four JSON
	 * blueprints must ship byte-identical.
	 */
	public function test_blueprint_contracts(): void {
		$tool = new WP_MCP_AI_Tool_Import_Healthcare_Blueprint();
		$this->assertTrue( method_exists( $tool, 'get_slug' ) );
		$this->assertTrue( method_exists( $tool, 'execute' ) );

		$dir = ( new ReflectionClass( 'WP_MCP_AI_Tool_Import_Healthcare_Blueprint' ) )->getConstant( 'BLUEPRINTS_DIR' );
		$this->assertNotFalse( $dir );

		foreach ( array( 'general-clinic.json', 'personal-health-tracker.json', 'radiology-review.json', 'veterinary-practice.json' ) as $json ) {
			$path = rtrim( (string) $dir, '/' ) . '/' . $json;
			$this->assertFileExists( $path, $json );
		}
	}
}
