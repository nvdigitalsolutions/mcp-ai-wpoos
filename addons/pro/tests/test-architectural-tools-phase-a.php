<?php
/**
 * Tests for Architectural Design tool relocation, availability, and the
 * refactored compliance / cost-estimator behaviour.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Architectural Design tools (Phase A).
 */
class Test_Architectural_Tools_Phase_A extends WP_UnitTestCase {

	/**
	 * Set up — load tool files (init file may not have run during test bootstrap).
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

		$files = array(
			'floor-planning/class-wp-mcp-ai-tool-generate-floor-plan.php',
			'floor-planning/class-wp-mcp-ai-tool-optimize-space-layout.php',
			'floor-planning/class-wp-mcp-ai-tool-create-floor-plan-variations.php',
			'floor-planning/class-wp-mcp-ai-tool-convert-sketch-to-floor-plan.php',
			'visualization/class-wp-mcp-ai-tool-generate-3d-model.php',
			'visualization/class-wp-mcp-ai-tool-render-architectural-view.php',
			'visualization/class-wp-mcp-ai-tool-create-walkthrough-animation.php',
			'documentation/class-wp-mcp-ai-tool-generate-construction-drawings.php',
			'documentation/class-wp-mcp-ai-tool-generate-detail-drawings.php',
			'documentation/class-wp-mcp-ai-tool-export-architectural-documents.php',
			'analysis-compliance/class-wp-mcp-ai-tool-check-building-code-compliance.php',
			'analysis-compliance/class-wp-mcp-ai-tool-analyze-structural-feasibility.php',
			'analysis-compliance/class-wp-mcp-ai-tool-calculate-sustainability-metrics.php',
			'estimation-scheduling/class-wp-mcp-ai-tool-generate-material-schedule.php',
			'estimation-scheduling/class-wp-mcp-ai-tool-estimate-construction-cost.php',
			'estimation-scheduling/class-wp-mcp-ai-tool-generate-construction-timeline.php',
		);
		foreach ( $files as $f ) {
			$abs = $base . $f;
			if ( file_exists( $abs ) ) {
				require_once $abs;
			}
		}
	}

	/**
	 * Provider: all 16 Phase A tool classes.
	 *
	 * @return array
	 */
	public static function tool_classes_provider() {
		return array(
			array( 'WP_MCP_AI_Tool_Generate_Floor_Plan' ),
			array( 'WP_MCP_AI_Tool_Optimize_Space_Layout' ),
			array( 'WP_MCP_AI_Tool_Create_Floor_Plan_Variations' ),
			array( 'WP_MCP_AI_Tool_Convert_Sketch_To_Floor_Plan' ),
			array( 'WP_MCP_AI_Tool_Generate_3d_Model' ),
			array( 'WP_MCP_AI_Tool_Render_Architectural_View' ),
			array( 'WP_MCP_AI_Tool_Create_Walkthrough_Animation' ),
			array( 'WP_MCP_AI_Tool_Generate_Construction_Drawings' ),
			array( 'WP_MCP_AI_Tool_Generate_Detail_Drawings' ),
			array( 'WP_MCP_AI_Tool_Export_Architectural_Documents' ),
			array( 'WP_MCP_AI_Tool_Check_Building_Code_Compliance' ),
			array( 'WP_MCP_AI_Tool_Analyze_Structural_Feasibility' ),
			array( 'WP_MCP_AI_Tool_Calculate_Sustainability_Metrics' ),
			array( 'WP_MCP_AI_Tool_Generate_Material_Schedule' ),
			array( 'WP_MCP_AI_Tool_Estimate_Construction_Cost' ),
			array( 'WP_MCP_AI_Tool_Generate_Construction_Timeline' ),
		);
	}

	/**
	 * Each Phase A tool class loads from its module subdirectory.
	 *
	 * @dataProvider tool_classes_provider
	 *
	 * @param string $class Tool class name.
	 */
	public function test_tool_class_exists( $class ) {
		$this->assertTrue( class_exists( $class ), "Tool class $class should exist after relocation." );
	}

	/**
	 * Each tool exposes `is_available()` and `get_unavailable_reason()`.
	 *
	 * @dataProvider tool_classes_provider
	 *
	 * @param string $class Tool class name.
	 */
	public function test_tool_has_availability_methods( $class ) {
		$this->assertTrue( method_exists( $class, 'is_available' ), "$class must implement is_available()." );
		$this->assertTrue( method_exists( $class, 'get_unavailable_reason' ), "$class must implement get_unavailable_reason()." );

		// Reason is always a non-empty string.
		$reason = call_user_func( array( $class, 'get_unavailable_reason' ) );
		$this->assertIsString( $reason );
		$this->assertNotEmpty( $reason );
	}

	/**
	 * `is_available()` returns false when the toolkit setting is off.
	 *
	 * @dataProvider tool_classes_provider
	 *
	 * @param string $class Tool class name.
	 */
	public function test_is_available_false_when_disabled( $class ) {
		$prev = get_option( 'wp_mcp_ai_settings', array() );
		update_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( call_user_func( array( $class, 'is_available' ) ) );
		update_option( 'wp_mcp_ai_settings', $prev );
	}

	/**
	 * `is_available()` returns true when the toolkit is enabled.
	 *
	 * @dataProvider tool_classes_provider
	 *
	 * @param string $class Tool class name.
	 */
	public function test_is_available_true_when_enabled( $class ) {
		$prev = get_option( 'wp_mcp_ai_settings', array() );
		update_option( 'wp_mcp_ai_settings', array( 'enable_architectural_design_toolkit' => 1 ) );
		// This will return false in base mode; only assert if not in base mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->assertFalse( call_user_func( array( $class, 'is_available' ) ) );
		} else {
			$this->assertTrue( call_user_func( array( $class, 'is_available' ) ) );
		}
		update_option( 'wp_mcp_ai_settings', $prev );
	}

	// =================================================================
	// Refactored compliance check.
	// =================================================================

	/**
	 * Refactored compliance check uses the registry and returns a real
	 * evaluation rather than the static stub.
	 */
	public function test_compliance_check_evaluates_setbacks() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		$tool = new WP_MCP_AI_Tool_Check_Building_Code_Compliance();

		$args   = array(
			'floor_plan'       => array(
				'lot_area_m2'             => 250.0,
				'built_up_area_m2'        => 350.0,
				'footprint_area_m2'       => 130.0,
				'exits'                   => 2,
				'corridor_width_m'        => 1.3,
				'stair_width_m'           => 1.2,
				'travel_distance_m'       => 25.0,
				'min_door_clear_width_mm' => 850.0,
				'setbacks_m'              => array(
					'front' => 2.5,
					'rear'  => 1.5,
					'left'  => 1.2,
					'right' => 0.5, // Violation.
				),
			),
			'country_code'     => 'LK',
			'check_categories' => array( 'egress', 'accessibility', 'zoning' ),
			'building_type'    => 'residential',
		);
		$result = $tool->execute( $args, array( 'user_id' => $user->ID ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'compliance', $result );
		$this->assertNotEmpty( $result['compliance']['code_packs'] );
		$this->assertSame( 'LK', $result['compliance']['country_code'] );
		$this->assertNotEmpty( $result['compliance']['checks'] );

		// At least one fail must be present (right setback shortfall).
		$has_fail = false;
		foreach ( $result['compliance']['checks'] as $c ) {
			if ( 'fail' === $c['status'] && 'zoning' === $c['category'] ) {
				$has_fail = true;
				break;
			}
		}
		$this->assertTrue( $has_fail, 'Right setback violation should be flagged.' );

		// Overall status reflects fail.
		$this->assertSame( 'fail', $result['compliance']['overall_status'] );
		// Disclaimer present.
		$this->assertNotEmpty( $result['disclaimer'] );
	}

	/**
	 * Compliance check with no rules-applicable categories still returns a
	 * sane structure.
	 */
	public function test_compliance_check_minimal() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		$tool   = new WP_MCP_AI_Tool_Check_Building_Code_Compliance();
		$result = $tool->execute(
			array(
				'floor_plan'       => array(
					'exits'             => 2,
					'corridor_width_m'  => 1.5,
					'stair_width_m'     => 1.2,
					'travel_distance_m' => 10,
				),
				'country_code'     => 'US',
				'check_categories' => array( 'egress' ),
			),
			array( 'user_id' => $user->ID )
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'US', $result['compliance']['country_code'] );
		$this->assertSame( 'pass', $result['compliance']['overall_status'] );
	}

	// =================================================================
	// Refactored cost estimator.
	// =================================================================

	/**
	 * Cost estimator uses the engine to produce LKR-denominated estimates
	 * for a Sri Lankan project.
	 */
	public function test_cost_estimator_uses_engine_for_lk() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		$tool   = new WP_MCP_AI_Tool_Estimate_Construction_Cost();
		$result = $tool->execute(
			array(
				'floor_plan'          => array( 'rooms' => 3 ),
				'total_area'          => 200.0,
				'area_unit'           => 'sqm',
				'country_code'        => 'LK',
				'quality_level'       => 'standard',
				'construction_type'   => 'masonry',
				'include_breakdown'   => true,
				'contingency_percent' => 10,
			),
			array( 'user_id' => $user->ID )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'LKR', $result['estimate']['currency'] );
		$this->assertEqualsWithDelta( 145000.0, $result['estimate']['rate_per_sqm'], 0.5 );
		// Subtotal: 200 * 145000 = 29_000_000; +10% = 31_900_000.
		$this->assertEqualsWithDelta( 31900000.0, $result['estimate']['total_cost'], 1.0 );
		$this->assertNotEmpty( $result['estimate']['breakdown'] );
		$this->assertNotEmpty( $result['disclaimer'] );
	}

	/**
	 * Cost estimator: square-foot input is converted to m^2 internally.
	 */
	public function test_cost_estimator_sqft_input() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		$tool   = new WP_MCP_AI_Tool_Estimate_Construction_Cost();
		$result = $tool->execute(
			array(
				'floor_plan'        => array( 'rooms' => 3 ),
				'total_area'        => 1000.0,
				'area_unit'         => 'sqft',
				'country_code'      => 'US',
				'quality_level'     => 'standard',
				'construction_type' => 'wood_frame',
			),
			array( 'user_id' => $user->ID )
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'USD', $result['estimate']['currency'] );
		// 1000 sf ≈ 92.9 m^2.
		$this->assertEqualsWithDelta( 92.903, $result['estimate']['area_sqm'], 0.05 );
	}

	/**
	 * Cost estimator: currency override converts via FX table.
	 */
	public function test_cost_estimator_currency_override() {
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		// Pin the FX table so the test is deterministic.
		add_filter(
			'wp_mcp_ai_arch_currency_rates',
			function () {
				return array(
					'USD' => 1.0,
					'LKR' => 300.0,
					'JMD' => 150.0,
				);
			}
		);

		$tool   = new WP_MCP_AI_Tool_Estimate_Construction_Cost();
		$result = $tool->execute(
			array(
				'floor_plan'          => array( 'rooms' => 2 ),
				'total_area'          => 100.0,
				'area_unit'           => 'sqm',
				'country_code'        => 'LK',
				'currency'            => 'USD',
				'quality_level'       => 'standard',
				'construction_type'   => 'masonry',
				'contingency_percent' => 0,
			),
			array( 'user_id' => $user->ID )
		);
		$this->assertSame( 'USD', $result['estimate']['currency'] );
		// 100 m^2 * 145000 LKR = 14_500_000 LKR -> /300 = 48_333.33 USD.
		$this->assertEqualsWithDelta( 48333.33, $result['estimate']['total_cost'], 1.0 );
	}
}
