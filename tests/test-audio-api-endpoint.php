<?php
/**
 * Tests for the Audio API endpoint.
 */
class WP_MCP_AI_Audio_API_Endpoint_Test extends WP_UnitTestCase {

	/**
	 * Clean up global state after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the audio API endpoint is registered.
	 */
	public function test_audio_api_endpoint_is_registered() {
		$routes = rest_get_server()->get_routes();
		$namespace = WP_MCP_AI_REST::REST_NAMESPACE;
		$route = '/' . $namespace . '/audio/transcribe';

		$this->assertArrayHasKey( $route, $routes, 'Audio API endpoint should be registered' );
	}

	/**
	 * Test that the audio API endpoint requires authentication.
	 */
	public function test_audio_api_endpoint_requires_authentication() {
		// Ensure user is not authenticated.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/' . WP_MCP_AI_REST::REST_NAMESPACE . '/audio/transcribe' );
		$request->set_param( 'attachment_id', 123 );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status(), 'Should return 401 for unauthenticated requests' );
	}

	/**
	 * Test that the audio API endpoint validates attachment_id parameter.
	 */
	public function test_audio_api_endpoint_validates_attachment_id() {
		// Create a test user.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/' . WP_MCP_AI_REST::REST_NAMESPACE . '/audio/transcribe' );
		// No attachment_id parameter provided.

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status(), 'Should return 400 when attachment_id is missing' );
		$data = $response->get_data();
		$this->assertSame( 'wp_mcp_ai_missing_attachment', $data['code'], 'Should return correct error code' );
	}

	/**
	 * Test that the audio API class is properly instantiated.
	 */
	public function test_audio_api_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Audio_API' ), 'Audio API class should exist' );
	}

	/**
	 * Test that the audio API is available in container.
	 */
	public function test_audio_api_available_in_container() {
		$container = wp_mcp_ai_container();
		$this->assertTrue( $container->has( 'audio_api' ), 'Audio API should be registered in container' );
	}

	/**
	 * Test that the audio API instance is accessible globally.
	 */
	public function test_audio_api_global_instance() {
		$plugin = WP_MCP_AI::instance();
		$this->assertNotNull( $plugin->audio_api, 'Audio API instance should be set on plugin' );
		$this->assertInstanceOf( 'WP_MCP_AI_Audio_API', $plugin->audio_api, 'Should be instance of Audio API class' );
	}
}
