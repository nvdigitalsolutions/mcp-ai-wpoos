<?php
/**
 * Pro Dashboard Charts Tests
 *
 * Tests to verify chart data is properly generated and passed to JavaScript.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

class Test_Pro_Dashboard_Charts extends WP_UnitTestCase {
	/**
	 * Test that get_chart_data returns proper structure.
	 */
	public function test_get_chart_data_structure() {
		// Access the Pro Dashboard instance.
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		// Use reflection to access private get_chart_data method.
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_chart_data' );
		$method->setAccessible( true );
		
		$chart_data = $method->invoke( $dashboard );
		
		// Verify structure.
		$this->assertIsArray( $chart_data );
		$this->assertArrayHasKey( 'controls', $chart_data );
		$this->assertArrayHasKey( 'risks', $chart_data );
		$this->assertArrayHasKey( 'metrics', $chart_data );
	}
	
	/**
	 * Test that controls data has required fields.
	 */
	public function test_chart_data_controls() {
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_chart_data' );
		$method->setAccessible( true );
		
		$chart_data = $method->invoke( $dashboard );
		$controls = $chart_data['controls'];
		
		// Verify required fields exist.
		$this->assertArrayHasKey( 'implemented', $controls );
		$this->assertArrayHasKey( 'partial', $controls );
		$this->assertArrayHasKey( 'planned', $controls );
		$this->assertArrayHasKey( 'not_applicable', $controls );
		$this->assertArrayHasKey( 'total', $controls );
		
		// Verify data types.
		$this->assertIsInt( $controls['implemented'] );
		$this->assertIsInt( $controls['partial'] );
		$this->assertIsInt( $controls['planned'] );
		$this->assertIsInt( $controls['not_applicable'] );
		$this->assertIsInt( $controls['total'] );
		
		// Verify total equals sum of parts.
		$sum = $controls['implemented'] + $controls['partial'] + $controls['planned'] + $controls['not_applicable'];
		$this->assertEquals( $controls['total'], $sum );
	}
	
	/**
	 * Test that risks data has required fields.
	 */
	public function test_chart_data_risks() {
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_chart_data' );
		$method->setAccessible( true );
		
		$chart_data = $method->invoke( $dashboard );
		$risks = $chart_data['risks'];
		
		// Verify required fields exist.
		$this->assertArrayHasKey( 'critical', $risks );
		$this->assertArrayHasKey( 'high', $risks );
		$this->assertArrayHasKey( 'medium', $risks );
		$this->assertArrayHasKey( 'low', $risks );
		
		// Verify data types.
		$this->assertIsInt( $risks['critical'] );
		$this->assertIsInt( $risks['high'] );
		$this->assertIsInt( $risks['medium'] );
		$this->assertIsInt( $risks['low'] );
	}
	
	/**
	 * Test that metrics data has required fields.
	 */
	public function test_chart_data_metrics() {
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_chart_data' );
		$method->setAccessible( true );
		
		$chart_data = $method->invoke( $dashboard );
		$metrics = $chart_data['metrics'];
		
		// Verify required fields exist.
		$this->assertArrayHasKey( 'incidents', $metrics );
		$this->assertArrayHasKey( 'vulnerabilities_fixed', $metrics );
		
		// Verify data types (arrays of integers).
		$this->assertIsArray( $metrics['incidents'] );
		$this->assertIsArray( $metrics['vulnerabilities_fixed'] );
		
		// Verify array lengths (should be 6 months of data).
		$this->assertCount( 6, $metrics['incidents'] );
		$this->assertCount( 6, $metrics['vulnerabilities_fixed'] );
	}
	
	/**
	 * Test REST API endpoint returns chart data.
	 */
	public function test_rest_api_compliance_status() {
		// Create an admin user and set as current.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		
		// Make REST API request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/pro/compliance/status' );
		$response = rest_do_request( $request );
		
		$this->assertEquals( 200, $response->get_status() );
		
		$data = $response->get_data();
		
		// Verify structure.
		$this->assertArrayHasKey( 'controls', $data );
		$this->assertArrayHasKey( 'metrics', $data );
		$this->assertArrayHasKey( 'risks', $data );
		
		// Verify controls data.
		$controls = $data['controls'];
		$this->assertArrayHasKey( 'implemented', $controls );
		$this->assertArrayHasKey( 'partial', $controls );
		$this->assertArrayHasKey( 'planned', $controls );
		$this->assertArrayHasKey( 'not_applicable', $controls );
		$this->assertArrayHasKey( 'total', $controls );
	}
	
	/**
	 * Test that ISO 27001 controls can be retrieved.
	 */
	public function test_get_iso27001_controls() {
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'get_iso27001_controls' );
		$method->setAccessible( true );
		
		$controls = $method->invoke( $dashboard );
		
		// Should return an array of controls.
		$this->assertIsArray( $controls );
		
		// Should have controls (from WP_MCP_AI_Compliance_Data or markdown file).
		$this->assertGreaterThan( 0, count( $controls ) );
		
		// Each control should have required fields.
		if ( count( $controls ) > 0 ) {
			$first_control = $controls[0];
			$this->assertArrayHasKey( 'id', $first_control );
			$this->assertArrayHasKey( 'name', $first_control );
			$this->assertArrayHasKey( 'status', $first_control );
			$this->assertArrayHasKey( 'status_key', $first_control );
			$this->assertArrayHasKey( 'applicable', $first_control );
		}
	}
	
	/**
	 * Test that calculate_controls_stats works correctly.
	 */
	public function test_calculate_controls_stats() {
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		$reflection = new ReflectionClass( $dashboard );
		$method = $reflection->getMethod( 'calculate_controls_stats' );
		$method->setAccessible( true );
		
		// Sample controls data.
		$sample_controls = array(
			array( 'status_key' => 'implemented' ),
			array( 'status_key' => 'implemented' ),
			array( 'status_key' => 'partial' ),
			array( 'status_key' => 'planned' ),
			array( 'status_key' => 'not_applicable' ),
		);
		
		$stats = $method->invoke( $dashboard, $sample_controls );
		
		// Verify stats structure.
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'implemented', $stats );
		$this->assertArrayHasKey( 'partial', $stats );
		$this->assertArrayHasKey( 'planned', $stats );
		$this->assertArrayHasKey( 'not_applicable', $stats );
		$this->assertArrayHasKey( 'total', $stats );
		
		// Verify counts.
		$this->assertEquals( 2, $stats['implemented'] );
		$this->assertEquals( 1, $stats['partial'] );
		$this->assertEquals( 1, $stats['planned'] );
		$this->assertEquals( 1, $stats['not_applicable'] );
		$this->assertEquals( 5, $stats['total'] );
	}
	
	/**
	 * Test that chart data is passed to JavaScript via wp_localize_script.
	 */
	public function test_chart_data_localized() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		
		// Simulate admin page load.
		set_current_screen( 'toplevel_page_nvoos-pro-dashboard' );
		
		$dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
		
		// Trigger asset enqueuing.
		do_action( 'admin_enqueue_scripts', 'toplevel_page_nvoos-pro-dashboard' );
		
		// Check that script is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-pro-dashboard', 'enqueued' ) );
		
		// Get localized data.
		global $wp_scripts;
		$data = $wp_scripts->get_data( 'wp-mcp-ai-pro-dashboard', 'data' );
		
		// Should have localized data.
		$this->assertNotEmpty( $data );
		
		// Should contain chartData in the JavaScript object.
		$this->assertStringContainsString( 'wpMcpAiProDashboard', $data );
		$this->assertStringContainsString( 'chartData', $data );
	}
}
