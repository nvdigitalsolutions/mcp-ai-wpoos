<?php
/**
 * Tests for Performance Reporting Service.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Performance Reporting Service functionality.
 */
class Test_Performance_Reporting_Service extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the performance reporting service if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Reporting_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-performance-reporting-service.php';
		}

		// Load settings repository if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Settings_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-settings-repository.php';
		}

		// Reset the static settings repository to ensure clean state.
		WP_MCP_AI_Performance_Reporting_Service::set_settings_repository( null );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clear any performance baselines.
		delete_option( 'wp_mcp_ai_performance_baselines' );

		// Reset the static settings repository.
		WP_MCP_AI_Performance_Reporting_Service::set_settings_repository( null );

		parent::tearDown();
	}

	/**
	 * Test that service uses settings repository instead of direct option calls.
	 */
	public function test_uses_settings_repository() {
		// Create a mock settings repository.
		$mock_repo = $this->getMockBuilder( 'WP_MCP_AI_Settings_Repository' )
			->setMethods( array( 'get', 'update' ) )
			->getMock();

		// Expect get() to be called.
		$mock_repo->expects( $this->once() )
			->method( 'get' )
			->with( 'performance_baselines', array() )
			->willReturn(
				array(
					'rest_api' => array(
						'avg_response_time' => 100,
						'avg_memory_usage'  => 1000,
						'avg_db_queries'    => 5,
						'updated_at'        => '2025-01-01 00:00:00',
					),
				)
			);

		// Set the mock repository.
		WP_MCP_AI_Performance_Reporting_Service::set_settings_repository( $mock_repo );

		// Call get_baselines - should use repository.
		$baselines = WP_MCP_AI_Performance_Reporting_Service::get_baselines();

		// Verify we got data from mock.
		$this->assertIsArray( $baselines );
		$this->assertArrayHasKey( 'rest_api', $baselines );
		$this->assertEquals( 100, $baselines['rest_api']['avg_response_time'] );
	}

	/**
	 * Test get_baselines with real repository.
	 */
	public function test_get_baselines_returns_empty_array_when_no_data() {
		// Use real settings repository.
		$repository = new WP_MCP_AI_Settings_Repository();
		WP_MCP_AI_Performance_Reporting_Service::set_settings_repository( $repository );

		// Ensure no baseline data exists.
		delete_option( 'wp_mcp_ai_performance_baselines' );

		// Skip this test if Performance Monitor CCT is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT not available (base version mode)' );
		}

		// Get baselines - should trigger auto-generation.
		$baselines = WP_MCP_AI_Performance_Reporting_Service::get_baselines();

		// Should return array (might be empty or populated depending on data).
		$this->assertIsArray( $baselines );
	}

	/**
	 * Test that update_baselines uses repository.
	 */
	public function test_update_baselines_uses_repository() {
		// Skip if Performance Monitor CCT is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT not available (base version mode)' );
		}

		// Create a mock settings repository.
		$mock_repo = $this->getMockBuilder( 'WP_MCP_AI_Settings_Repository' )
			->setMethods( array( 'update' ) )
			->getMock();

		// Expect update() to be called with baselines data.
		$mock_repo->expects( $this->once() )
			->method( 'update' )
			->with( 'performance_baselines', $this->isType( 'array' ) );

		// Set the mock repository.
		WP_MCP_AI_Performance_Reporting_Service::set_settings_repository( $mock_repo );

		// Call update_baselines - should use repository.
		$baselines = WP_MCP_AI_Performance_Reporting_Service::update_baselines();

		// Should return array.
		$this->assertIsArray( $baselines );
	}

	/**
	 * Test that service doesn't call get_option directly.
	 *
	 * This test verifies the refactoring goal - no direct WordPress option access.
	 */
	public function test_does_not_call_get_option_directly() {
		// Use reflection to check the service class source code.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Performance_Reporting_Service' );
		$filename   = $reflection->getFileName();
		$source     = file_get_contents( $filename );

		// Check for direct get_option calls in get_baselines method.
		$get_baselines_method = $reflection->getMethod( 'get_baselines' );
		$start_line           = $get_baselines_method->getStartLine();
		$end_line             = $get_baselines_method->getEndLine();

		$lines       = file( $filename );
		$method_code = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// Should NOT contain direct get_option call.
		$this->assertStringNotContainsString( 'get_option(', $method_code, 'get_baselines should not call get_option directly' );
	}

	/**
	 * Test that service doesn't call update_option directly.
	 *
	 * This test verifies the refactoring goal - no direct WordPress option access.
	 */
	public function test_does_not_call_update_option_directly() {
		// Use reflection to check the service class source code.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Performance_Reporting_Service' );
		$filename   = $reflection->getFileName();

		// Check for direct update_option calls in update_baselines method.
		$update_baselines_method = $reflection->getMethod( 'update_baselines' );
		$start_line              = $update_baselines_method->getStartLine();
		$end_line                = $update_baselines_method->getEndLine();

		$lines       = file( $filename );
		$method_code = implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );

		// Should NOT contain direct update_option call.
		$this->assertStringNotContainsString( 'update_option(', $method_code, 'update_baselines should not call update_option directly' );
	}

	/**
	 * Test baselines persistence with real repository.
	 */
	public function test_baselines_persistence() {
		// Skip if Performance Monitor CCT is not available.
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$this->markTestSkipped( 'Performance Monitor CCT not available (base version mode)' );
		}

		// Use real settings repository.
		$repository = new WP_MCP_AI_Settings_Repository();
		WP_MCP_AI_Performance_Reporting_Service::set_settings_repository( $repository );

		// Clear existing data.
		delete_option( 'wp_mcp_ai_performance_baselines' );

		// Update baselines.
		$baselines = WP_MCP_AI_Performance_Reporting_Service::update_baselines();

		// Verify data was stored via repository.
		$stored_baselines = $repository->get( 'performance_baselines', array() );
		$this->assertIsArray( $stored_baselines );
		$this->assertEquals( $baselines, $stored_baselines );
	}
}
