<?php
/**
 * Test Remote Site Manager ID normalization.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager ID normalization.
 */
class Test_Remote_Site_Manager_ID_Normalization extends WP_UnitTestCase {

	/**
	 * Test that connection IDs are normalized to lowercase.
	 */
	public function test_connection_id_normalization() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Create a connection with mixed-case ID directly in the database
		// to simulate the old behavior.
		$mixed_case_id = 'conn_AbCdEfGhIjKl';
		$connections = array(
			$mixed_case_id => array(
				'id'              => $mixed_case_id,
				'name'            => 'Test Connection',
				'url'             => 'https://example.com',
				'auth_type'       => 'none',
				'username'        => '',
				'password'        => '',
				'token'           => '',
				'consumer_key'    => '',
				'consumer_secret' => '',
				'has_woocommerce' => false,
				'enabled'         => true,
				'created'         => current_time( 'mysql' ),
				'updated'         => current_time( 'mysql' ),
			),
		);

		update_option( 'wp_mcp_ai_pro_remote_sites', $connections );

		// Get all connections - this should trigger migration.
		$retrieved = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		// Check that the connection key is now lowercase.
		$expected_lowercase_id = strtolower( $mixed_case_id );
		$this->assertArrayHasKey( $expected_lowercase_id, $retrieved, 'Connection key should be lowercase' );
		$this->assertArrayNotHasKey( $mixed_case_id, $retrieved, 'Original mixed-case key should not exist' );

		// Check that the ID field is also lowercase.
		$this->assertEquals( $expected_lowercase_id, $retrieved[ $expected_lowercase_id ]['id'], 'ID field should be lowercase' );

		// Verify we can retrieve the connection using the lowercase ID.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $expected_lowercase_id );
		$this->assertNotNull( $connection, 'Connection should be retrievable with lowercase ID' );
		$this->assertEquals( 'Test Connection', $connection['name'], 'Connection name should match' );

		// Clean up.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test that new connection IDs are generated in lowercase.
	 */
	public function test_new_connection_ids_are_lowercase() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Clear any existing connections.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		// Create a new connection.
		$connection_data = array(
			'name'            => 'New Test Connection',
			'url'             => 'https://newexample.com',
			'auth_type'       => 'none',
			'username'        => '',
			'password'        => '',
			'token'           => '',
			'consumer_key'    => '',
			'consumer_secret' => '',
			'has_woocommerce' => false,
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Verify the connection ID is a string.
		$this->assertIsString( $connection_id, 'Connection ID should be a string' );

		// Verify the connection ID is lowercase.
		$this->assertEquals( strtolower( $connection_id ), $connection_id, 'New connection ID should be lowercase' );

		// Verify the connection ID starts with 'conn_'.
		$this->assertStringStartsWith( 'conn_', $connection_id, 'Connection ID should start with conn_' );

		// Clean up.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Test that sanitize_key matches the stored connection IDs.
	 */
	public function test_sanitize_key_matches_stored_ids() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Clear any existing connections.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		// Create a new connection.
		$connection_data = array(
			'name'            => 'Sanitize Test Connection',
			'url'             => 'https://sanitizetest.com',
			'auth_type'       => 'none',
			'has_woocommerce' => false,
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		// Simulate what happens when the ID comes from $_GET (with sanitize_key).
		$sanitized_id = sanitize_key( $connection_id );

		// Verify that sanitize_key doesn't change the ID.
		$this->assertEquals( $connection_id, $sanitized_id, 'sanitize_key should not change the connection ID' );

		// Verify we can retrieve the connection using the sanitized ID.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $sanitized_id );
		$this->assertNotNull( $connection, 'Connection should be retrievable with sanitized ID' );

		// Clean up.
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}
}
