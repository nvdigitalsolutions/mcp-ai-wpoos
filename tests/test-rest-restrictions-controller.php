<?php
/**
 * Tests for the WP_MCP_AI_REST_Restrictions_Controller.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test restrictions REST endpoints and their permission model.
 */
class Test_REST_Restrictions_Controller extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->test_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		delete_option( WP_MCP_AI_Restriction_Registry::INDEX_OPTION );
		delete_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_user_meta( $this->test_user_id, WP_MCP_AI_Restriction_Registry::USER_META_KEY );
		delete_option( WP_MCP_AI_Restriction_Registry::INDEX_OPTION );
		delete_option( WP_MCP_AI_Restriction_Registry::NOTICE_OPTION );

		parent::tearDown();
	}

	/**
	 * Test routes are registered under the plugin namespace.
	 */
	public function test_routes_are_registered() {
		do_action( 'rest_api_init' );
		WP_MCP_AI_REST_Restrictions_Controller::register_routes();

		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );

		$this->assertArrayHasKey( '/mcp-ai/v1/restrictions', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/users/(?P<id>[\d]+)/restrictions', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/users/(?P<id>[\d]+)/restrictions/(?P<type>[a-z_]+)', $routes );
	}

	/**
	 * Test the listing endpoint returns active restrictions.
	 */
	public function test_get_restrictions_lists_active_rows() {
		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL,
			array( 'reason' => 'REST listing test' )
		);

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/restrictions' );
		$response = WP_MCP_AI_REST_Restrictions_Controller::get_restrictions( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $this->test_user_id, $data['rows'][0]['user_id'] );
	}

	/**
	 * Test the per-user endpoint returns records for the user themselves.
	 */
	public function test_get_user_restrictions_returns_own_records() {
		wp_set_current_user( $this->test_user_id );

		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/' . $this->test_user_id . '/restrictions' );
		$request->set_param( 'id', $this->test_user_id );
		$response = WP_MCP_AI_REST_Restrictions_Controller::get_user_restrictions( $request );
		$data     = $response->get_data();

		$this->assertSame( $this->test_user_id, $data['user_id'] );
		$this->assertArrayHasKey( WP_MCP_AI_Restriction_Registry::TYPE_MANUAL, $data['restrictions'] );
	}

	/**
	 * Test the per-user endpoint rejects unknown users.
	 */
	public function test_get_user_restrictions_rejects_missing_user() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/users/999999/restrictions' );
		$request->set_param( 'id', 999999 );
		$response = WP_MCP_AI_REST_Restrictions_Controller::get_user_restrictions( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_user_not_found', $response->get_error_code() );
	}

	/**
	 * Test the lift endpoint clears a restriction.
	 */
	public function test_lift_restriction_endpoint() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		WP_MCP_AI_Restriction_Registry::flag(
			$this->test_user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_MANUAL,
			array( 'reason' => 'REST lift test' )
		);

		$request = new WP_REST_Request(
			'DELETE',
			'/mcp-ai/v1/users/' . $this->test_user_id . '/restrictions/' . WP_MCP_AI_Restriction_Registry::TYPE_MANUAL
		);
		$request->set_param( 'id', $this->test_user_id );
		$request->set_param( 'type', WP_MCP_AI_Restriction_Registry::TYPE_MANUAL );
		$response = WP_MCP_AI_REST_Restrictions_Controller::lift_restriction( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['lifted'] );
		$this->assertFalse( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id ) );
	}

	/**
	 * Test the manual-block endpoint creates a restriction.
	 */
	public function test_add_manual_restriction_endpoint() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/users/' . $this->test_user_id . '/restrictions' );
		$request->set_param( 'id', $this->test_user_id );
		$request->set_param( 'reason', 'Manual REST block' );
		$request->set_param( 'expires_in', 3600 );

		$response = WP_MCP_AI_REST_Restrictions_Controller::add_manual_restriction( $request );
		$data     = $response->get_data();

		$this->assertSame( $this->test_user_id, $data['user_id'] );
		$this->assertSame( 'Manual REST block', $data['record']['reason'] );
		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $this->test_user_id, WP_MCP_AI_Restriction_Registry::TYPE_MANUAL ) );
	}

	/**
	 * Test the admin-only permission callback.
	 */
	public function test_admin_permission_callback() {
		wp_set_current_user( $this->test_user_id );
		$result = WP_MCP_AI_REST_Restrictions_Controller::check_admin_permission();
		$this->assertWPError( $result );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( WP_MCP_AI_REST_Restrictions_Controller::check_admin_permission() );
	}
}
