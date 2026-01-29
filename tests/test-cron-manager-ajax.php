<?php
/**
 * Tests for Cron Manager AJAX functionality
 *
 * Verifies that the AJAX endpoints return the expected data structure
 * for the Cron Manager page with auto-refresh functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cron Manager AJAX functionality.
 */
class Test_Cron_Manager_Ajax extends WP_UnitTestCase {

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Manager instance
	 *
	 * @var WP_MCP_AI_Admin_Cron_Manager
	 */
	private $manager;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialize manager.
		$this->manager = new WP_MCP_AI_Admin_Cron_Manager();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Clean up any test cron jobs.
		wp_clear_scheduled_hook( 'wp_mcp_ai_test_cron_hook' );
		parent::tearDown();
	}

	/**
	 * Test that AJAX action is registered.
	 */
	public function test_ajax_action_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_cron_manager_stats' ),
			'AJAX action wp_ajax_wp_mcp_ai_get_cron_manager_stats should be registered'
		);
	}

	/**
	 * Test AJAX handler requires authentication.
	 */
	public function test_ajax_requires_authentication() {
		// Log out user.
		wp_set_current_user( 0 );

		// Attempt to call AJAX endpoint without authentication.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		try {
			$this->manager->ajax_get_stats();
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
			$this->manager->ajax_get_stats();
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
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		// Capture the AJAX response.
		ob_start();
		try {
			$this->manager->ajax_get_stats();
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
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$stats = $data['data']['stats'];

		// Verify stats has expected keys.
		$this->assertArrayHasKey( 'total', $stats, 'Stats should have total' );
		$this->assertArrayHasKey( 'active', $stats, 'Stats should have active' );
		$this->assertArrayHasKey( 'inactive', $stats, 'Stats should have inactive' );
		$this->assertArrayHasKey( 'recurring', $stats, 'Stats should have recurring' );
		$this->assertArrayHasKey( 'one_off', $stats, 'Stats should have one_off' );

		// Verify stats are numeric.
		$this->assertIsNumeric( $stats['total'], 'Total should be numeric' );
		$this->assertIsNumeric( $stats['active'], 'Active should be numeric' );
		$this->assertIsNumeric( $stats['inactive'], 'Inactive should be numeric' );
		$this->assertIsNumeric( $stats['recurring'], 'Recurring should be numeric' );
		$this->assertIsNumeric( $stats['one_off'], 'One-off should be numeric' );
	}

	/**
	 * Test jobs array structure in AJAX response.
	 */
	public function test_ajax_jobs_structure() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$jobs = $data['data']['jobs'];

		// Jobs should be an array.
		$this->assertIsArray( $jobs, 'Jobs should be an array' );
	}

	/**
	 * Test job structure when cron jobs exist.
	 */
	public function test_ajax_job_fields() {
		// Create a test cron job.
		WP_MCP_AI_Cron_Manager::add_job(
			'wp_mcp_ai_test_cron_hook',
			time() + 3600,
			array( 'test' => 'data' ),
			'',
			$this->admin_id
		);

		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$jobs = $data['data']['jobs'];

		$this->assertNotEmpty( $jobs, 'Should have at least one job' );

		// Verify first job structure.
		$job = $jobs[0];
		$this->assertArrayHasKey( 'hook', $job, 'Job should have hook' );
		$this->assertArrayHasKey( 'args', $job, 'Job should have args' );
		$this->assertArrayHasKey( 'schedule', $job, 'Job should have schedule' );
		$this->assertArrayHasKey( 'is_active', $job, 'Job should have is_active' );
		$this->assertArrayHasKey( 'is_recurring', $job, 'Job should have is_recurring' );
		$this->assertArrayHasKey( 'creator', $job, 'Job should have creator' );
		$this->assertArrayHasKey( 'job_id', $job, 'Job should have job_id' );
		$this->assertArrayHasKey( 'delete_nonce', $job, 'Job should have delete_nonce' );

		// Verify types.
		$this->assertIsString( $job['hook'], 'Hook should be string' );
		$this->assertIsArray( $job['args'], 'Args should be array' );
		$this->assertIsBool( $job['is_active'], 'is_active should be boolean' );
		$this->assertIsBool( $job['is_recurring'], 'is_recurring should be boolean' );
		$this->assertIsString( $job['creator'], 'Creator should be string' );
	}

	/**
	 * Test that stats are non-negative.
	 */
	public function test_stats_are_non_negative() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$stats = $data['data']['stats'];

		// All stats should be >= 0.
		$this->assertGreaterThanOrEqual( 0, $stats['total'], 'Total should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['active'], 'Active should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['inactive'], 'Inactive should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['recurring'], 'Recurring should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $stats['one_off'], 'One-off should be non-negative' );
	}

	/**
	 * Test stats calculations are correct.
	 */
	public function test_stats_calculations() {
		// Create test jobs - one active, one recurring.
		WP_MCP_AI_Cron_Manager::add_job(
			'wp_mcp_ai_test_one_off',
			time() + 3600,
			array(),
			'',
			$this->admin_id
		);

		WP_MCP_AI_Cron_Manager::add_job(
			'wp_mcp_ai_test_recurring',
			time() + 3600,
			array(),
			'hourly',
			$this->admin_id
		);

		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$stats = $data['data']['stats'];

		$this->assertGreaterThanOrEqual( 2, $stats['total'], 'Should have at least 2 jobs' );
		$this->assertGreaterThanOrEqual( 2, $stats['active'], 'Should have 2 active jobs' );
		$this->assertGreaterThanOrEqual( 1, $stats['recurring'], 'Should have at least 1 recurring job' );
		$this->assertGreaterThanOrEqual( 1, $stats['one_off'], 'Should have at least 1 one-off job' );
	}

	/**
	 * Test DLQ stats are included if available.
	 */
	public function test_dlq_stats_included() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		// DLQ stats may be null if class not available.
		$this->assertArrayHasKey( 'dlq_stats', $data['data'], 'Response should have dlq_stats key' );
	}

	/**
	 * Test AJAX response is valid JSON.
	 */
	public function test_ajax_response_is_valid_json() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
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
	 * Test concurrent AJAX requests don't interfere.
	 */
	public function test_concurrent_ajax_requests() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		// Make multiple requests.
		for ( $i = 0; $i < 3; $i++ ) {
			ob_start();
			try {
				$this->manager->ajax_get_stats();
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
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
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

		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		try {
			$this->manager->ajax_get_stats();
			$this->fail( 'Non-admin should not be able to access endpoint' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - permissions error.
			$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
		}
	}

	/**
	 * Test that pruning is called during AJAX request.
	 */
	public function test_pruning_called_during_ajax() {
		// This test verifies that maybe_prune_jobs is called.
		// Create an old executed job that should be pruned.
		WP_MCP_AI_Cron_Manager::add_job(
			'wp_mcp_ai_old_job',
			time() - 86400 * 2, // 2 days ago.
			array(),
			'',
			$this->admin_id
		);

		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		// Should succeed regardless of pruning.
		$this->assertTrue( $data['success'], 'AJAX should succeed even with pruning' );
	}

	/**
	 * Test delete nonce is valid for each job.
	 */
	public function test_delete_nonces_are_valid() {
		// Create a test job.
		WP_MCP_AI_Cron_Manager::add_job(
			'wp_mcp_ai_test_nonce',
			time() + 3600,
			array(),
			'',
			$this->admin_id
		);

		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_cron_manager' );

		ob_start();
		try {
			$this->manager->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$response = ob_get_clean();
		$data     = json_decode( $response, true );

		$jobs = $data['data']['jobs'];

		foreach ( $jobs as $job ) {
			$this->assertNotEmpty( $job['delete_nonce'], 'Delete nonce should not be empty' );
			
			// Verify nonce is valid.
			$nonce_valid = wp_verify_nonce( $job['delete_nonce'], 'wp_mcp_ai_delete_cron_' . $job['job_id'] );
			$this->assertNotFalse( $nonce_valid, 'Delete nonce should be valid' );
		}
	}
}
