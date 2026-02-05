<?php
/**
 * Test Orchestration Dashboard System Status
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Orchestration Dashboard System Status
 */
class Test_Orchestration_System_Status extends WP_UnitTestCase {

	/**
	 * Test dashboard class exists
	 */
	public function test_dashboard_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ), 'WP_MCP_AI_Orchestration_Dashboard class should exist' );
	}

	/**
	 * Test get_system_status method returns proper structure
	 */
	public function test_get_system_status_structure() {
		// Skip if Pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_system_status' );
		$method->setAccessible( true );

		$status = $method->invoke( $dashboard );

		// Test structure.
		$this->assertIsArray( $status, 'System status should be an array' );
		$this->assertArrayHasKey( 'cron', $status, 'System status should have cron key' );
		$this->assertArrayHasKey( 'async', $status, 'System status should have async key' );
		$this->assertArrayHasKey( 'sse', $status, 'System status should have sse key' );
		$this->assertArrayHasKey( 'health', $status, 'System status should have health key' );
	}

	/**
	 * Test cron status is populated
	 */
	public function test_cron_status_populated() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_system_status' );
		$method->setAccessible( true );

		$status = $method->invoke( $dashboard );

		// Cron status should be an array.
		$this->assertIsArray( $status['cron'], 'Cron status should be an array' );

		// If WP_MCP_AI_Cron_Status_Service is available, cron should have metrics.
		if ( class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			$this->assertArrayHasKey( 'active', $status['cron'], 'Cron status should have active key' );
			$this->assertArrayHasKey( 'pending', $status['cron'], 'Cron status should have pending key' );
			$this->assertArrayHasKey( 'failed', $status['cron'], 'Cron status should have failed key' );
		}
	}

	/**
	 * Test async status is populated
	 */
	public function test_async_status_populated() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_system_status' );
		$method->setAccessible( true );

		$status = $method->invoke( $dashboard );

		// Async status should be an array.
		$this->assertIsArray( $status['async'], 'Async status should be an array' );

		// If WP_MCP_AI_Async_Health_Monitor is available, async should have metrics.
		if ( class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			$this->assertArrayHasKey( 'status', $status['async'], 'Async status should have status key' );
			$this->assertArrayHasKey( 'stuck_jobs', $status['async'], 'Async status should have stuck_jobs key' );
			$this->assertArrayHasKey( 'long_running', $status['async'], 'Async status should have long_running key' );
		}
	}

	/**
	 * Test health status is populated
	 */
	public function test_health_status_populated() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_system_status' );
		$method->setAccessible( true );

		$status = $method->invoke( $dashboard );

		// Health status should be an array.
		$this->assertIsArray( $status['health'], 'Health status should be an array' );

		// If WP_MCP_AI_Orchestration_Health_Service is available, health should have metrics.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
			$this->assertArrayHasKey( 'status', $status['health'], 'Health status should have status key' );
			$this->assertArrayHasKey( 'label', $status['health'], 'Health status should have label key' );
			$this->assertArrayHasKey( 'icon', $status['health'], 'Health status should have icon key' );
		}
	}

	/**
	 * Test SSE status is populated
	 */
	public function test_sse_status_populated() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_system_status' );
		$method->setAccessible( true );

		$status = $method->invoke( $dashboard );

		// SSE status should be an array.
		$this->assertIsArray( $status['sse'], 'SSE status should be an array' );
		$this->assertArrayHasKey( 'available', $status['sse'], 'SSE status should have available key' );
		$this->assertArrayHasKey( 'endpoint', $status['sse'], 'SSE status should have endpoint key' );

		// Available should be boolean.
		$this->assertIsBool( $status['sse']['available'], 'SSE available should be boolean' );

		// Endpoint should be a string.
		$this->assertIsString( $status['sse']['endpoint'], 'SSE endpoint should be string' );
	}

	/**
	 * Test get_dashboard_data includes system_status
	 */
	public function test_dashboard_data_includes_system_status() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $dashboard );

		// Dashboard data should include system_status.
		$this->assertIsArray( $data, 'Dashboard data should be an array' );
		$this->assertArrayHasKey( 'system_status', $data, 'Dashboard data should include system_status' );
		$this->assertIsArray( $data['system_status'], 'System status should be an array' );
	}
}
