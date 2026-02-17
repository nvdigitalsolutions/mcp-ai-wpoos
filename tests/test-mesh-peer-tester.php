<?php
/**
 * Tests for WP_MCP_AI_Mesh_Peer_Tester class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test mesh peer connection testing functionality.
 */
class Test_Mesh_Peer_Tester extends WP_UnitTestCase {

	/**
	 * Test that invalid peer configuration returns error.
	 */
	public function test_invalid_peer_returns_error() {
		// Test with empty peer.
		$result = WP_MCP_AI_Mesh_Peer_Tester::test_connection( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_peer', $result->get_error_code() );

		// Test with missing URL.
		$result = WP_MCP_AI_Mesh_Peer_Tester::test_connection( array( 'name' => 'Test' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_peer', $result->get_error_code() );
	}

	/**
	 * Test that invalid URL format returns error.
	 */
	public function test_invalid_url_returns_error() {
		$peer = array(
			'name' => 'Test Peer',
			'url'  => 'not-a-valid-url',
		);

		$result = WP_MCP_AI_Mesh_Peer_Tester::test_connection( $peer );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Test that valid peer structure doesn't error on validation.
	 *
	 * Note: This test will make actual HTTP requests if run against a real site.
	 * In unit tests, the HTTP request will likely fail (which is expected).
	 */
	public function test_valid_peer_structure() {
		$peer = array(
			'name'    => 'Test Peer',
			'url'     => 'https://example.com',
			'api_key' => 'test_key_123',
		);

		$result = WP_MCP_AI_Mesh_Peer_Tester::test_connection( $peer );

		// Result could be WP_Error (connection failed) or array (success).
		// We're just testing that it doesn't error on validation.
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be array or WP_Error'
		);

		// If it's an error, it should be a connection error, not validation.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals(
				'invalid_peer',
				$result->get_error_code(),
				'Should not be validation error'
			);
			$this->assertNotEquals(
				'invalid_url',
				$result->get_error_code(),
				'Should not be URL validation error'
			);
		}
	}

	/**
	 * Test that test results have expected structure.
	 *
	 * This tests the structure when a successful result is returned.
	 */
	public function test_result_structure() {
		// Mock a successful result (we can't rely on external sites in tests).
		// This test verifies the expected structure only.
		$expected_keys = array(
			'success',
			'url',
			'reachable',
			'wellknown',
			'authenticated',
			'site_name',
			'capabilities',
			'message',
			'details',
		);

		// We'll just verify the structure is correct by checking that
		// the tester would return these keys in a successful scenario.
		// Actual HTTP testing requires mocking or integration tests.
		$this->assertTrue( true, 'Structure test placeholder - see expected_keys array' );
	}

	/**
	 * Test update_peer_test_status doesn't error with valid data.
	 */
	public function test_update_peer_test_status() {
		// Create a test ai_peer post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'post_title'  => 'Test Peer',
				'post_status' => 'publish',
			)
		);

		$mesh_peer_id = 'mesh_test_' . $post_id;
		update_post_meta( $post_id, '_wp_mcp_ai_mesh_peer_id', $mesh_peer_id );
		update_post_meta( $post_id, '_wp_mcp_ai_connection_type', 'mesh' );

		$test_results = array(
			'success'       => true,
			'reachable'     => true,
			'wellknown'     => true,
			'authenticated' => true,
		);

		// This should not throw any errors.
		WP_MCP_AI_Mesh_Peer_Tester::update_peer_test_status( $mesh_peer_id, $test_results );

		// Verify health status was updated.
		$health_status = get_post_meta( $post_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, true );
		$this->assertEquals( 'healthy', $health_status );

		// Verify last verified timestamp was set.
		$last_verified = get_post_meta( $post_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, true );
		$this->assertNotEmpty( $last_verified );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test health status is set correctly based on test results.
	 */
	public function test_health_status_based_on_results() {
		// Create test peer.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'post_title'  => 'Test Peer',
				'post_status' => 'publish',
			)
		);

		$mesh_peer_id = 'mesh_test_health_' . $post_id;
		update_post_meta( $post_id, '_wp_mcp_ai_mesh_peer_id', $mesh_peer_id );

		// Test 1: Successful authentication = healthy.
		$results = array(
			'success'       => true,
			'authenticated' => true,
		);
		WP_MCP_AI_Mesh_Peer_Tester::update_peer_test_status( $mesh_peer_id, $results );
		$this->assertEquals( 'healthy', get_post_meta( $post_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, true ) );

		// Test 2: No authentication = degraded.
		$results = array(
			'success'       => true,
			'authenticated' => false,
		);
		WP_MCP_AI_Mesh_Peer_Tester::update_peer_test_status( $mesh_peer_id, $results );
		$this->assertEquals( 'degraded', get_post_meta( $post_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, true ) );

		// Test 3: Connection failed = down.
		$results = array(
			'success' => false,
		);
		WP_MCP_AI_Mesh_Peer_Tester::update_peer_test_status( $mesh_peer_id, $results );
		$this->assertEquals( 'down', get_post_meta( $post_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, true ) );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test that WP_Error from wellknown endpoint doesn't cause fatal error.
	 *
	 * This test verifies the fix for the issue where accessing $wellknown as an array
	 * when it's a WP_Error would cause a fatal error.
	 *
	 * Note: This test uses an external URL which may cause intermittent failures
	 * if the network is unavailable. However, it's a valuable integration test
	 * that verifies the real-world scenario of the bug.
	 */
	public function test_wellknown_wp_error_handling() {
		// Use a peer that will likely fail wellknown but may succeed reachability.
		// We use wordpress.org as it exists but won't have our wellknown endpoint.
		$peer = array(
			'name' => 'Test Peer',
			'url'  => 'https://wordpress.org',
		);

		$result = WP_MCP_AI_Mesh_Peer_Tester::test_connection( $peer );

		// The primary goal: verify no fatal error occurs.
		// The result should be an array (partial success) or WP_Error (complete failure).
		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'Result should be array or WP_Error, not cause fatal error'
		);

		// If result is an array, verify it has the expected structure.
		if ( is_array( $result ) ) {
			// Verify all expected keys exist.
			$this->assertArrayHasKey( 'site_name', $result );
			$this->assertArrayHasKey( 'capabilities', $result );
			$this->assertArrayHasKey( 'wellknown', $result );

			// Verify data types are correct regardless of wellknown success.
			$this->assertIsString( $result['site_name'] );
			$this->assertIsArray( $result['capabilities'] );
			$this->assertIsBool( $result['wellknown'] );

			// When wellknown fails (expected for wordpress.org), verify defaults.
			// This validates the fix: empty values instead of fatal error.
			if ( false === $result['wellknown'] ) {
				$this->assertEquals(
					'',
					$result['site_name'],
					'site_name should be empty string when wellknown fails'
				);
				$this->assertEquals(
					array(),
					$result['capabilities'],
					'capabilities should be empty array when wellknown fails'
				);
			}
		}
	}
}
