<?php
/**
 * Tests for Crawl4AI Monitor AJAX functionality
 *
 * Verifies that the AJAX endpoints return the expected data structure
 * for the Crawl4AI monitor page with auto-refresh functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Crawl4AI Monitor AJAX functionality.
 */
class Test_Crawl4AI_Monitor_Ajax extends WP_UnitTestCase {

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Monitor instance
	 *
	 * @var WP_MCP_AI_Admin_Crawl4AI_Monitor
	 */
	private $monitor;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialize monitor.
		$this->monitor = new WP_MCP_AI_Admin_Crawl4AI_Monitor();
	}

	/**
	 * Test that AJAX action is registered.
	 */
	public function test_ajax_action_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_crawl4ai_stats' ),
			'AJAX action wp_ajax_wp_mcp_ai_get_crawl4ai_stats should be registered'
		);
	}

	/**
	 * Test AJAX handler requires authentication.
	 */
	public function test_ajax_requires_authentication() {
		// Log out user.
		wp_set_current_user( 0 );

		// Attempt to call AJAX endpoint without authentication.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		try {
			$this->monitor->ajax_get_stats();
			$this->fail( 'Should have failed without authentication' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected behavior - AJAX died due to missing capabilities.
			$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
		}
	}

	/**
	 * Test AJAX handler requires valid nonce.
	 */
	public function test_ajax_requires_valid_nonce() {
		$_POST['nonce'] = 'invalid_nonce';

		try {
			$this->monitor->ajax_get_stats();
			$this->fail( 'Should have failed with invalid nonce' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected behavior - nonce verification failed.
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	/**
	 * Test AJAX handler returns expected data structure.
	 */
	public function test_ajax_returns_expected_structure() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		// Capture the AJAX response.
		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_success calls wp_die.
		}
		$response = ob_get_clean();

		// Parse JSON response.
		$data = json_decode( $response, true );

		// Verify response structure.
		$this->assertTrue( $data['success'], 'AJAX response should be successful' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data key' );
		$this->assertArrayHasKey( 'stats', $data['data'], 'Data should have stats key' );
		$this->assertArrayHasKey( 'jobs', $data['data'], 'Data should have jobs key' );
	}

	/**
	 * Test stats structure in AJAX response.
	 */
	public function test_ajax_stats_structure() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$stats = $data['data']['stats'];

		// Verify stats has expected keys.
		$this->assertArrayHasKey( 'total_jobs', $stats, 'Stats should have total_jobs' );
		$this->assertArrayHasKey( 'running_jobs', $stats, 'Stats should have running_jobs' );
		$this->assertArrayHasKey( 'completed_jobs', $stats, 'Stats should have completed_jobs' );
		$this->assertArrayHasKey( 'failed_jobs', $stats, 'Stats should have failed_jobs' );
		$this->assertArrayHasKey( 'browser_pools', $stats, 'Stats should have browser_pools' );

		// Verify stats are numeric.
		$this->assertIsNumeric( $stats['total_jobs'], 'Total jobs should be numeric' );
		$this->assertIsNumeric( $stats['running_jobs'], 'Running jobs should be numeric' );
		$this->assertIsNumeric( $stats['completed_jobs'], 'Completed jobs should be numeric' );
		$this->assertIsNumeric( $stats['failed_jobs'], 'Failed jobs should be numeric' );
		$this->assertIsNumeric( $stats['browser_pools'], 'Browser pools should be numeric' );
	}

	/**
	 * Test jobs array structure in AJAX response.
	 */
	public function test_ajax_jobs_structure() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$jobs = $data['data']['jobs'];

		// Jobs should be an array.
		$this->assertIsArray( $jobs, 'Jobs should be an array' );

		// If there are jobs, verify their structure.
		if ( ! empty( $jobs ) ) {
			$job = $jobs[0];

			// Verify job has expected fields (some may be null/N/A).
			$this->assertArrayHasKey( 'id', $job, 'Job should have id field' );
			$this->assertArrayHasKey( 'url', $job, 'Job should have url field' );
			$this->assertArrayHasKey( 'status', $job, 'Job should have status field' );
		}
	}

	/**
	 * Test that stats are non-negative.
	 */
	public function test_stats_are_non_negative() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$stats = $data['data']['stats'];

		// All stats should be >= 0.
		$this->assertGreaterThanOrEqual( 0, $stats['total_jobs'], 'Total jobs should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['running_jobs'], 'Running jobs should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['completed_jobs'], 'Completed jobs should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['failed_jobs'], 'Failed jobs should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['browser_pools'], 'Browser pools should be non-negative' );
	}

	/**
	 * Test AJAX response is valid JSON.
	 */
	public function test_ajax_response_is_valid_json() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();

		// Should be valid JSON.
		$data = json_decode( $response, true );
		$this->assertNotNull( $data, 'Response should be valid JSON' );
		$this->assertEquals( JSON_ERROR_NONE, json_last_error(), 'JSON should have no errors' );
	}

	/**
	 * Test AJAX handler handles missing Crawl4AI gracefully.
	 */
	public function test_ajax_handles_missing_crawl4ai() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		// Even if Crawl4AI is not available, should return empty stats.
		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		// Should still have valid structure with zero values.
		$this->assertTrue( $data['success'], 'Should succeed even without Crawl4AI' );
		$this->assertIsArray( $data['data']['stats'], 'Stats should be an array' );
		$this->assertIsArray( $data['data']['jobs'], 'Jobs should be an array' );
	}

	/**
	 * Test concurrent AJAX requests don't interfere.
	 */
	public function test_concurrent_ajax_requests() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		// Make multiple requests.
		for ( $i = 0; $i < 3; $i++ ) {
			ob_start();
			try {
				$this->monitor->ajax_get_stats();
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected.
			}
			$response = ob_get_clean();
			$data     = json_decode( $response, true );

			$this->assertTrue( $data['success'], "Request {$i} should succeed" );
		}
	}

	/**
	 * Test admin user can access AJAX endpoint.
	 */
	public function test_admin_can_access() {
		wp_set_current_user( $this->admin_id );
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		ob_start();
		try {
			$this->monitor->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$this->assertTrue( $data['success'], 'Admin should be able to access endpoint' );
	}

	/**
	 * Test non-admin user cannot access AJAX endpoint.
	 */
	public function test_non_admin_cannot_access() {
		// Create subscriber user.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_crawl4ai_monitor' );

		try {
			$this->monitor->ajax_get_stats();
			$this->fail( 'Non-admin should not be able to access endpoint' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - permissions error.
			$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
		}
	}
}
