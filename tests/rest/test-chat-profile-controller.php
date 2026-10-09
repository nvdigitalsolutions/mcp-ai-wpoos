<?php
/**
 * Tests for WP_MCP_AI_REST_Chat_Profile_Controller — the chat-profile bridge
 * exposing the catalogue (GET) and the profile switch (POST) under
 * `/mcp-ai/v1/chat-profile` (proposal 015, read-only mode).
 *
 * The route is deliberately not a registered tool so the agent can never
 * widen its own grant mid-run; the POST surface enforces the same
 * downgrade/upgrade policy as the manager.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Chat profile REST controller test suite.
 *
 * @group chat-profile
 */
class Test_REST_Chat_Profile_Controller extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up: fresh REST server, registered routes, fixture users, caches.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Force-register the controller's routes so dispatch() resolves them
		// even if bootstrap didn't wire them in this test context.
		$controller = new WP_MCP_AI_REST_Chat_Profile_Controller();
		$controller->register_routes();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
	}

	/**
	 * Tear down: restore server, current user, settings and caches.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings_Base::get_default_settings() );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
		WP_MCP_AI_Chat_Profile_Registry::reset_cache();

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Find a profile entry in the response data by slug.
	 *
	 * @param array  $data Response data.
	 * @param string $slug Profile slug.
	 * @return array|null
	 */
	private function find_profile( array $data, $slug ) {
		foreach ( $data['profiles'] as $profile ) {
			if ( $slug === $profile['slug'] ) {
				return $profile;
			}
		}
		return null;
	}

	/**
	 * Disable the feature the way the admin UI persists settings.
	 *
	 * @return void
	 */
	private function disable_feature() {
		$settings                         = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$settings['chat_profile_enabled'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		WP_MCP_AI_Chat_Profile_Manager::reset_cache();
	}

	// -------------------------------------------------------------------------
	// Route registration + permission gate.
	// -------------------------------------------------------------------------

	/**
	 * The chat-profile route is registered for GET and POST.
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/chat-profile', $routes );
		$this->assertTrue( is_array( $routes['/mcp-ai/v1/chat-profile'] ) );
	}

	/**
	 * Anonymous users are rejected with 401.
	 */
	public function test_check_permission_rejects_anonymous() {
		wp_set_current_user( 0 );

		$controller = new WP_MCP_AI_REST_Chat_Profile_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-profile' );

		$result = $controller->check_permission( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Any logged-in user passes the permission gate.
	 */
	public function test_check_permission_allows_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$controller = new WP_MCP_AI_REST_Chat_Profile_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-profile' );

		$this->assertTrue( $controller->check_permission( $request ) );
	}

	// -------------------------------------------------------------------------
	// GET /chat-profile.
	// -------------------------------------------------------------------------

	/**
	 * GET returns the catalogue, the resolved profile, and the enabled flag.
	 *
	 * A subscriber cannot select write (upgrade) but can select read-only.
	 */
	public function test_get_state_as_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-profile' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'write', $data['current'] );

		$write     = $this->find_profile( $data, 'write' );
		$read_only = $this->find_profile( $data, 'read-only' );

		$this->assertNotNull( $write );
		$this->assertNotNull( $read_only );
		$this->assertFalse( $write['selectable'], 'Subscribers must not select the write profile.' );
		$this->assertTrue( $read_only['selectable'] );
		$this->assertSame( 'Full access', $write['label'] );
		$this->assertSame( 'Read-only', $read_only['label'] );
	}

	/**
	 * An administrator may select every profile, including write.
	 */
	public function test_get_state_as_admin() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-profile' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data  = $response->get_data();
		$write = $this->find_profile( $data, 'write' );

		$this->assertTrue( $write['selectable'] );
	}

	/**
	 * An anonymous dispatch is rejected with 401.
	 */
	public function test_get_state_anonymous_rejected() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-profile' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// POST /chat-profile.
	// -------------------------------------------------------------------------

	/**
	 * A subscriber may downgrade to read-only; the selection persists.
	 */
	public function test_post_downgrade_as_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-profile' );
		$request->set_param( 'profile', 'read-only' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'read-only', $response->get_data()['current'] );
		$this->assertSame(
			'read-only',
			get_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true )
		);
	}

	/**
	 * A subscriber may not upgrade to write — 403 with the policy code.
	 */
	public function test_post_upgrade_as_subscriber_forbidden() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-profile' );
		$request->set_param( 'profile', 'write' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wp_mcp_ai_chat_profile_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( '', get_user_meta( $this->subscriber_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true ) );
	}

	/**
	 * An unknown profile slug is rejected with 400.
	 */
	public function test_post_unknown_profile_rejected() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-profile' );
		$request->set_param( 'profile', 'bogus' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wp_mcp_ai_chat_profile_invalid', $response->as_error()->get_error_code() );
	}

	/**
	 * A missing profile param is rejected by the REST validator with 400.
	 */
	public function test_post_missing_profile_rejected() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-profile' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->as_error()->get_error_code() );
	}

	/**
	 * An administrator switching to the site default (write) clears the
	 * personal override meta.
	 */
	public function test_post_admin_default_clears_meta() {
		update_user_meta( $this->admin_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, 'read-only' );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-profile' );
		$request->set_param( 'profile', 'write' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'write', $response->get_data()['current'] );
		$this->assertSame( '', get_user_meta( $this->admin_id, WP_MCP_AI_Chat_Profile_Manager::META_KEY, true ) );
	}

	/**
	 * While the feature is disabled, GET reports disabled and POST refuses.
	 */
	public function test_feature_disabled_gates_surface() {
		$this->disable_feature();
		wp_set_current_user( $this->admin_id );

		$get_request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-profile' );
		$get_response = $this->server->dispatch( $get_request );
		$this->assertSame( 200, $get_response->get_status() );
		$this->assertFalse( $get_response->get_data()['enabled'] );

		$post_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-profile' );
		$post_request->set_param( 'profile', 'read-only' );
		$post_response = $this->server->dispatch( $post_request );

		$this->assertSame( 403, $post_response->get_status() );
		$this->assertSame( 'wp_mcp_ai_chat_profile_disabled', $post_response->as_error()->get_error_code() );
	}
}
