<?php
/**
 * Test Performance CCT Save Confirmation
 *
 * Tests that performance tests properly save results to CCT and return
 * confirmation in AJAX responses for Elementor widgets.
 *
 * Following SoC: Tests focus on integration between AJAX handlers, CCT storage,
 * and widget responses. Each test method validates a specific aspect.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Performance CCT save confirmation
 */
class Test_Performance_CCT_Save_Confirmation extends WP_UnitTestCase {

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Create admin user with manage_options capability.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that lightweight checks save to CCT and return item ID.
	 *
	 * Following SoC: Tests the save operation in isolation.
	 */
	public function test_lightweight_check_saves_to_cct() {
		// Skip if CCT class is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'run_lightweight_check' );
		$method->setAccessible( true );

		// Run a speed test.
		$result = $method->invoke( $section, 'speed' );

		// Verify result structure.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertArrayHasKey( 'cct_item_id', $result, 'Result should have cct_item_id key' );
		$this->assertArrayHasKey( 'saved_to_cct', $result, 'Result should have saved_to_cct key' );

		// Verify CCT save status.
		if ( false !== $result['cct_item_id'] ) {
			$this->assertTrue( $result['saved_to_cct'], 'saved_to_cct should be true when item ID is returned' );
			$this->assertIsNumeric( $result['cct_item_id'], 'CCT item ID should be numeric' );
			$this->assertGreaterThan( 0, $result['cct_item_id'], 'CCT item ID should be positive' );
		} else {
			$this->assertFalse( $result['saved_to_cct'], 'saved_to_cct should be false when item ID is false' );
		}
	}

	/**
	 * Test that component is correctly determined from POST data.
	 *
	 * Following SoC: Tests component detection logic independently.
	 */
	public function test_component_determination_from_post_data() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'determine_test_component' );
		$method->setAccessible( true );

		// Test component override from POST.
		$_POST['component'] = 'chat_ui';
		$component          = $method->invoke( $section );
		$this->assertEquals( 'chat_ui', $component, 'Should use component from POST data' );

		// Test assistant-specific context.
		unset( $_POST['component'] );
		$_POST['assistant_id'] = '123';
		$component             = $method->invoke( $section );
		$this->assertEquals( 'cpt_assistant', $component, 'Should detect assistant context' );

		// Clean up.
		unset( $_POST['component'] );
		unset( $_POST['assistant_id'] );
	}

	/**
	 * Test that AJAX response includes CCT save confirmation.
	 *
	 * Following SoC: Tests AJAX handler response structure.
	 */
	public function test_ajax_response_includes_cct_confirmation() {
		// Skip if CCT class is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'run_performance_test_programmatically' );
		$method->setAccessible( true );

		// Run a security test (will use lightweight checks).
		$_POST['component'] = 'elementor';
		$result             = $method->invoke( $section, 'security' );

		// Verify response includes CCT fields.
		$this->assertIsArray( $result, 'Result should be an array' );
		
		// These fields should always be present in the response.
		$expected_fields = array( 'success', 'message' );
		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $result, "Result should have $field key" );
		}

		// If successful, should include CCT confirmation fields.
		if ( $result['success'] ) {
			$this->assertArrayHasKey( 'cct_item_id', $result, 'Successful result should have cct_item_id key' );
			$this->assertArrayHasKey( 'saved_to_cct', $result, 'Successful result should have saved_to_cct key' );
		}

		// Clean up.
		unset( $_POST['component'] );
	}

	/**
	 * Test that metrics are extracted from lightweight check output.
	 *
	 * Following SoC: Tests metric extraction logic.
	 */
	public function test_metrics_extraction_from_lightweight_check() {
		// Skip if CCT class is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'run_lightweight_check' );
		$method->setAccessible( true );

		// Run a stress test.
		$result = $method->invoke( $section, 'stress' );

		// Verify metrics are included.
		$this->assertArrayHasKey( 'metrics', $result, 'Result should include metrics' );
		$this->assertIsArray( $result['metrics'], 'Metrics should be an array' );

		// Verify metric structure.
		$expected_metrics = array(
			'avg_response_time',
			'memory_peak_bytes',
			'memory_peak_mb',
			'db_queries',
			'error_rate',
			'total_errors',
		);

		foreach ( $expected_metrics as $metric ) {
			$this->assertArrayHasKey( $metric, $result['metrics'], "Metrics should include $metric" );
		}
	}

	/**
	 * Test that test results are properly formatted for CCT storage.
	 *
	 * Following SoC: Tests result formatting logic.
	 */
	public function test_test_results_formatting_for_cct() {
		// Skip if CCT class is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT class not available.' );
		}

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'run_lightweight_check' );
		$method->setAccessible( true );

		// Run an optimization test.
		$result = $method->invoke( $section, 'optimization' );

		// Verify test_results are included.
		$this->assertArrayHasKey( 'test_results', $result, 'Result should include test_results' );
		$this->assertIsArray( $result['test_results'], 'Test results should be an array' );

		// Verify test results structure.
		$expected_fields = array( 'total', 'passed', 'failed', 'warnings', 'checks' );
		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey( $field, $result['test_results'], "Test results should include $field" );
		}

		// Verify counts make sense.
		$total = $result['test_results']['total'];
		$this->assertGreaterThan( 0, $total, 'Total checks should be greater than 0' );
		
		$passed  = $result['test_results']['passed'];
		$failed  = $result['test_results']['failed'];
		$warnings = $result['test_results']['warnings'];
		
		$this->assertEquals( $total, $passed + $failed + $warnings, 'Total should equal sum of passed, failed, and warnings' );
	}

	/**
	 * Test that different test types are properly categorized.
	 *
	 * Following SoC: Tests test type handling.
	 */
	public function test_different_test_types_categorization() {
		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';

		$section = new WP_MCP_AI_Section_Performance();

		// Use reflection to call protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'run_lightweight_check' );
		$method->setAccessible( true );

		// Test all supported test types.
		$test_types = array( 'stress', 'security', 'speed', 'optimization' );

		foreach ( $test_types as $test_type ) {
			$result = $method->invoke( $section, $test_type );
			
			$this->assertIsArray( $result, "Result for $test_type should be an array" );
			$this->assertArrayHasKey( 'success', $result, "Result for $test_type should have success key" );
			$this->assertStringContainsString( $test_type, strtolower( $result['message'] ), "Message should mention test type: $test_type" );
		}
	}
}
