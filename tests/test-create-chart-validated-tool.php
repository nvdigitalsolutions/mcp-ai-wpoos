<?php
/**
 * Tests for the create_chart_validated tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-chart-validated.php';

/**
 * Test case for the Symfony Validator version of create_chart tool.
 */
class WP_MCP_AI_Create_Chart_Validated_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the tool has correct metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Create_Chart_Validated();

		$this->assertSame( 'create_chart_validated', $tool->get_slug() );
		$this->assertSame( 'Create Chart (Validated)', $tool->get_name() );
		$this->assertStringContainsString( 'chart', strtolower( $tool->get_description() ) );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'type', $schema['properties'] );
		$this->assertArrayHasKey( 'data', $schema['properties'] );
	}

	/**
	 * Test tool execution with minimum valid data.
	 */
	public function test_execute_with_minimum_valid_data() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type' => 'bar',
				'data' => array(
					'labels'   => array( 'Jan', 'Feb', 'Mar' ),
					'datasets' => array(
						array(
							'label' => 'Sales',
							'data'  => array( 10, 20, 30 ),
						),
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'html', $result );
	}

	/**
	 * Test tool rejects missing chart type.
	 */
	public function test_execute_rejects_missing_type() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'data' => array(
					'labels'   => array( 'A', 'B' ),
					'datasets' => array(
						array(
							'label' => 'Test',
							'data'  => array( 1, 2 ),
						),
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects invalid chart type.
	 */
	public function test_execute_rejects_invalid_type() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type' => 'invalid_type',
				'data' => array(
					'labels'   => array( 'A', 'B' ),
					'datasets' => array(
						array(
							'label' => 'Test',
							'data'  => array( 1, 2 ),
						),
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects missing chart data.
	 */
	public function test_execute_rejects_missing_data() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array( 'type' => 'bar' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool execution with custom dimensions.
	 */
	public function test_execute_with_custom_dimensions() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type'   => 'line',
				'data'   => array(
					'labels'   => array( 'Q1', 'Q2', 'Q3' ),
					'datasets' => array(
						array(
							'label' => 'Revenue',
							'data'  => array( 100, 200, 150 ),
						),
					),
				),
				'width'  => 1000,
				'height' => 500,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'html', $result );
	}

	/**
	 * Test tool rejects width below minimum.
	 */
	public function test_execute_rejects_width_below_minimum() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type'  => 'bar',
				'data'  => array(
					'labels'   => array( 'A' ),
					'datasets' => array(
						array(
							'label' => 'Test',
							'data'  => array( 1 ),
						),
					),
				),
				'width' => 50,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool rejects width above maximum.
	 */
	public function test_execute_rejects_width_above_maximum() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type'  => 'bar',
				'data'  => array(
					'labels'   => array( 'A' ),
					'datasets' => array(
						array(
							'label' => 'Test',
							'data'  => array( 1 ),
						),
					),
				),
				'width' => 3000,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_validation_error', $result->get_error_code() );
	}

	/**
	 * Test tool execution with optional title.
	 */
	public function test_execute_with_optional_title() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type'  => 'pie',
				'data'  => array(
					'labels'   => array( 'Red', 'Blue', 'Yellow' ),
					'datasets' => array(
						array(
							'label' => 'Colors',
							'data'  => array( 300, 50, 100 ),
						),
					),
				),
				'title' => 'Color Distribution',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'html', $result );
	}

	/**
	 * Test tool requires authentication.
	 */
	public function test_execute_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$result = $tool->execute(
			array(
				'type' => 'bar',
				'data' => array(
					'labels'   => array( 'A' ),
					'datasets' => array(
						array(
							'label' => 'Test',
							'data'  => array( 1 ),
						),
					),
				),
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test capability flags match the original tool.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
	}

	/**
	 * Test shortcut tasks are delegated properly.
	 */
	public function test_shortcut_tasks() {
		$tool      = new WP_MCP_AI_Tool_Create_Chart_Validated();
		$shortcuts = $tool->get_shortcut_tasks();

		$this->assertIsArray( $shortcuts );
		$this->assertNotEmpty( $shortcuts );
	}
}
