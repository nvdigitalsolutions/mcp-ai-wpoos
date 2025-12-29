<?php
/**
 * Tests for WP_MCP_AI_Tool_Create_Chart class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for tool create chart tests.
 *
 * @group tools
 * @group create-chart
 */
class WP_MCP_AI_Tool_Create_Chart_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Create_Chart
	 */
	protected $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-chart.php';

		$this->tool = new WP_MCP_AI_Tool_Create_Chart();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'create_chart', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test parameter schema structure.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'type', $schema['properties'] );
		$this->assertArrayHasKey( 'data', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'type', $schema['required'] );
		$this->assertContains( 'data', $schema['required'] );
	}

	/**
	 * Test creating a simple bar chart.
	 */
	public function test_create_bar_chart() {
		$arguments = array(
			'type'  => 'bar',
			'data'  => array(
				'labels'   => array( 'Jan', 'Feb', 'Mar', 'Apr' ),
				'datasets' => array(
					array(
						'label'           => 'Sales',
						'data'            => array( 100, 150, 200, 175 ),
						'backgroundColor' => 'rgba(75, 192, 192, 0.6)',
					),
				),
			),
			'title' => 'Monthly Sales',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( 'bar', $result['chart_type'] );
		$this->assertArrayHasKey( 'html', $result );
		$this->assertArrayHasKey( 'chart_config', $result );
		$this->assertStringContainsString( 'Chart.js', $result['html'] );
		$this->assertStringContainsString( 'Monthly Sales', $result['html'] );
		$this->assertFalse( $result['saved_as_file'] );
	}

	/**
	 * Test creating a pie chart.
	 */
	public function test_create_pie_chart() {
		$arguments = array(
			'type' => 'pie',
			'data' => array(
				'labels'   => array( 'Red', 'Blue', 'Yellow' ),
				'datasets' => array(
					array(
						'data'            => array( 300, 50, 100 ),
						'backgroundColor' => array( '#FF6384', '#36A2EB', '#FFCE56' ),
					),
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'pie', $result['chart_type'] );
		$this->assertArrayHasKey( 'html', $result );
	}

	/**
	 * Test creating a line chart.
	 */
	public function test_create_line_chart() {
		$arguments = array(
			'type' => 'line',
			'data' => array(
				'labels'   => array( 'Week 1', 'Week 2', 'Week 3', 'Week 4' ),
				'datasets' => array(
					array(
						'label'       => 'Revenue',
						'data'        => array( 1000, 1500, 1200, 1800 ),
						'borderColor' => 'rgb(75, 192, 192)',
						'tension'     => 0.1,
					),
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'line', $result['chart_type'] );
	}

	/**
	 * Test authentication requirement.
	 */
	public function test_requires_authentication() {
		$arguments = array(
			'type' => 'bar',
			'data' => array(
				'labels'   => array( 'A', 'B' ),
				'datasets' => array(
					array(
						'data' => array( 1, 2 ),
					),
				),
			),
		);

		$context = array(); // No user_id.
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test invalid chart type.
	 */
	public function test_invalid_chart_type() {
		$arguments = array(
			'type' => 'invalid_type',
			'data' => array(
				'labels'   => array( 'A', 'B' ),
				'datasets' => array(
					array(
						'data' => array( 1, 2 ),
					),
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_chart_type', $result->get_error_code() );
	}

	/**
	 * Test missing chart data.
	 */
	public function test_missing_chart_data() {
		$arguments = array(
			'type' => 'bar',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_data', $result->get_error_code() );
	}

	/**
	 * Test missing datasets in data.
	 */
	public function test_missing_datasets() {
		$arguments = array(
			'type' => 'bar',
			'data' => array(
				'labels' => array( 'A', 'B' ),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_datasets', $result->get_error_code() );
	}

	/**
	 * Test custom dimensions.
	 */
	public function test_custom_dimensions() {
		$arguments = array(
			'type'   => 'bar',
			'data'   => array(
				'labels'   => array( 'A', 'B' ),
				'datasets' => array(
					array(
						'data' => array( 1, 2 ),
					),
				),
			),
			'width'  => 1200,
			'height' => 600,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$this->assertEquals( 1200, $result['width'] );
		$this->assertEquals( 600, $result['height'] );
	}

	/**
	 * Test saving chart as attachment.
	 */
	public function test_save_as_attachment() {
		$arguments = array(
			'type'               => 'bar',
			'data'               => array(
				'labels'   => array( 'A', 'B', 'C' ),
				'datasets' => array(
					array(
						'label' => 'Test Data',
						'data'  => array( 10, 20, 30 ),
					),
				),
			),
			'save_as_attachment' => true,
			'file_name'          => 'test-chart',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['saved_as_file'] );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertStringContainsString( 'test-chart', $result['file_name'] );
		$this->assertStringContainsString( '.html', $result['file_name'] );

		// Verify attachment exists.
		$attachment = get_post( $result['attachment_id'] );
		$this->assertNotNull( $attachment );
		$this->assertEquals( 'attachment', $attachment->post_type );
		$this->assertEquals( 'text/html', $attachment->post_mime_type );

		// Verify file exists.
		$this->assertFileExists( $result['file_path'] );

		// Cleanup.
		wp_delete_attachment( $result['attachment_id'], true );
	}

	/**
	 * Test shortcut tasks.
	 */
	public function test_shortcut_tasks() {
		$shortcuts = $this->tool->get_shortcut_tasks();

		$this->assertIsArray( $shortcuts );
		$this->assertNotEmpty( $shortcuts );
		$this->assertArrayHasKey( 'label', $shortcuts[0] );
		$this->assertArrayHasKey( 'payload', $shortcuts[0] );
	}

	/**
	 * Test HTML generation includes Chart.js.
	 */
	public function test_html_includes_chartjs() {
		$arguments = array(
			'type' => 'bar',
			'data' => array(
				'labels'   => array( 'Test' ),
				'datasets' => array(
					array(
						'data' => array( 100 ),
					),
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$html = $result['html'];

		// Check for Chart.js CDN.
		$this->assertStringContainsString( 'cdn.jsdelivr.net/npm/chart.js', $html );
		$this->assertStringContainsString( '<canvas', $html );
		$this->assertStringContainsString( 'new Chart', $html );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
	}

	/**
	 * Test chart config structure.
	 */
	public function test_chart_config_structure() {
		$arguments = array(
			'type' => 'bar',
			'data' => array(
				'labels'   => array( 'A', 'B' ),
				'datasets' => array(
					array(
						'data' => array( 1, 2 ),
					),
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$config = $result['chart_config'];

		$this->assertArrayHasKey( 'type', $config );
		$this->assertArrayHasKey( 'data', $config );
		$this->assertArrayHasKey( 'options', $config );
		$this->assertEquals( 'bar', $config['type'] );
		$this->assertArrayHasKey( 'labels', $config['data'] );
		$this->assertArrayHasKey( 'datasets', $config['data'] );
	}

	/**
	 * Test multiple datasets.
	 */
	public function test_multiple_datasets() {
		$arguments = array(
			'type' => 'bar',
			'data' => array(
				'labels'   => array( 'Q1', 'Q2', 'Q3', 'Q4' ),
				'datasets' => array(
					array(
						'label'           => '2023',
						'data'            => array( 100, 150, 200, 175 ),
						'backgroundColor' => 'rgba(75, 192, 192, 0.6)',
					),
					array(
						'label'           => '2024',
						'data'            => array( 120, 170, 220, 195 ),
						'backgroundColor' => 'rgba(255, 99, 132, 0.6)',
					),
				),
			),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertNotWPError( $result );
		$config = $result['chart_config'];
		$this->assertCount( 2, $config['data']['datasets'] );
		$this->assertEquals( '2023', $config['data']['datasets'][0]['label'] );
		$this->assertEquals( '2024', $config['data']['datasets'][1]['label'] );
	}
}
