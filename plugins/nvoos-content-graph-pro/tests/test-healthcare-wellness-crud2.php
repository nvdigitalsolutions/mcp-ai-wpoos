<?php
/**
 * Characterization tests for the Wave F4 healthcare wellness CRUD batch 2 —
 * the twelve ported prescription/medical-record tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp — docgen misnamed-page precedent).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/healthcare/wellness/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Healthcare wellness CRUD batch 2 tests.
 */
class Test_Healthcare_Wellness_Crud2 extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the twelve gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-create-prescription.php',
				'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-list-prescriptions.php',
				'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-get-prescription.php',
				'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-update-prescription.php',
				'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-delete-prescription.php',
				'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-search-prescriptions.php',
				'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php',
				'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-list-medical-records.php',
				'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-get-medical-record.php',
				'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-update-medical-record.php',
				'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-delete-medical-record.php',
				'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-search-medical-records.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The twelve ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Prescription'    => 'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-create-prescription.php',
			'WP_MCP_AI_Tool_List_Prescriptions'     => 'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-list-prescriptions.php',
			'WP_MCP_AI_Tool_Get_Prescription'       => 'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-get-prescription.php',
			'WP_MCP_AI_Tool_Update_Prescription'    => 'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-update-prescription.php',
			'WP_MCP_AI_Tool_Delete_Prescription'    => 'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-delete-prescription.php',
			'WP_MCP_AI_Tool_Search_Prescriptions'   => 'tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-search-prescriptions.php',
			'WP_MCP_AI_Tool_Create_Medical_Record'  => 'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php',
			'WP_MCP_AI_Tool_List_Medical_Records'   => 'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-list-medical-records.php',
			'WP_MCP_AI_Tool_Get_Medical_Record'     => 'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-get-medical-record.php',
			'WP_MCP_AI_Tool_Update_Medical_Record'  => 'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-update-medical-record.php',
			'WP_MCP_AI_Tool_Delete_Medical_Record'  => 'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-delete-medical-record.php',
			'WP_MCP_AI_Tool_Search_Medical_Records' => 'tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-search-medical-records.php',
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
			'WP_MCP_AI_Tool_Create_Prescription'    => 'create_prescription',
			'WP_MCP_AI_Tool_List_Prescriptions'     => 'list_prescriptions',
			'WP_MCP_AI_Tool_Get_Prescription'       => 'get_prescription',
			'WP_MCP_AI_Tool_Update_Prescription'    => 'update_prescription',
			'WP_MCP_AI_Tool_Delete_Prescription'    => 'delete_prescription',
			'WP_MCP_AI_Tool_Search_Prescriptions'   => 'search_prescriptions',
			'WP_MCP_AI_Tool_Create_Medical_Record'  => 'create_medical_record',
			'WP_MCP_AI_Tool_List_Medical_Records'   => 'list_medical_records',
			'WP_MCP_AI_Tool_Get_Medical_Record'     => 'get_medical_record',
			'WP_MCP_AI_Tool_Update_Medical_Record'  => 'update_medical_record',
			'WP_MCP_AI_Tool_Delete_Medical_Record'  => 'delete_medical_record',
			'WP_MCP_AI_Tool_Search_Medical_Records' => 'search_medical_records',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The deterministic create-medical-record contract degrades gracefully
	 * with a missing member (the byte-identical first-gate code).
	 */
	public function test_create_medical_record_missing_gate(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_Create_Medical_Record();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_member', $result->get_error_code() );
	}
}
