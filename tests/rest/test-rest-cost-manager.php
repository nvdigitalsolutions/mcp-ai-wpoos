<?php
/**
 * Tests for WP_MCP_AI_REST_Cost_Manager.
 *
 * Focuses on the permission gates and validation callbacks. Data-fetch
 * methods rely on cost-tracking infrastructure that is not seeded in the
 * unit-test environment, so this suite exercises only the auth/validation
 * surface — which is what determines whether the cost endpoints are
 * actually reachable.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the cost-manager REST permission/validation surface.
 */
class Test_REST_Cost_Manager extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID (acts as "self" cost owner).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Other subscriber (no relation to the request).
	 *
	 * @var int
	 */
	private $other_subscriber_id;

	/**
	 * Set up users.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id            = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// check_admin_permission().
	// -------------------------------------------------------------------------

	/**
	 * Anonymous users must be rejected from admin endpoints.
	 */
	public function test_check_admin_permission_rejects_anonymous() {
		wp_set_current_user( 0 );

		$result = WP_MCP_AI_REST_Cost_Manager::check_admin_permission();

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Subscribers must be rejected from admin endpoints.
	 */
	public function test_check_admin_permission_rejects_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$result = WP_MCP_AI_REST_Cost_Manager::check_admin_permission();

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Administrators are admitted.
	 */
	public function test_check_admin_permission_allows_admin() {
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( WP_MCP_AI_REST_Cost_Manager::check_admin_permission() );
	}

	// -------------------------------------------------------------------------
	// check_cost_access_permission() — admin OR self.
	// -------------------------------------------------------------------------

	/**
	 * Anonymous users get 401 (must be logged in to access cost data at all).
	 */
	public function test_check_cost_access_permission_returns_401_for_anonymous() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/123/cost-breakdown' );
		$request->set_param( 'id', $this->subscriber_id );

		$result = WP_MCP_AI_REST_Cost_Manager::check_cost_access_permission( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Admins can read any user's cost breakdown.
	 */
	public function test_check_cost_access_permission_allows_admin_for_any_user() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request();
		$request->set_param( 'id', $this->subscriber_id );

		$this->assertTrue(
			WP_MCP_AI_REST_Cost_Manager::check_cost_access_permission( $request )
		);
	}

	/**
	 * A subscriber may read their own breakdown.
	 */
	public function test_check_cost_access_permission_allows_self() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request();
		$request->set_param( 'id', $this->subscriber_id );

		$this->assertTrue(
			WP_MCP_AI_REST_Cost_Manager::check_cost_access_permission( $request )
		);
	}

	/**
	 * A subscriber may NOT read another user's breakdown.
	 */
	public function test_check_cost_access_permission_rejects_other_user() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request();
		$request->set_param( 'id', $this->other_subscriber_id );

		$result = WP_MCP_AI_REST_Cost_Manager::check_cost_access_permission( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// validate_user_id().
	// -------------------------------------------------------------------------

	/**
	 * Existing user IDs are accepted.
	 */
	public function test_validate_user_id_accepts_existing_user() {
		$request = new WP_REST_Request();

		$this->assertTrue(
			WP_MCP_AI_REST_Cost_Manager::validate_user_id( $this->subscriber_id, $request, 'id' )
		);
	}

	/**
	 * Non-existent user IDs are rejected.
	 */
	public function test_validate_user_id_rejects_unknown_user() {
		$request = new WP_REST_Request();

		$this->assertFalse(
			WP_MCP_AI_REST_Cost_Manager::validate_user_id( 999999, $request, 'id' )
		);
	}

	// -------------------------------------------------------------------------
	// Route registration.
	// -------------------------------------------------------------------------

	/**
	 * Registering routes wires the documented endpoints to the REST server.
	 */
	public function test_register_routes_wires_endpoints() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		WP_MCP_AI_REST_Cost_Manager::register_routes();

		$routes = $wp_rest_server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/cost/total', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/cost/by-provider', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/cost/trend', $routes );

		$wp_rest_server = null;
	}
}
