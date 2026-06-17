<?php
/**
 * Tests for cron-status cancel and retry REST routes.
 *
 * Exercises POST /mcp-ai/v1/cron-status/{job_id}/cancel and
 * POST /mcp-ai/v1/cron-status/{job_id}/retry through the WordPress REST
 * server so permissions, sanitisation, and status transitions are
 * validated end-to-end.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for the cron-status cancel/retry REST routes.
 */
class Test_Cron_Status_Controls extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Author user ID (has edit_posts).
	 *
	 * @var int
	 */
	protected $author_id;

	/**
	 * Subscriber user ID (no edit_posts).
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Pre-generated REST nonces for each test user.
	 *
	 * @var string
	 */
	protected $admin_nonce;
	protected $author_nonce;
	protected $subscriber_nonce;

	/**
	 * Set up REST server and test users.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-tools-controller.php';

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Register the controller routes directly (plugin bootstrap may not run in test context).
		$controller = new WP_MCP_AI_REST_Tools_Controller();
		$controller->register_routes();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Pre-generate nonces so authenticated tests can attach them to requests.
		$this->admin_nonce      = wp_create_nonce( 'wp_rest' );
		$this->author_nonce     = wp_create_nonce( 'wp_rest' );
		$this->subscriber_nonce = wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Tear down REST server and reset current user.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Plant a fake async job transient and return the job ID.
	 *
	 * @param string $status  Job status (pending, running, completed, failed, cancelled).
	 * @param int    $user_id Owner user ID.
	 * @return string Job ID.
	 */
	private function plant_async_job( $status = 'pending', $user_id = 0 ) {
		$job_id   = 'async_test_' . wp_generate_password( 8, false );
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => 'test_tool',
			'arguments'    => array(),
			'context'      => array( 'user_id' => $user_id ),
			'status'       => $status,
			'queued_at'    => time() - 60,
			'started_at'   => 'running' === $status ? time() - 30 : null,
			'completed_at' => in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ? time() - 10 : null,
			'result'       => null,
			'error'        => null,
		);
		set_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id, $metadata, HOUR_IN_SECONDS );
		return $job_id;
	}

	// -------------------------------------------------------------------------
	// Route registration.
	// -------------------------------------------------------------------------

	/**
	 * Cancel and retry routes must be registered.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/cron-status/(?P<job_id>[a-zA-Z0-9_.]+)/cancel', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/cron-status/(?P<job_id>[a-zA-Z0-9_.]+)/retry', $routes );
	}

	// -------------------------------------------------------------------------
	// Authentication guard.
	// -------------------------------------------------------------------------

	/**
	 * Cancel route must reject unauthenticated requests.
	 */
	public function test_cancel_job_requires_authentication() {
		wp_set_current_user( 0 );
		$job_id  = $this->plant_async_job( 'pending', 0 );
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/cancel' );
		$request->set_param( 'job_id', $job_id );
		$response = $this->server->dispatch( $request );
		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	/**
	 * Retry route must reject unauthenticated requests.
	 */
	public function test_retry_job_requires_authentication() {
		wp_set_current_user( 0 );
		$job_id  = $this->plant_async_job( 'failed', 0 );
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/retry' );
		$request->set_param( 'job_id', $job_id );
		$response = $this->server->dispatch( $request );
		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Cancel — success path.
	// -------------------------------------------------------------------------

	/**
	 * Cancelling a pending async job should return 200 + success: true
	 * and flip the stored status to 'cancelled'.
	 */
	public function test_cancel_job_returns_success_for_async_job() {
		wp_set_current_user( $this->author_id );
		$job_id = $this->plant_async_job( 'pending', $this->author_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/cancel' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->author_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( $job_id, $data['job_id'] );

		// Verify the transient was updated.
		$meta = get_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id );
		$this->assertSame( 'cancelled', $meta['status'] );
	}

	/**
	 * An admin should be able to cancel any user's job.
	 */
	public function test_cancel_job_admin_can_cancel_any_job() {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->plant_async_job( 'running', $this->author_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/cancel' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->admin_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	// -------------------------------------------------------------------------
	// Cancel — error paths.
	// -------------------------------------------------------------------------

	/**
	 * Cancelling an already-completed job should return an error.
	 */
	public function test_cancel_job_returns_error_for_terminal_job() {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->plant_async_job( 'completed', $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/cancel' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->admin_nonce );

		$response = $this->server->dispatch( $request );
		// Should be a 4xx error.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * A user should not be able to cancel another user's job.
	 */
	public function test_cancel_job_ownership_check() {
		wp_set_current_user( $this->subscriber_id );

		// subscriber_id tries to cancel a job owned by author_id.
		// Subscriber doesn't have edit_posts so the permission_callback itself may
		// reject, but we just assert we don't get a 200 success.
		$job_id = $this->plant_async_job( 'pending', $this->author_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/cancel' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->subscriber_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertNotSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Retry — success path.
	// -------------------------------------------------------------------------

	/**
	 * Retrying a failed async job should return 200 + success: true
	 * and reset the stored status to 'pending'.
	 */
	public function test_retry_job_returns_success_for_failed_job() {
		wp_set_current_user( $this->author_id );
		$job_id = $this->plant_async_job( 'failed', $this->author_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/retry' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->author_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( $job_id, $data['job_id'] );

		// Verify the transient was reset to pending.
		$meta = get_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id );
		$this->assertSame( 'pending', $meta['status'] );
	}

	/**
	 * Retrying a cancelled job should also succeed.
	 */
	public function test_retry_job_returns_success_for_cancelled_job() {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->plant_async_job( 'cancelled', $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/retry' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->admin_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	// -------------------------------------------------------------------------
	// Retry — error paths.
	// -------------------------------------------------------------------------

	/**
	 * Retrying a job that is still running should return an error.
	 */
	public function test_retry_job_returns_error_for_running_job() {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->plant_async_job( 'running', $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/retry' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->admin_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * Retrying a pending job (not yet failed) should return an error.
	 */
	public function test_retry_job_returns_error_for_pending_job() {
		wp_set_current_user( $this->admin_id );
		$job_id = $this->plant_async_job( 'pending', $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/' . $job_id . '/retry' );
		$request->set_param( 'job_id', $job_id );
		$request->set_header( 'X-WP-Nonce', $this->admin_nonce );

		$response = $this->server->dispatch( $request );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Source-registry delegation.
	// -------------------------------------------------------------------------

	/**
	 * Cancel route should call cancel_job() on a registered source if the job
	 * is handled there (non-async_ prefix).
	 */
	public function test_cancel_job_calls_source_cancel_method() {
		wp_set_current_user( $this->admin_id );

		$called = false;
		$mock   = new class() implements Interface_WP_MCP_AI_Cron_Status_Job_Source {
			private $job_id = 'mock_job_xyz';
			public $called  = false;

			public function get_slug() {
				return 'mock_source';
			}

			public function get_jobs( $user_id = 0, $assistant_id = null ) {
				return array();
			}

			public function cancel_job( $job_id, $user_id = 0 ) {
				if ( $job_id === $this->job_id ) {
					$this->called = true;
					return true;
				}
				return null;
			}
		};

		add_filter(
			'wp_mcp_ai_cron_status_job_sources',
			function ( $sources ) use ( $mock ) {
				$sources['mock_source'] = $mock;
				return $sources;
			}
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status/mock_job_xyz/cancel' );
		$request->set_param( 'job_id', 'mock_job_xyz' );
		$request->set_header( 'X-WP-Nonce', $this->admin_nonce );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $mock->called, 'Source cancel_job() should have been called.' );

		// Cleanup.
		remove_all_filters( 'wp_mcp_ai_cron_status_job_sources' );
	}

	// -------------------------------------------------------------------------
	// Executor unit tests.
	// -------------------------------------------------------------------------

	/**
	 * WP_MCP_AI_Tool_Async_Executor::cancel_job() should work without REST.
	 */
	public function test_executor_cancel_job_direct() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$job_id   = $this->plant_async_job( 'pending', $this->author_id );

		$result = $executor->cancel_job( $job_id, $this->author_id );
		$this->assertTrue( $result );

		$meta = get_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id );
		$this->assertSame( 'cancelled', $meta['status'] );
	}

	/**
	 * WP_MCP_AI_Tool_Async_Executor::retry_job() should work without REST.
	 */
	public function test_executor_retry_job_direct() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$job_id   = $this->plant_async_job( 'failed', $this->author_id );

		$result = $executor->retry_job( $job_id, $this->author_id );
		$this->assertSame( $job_id, $result );

		$meta = get_transient( WP_MCP_AI_Tool_Async_Executor::METADATA_TRANSIENT_PREFIX . $job_id );
		$this->assertSame( 'pending', $meta['status'] );
	}

	/**
	 * WP_MCP_AI_Tool_Async_Executor::is_owned_by() should return true for the owner.
	 */
	public function test_executor_is_owned_by() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$job_id   = $this->plant_async_job( 'pending', $this->author_id );

		$this->assertTrue( $executor->is_owned_by( $job_id, $this->author_id ) );
		$this->assertFalse( $executor->is_owned_by( $job_id, $this->subscriber_id ) );
		// Admin should always pass the ownership check.
		$this->assertTrue( $executor->is_owned_by( $job_id, $this->admin_id ) );
	}
}
