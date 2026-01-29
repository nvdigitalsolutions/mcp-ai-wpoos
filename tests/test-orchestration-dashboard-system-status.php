<?php
/**
 * Tests for Orchestration Dashboard System Status functionality
 *
 * Verifies that the system status data is properly returned in the AJAX response
 * and includes all required monitoring metrics.
 *
 * @package WP_MCP_AI
 */

/**
 * Test orchestration dashboard system status functionality.
 */
class Test_Orchestration_Dashboard_System_Status extends WP_UnitTestCase {

	/**
	 * Instance of the dashboard class
	 *
	 * @var WP_MCP_AI_Orchestration_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Set up an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Initialize the dashboard class.
		$this->dashboard = new WP_MCP_AI_Orchestration_Dashboard();
	}

	/**
	 * Test that dashboard data includes system_status key.
	 */
	public function test_dashboard_data_includes_system_status() {
		// Use reflection to access the private get_dashboard_data method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		// Verify system_status key exists.
		$this->assertArrayHasKey( 'system_status', $data, 'Dashboard data should have system_status key' );
		$this->assertIsArray( $data['system_status'], 'System status should be an array' );
	}

	/**
	 * Test system status structure includes all required sections.
	 */
	public function test_system_status_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data          = $method->invoke( $this->dashboard );
		$system_status = $data['system_status'];

		// Verify all required sections exist.
		$this->assertArrayHasKey( 'cron', $system_status, 'System status should have cron section' );
		$this->assertArrayHasKey( 'async', $system_status, 'System status should have async section' );
		$this->assertArrayHasKey( 'health', $system_status, 'System status should have health section' );
		$this->assertArrayHasKey( 'sse', $system_status, 'System status should have sse section' );
	}

	/**
	 * Test cron status section structure.
	 */
	public function test_cron_status_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data          = $method->invoke( $this->dashboard );
		$cron_status   = $data['system_status']['cron'];

		// Verify cron status structure.
		$this->assertIsArray( $cron_status, 'Cron status should be an array' );

		// When service is available, check for expected keys.
		if ( class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			$this->assertArrayHasKey( 'active', $cron_status, 'Cron status should have active count' );
			$this->assertArrayHasKey( 'pending', $cron_status, 'Cron status should have pending count' );
			$this->assertArrayHasKey( 'failed', $cron_status, 'Cron status should have failed count' );
		}
	}

	/**
	 * Test that Cron Status Service can be instantiated successfully.
	 */
	public function test_cron_status_service_instantiation() {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			$this->markTestSkipped( 'Cron Status Service not available' );
		}

		// Verify the service can be instantiated without errors.
		try {
			$service = new WP_MCP_AI_Cron_Status_Service();
			$this->assertInstanceOf( 'WP_MCP_AI_Cron_Status_Service', $service, 'Service should be instantiable' );

			// Verify the service has the expected method.
			$this->assertTrue(
				method_exists( $service, 'get_status_summary' ),
				'Service should have get_status_summary method'
			);

			// Verify calling the method doesn't throw an exception.
			$result = $service->get_status_summary( 0, 5 );
			$this->assertIsArray( $result, 'get_status_summary should return an array' );
		} catch ( Exception $e ) {
			$this->fail( 'Cron Status Service instantiation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Test async status section structure.
	 */
	public function test_async_status_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data         = $method->invoke( $this->dashboard );
		$async_status = $data['system_status']['async'];

		// Verify async status structure.
		$this->assertIsArray( $async_status, 'Async status should be an array' );

		// When service is available, check for expected keys.
		if ( class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			$this->assertArrayHasKey( 'status', $async_status, 'Async status should have status field' );
			$this->assertArrayHasKey( 'stuck_jobs', $async_status, 'Async status should have stuck_jobs count' );
			$this->assertArrayHasKey( 'long_running', $async_status, 'Async status should have long_running count' );
		}
	}

	/**
	 * Test health status section structure.
	 */
	public function test_health_status_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data          = $method->invoke( $this->dashboard );
		$health_status = $data['system_status']['health'];

		// Verify health status structure.
		$this->assertIsArray( $health_status, 'Health status should be an array' );

		// When service is available, check for expected keys.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
			$this->assertArrayHasKey( 'status', $health_status, 'Health status should have status field' );
			$this->assertArrayHasKey( 'label', $health_status, 'Health status should have label field' );
		}
	}

	/**
	 * Test SSE status section structure.
	 */
	public function test_sse_status_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data       = $method->invoke( $this->dashboard );
		$sse_status = $data['system_status']['sse'];

		// Verify SSE status structure.
		$this->assertIsArray( $sse_status, 'SSE status should be an array' );
		$this->assertArrayHasKey( 'available', $sse_status, 'SSE status should have available field' );
		$this->assertArrayHasKey( 'endpoint', $sse_status, 'SSE status should have endpoint field' );
	}

	/**
	 * Test that system status data is included in AJAX response.
	 */
	public function test_ajax_response_includes_system_status() {
		// Set up nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Capture the output.
		ob_start();
		try {
			$this->dashboard->ajax_get_dashboard_data();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_success calls wp_die().
		}
		$output = ob_get_clean();

		// Decode JSON response.
		$response = json_decode( $output, true );

		// Verify system_status is in response.
		$this->assertIsArray( $response, 'AJAX response should be an array' );
		$this->assertTrue( $response['success'], 'AJAX response should be successful' );
		$this->assertArrayHasKey( 'data', $response, 'AJAX response should have data key' );
		$this->assertArrayHasKey( 'system_status', $response['data'], 'AJAX data should have system_status' );
	}

	/**
	 * Test that graceful degradation works when services are unavailable.
	 */
	public function test_graceful_degradation_when_services_unavailable() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		// Even if services are unavailable, system_status should exist.
		$this->assertArrayHasKey( 'system_status', $data, 'System status should exist even if services are unavailable' );
		$this->assertIsArray( $data['system_status'], 'System status should be an array' );

		// All sections should exist as arrays (even if empty).
		$this->assertIsArray( $data['system_status']['cron'], 'Cron section should be an array' );
		$this->assertIsArray( $data['system_status']['async'], 'Async section should be an array' );
		$this->assertIsArray( $data['system_status']['health'], 'Health section should be an array' );
		$this->assertIsArray( $data['system_status']['sse'], 'SSE section should be an array' );
	}
}
