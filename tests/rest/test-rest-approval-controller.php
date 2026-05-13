<?php
/**
 * Tests for WP_MCP_AI_REST_Approval_Controller.
 *
 * Exercises the HITL approval-queue REST surface (`/mcp-ai/v1/approvals*`)
 * through the WordPress REST server so permissions, sanitisation, and the
 * approve/deny transitions are validated end-to-end.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for the approval queue REST controller.
 */
class Test_REST_Approval_Controller extends WP_UnitTestCase {

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
	 * Subscriber user ID (used as approval requester).
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Other subscriber user ID (no relation to any approval).
	 *
	 * @var int
	 */
	protected $other_subscriber_id;

	/**
	 * Set up the REST server and test users.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the approval CPT is registered before the REST server boots.
		WP_MCP_AI_Approval_Queue::register_cpt();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Explicitly register the controller's routes; in production they are
		// wired during plugin bootstrap, but tests don't always reach that path.
		$controller = new WP_MCP_AI_REST_Approval_Controller();
		$controller->register_routes();

		$this->admin_id            = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down REST server.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper: enqueue a pending approval owned by the given requester.
	 *
	 * @param int $requester_id Requester user ID.
	 * @return int Approval post ID.
	 */
	private function enqueue_approval( $requester_id ) {
		$queue = WP_MCP_AI_Approval_Queue::get_instance();
		$id    = $queue->enqueue(
			array(
				'tool'         => 'delete_post',
				'arguments'    => array( 'post_id' => 123 ),
				'assistant_id' => 0,
				'requester_id' => $requester_id,
				'session_id'   => 'session-test',
				'reason'       => 'Test approval',
			)
		);
		$this->assertIsInt( $id, 'Approval enqueue should return an integer post ID.' );
		return $id;
	}

	// -------------------------------------------------------------------------
	// Route registration.
	// -------------------------------------------------------------------------

	/**
	 * The four HITL routes must be registered.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/approvals', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/approvals/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/approvals/(?P<id>[\d]+)/approve', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/approvals/(?P<id>[\d]+)/deny', $routes );
	}

	// -------------------------------------------------------------------------
	// Permission gates: list (manage_options).
	// -------------------------------------------------------------------------

	/**
	 * GET /approvals must reject anonymous users.
	 */
	public function test_get_items_rejects_anonymous() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/approvals' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * GET /approvals must reject subscriber-level users.
	 */
	public function test_get_items_rejects_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/approvals' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * GET /approvals returns the pending list to admins.
	 */
	public function test_get_items_returns_pending_for_admin() {
		$this->enqueue_approval( $this->subscriber_id );
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/approvals' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'approvals', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertGreaterThanOrEqual( 1, $data['total'] );
	}

	// -------------------------------------------------------------------------
	// Permission gates: single-item read (admin OR requester-self).
	// -------------------------------------------------------------------------

	/**
	 * GET /approvals/{id} permits the original requester even without manage_options.
	 */
	public function test_get_item_permits_requester_self() {
		$id = $this->enqueue_approval( $this->subscriber_id );
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/approvals/' . $id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$record = $response->get_data();
		$this->assertSame( $id, (int) $record['id'] );
	}

	/**
	 * GET /approvals/{id} rejects an unrelated subscriber.
	 */
	public function test_get_item_rejects_unrelated_subscriber() {
		$id = $this->enqueue_approval( $this->subscriber_id );
		wp_set_current_user( $this->other_subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/approvals/' . $id );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Approve / deny flows (manage_options).
	// -------------------------------------------------------------------------

	/**
	 * POST /approvals/{id}/approve transitions the approval to approved.
	 */
	public function test_approve_item_succeeds_for_admin() {
		$id = $this->enqueue_approval( $this->subscriber_id );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/approvals/' . $id . '/approve' );
		$request->set_param( 'note', 'Looks good' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 'approved', $body['status'] );
		$this->assertSame( $id, $body['approval_id'] );
	}

	/**
	 * POST /approvals/{id}/deny transitions the approval to denied.
	 */
	public function test_deny_item_succeeds_for_admin() {
		$id = $this->enqueue_approval( $this->subscriber_id );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/approvals/' . $id . '/deny' );
		$request->set_param( 'note', 'Too risky' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 'denied', $body['status'] );
	}

	/**
	 * Subscribers cannot approve, even for their own requests.
	 */
	public function test_approve_item_rejects_subscriber_self() {
		$id = $this->enqueue_approval( $this->subscriber_id );
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/approvals/' . $id . '/approve' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * Approving a non-existent approval returns a 4xx error.
	 */
	public function test_approve_item_with_unknown_id_returns_error() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/approvals/999999/approve' );
		$response = $this->server->dispatch( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * GET /approvals/{id} for a non-existent approval as admin returns 404.
	 */
	public function test_get_item_returns_404_for_unknown_id() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/approvals/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'approval_not_found', $response->as_error()->get_error_code() );
	}
}
