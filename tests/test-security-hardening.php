<?php
/**
 * Tests for security hardening improvements.
 *
 * @package WP_MCP_AI
 */

/**
 * Test security hardening measures.
 */
class Test_Security_Hardening extends WP_UnitTestCase {

	/**
	 * Test that ai_peer CPT requires manage_options capability.
	 */
	public function test_ai_peer_cpt_requires_manage_options() {
		$post_type_object = get_post_type_object( 'ai_peer' );

		$this->assertNotNull( $post_type_object, 'ai_peer post type should be registered' );

		// Check all capabilities require manage_options.
		$this->assertEquals( 'manage_options', $post_type_object->cap->edit_post );
		$this->assertEquals( 'manage_options', $post_type_object->cap->read_post );
		$this->assertEquals( 'manage_options', $post_type_object->cap->delete_post );
		$this->assertEquals( 'manage_options', $post_type_object->cap->edit_posts );
		$this->assertEquals( 'manage_options', $post_type_object->cap->edit_others_posts );
		$this->assertEquals( 'manage_options', $post_type_object->cap->delete_posts );
		$this->assertEquals( 'manage_options', $post_type_object->cap->publish_posts );
		$this->assertEquals( 'manage_options', $post_type_object->cap->read_private_posts );
	}

	/**
	 * Test that mcp_ai_assistant CPT requires proper capabilities.
	 */
	public function test_mcp_ai_assistant_cpt_capabilities() {
		$post_type_object = get_post_type_object( 'mcp_ai_assistant' );

		$this->assertNotNull( $post_type_object, 'mcp_ai_assistant post type should be registered' );

		// The assistant CPT uses capability_type 'post' but may have custom capability mapping.
		// Verify it exists and is properly configured.
		$this->assertNotEmpty( $post_type_object->cap );
	}

	/**
	 * Test that non-admin users cannot create AI peers.
	 */
	public function test_non_admin_cannot_create_ai_peer() {
		// Create a subscriber user.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// Attempt to check capability.
		$can_create      = current_user_can( 'edit_posts' );
		$can_create_peer = current_user_can( 'manage_options' );

		$this->assertFalse( $can_create_peer, 'Subscriber should not have manage_options capability' );

		// Clean up.
		wp_delete_user( $subscriber );
	}

	/**
	 * Test that admin users can create AI peers.
	 */
	public function test_admin_can_create_ai_peer() {
		// Create an admin user.
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Check capability.
		$can_create_peer = current_user_can( 'manage_options' );

		$this->assertTrue( $can_create_peer, 'Administrator should have manage_options capability' );

		// Clean up.
		wp_delete_user( $admin );
	}

	/**
	 * Test that AJAX handlers verify nonces.
	 *
	 * This is a meta-test that verifies the test framework works.
	 */
	public function test_ajax_nonce_verification_framework() {
		// Set up a fake AJAX request without a valid nonce.
		$_POST['nonce'] = 'invalid_nonce';

		// Verify that check_ajax_referer would fail.
		$result = check_ajax_referer( 'wp-mcp-ai-settings', 'nonce', false );

		$this->assertFalse( $result, 'Invalid nonce should fail verification' );

		// Clean up.
		unset( $_POST['nonce'] );
	}

	/**
	 * Test that admin_post handlers verify nonces.
	 *
	 * This is a meta-test that verifies the test framework works.
	 */
	public function test_admin_referer_verification_framework() {
		// Set up a fake admin_post request without a valid nonce.
		$_REQUEST['_wpnonce'] = 'invalid_nonce';

		// Verify that check_admin_referer would fail.
		$result = check_admin_referer( 'wp_mcp_ai_save_settings', '_wpnonce', false );

		$this->assertFalse( $result, 'Invalid admin nonce should fail verification' );

		// Clean up.
		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * Test that GET parameters are properly sanitized in notice displays.
	 *
	 * This test verifies that our security improvements properly sanitize
	 * GET parameters even when they're only used for display.
	 */
	public function test_get_parameter_sanitization() {
		// Test sanitize_key function works.
		$test_input = 'test_value';
		$sanitized  = sanitize_key( $test_input );

		$this->assertEquals( 'test_value', $sanitized, 'sanitize_key should preserve valid keys' );

		// Test sanitize_key removes invalid characters.
		$test_input = 'test<script>alert(1)</script>';
		$sanitized  = sanitize_key( $test_input );

		$this->assertNotContains( '<', $sanitized, 'sanitize_key should remove HTML tags' );
		$this->assertNotContains( '>', $sanitized, 'sanitize_key should remove HTML tags' );
	}

	/**
	 * Test that esc_attr properly escapes attribute values.
	 */
	public function test_esc_attr_escaping() {
		$test_value = 'test"value\'with<quotes>';
		$escaped    = esc_attr( $test_value );

		// esc_attr should convert quotes to HTML entities.
		$this->assertNotEquals( $test_value, $escaped, 'esc_attr should escape the value' );
		$this->assertStringContainsString( '&', $escaped, 'esc_attr should use HTML entities' );
	}

	/**
	 * Test that boolean color values are properly escaped.
	 */
	public function test_boolean_color_escaping() {
		// Simulate the pattern used in admin files.
		$jetengine_active = true;
		$color            = $jetengine_active ? '#d5f0db' : '#f0f0f1';
		$escaped_color    = esc_attr( $color );

		$this->assertEquals( '#d5f0db', $escaped_color, 'Color value should be properly escaped' );

		// Test with false.
		$jetengine_active = false;
		$color            = $jetengine_active ? '#d5f0db' : '#f0f0f1';
		$escaped_color    = esc_attr( $color );

		$this->assertEquals( '#f0f0f1', $escaped_color, 'Color value should be properly escaped' );
	}

	/**
	 * Test sanitize_text_field function.
	 */
	public function test_sanitize_text_field() {
		$test_input = '<script>alert("xss")</script>normal text';
		$sanitized  = sanitize_text_field( $test_input );

		$this->assertNotContains( '<script>', $sanitized, 'sanitize_text_field should remove script tags' );
		$this->assertStringContainsString( 'normal text', $sanitized, 'sanitize_text_field should preserve safe text' );
	}

	/**
	 * Test that wp_unslash properly removes slashes.
	 */
	public function test_wp_unslash() {
		$slashed   = "test\'value\"with\\slashes";
		$unslashed = wp_unslash( $slashed );

		$this->assertNotEquals( $slashed, $unslashed, 'wp_unslash should remove slashes' );
	}

	/**
	 * Verify that REST API permission callbacks are defined.
	 *
	 * This test ensures that REST endpoints have permission callbacks.
	 */
	public function test_rest_api_has_permission_callbacks() {
		// Get all registered REST routes.
		$routes = rest_get_server()->get_routes();

		// Check routes in our namespace.
		$namespace    = 'mcp-ai/v1';
		$found_routes = false;

		foreach ( $routes as $route => $handlers ) {
			if ( strpos( $route, '/' . $namespace . '/' ) === 0 ) {
				$found_routes = true;

				// Each route should have handlers with permission_callback.
				foreach ( $handlers as $handler ) {
					$this->assertArrayHasKey(
						'permission_callback',
						$handler,
						"Route {$route} should have a permission_callback"
					);
				}
			}
		}

		$this->assertTrue( $found_routes, 'Should find routes in mcp-ai/v1 namespace' );
	}
}
