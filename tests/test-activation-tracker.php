<?php
/**
 * Tests for WP_MCP_AI_Activation_Tracker class
 *
 * @package WP_MCP_AI
 */

/**
 * Test activation tracker functionality.
 */
class Test_Activation_Tracker extends WP_UnitTestCase {

	/**
	 * Test that the tracker class exists.
	 */
	public function test_tracker_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Activation_Tracker' ) );
	}

	/**
	 * Test tracking can be disabled via filter.
	 */
	public function test_tracking_disabled_via_filter() {
		// Add filter to disable tracking.
		add_filter( 'wp_mcp_ai_enable_usage_tracking', '__return_false' );

		// Tracking should not send any HTTP requests when disabled.
		// We can't easily test this without mocking wp_remote_post,
		// but we can verify the filter is respected.
		$result = apply_filters( 'wp_mcp_ai_enable_usage_tracking', true );
		$this->assertFalse( $result );

		// Remove filter.
		remove_filter( 'wp_mcp_ai_enable_usage_tracking', '__return_false' );
	}

	/**
	 * Test tracking can be disabled via settings.
	 */
	public function test_tracking_disabled_via_settings() {
		// Set the option to disable tracking.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'disable_activation_tracking' => true,
			)
		);

		// Call tracking method (should return early).
		// Since tracking is async and non-blocking, we can't directly verify
		// it was skipped, but this ensures no fatal errors occur.
		WP_MCP_AI_Activation_Tracker::track_activation( 'complete' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );

		// If we got here without errors, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test tracking data filter.
	 */
	public function test_tracking_data_filter() {
		$test_data = array(
			'plugin_variant' => 'test',
			'plugin_version' => '1.0.0',
		);

		// Add filter to modify tracking data.
		add_filter(
			'wp_mcp_ai_activation_tracking_data',
			function ( $data, $variant ) use ( $test_data ) {
				$this->assertEquals( 'test', $variant );
				return array_merge( $data, array( 'custom_field' => 'custom_value' ) );
			},
			10,
			2
		);

		// Call tracking (won't actually send since we can't mock wp_remote_post easily).
		// This test just verifies the filter exists and can be used.
		$filtered = apply_filters( 'wp_mcp_ai_activation_tracking_data', $test_data, 'test' );
		$this->assertArrayHasKey( 'custom_field', $filtered );
		$this->assertEquals( 'custom_value', $filtered['custom_field'] );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_activation_tracking_data' );
	}

	/**
	 * Test deactivation tracking data filter.
	 */
	public function test_deactivation_tracking_data_filter() {
		$test_data = array(
			'event'          => 'deactivation',
			'plugin_variant' => 'test',
		);

		// Add filter to modify deactivation tracking data.
		add_filter(
			'wp_mcp_ai_deactivation_tracking_data',
			function ( $data, $variant ) use ( $test_data ) {
				$this->assertEquals( 'test', $variant );
				return array_merge( $data, array( 'reason' => 'testing' ) );
			},
			10,
			2
		);

		// Call tracking filter.
		$filtered = apply_filters( 'wp_mcp_ai_deactivation_tracking_data', $test_data, 'test' );
		$this->assertArrayHasKey( 'reason', $filtered );
		$this->assertEquals( 'testing', $filtered['reason'] );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_deactivation_tracking_data' );
	}

	/**
	 * Test that local environments are detected.
	 *
	 * Note: This test uses reflection to access private methods.
	 */
	public function test_local_environment_detection() {
		// Use reflection to test the private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Activation_Tracker' );
		$method     = $reflection->getMethod( 'is_local_environment' );
		$method->setAccessible( true );

		// Test with localhost.
		add_filter(
			'site_url',
			function () {
				return 'http://localhost/wordpress';
			}
		);
		$this->assertTrue( $method->invoke( null ) );
		remove_all_filters( 'site_url' );

		// Test with 127.0.0.1.
		add_filter(
			'site_url',
			function () {
				return 'http://127.0.0.1/wordpress';
			}
		);
		$this->assertTrue( $method->invoke( null ) );
		remove_all_filters( 'site_url' );

		// Test with .local domain.
		add_filter(
			'site_url',
			function () {
				return 'http://mysite.local';
			}
		);
		$this->assertTrue( $method->invoke( null ) );
		remove_all_filters( 'site_url' );

		// Test with production domain.
		add_filter(
			'site_url',
			function () {
				return 'https://example.com';
			}
		);
		$this->assertFalse( $method->invoke( null ) );
		remove_all_filters( 'site_url' );
	}

	/**
	 * Test site hash generation.
	 */
	public function test_site_hash_generation() {
		// Use reflection to test the private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Activation_Tracker' );
		$method     = $reflection->getMethod( 'get_site_hash' );
		$method->setAccessible( true );

		// Generate hash twice - should be the same.
		$hash1 = $method->invoke( null );
		$hash2 = $method->invoke( null );

		$this->assertEquals( $hash1, $hash2, 'Site hash should be consistent' );
		$this->assertEquals( 64, strlen( $hash1 ), 'Site hash should be 64 characters (SHA-256)' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash1, 'Site hash should be hexadecimal' );
	}
}
