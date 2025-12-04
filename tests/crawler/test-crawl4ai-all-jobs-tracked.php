<?php
/**
 * Tests for tracking all Crawl4AI jobs (sync, async, and local).
 */

class WP_MCP_AI_Crawler_All_Jobs_Tracked_Test extends WP_UnitTestCase {
	/**
	 * Stubbed HTTP responses.
	 *
	 * @var array
	 */
	protected $http_responses = array();

	public function setUp(): void {
		parent::setUp();

		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['crawl4ai_base_url'] = 'https://api.example.com';
		$settings['crawl4ai_api_key']  = 'test-token';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		WP_MCP_AI_Crawler::init();

		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10 );
		parent::tearDown();
	}

	/**
	 * Test that synchronous remote jobs are tracked by the manager.
	 */
	public function test_synchronous_remote_jobs_are_tracked() {
		$this->http_responses = array(
			array(
				'code' => 200,
				'body' => array(
					'status'  => 'completed',
					'task_id' => 'sync-task-123',
					'results' => array(
						array(
							'url'      => 'https://example.org',
							'markdown' => '# Example Page',
						),
					),
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result = $tool->execute(
			array( 'urls' => array( 'https://example.org' ) ),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'sync-task-123', $result['task_id'] );

		// Verify the job is in the cache
		$cached = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( 'sync-task-123' );
		$this->assertIsArray( $cached );
		$this->assertSame( 'completed', $cached['status'] );
		$this->assertArrayHasKey( 'tracking', $cached['metadata'] );
		$this->assertArrayHasKey( 'registered_at', $cached['metadata']['tracking'] );

		// Verify job was registered with the manager
		$job_status = WP_MCP_AI_Crawler::get_job_status( 'sync-task-123' );
		$this->assertIsArray( $job_status );
		$this->assertSame( 'sync-task-123', $job_status['task_id'] );
		$this->assertSame( 'completed', $job_status['status'] );
	}

	/**
	 * Test that local fallback jobs are tracked by the manager.
	 */
	public function test_local_fallback_jobs_are_tracked() {
		// Remove the base URL to force local crawl
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		unset( $settings['crawl4ai_base_url'] );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$this->http_responses = array(
			array(
				'code' => 200,
				'body' => '<html><head><title>Test Page</title></head><body><h1>Hello World</h1></body></html>',
			),
		);

		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result = $tool->execute(
			array( 'urls' => array( 'https://example.org' ) ),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $result['task_id'] );
		$this->assertStringStartsWith( 'local-', $result['task_id'] );

		$task_id = $result['task_id'];

		// Verify the job is in the cache
		$cached = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );
		$this->assertIsArray( $cached );
		$this->assertSame( 'completed', $cached['status'] );
		$this->assertArrayHasKey( 'tracking', $cached['metadata'] );
		$this->assertArrayHasKey( 'registered_at', $cached['metadata']['tracking'] );

		// Verify job was registered with the manager
		$job_status = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertIsArray( $job_status );
		$this->assertSame( $task_id, $job_status['task_id'] );
		$this->assertSame( 'completed', $job_status['status'] );
	}

	/**
	 * Test that async remote jobs are still tracked (existing behavior).
	 */
	public function test_async_remote_jobs_are_tracked() {
		$this->http_responses = array(
			array(
				'code' => 200,
				'body' => array(
					'status'  => 'pending',
					'task_id' => 'async-task-456',
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result = $tool->execute(
			array( 'urls' => array( 'https://example.org' ) ),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['async'] );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'async-task-456', $result['task_id'] );

		// Verify job was registered with the manager for polling
		$job_status = WP_MCP_AI_Crawler::get_job_status( 'async-task-456' );
		$this->assertIsArray( $job_status );
		$this->assertSame( 'async-task-456', $job_status['task_id'] );
		$this->assertSame( 'pending', $job_status['status'] );
	}

	/**
	 * Test that completed jobs are not polled.
	 */
	public function test_completed_jobs_skip_polling() {
		$task_id = 'completed-task-789';
		
		// Register a completed job
		$registered = WP_MCP_AI_Crawler::register_completed_job(
			$task_id,
			array(
				'base_url'  => 'https://api.example.com',
				'arguments' => array( 'urls' => array( 'https://example.org' ) ),
				'context'   => array( 'user_id' => 1 ),
				'status'    => 'completed',
				'result'    => array(
					'status'  => 'completed',
					'task_id' => $task_id,
					'results' => array(
						array(
							'url'      => 'https://example.org',
							'markdown' => '# Test',
						),
					),
					'metadata' => array(),
					'raw'      => array(),
				),
			)
		);

		$this->assertTrue( $registered );

		// Verify the job is tracked
		$job_status = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertIsArray( $job_status );
		$this->assertSame( 'completed', $job_status['status'] );

		// Attempt to poll the job - should exit early without errors
		WP_MCP_AI_Crawler::handle_poll_event( $task_id );

		// Job should still exist (not deleted by polling)
		$job_status_after = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertIsArray( $job_status_after );
		$this->assertSame( 'completed', $job_status_after['status'] );
	}

	/**
	 * Mock HTTP requests for testing.
	 *
	 * @param mixed  $preempt Preempt value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function mock_http_request( $preempt, $args, $url ) {
		unset( $args );

		$response = array_shift( $this->http_responses );
		if ( ! $response ) {
			return $preempt;
		}

		$body = $response['body'];
		if ( is_array( $body ) ) {
			$body = wp_json_encode( $body );
		}

		return array(
			'headers'  => array( 'content-type' => is_string( $response['body'] ) ? 'text/html' : 'application/json' ),
			'body'     => $body,
			'response' => array(
				'code'    => $response['code'],
				'message' => 'OK',
			),
		);
	}
}
