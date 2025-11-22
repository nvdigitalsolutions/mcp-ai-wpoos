<?php
/**
 * Test consolidated job notifications endpoint
 *
 * Verifies that the job-notifications endpoint returns both notifications
 * and job_counts to eliminate the need for separate cron-status polling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for consolidated job notifications
 */
class Test_REST_Job_Notifications_Consolidated extends WP_UnitTestCase {

	/**
	 * Test that job-notifications endpoint includes job_counts
	 */
	public function test_job_notifications_includes_counts() {
		// Create a test user with necessary permissions.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Make request to job-notifications endpoint.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/job-notifications' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'clear', false );

		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status(), 'Expected 200 response status' );

		$data = $response->get_data();

		// Verify response structure.
		$this->assertIsArray( $data, 'Response should be an array' );
		$this->assertArrayHasKey( 'notifications', $data, 'Response should have notifications key' );
		$this->assertArrayHasKey( 'count', $data, 'Response should have count key' );
		$this->assertArrayHasKey( 'job_counts', $data, 'Response should have job_counts key' );

		// Verify job_counts structure.
		$job_counts = $data['job_counts'];
		$this->assertIsArray( $job_counts, 'job_counts should be an array' );
		$this->assertArrayHasKey( 'pending', $job_counts, 'job_counts should have pending' );
		$this->assertArrayHasKey( 'running', $job_counts, 'job_counts should have running' );
		$this->assertArrayHasKey( 'completed', $job_counts, 'job_counts should have completed' );
		$this->assertArrayHasKey( 'failed', $job_counts, 'job_counts should have failed' );
		$this->assertArrayHasKey( 'total', $job_counts, 'job_counts should have total' );

		// Verify counts are numeric.
		$this->assertIsInt( $job_counts['pending'], 'pending should be an integer' );
		$this->assertIsInt( $job_counts['running'], 'running should be an integer' );
		$this->assertIsInt( $job_counts['completed'], 'completed should be an integer' );
		$this->assertIsInt( $job_counts['failed'], 'failed should be an integer' );
		$this->assertIsInt( $job_counts['total'], 'total should be an integer' );
	}

	/**
	 * Test that job counts are zero when no jobs exist
	 */
	public function test_job_counts_zero_when_no_jobs() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/job-notifications' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'clear', false );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// When no jobs exist, all counts should be zero.
		$this->assertEquals( 0, $data['job_counts']['pending'], 'Should have 0 pending jobs' );
		$this->assertEquals( 0, $data['job_counts']['running'], 'Should have 0 running jobs' );
		$this->assertEquals( 0, $data['job_counts']['completed'], 'Should have 0 completed jobs' );
		$this->assertEquals( 0, $data['job_counts']['failed'], 'Should have 0 failed jobs' );
		$this->assertEquals( 0, $data['job_counts']['total'], 'Should have 0 total jobs' );
	}

	/**
	 * Test authentication is required
	 */
	public function test_job_notifications_requires_authentication() {
		// No user logged in.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/job-notifications' );
		$request->set_param( 'assistant_id', 1 );

		$response = rest_do_request( $request );

		// Should return 401 or 403.
		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Unauthenticated request should return 401 or 403'
		);
	}
}
