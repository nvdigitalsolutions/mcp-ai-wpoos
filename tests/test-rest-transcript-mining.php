<?php
/**
 * Tests for the transcript mining REST controller.
 *
 * Verifies the three endpoints under `/mcp-ai/v1/transcript-mining/`:
 * - POST /jobs (enqueue)
 * - GET  /jobs/{id} (poll)
 * - POST /jobs/{id}/cancel
 *
 * Permission gate is `manage_options`. WP REST nonce handling is exercised
 * implicitly by `WP_REST_Server::dispatch` — the explicit cap check on the
 * controller is what's being validated here.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for WP_MCP_AI_REST_Transcript_Mining_Controller.
 */
class Test_REST_Transcript_Mining_Controller extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Set up REST server and register routes.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;

		// Fire rest_api_init so the plugin controllers (including
		// WP_MCP_AI_REST, which instantiates the transcript-mining controller)
		// register their routes in the correct action context. Calling
		// register_rest_route() outside rest_api_init raises an
		// incorrect-usage notice.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wp_mcp_ai_tx_mine_job_' ) . '%'
			)
		);

		parent::tearDown();
	}

	/**
	 * The three routes are registered under the expected namespace.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes( 'mcp-ai/v1' );
		$this->assertArrayHasKey( '/mcp-ai/v1/transcript-mining/jobs', $routes );

		$found_show   = false;
		$found_cancel = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( false === strpos( $route, '/mcp-ai/v1/transcript-mining/jobs/' ) ) {
				continue;
			}
			if ( false !== strpos( $route, '/cancel' ) ) {
				$found_cancel = true;
			} else {
				$found_show = true;
			}
		}
		$this->assertTrue( $found_show, 'GET /jobs/{id} route registered' );
		$this->assertTrue( $found_cancel, 'POST /jobs/{id}/cancel route registered' );
	}

	/**
	 * A subscriber-level user must be rejected (manage_options gate).
	 */
	public function test_create_job_requires_manage_options() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/transcript-mining/jobs' );
		$request->set_param( 'agent_id', '8101' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Admin can enqueue and the response carries a job id + initial fields.
	 */
	public function test_admin_can_enqueue_and_poll_job() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/transcript-mining/jobs' );
		$request->set_param( 'agent_id', '8102' );
		$request->set_param( 'session_keys', array( 'sess_x', 'sess_y' ) );
		$request->set_param( 'batch_size', 2 );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertNotEmpty( $data['id'] );
		$this->assertSame( 'queued', $data['status'] );
		$this->assertSame( 2, $data['total'] );

		// Poll the same job.
		$poll = new WP_REST_Request( 'GET', '/mcp-ai/v1/transcript-mining/jobs/' . $data['id'] );
		$poll->set_url_params( array( 'id' => $data['id'] ) );
		$poll_response = $this->server->dispatch( $poll );
		$this->assertSame( 200, $poll_response->get_status() );
		$poll_data = $poll_response->get_data();
		$this->assertSame( $data['id'], $poll_data['id'] );
		$this->assertArrayHasKey( 'percent', $poll_data );
	}

	/**
	 * Polling an unknown id yields 404.
	 */
	public function test_get_unknown_job_returns_404() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/transcript-mining/jobs/does-not-exist' );
		$request->set_url_params( array( 'id' => 'does-not-exist' ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Cancel endpoint flips status to `cancelled`.
	 */
	public function test_admin_can_cancel_job() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$create = new WP_REST_Request( 'POST', '/mcp-ai/v1/transcript-mining/jobs' );
		$create->set_param( 'agent_id', '8103' );
		$create->set_param( 'session_keys', array( 'sess_z' ) );
		$create_resp = $this->server->dispatch( $create );
		$job_id      = $create_resp->get_data()['id'];

		$cancel = new WP_REST_Request( 'POST', '/mcp-ai/v1/transcript-mining/jobs/' . $job_id . '/cancel' );
		$cancel->set_url_params( array( 'id' => $job_id ) );
		$cancel_resp = $this->server->dispatch( $cancel );
		$this->assertSame( 200, $cancel_resp->get_status() );
		$this->assertSame( 'cancelled', $cancel_resp->get_data()['status'] );
	}

	/**
	 * Missing agent_id is rejected client-side.
	 */
	public function test_create_job_missing_agent_id_returns_400() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/transcript-mining/jobs' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}
}
