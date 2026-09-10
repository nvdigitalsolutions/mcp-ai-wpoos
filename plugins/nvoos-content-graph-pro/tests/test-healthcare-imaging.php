<?php
/**
 * Characterization tests for the Wave F4 healthcare imaging + DICOMweb
 * batch — the eight ported imaging tools plus the DICOMweb client helper.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces are asserted (the gated map
 *   files are required deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/imaging/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare imaging tests.
 */
class Test_Healthcare_Imaging extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the nine gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-manage-imaging-studies.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-interpret-imaging-study.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-connect-dicomweb.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-import-dicom-study.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-export-dicom-study.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-attach-radiology-report.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-compare-imaging-studies.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-tool-get-imaging-hanging-protocol.php',
				'tools/healthcare/imaging/class-wp-mcp-ai-dicomweb-client.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The nine ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Manage_Imaging_Studies'       => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-manage-imaging-studies.php',
			'WP_MCP_AI_Tool_Interpret_Imaging_Study'      => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-interpret-imaging-study.php',
			'WP_MCP_AI_Tool_Connect_DICOMweb'             => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-connect-dicomweb.php',
			'WP_MCP_AI_Tool_Import_DICOM_Study'           => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-import-dicom-study.php',
			'WP_MCP_AI_Tool_Export_DICOM_Study'           => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-export-dicom-study.php',
			'WP_MCP_AI_Tool_Attach_Radiology_Report'      => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-attach-radiology-report.php',
			'WP_MCP_AI_Tool_Compare_Imaging_Studies'      => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-compare-imaging-studies.php',
			'WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol' => 'tools/healthcare/imaging/class-wp-mcp-ai-tool-get-imaging-hanging-protocol.php',
			'WP_MCP_AI_DICOMweb_Client'                   => 'tools/healthcare/imaging/class-wp-mcp-ai-dicomweb-client.php',
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
			'WP_MCP_AI_Tool_Manage_Imaging_Studies'       => 'manage_imaging_studies',
			'WP_MCP_AI_Tool_Interpret_Imaging_Study'      => 'interpret_imaging_study',
			'WP_MCP_AI_Tool_Connect_DICOMweb'             => 'connect_dicomweb',
			'WP_MCP_AI_Tool_Import_DICOM_Study'           => 'import_dicom_study',
			'WP_MCP_AI_Tool_Export_DICOM_Study'           => 'export_dicom_study',
			'WP_MCP_AI_Tool_Attach_Radiology_Report'      => 'attach_radiology_report',
			'WP_MCP_AI_Tool_Compare_Imaging_Studies'      => 'compare_imaging_studies',
			'WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol' => 'get_imaging_hanging_protocol',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The DICOMweb client's connection-option contract must be
	 * byte-identical.
	 */
	public function test_dicomweb_client_contract(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_DICOMweb_Client' );
		$this->assertTrue( $reflection->hasConstant( 'OPTION_CONNECTION' ) );
		$this->assertTrue( method_exists( 'WP_MCP_AI_DICOMweb_Client', 'save_connection' ) );
		$this->assertTrue( method_exists( 'WP_MCP_AI_DICOMweb_Client', 'get_connection' ) );
	}
}
