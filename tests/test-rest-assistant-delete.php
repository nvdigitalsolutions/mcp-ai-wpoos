<?php
/**
 * Tests for the assistant DELETE REST endpoint.
 */
class WP_MCP_AI_REST_Assistant_Delete_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID for testing.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Deletion',
			)
		);

		// Bootstrap REST controller.
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller with dependencies.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mock LM router.
	 */
	private function bootstrap_rest_controller( $mock_client ) {
		$registry  = wp_mcp_ai_get_registry();
		$container = wp_mcp_ai_container();

		$authenticator = new WP_MCP_AI_REST_Authenticator();
		$validator     = new WP_MCP_AI_REST_Validator();

		$main_controller = new WP_MCP_AI_REST( $registry, $mock_client, $authenticator, $validator );
		$mcp_controller  = new WP_MCP_AI_REST_MCP_Controller( $main_controller, $authenticator, $validator );

		$mcp_controller->register_routes();
	}

	/**
	 * Test that DELETE endpoint is blocked when setting is disabled.
	 */
	public function test_delete_blocked_when_setting_disabled() {
		// Ensure the setting is disabled (default).
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $this->assistant_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_assistant_delete_disabled', $data['code'] );

		// Verify assistant still exists.
		$post = get_post( $this->assistant_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'publish', $post->post_status );
	}

	/**
	 * Test that DELETE endpoint works when setting is enabled.
	 */
	public function test_delete_succeeds_when_setting_enabled() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $this->assistant_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertTrue( $data['deleted'] );
		$this->assertArrayHasKey( 'previous', $data );
		$this->assertSame( $this->assistant_id, $data['previous']['id'] );
		$this->assertSame( 'Test Assistant for Deletion', $data['previous']['title'] );

		// Verify assistant no longer exists.
		$post = get_post( $this->assistant_id );
		$this->assertNull( $post );
	}

	/**
	 * Test that DELETE endpoint returns 404 for non-existent assistant.
	 */
	public function test_delete_returns_404_for_nonexistent_assistant() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$nonexistent_id = 99999;
		$request        = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $nonexistent_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_assistant_invalid_id', $data['code'] );
	}

	/**
	 * Test that DELETE endpoint returns 404 for wrong post type.
	 */
	public function test_delete_returns_404_for_wrong_post_type() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create a regular post instead of assistant.
		$regular_post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Regular Post',
			)
		);

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $regular_post_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_assistant_invalid_id', $data['code'] );

		// Verify post still exists.
		$post = get_post( $regular_post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
	}

	/**
	 * Test that DELETE endpoint requires authentication.
	 */
	public function test_delete_requires_authentication() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Clear current user (unauthenticated).
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $this->assistant_id );
		// No authentication headers.

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 401, $response->get_status() );

		// Verify assistant still exists.
		$post = get_post( $this->assistant_id );
		$this->assertInstanceOf( WP_Post::class, $post );
	}

	/**
	 * Test that DELETE endpoint checks delete_post capability.
	 */
	public function test_delete_checks_user_capability() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create a subscriber user (no delete_post capability).
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $this->assistant_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_cannot_delete', $data['code'] );

		// Verify assistant still exists.
		$post = get_post( $this->assistant_id );
		$this->assertInstanceOf( WP_Post::class, $post );
	}

	/**
	 * Test that wp_mcp_ai_rest_assistant_deleted action fires.
	 */
	public function test_delete_fires_action_hook() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_delete'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$action_fired   = false;
		$action_post    = null;
		$action_request = null;

		add_action(
			'wp_mcp_ai_rest_assistant_deleted',
			function ( $post, $request ) use ( &$action_fired, &$action_post, &$action_request ) {
				$action_fired   = true;
				$action_post    = $post;
				$action_request = $request;
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $this->assistant_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $action_fired, 'wp_mcp_ai_rest_assistant_deleted action should fire' );
		$this->assertInstanceOf( WP_Post::class, $action_post );
		$this->assertSame( $this->assistant_id, $action_post->ID );
		$this->assertInstanceOf( WP_REST_Request::class, $action_request );
	}
}
