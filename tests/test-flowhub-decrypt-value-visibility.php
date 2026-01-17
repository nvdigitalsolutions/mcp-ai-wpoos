<?php
/**
 * Test Flowhub Client can access decrypt_value() method.
 *
 * This test verifies the fix for the fatal error:
 * "Call to protected method WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value()
 * from scope WP_MCP_AI_Flowhub_Client"
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Flowhub client decrypt_value visibility.
 */
class Test_Flowhub_Decrypt_Value_Visibility extends WP_UnitTestCase {

	/**
	 * Test that decrypt_value() is publicly accessible from Flowhub client.
	 */
	public function test_flowhub_can_call_decrypt_value() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		// Create a test connection with encrypted api_key.
		$test_api_key  = 'test_flowhub_key_12345';
		$encrypted_key = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $test_api_key );

		$connection_data = array(
			'name'               => 'Test Flowhub Connection',
			'connection_type'    => 'flowhub',
			'client_id'          => 'test_client_id',
			'api_key'            => $encrypted_key,
			'_api_key_encrypted' => true, // Prevent re-encryption
			'location_id'        => 'test_location',
			'enabled'            => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id, 'Connection ID should be a string' );

		// Create a Flowhub client with this connection.
		$client = new WP_MCP_AI_Flowhub_Client( $connection_id );

		// This should not throw a fatal error anymore.
		$decrypted_key = $client->get_key( $connection_id );

		// Verify the key was decrypted correctly.
		$this->assertEquals( $test_api_key, $decrypted_key, 'Decrypted key should match original' );

		// Clean up.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
	}

	/**
	 * Test that encrypt_value() is publicly accessible.
	 */
	public function test_encrypt_value_is_public() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// This should not throw a fatal error.
		$test_value = 'sensitive_data_12345';
		$encrypted  = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $test_value );

		$this->assertNotEmpty( $encrypted, 'Encrypted value should not be empty' );
		$this->assertNotEquals( $test_value, $encrypted, 'Encrypted value should be different from original' );
	}

	/**
	 * Test that decrypt_value() is publicly accessible.
	 */
	public function test_decrypt_value_is_public() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Test encrypt/decrypt cycle.
		$test_value = 'sensitive_data_12345';
		$encrypted  = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $test_value );

		// This should not throw a fatal error.
		$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $encrypted );

		$this->assertEquals( $test_value, $decrypted, 'Decrypted value should match original' );
	}

	/**
	 * Test that Payhere client can also call decrypt_value().
	 */
	public function test_payhere_can_call_decrypt_value() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Payhere_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-payhere-client.php';
		}

		// Create a test connection with encrypted app_secret.
		$test_secret      = 'test_payhere_secret_67890';
		$encrypted_secret = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $test_secret );

		$connection_data = array(
			'name'                  => 'Test Payhere Connection',
			'connection_type'       => 'payhere',
			'app_id'                => 'test_app_id',
			'app_secret'            => $encrypted_secret,
			'_app_secret_encrypted' => true, // Prevent re-encryption
			'enabled'               => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertIsString( $connection_id, 'Connection ID should be a string' );

		// Create a Payhere client with this connection.
		$client = new WP_MCP_AI_Payhere_Client( $connection_id );

		// This should not throw a fatal error anymore.
		$decrypted_secret = $client->get_app_secret( $connection_id );

		// Verify the secret was decrypted correctly.
		$this->assertEquals( $test_secret, $decrypted_secret, 'Decrypted secret should match original' );

		// Clean up.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );
	}
}
