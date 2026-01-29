<?php
/**
 * Tests for Orchestration Dashboard AJAX functionality
 *
 * Verifies that the AJAX endpoints return the expected data structure
 * for the orchestration dashboard.
 *
 * @package WP_MCP_AI
 */

/**
 * Test orchestration dashboard AJAX functionality.
 */
class Test_Orchestration_Dashboard_Ajax extends WP_UnitTestCase {

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
	 * Test that the AJAX action is registered.
	 */
	public function test_ajax_action_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_dashboard_data' ),
			'AJAX action wp_ajax_wp_mcp_ai_get_dashboard_data should be registered'
		);
	}

	/**
	 * Test dashboard data structure.
	 *
	 * Uses reflection to access the private get_dashboard_data method.
	 */
	public function test_dashboard_data_structure() {
		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		// Verify data structure.
		$this->assertIsArray( $data, 'Dashboard data should be an array' );
		$this->assertArrayHasKey( 'overview', $data, 'Data should have overview key' );
		$this->assertArrayHasKey( 'capacity', $data, 'Data should have capacity key' );
		$this->assertArrayHasKey( 'sessions', $data, 'Data should have sessions key' );
		$this->assertArrayHasKey( 'workflows', $data, 'Data should have workflows key' );
		$this->assertArrayHasKey( 'activity', $data, 'Data should have activity key' );
		$this->assertArrayHasKey( 'timestamp', $data, 'Data should have timestamp key' );
	}

	/**
	 * Test overview metrics structure.
	 */
	public function test_overview_metrics_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		$overview = $data['overview'];
		$this->assertIsArray( $overview, 'Overview should be an array' );
		$this->assertArrayHasKey( 'active_sessions', $overview, 'Overview should have active_sessions' );
		$this->assertArrayHasKey( 'total_plans', $overview, 'Overview should have total_plans' );
		$this->assertArrayHasKey( 'total_executions', $overview, 'Overview should have total_executions' );
		$this->assertArrayHasKey( 'system_health', $overview, 'Overview should have system_health' );
	}

	/**
	 * Test capacity metrics structure.
	 */
	public function test_capacity_metrics_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		$capacity = $data['capacity'];
		$this->assertIsArray( $capacity, 'Capacity should be an array' );
		$this->assertArrayHasKey( 'utilization', $capacity, 'Capacity should have utilization' );
		$this->assertArrayHasKey( 'queue_length', $capacity, 'Capacity should have queue_length' );
		$this->assertArrayHasKey( 'load_status', $capacity, 'Capacity should have load_status' );
	}

	/**
	 * Test workflows array structure.
	 */
	public function test_workflows_array_structure() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		$workflows = $data['workflows'];
		$this->assertIsArray( $workflows, 'Workflows should be an array' );

		// If there are workflows, test the structure.
		if ( count( $workflows ) > 0 ) {
			$workflow = $workflows[0];
			$this->assertArrayHasKey( 'workflow_id', $workflow, 'Workflow should have workflow_id' );
			$this->assertArrayHasKey( 'team_id', $workflow, 'Workflow should have team_id' );
			$this->assertArrayHasKey( 'task_type', $workflow, 'Workflow should have task_type' );
			$this->assertArrayHasKey( 'state', $workflow, 'Workflow should have state' );
			$this->assertArrayHasKey( 'age_display', $workflow, 'Workflow should have age_display' );
		}
	}

	/**
	 * Test that sessions array is returned (even if empty).
	 */
	public function test_sessions_array_returned() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		$sessions = $data['sessions'];
		$this->assertIsArray( $sessions, 'Sessions should be an array' );
	}

	/**
	 * Test that activity array is returned (even if empty).
	 */
	public function test_activity_array_returned() {
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_dashboard_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $this->dashboard );

		$activity = $data['activity'];
		$this->assertIsArray( $activity, 'Activity should be an array' );
		$this->assertGreaterThan( 0, count( $activity ), 'Activity should have at least one item (system initialized)' );
	}

	/**
	 * Test AJAX response structure.
	 */
	public function test_ajax_dashboard_response() {
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

		// Verify response structure.
		$this->assertIsArray( $response, 'AJAX response should be an array' );
		$this->assertTrue( $response['success'], 'AJAX response should be successful' );
		$this->assertArrayHasKey( 'data', $response, 'AJAX response should have data key' );

		$data = $response['data'];
		$this->assertArrayHasKey( 'overview', $data, 'AJAX data should have overview' );
		$this->assertArrayHasKey( 'capacity', $data, 'AJAX data should have capacity' );
		$this->assertArrayHasKey( 'sessions', $data, 'AJAX data should have sessions' );
		$this->assertArrayHasKey( 'workflows', $data, 'AJAX data should have workflows' );
		$this->assertArrayHasKey( 'activity', $data, 'AJAX data should have activity' );
	}
}
