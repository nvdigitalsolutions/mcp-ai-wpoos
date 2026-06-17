<?php
/**
 * Tests for Architectural Design Phase D tools.
 *
 * Phase D — Interoperability (DWG / IFC / gbXML import-export) and project
 * delivery (BIM Execution Plan, RFI / submittal logs).
 *
 * Fixtures cover:
 *   - Round-trip: floor-plan payload → import_dwg_floor_plan → export_to_ifc.
 *   - import_ifc_model summary counts.
 *   - export_to_gbxml well-formed XML output.
 *   - generate_bim_execution_plan section completeness.
 *   - manage_rfi_log create → list → update → status workflow.
 *   - manage_submittal_log create → approve workflow + revision counter.
 *   - Cross-tool: project CPT post-meta isolation between RFI and submittals.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Phase D tools.
 */
class Test_Architectural_Tools_Phase_D extends WP_UnitTestCase {

	/**
	 * Editor user ID for tool execution context.
	 *
	 * @var int
	 */
	protected $editor_id = 0;

	/**
	 * Project post ID (mcp_ai_arch_proj).
	 *
	 * @var int
	 */
	protected $project_id = 0;

	/**
	 * Set up — load Phase D files and the architectural project CPT.
	 */
	public function setUp(): void {
		parent::setUp();

		$pro_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH
			: dirname( __DIR__ ) . '/';

		$base = $pro_path . 'includes/tools/architectural-design/';
		if ( ! file_exists( $base ) ) {
			$this->markTestSkipped( 'Architectural Design toolkit not present.' );
		}

		require_once $base . 'class-wp-mcp-ai-architectural-engine.php';
		require_once $base . 'class-wp-mcp-ai-architectural-codes.php';
		require_once $base . 'class-wp-mcp-ai-architectural-sustainability.php';
		require_once $base . 'class-wp-mcp-ai-architectural-interop.php';

		$files = array(
			'interoperability/class-wp-mcp-ai-tool-import-dwg-floor-plan.php',
			'interoperability/class-wp-mcp-ai-tool-import-ifc-model.php',
			'interoperability/class-wp-mcp-ai-tool-export-to-ifc.php',
			'interoperability/class-wp-mcp-ai-tool-export-to-gbxml.php',
			'project-delivery/class-wp-mcp-ai-tool-generate-bim-execution-plan.php',
			'project-delivery/class-wp-mcp-ai-tool-manage-rfi-log.php',
			'project-delivery/class-wp-mcp-ai-tool-manage-submittal-log.php',
		);
		foreach ( $files as $f ) {
			$abs = $base . $f;
			if ( file_exists( $abs ) ) {
				require_once $abs;
			}
		}

		// Ensure the project CPT exists for log tests.
		$project_cpt = $pro_path . 'includes/class-wp-mcp-ai-architectural-project-cpt.php';
		if ( file_exists( $project_cpt ) ) {
			require_once $project_cpt;
			if ( class_exists( 'WP_MCP_AI_Architectural_Project_CPT' ) && method_exists( 'WP_MCP_AI_Architectural_Project_CPT', 'register_post_type' ) ) {
				WP_MCP_AI_Architectural_Project_CPT::register_post_type();
			}
		}
		if ( ! post_type_exists( 'mcp_ai_arch_proj' ) ) {
			register_post_type(
				'mcp_ai_arch_proj',
				array(
					'public'          => false,
					'show_ui'         => false,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		update_option( 'wp_mcp_ai_settings', array( 'enable_architectural_design_toolkit' => 1 ) );

		$this->project_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_arch_proj',
				'post_status' => 'publish',
				'post_title'  => 'Phase D Test Project',
				'post_author' => $this->editor_id,
			)
		);
	}

	/**
	 * Helper to build a context array.
	 *
	 * @return array
	 */
	protected function ctx() {
		return array( 'user_id' => $this->editor_id );
	}

	/**
	 * Sample floor plan payload (LK villa shape).
	 *
	 * @return array
	 */
	protected function sample_plan() {
		return array(
			'project' => array(
				'name'         => 'Villa Test',
				'country_code' => 'LK',
			),
			'units'   => 'metric',
			'levels'  => array(
				array(
					'id'          => 'L1',
					'name'        => 'Ground',
					'elevation_m' => 0,
				),
				array(
					'id'          => 'L2',
					'name'        => 'First',
					'elevation_m' => 3,
				),
			),
			'rooms'   => array(
				array(
					'id'        => 'R1',
					'name'      => 'Living',
					'level_id'  => 'L1',
					'use'       => 'living',
					'area_m2'   => 22,
					'occupants' => 5,
				),
				array(
					'id'        => 'R2',
					'name'      => 'Kitchen',
					'level_id'  => 'L1',
					'use'       => 'kitchen',
					'area_m2'   => 12,
					'occupants' => 2,
				),
				array(
					'id'        => 'R3',
					'name'      => 'Bedroom',
					'level_id'  => 'L2',
					'use'       => 'bedroom',
					'area_m2'   => 16,
					'occupants' => 2,
				),
			),
			'walls'   => array(
				array(
					'id'           => 'W1',
					'level_id'     => 'L1',
					'length_m'     => 5.0,
					'height_m'     => 3.0,
					'thickness_mm' => 200,
					'is_exterior'  => true,
				),
			),
			'doors'   => array(
				array(
					'id'       => 'D1',
					'wall_id'  => 'W1',
					'width_m'  => 0.9,
					'height_m' => 2.1,
				),
			),
			'windows' => array(
				array(
					'id'       => 'WN1',
					'wall_id'  => 'W1',
					'width_m'  => 1.2,
					'height_m' => 1.5,
					'sill_m'   => 0.9,
				),
			),
		);
	}

	/**
	 * Import_dwg_floor_plan.
	 */
	public function test_import_dwg_normalises_synonyms() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Dwg_Floor_Plan' ) ) {
			$this->markTestSkipped( 'Import DWG tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Import_Dwg_Floor_Plan();
		$res  = $tool->execute(
			array(
				'payload'      => $this->sample_plan(),
				'source_label' => 'site-revC.dwg',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $res );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 'dwg-json', $res['source'] );
		$this->assertSame( 'site-revC.dwg', $res['source_label'] );
		$this->assertCount( 3, $res['payload']['spaces'] );
		$this->assertCount( 2, $res['payload']['openings'] );
		$this->assertContains( 'normalised "rooms" -> "spaces"', $res['warnings'] );
	}

	/** Test import dwg requires payload.
	 */
	public function test_import_dwg_requires_payload() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Dwg_Floor_Plan' ) ) {
			$this->markTestSkipped( 'Import DWG tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Import_Dwg_Floor_Plan();
		$res  = $tool->execute( array(), $this->ctx() );
		$this->assertWPError( $res );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $res->get_error_code() );
	}

	/**
	 * Import_ifc_model.
	 */
	public function test_import_ifc_returns_summary_counts() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Ifc_Model' ) ) {
			$this->markTestSkipped( 'Import IFC tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Import_Ifc_Model();
		$res  = $tool->execute( array( 'payload' => $this->sample_plan() ), $this->ctx() );
		$this->assertNotWPError( $res );
		$this->assertSame( 2, $res['summary']['levels_count'] );
		$this->assertSame( 3, $res['summary']['spaces_count'] );
		$this->assertSame( 2, $res['summary']['openings_count'] );
		$this->assertEqualsWithDelta( 50.0, $res['summary']['total_area_m2'], 0.01 );
	}

	/**
	 * Export_to_ifc — round trip.
	 */
	public function test_export_to_ifc_round_trip() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_To_Ifc' ) ) {
			$this->markTestSkipped( 'Export IFC tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Export_To_Ifc();
		$res  = $tool->execute(
			array(
				'floor_plan' => $this->sample_plan(),
				'author'     => 'Tester',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $res );
		$this->assertSame( 'IFC4X3', $res['format'] );
		$this->assertStringStartsWith( 'ISO-10303-21;', $res['ifc_text'] );
		$this->assertStringContainsString( "FILE_SCHEMA (('IFC4X3'));", $res['ifc_text'] );
		$this->assertStringContainsString( 'END-ISO-10303-21;', $res['ifc_text'] );
		$this->assertSame( 2, $res['entity_counts']['levels'] );
		$this->assertSame( 3, $res['entity_counts']['spaces'] );
		$this->assertSame( 2, $res['entity_counts']['openings'] );
	}

	/**
	 * Export_to_gbxml — XML well-formedness.
	 */
	public function test_export_to_gbxml_is_well_formed() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_To_Gbxml' ) ) {
			$this->markTestSkipped( 'Export gbXML tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Export_To_Gbxml();
		$res  = $tool->execute( array( 'floor_plan' => $this->sample_plan() ), $this->ctx() );
		$this->assertNotWPError( $res );
		$this->assertSame( 'gbXML 6.01', $res['format'] );

		$dom = new DOMDocument();
		$ok  = @$dom->loadXML( $res['xml'] );
		$this->assertNotFalse( $ok, 'gbXML output should parse as well-formed XML.' );
		$this->assertStringContainsString( 'version="6.01"', $res['xml'] );
		$this->assertStringContainsString( '<Name>Villa Test</Name>', $res['xml'] );
	}

	/**
	 * Generate_bim_execution_plan.
	 */
	public function test_bep_returns_full_section_catalog() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Bim_Execution_Plan' ) ) {
			$this->markTestSkipped( 'BEP tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Generate_Bim_Execution_Plan();
		$res  = $tool->execute(
			array(
				'project_name' => 'Villa Test',
				'country_code' => 'LK',
				'standards'    => array( 'ISO 19650-2', 'AIA E203' ),
				'bim_uses'     => array( 'design_authoring', 'energy_analysis' ),
				'lod'          => 'LOD 350',
				'cde_platform' => 'BIM 360',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $res );
		$this->assertSame( 12, $res['section_count'] );
		$this->assertArrayHasKey( 'process_design', $res['sections'] );
		$this->assertStringContainsString( 'BIM Execution Plan — Villa Test', $res['markdown'] );
		$this->assertStringContainsString( 'LOD 350', $res['sections']['information_exchanges']['guidance'] );
		$this->assertStringContainsString( 'BIM 360', $res['sections']['collaboration']['guidance'] );
	}

	/** Test bep requires project name.
	 */
	public function test_bep_requires_project_name() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Bim_Execution_Plan' ) ) {
			$this->markTestSkipped( 'BEP tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Generate_Bim_Execution_Plan();
		$res  = $tool->execute( array(), $this->ctx() );
		$this->assertWPError( $res );
	}

	/**
	 * Manage_rfi_log workflow.
	 */
	public function test_rfi_log_create_list_update_workflow() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Rfi_Log' ) ) {
			$this->markTestSkipped( 'RFI log tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Manage_Rfi_Log();

		// Create.
		$create = $tool->execute(
			array(
				'action'       => 'create',
				'project_id'   => $this->project_id,
				'subject'      => 'Foundation rebar spacing',
				'question'     => 'Confirm rebar spacing for grid B/3.',
				'requested_by' => 'Site Engineer',
				'discipline'   => 'structural',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $create );
		$this->assertSame( 'RFI-0001', $create['rfi']['id'] );
		$this->assertSame( 'open', $create['rfi']['status'] );

		// List.
		$list = $tool->execute(
			array(
				'action'     => 'list',
				'project_id' => $this->project_id,
			),
			$this->ctx()
		);
		$this->assertSame( 1, $list['count'] );

		// Update — answer + close.
		$upd = $tool->execute(
			array(
				'action'     => 'update',
				'project_id' => $this->project_id,
				'rfi_id'     => 'RFI-0001',
				'answer'     => '12mm at 200mm c/c.',
				'status'     => 'closed',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $upd );
		$this->assertSame( 'closed', $upd['rfi']['status'] );
		$this->assertStringContainsString( '12mm', $upd['rfi']['answer'] );
	}

	/** Test rfi log invalid status falls back to open.
	 */
	public function test_rfi_log_invalid_status_falls_back_to_open() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Rfi_Log' ) ) {
			$this->markTestSkipped( 'RFI log tool unavailable.' );
		}
		$tool   = new WP_MCP_AI_Tool_Manage_Rfi_Log();
		$create = $tool->execute(
			array(
				'action'     => 'create',
				'project_id' => $this->project_id,
				'subject'    => 'X',
				'question'   => 'Y',
				'status'     => 'made_up',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $create );
		$this->assertSame( 'open', $create['rfi']['status'] );
	}

	/** Test rfi log rejects non project post.
	 */
	public function test_rfi_log_rejects_non_project_post() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Rfi_Log' ) ) {
			$this->markTestSkipped( 'RFI log tool unavailable.' );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Not a project',
				'post_author' => $this->editor_id,
			)
		);
		$tool    = new WP_MCP_AI_Tool_Manage_Rfi_Log();
		$res     = $tool->execute(
			array(
				'action'     => 'list',
				'project_id' => $post_id,
			),
			$this->ctx()
		);
		$this->assertWPError( $res );
		$this->assertSame( 'wp_mcp_ai_invalid_project', $res->get_error_code() );
	}

	/**
	 * Manage_submittal_log workflow.
	 */
	public function test_submittal_log_approval_workflow_with_revision() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Submittal_Log' ) ) {
			$this->markTestSkipped( 'Submittal log tool unavailable.' );
		}
		$tool = new WP_MCP_AI_Tool_Manage_Submittal_Log();

		$create = $tool->execute(
			array(
				'action'         => 'create',
				'project_id'     => $this->project_id,
				'spec_section'   => '08 11 13',
				'title'          => 'Hollow Metal Doors — Shop Drawings',
				'submittal_type' => 'shop_drawing',
				'submitted_by'   => 'GC',
				'reviewer'       => 'Architect',
			),
			$this->ctx()
		);
		$this->assertNotWPError( $create );
		$this->assertSame( 'SUB-0001', $create['submittal']['id'] );
		$this->assertSame( 'submitted', $create['submittal']['status'] );

		// Revise & resubmit.
		$revise = $tool->execute(
			array(
				'action'          => 'update',
				'project_id'      => $this->project_id,
				'submittal_id'    => 'SUB-0001',
				'status'          => 'revise_and_resubmit',
				'review_comments' => 'Fix latch hardware schedule.',
				'revision'        => 1,
			),
			$this->ctx()
		);
		$this->assertSame( 'revise_and_resubmit', $revise['submittal']['status'] );
		$this->assertSame( 1, $revise['submittal']['revision'] );

		// Approve.
		$approve = $tool->execute(
			array(
				'action'       => 'update',
				'project_id'   => $this->project_id,
				'submittal_id' => 'SUB-0001',
				'status'       => 'approved_as_noted',
			),
			$this->ctx()
		);
		$this->assertSame( 'approved_as_noted', $approve['submittal']['status'] );
	}

	/**
	 * Cross-tool: RFI and submittal logs are isolated.
	 */
	public function test_rfi_and_submittal_logs_are_isolated_meta_keys() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Rfi_Log' ) || ! class_exists( 'WP_MCP_AI_Tool_Manage_Submittal_Log' ) ) {
			$this->markTestSkipped( 'Phase D log tools unavailable.' );
		}
		$rfi = new WP_MCP_AI_Tool_Manage_Rfi_Log();
		$sub = new WP_MCP_AI_Tool_Manage_Submittal_Log();

		$rfi->execute(
			array(
				'action'     => 'create',
				'project_id' => $this->project_id,
				'subject'    => 'X',
				'question'   => 'Y',
			),
			$this->ctx()
		);
		$sub->execute(
			array(
				'action'       => 'create',
				'project_id'   => $this->project_id,
				'spec_section' => '08 11 13',
				'title'        => 'Z',
			),
			$this->ctx()
		);

		$rfi_list = $rfi->execute(
			array(
				'action'     => 'list',
				'project_id' => $this->project_id,
			),
			$this->ctx()
		);
		$sub_list = $sub->execute(
			array(
				'action'     => 'list',
				'project_id' => $this->project_id,
			),
			$this->ctx()
		);
		$this->assertSame( 1, $rfi_list['count'] );
		$this->assertSame( 1, $sub_list['count'] );
		$this->assertSame( 'RFI-0001', $rfi_list['rfis'][0]['id'] );
		$this->assertSame( 'SUB-0001', $sub_list['submittals'][0]['id'] );
	}
}
