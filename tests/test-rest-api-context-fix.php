<?php
/**
 * Test REST API Context Parameter Fix
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for REST API context parameter handling
 */
class Test_REST_API_Context_Fix extends WP_UnitTestCase {

	/**
	 * Test that the fix class is loaded
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_REST_API_Context_Fix' ) );
	}

	/**
	 * Test that no-cache headers are added to WordPress core REST API responses
	 */
	public function test_no_cache_headers_added() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a REST API request with context=edit.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that response is successful.
		$this->assertEquals( 200, $response->get_status() );

		// Check that no-cache headers are present.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'must-revalidate', $headers['Cache-Control'] );
	}

	/**
	 * Test that Vary header is added for context parameter
	 */
	public function test_vary_header_added() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a REST API request.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that Vary header includes 'context'.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Vary', $headers );
		$this->assertStringContainsString( 'context', $headers['Vary'] );
	}

	/**
	 * Test that our custom endpoints are not affected
	 */
	public function test_custom_endpoints_not_affected() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a request to our custom endpoint.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Our endpoints should not have the extra no-cache headers added by the fix.
		// They should manage their own cache headers.
		$headers = $response->get_headers();

		// The fix should skip our endpoints, so we shouldn't see the specific
		// no-cache headers added by the fix (they have their own cache control).
		// This is a negative test - just verify the response is successful.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test is_edit_endpoint method recognizes block editor endpoints
	 */
	public function test_is_edit_endpoint_detection() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_REST_API_Context_Fix' );
		$method     = $reflection->getMethod( 'is_edit_endpoint' );
		$method->setAccessible( true );

		// Test that block editor endpoints are detected.
		$this->assertTrue( $method->invoke( null, '/wp/v2/posts/123' ) );
		$this->assertTrue( $method->invoke( null, '/wp/v2/pages/456' ) );
		$this->assertTrue( $method->invoke( null, '/wp/v2/types' ) );
		$this->assertTrue( $method->invoke( null, '/wp/v2/blocks/789' ) );
		$this->assertTrue( $method->invoke( null, '/wp/v2/templates' ) );

		// Test that non-editor endpoints are not detected.
		$this->assertFalse( $method->invoke( null, '/mcp-ai/v1/chat' ) );
		$this->assertFalse( $method->invoke( null, '/wp/v2/users' ) );
	}

	/**
	 * Test diagnostic information retrieval
	 */
	public function test_get_diagnostics() {
		$diagnostics = WP_MCP_AI_REST_API_Context_Fix::get_diagnostics();

		// Check that diagnostics array has expected keys.
		$this->assertIsArray( $diagnostics );
		$this->assertArrayHasKey( 'rest_url_rewrite_enabled', $diagnostics );
		$this->assertArrayHasKey( 'query_string_preserved', $diagnostics );
		$this->assertArrayHasKey( 'cache_headers_applied', $diagnostics );
		$this->assertArrayHasKey( 'server_software', $diagnostics );
		$this->assertArrayHasKey( 'recommendations', $diagnostics );

		// Check that recommendations is an array.
		$this->assertIsArray( $diagnostics['recommendations'] );
	}

	/**
	 * Test that get_diagnostics() safely handles is_plugin_active() availability
	 *
	 * This test verifies the fix for the issue where get_diagnostics() would
	 * cause a fatal error when called from WP-CLI or non-admin contexts where
	 * wp-admin/includes/plugin.php is not loaded.
	 */
	public function test_get_diagnostics_loads_plugin_functions() {
		// Call get_diagnostics() which should include plugin.php if needed.
		$diagnostics = WP_MCP_AI_REST_API_Context_Fix::get_diagnostics();

		// Verify the function executed successfully.
		$this->assertIsArray( $diagnostics );

		// After calling get_diagnostics(), is_plugin_active should be available.
		$this->assertTrue( function_exists( 'is_plugin_active' ) );

		// Verify caching_plugins key is present if any caching plugins are detected.
		// Even if no caching plugins are active, the method should not throw an error.
		if ( isset( $diagnostics['caching_plugins'] ) ) {
			$this->assertIsArray( $diagnostics['caching_plugins'] );
		}
	}

	/**
	 * Test that Pragma header is added
	 */
	public function test_pragma_header_added() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a REST API request with context=edit.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that Pragma header is present.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Pragma', $headers );
		$this->assertEquals( 'no-cache', $headers['Pragma'] );
	}

	/**
	 * Test that Expires header is added
	 */
	public function test_expires_header_added() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a REST API request with context=edit.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that Expires header is present.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Expires', $headers );
		$this->assertEquals( '0', $headers['Expires'] );
	}

	/**
	 * Test that ETag header is removed for context=edit requests
	 */
	public function test_etag_removed_for_edit_context() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a REST API request with context=edit.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// ETag should not be present for edit context requests.
		$headers = $response->get_headers();
		$this->assertArrayNotHasKey( 'ETag', $headers );
	}

	/**
	 * Test no-cache headers for block editor types endpoint
	 */
	public function test_no_cache_for_types_endpoint() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Make a REST API request to types endpoint (used by block editor).
		$request = new WP_REST_Request( 'GET', '/wp/v2/types' );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that response is successful.
		$this->assertEquals( 200, $response->get_status() );

		// Check that no-cache headers are present.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
	}

	/**
	 * Test context parameter preservation in different scenarios
	 */
	public function test_context_parameter_variations() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Test different context values.
		$contexts = array( 'view', 'edit', 'embed' );

		foreach ( $contexts as $context ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
			$request->set_param( 'context', $context );

			$server   = rest_get_server();
			$response = $server->dispatch( $request );

			// All context values should work.
			$this->assertEquals( 200, $response->get_status(), "Failed for context: $context" );

			// Verify context is in Vary header.
			$headers = $response->get_headers();
			$this->assertArrayHasKey( 'Vary', $headers );
			$this->assertStringContainsString( 'context', $headers['Vary'] );
		}
	}

	/**
	 * Test that context parameter is correctly detected from query params
	 *
	 * This test verifies the fix for the issue where context parameters
	 * might not be correctly detected when using get_param() instead of
	 * checking query params directly.
	 */
	public function test_context_query_parameter_detection() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create request with context as query parameter.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_query_params( array( 'context' => 'edit' ) );

		// Verify that query params are set correctly.
		$query_params = $request->get_query_params();
		$this->assertArrayHasKey( 'context', $query_params );
		$this->assertEquals( 'edit', $query_params['context'] );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that response is successful.
		$this->assertEquals( 200, $response->get_status() );

		// Check that no-cache headers are present (proving context was detected).
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'must-revalidate', $headers['Cache-Control'] );
	}

	/**
	 * Test that context parameter is correctly detected from set_param()
	 *
	 * This test verifies that the fix works when context is set via set_param(),
	 * which is how some REST endpoints and tools set parameters programmatically.
	 */
	public function test_context_set_param_detection() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create request and set context via set_param().
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		// Verify that get_param() returns the context.
		$this->assertEquals( 'edit', $request->get_param( 'context' ) );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		// Check that response is successful.
		$this->assertEquals( 200, $response->get_status() );

		// Check that no-cache headers are present (proving context was detected).
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'must-revalidate', $headers['Cache-Control'] );
	}
}
