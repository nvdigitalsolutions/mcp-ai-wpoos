<?php
/**
 * Tests for Credential Encryption.
 *
 * @package WP_MCP_AI
 */

/**
 * Credential Encryption test case.
 */
class Test_Credential_Encryption extends WP_UnitTestCase {

	/**
	 * Test encryption availability check.
	 */
	public function test_encryption_available() {
		$available = WP_MCP_AI_Credential_Encryption::is_available();
		
		// Should be true on most systems with OpenSSL.
		$this->assertTrue( is_bool( $available ) );
	}

	/**
	 * Test key generation.
	 */
	public function test_generate_key() {
		$key = WP_MCP_AI_Credential_Encryption::generate_key();

		if ( false !== $key ) {
			$this->assertIsString( $key );
			$this->assertNotEmpty( $key );
		}
	}

	/**
	 * Test encryption and decryption.
	 */
	public function test_encrypt_decrypt() {
		if ( ! WP_MCP_AI_Credential_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL encryption not available' );
		}

		$plaintext = 'sk-test-secret-api-key-12345';
		$encrypted = WP_MCP_AI_Credential_Encryption::encrypt( $plaintext );

		$this->assertNotEquals( $plaintext, $encrypted );
		$this->assertNotFalse( $encrypted );

		$decrypted = WP_MCP_AI_Credential_Encryption::decrypt( $encrypted );
		$this->assertEquals( $plaintext, $decrypted );
	}

	/**
	 * Test encryption produces different output each time.
	 */
	public function test_encryption_uniqueness() {
		if ( ! WP_MCP_AI_Credential_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL encryption not available' );
		}

		$plaintext = 'test-secret';
		$encrypted1 = WP_MCP_AI_Credential_Encryption::encrypt( $plaintext );
		$encrypted2 = WP_MCP_AI_Credential_Encryption::encrypt( $plaintext );

		// Different IVs should produce different ciphertext.
		$this->assertNotEquals( $encrypted1, $encrypted2 );

		// But both should decrypt to same plaintext.
		$this->assertEquals( $plaintext, WP_MCP_AI_Credential_Encryption::decrypt( $encrypted1 ) );
		$this->assertEquals( $plaintext, WP_MCP_AI_Credential_Encryption::decrypt( $encrypted2 ) );
	}

	/**
	 * Test rotation status tracking.
	 */
	public function test_rotation_status() {
		$status = WP_MCP_AI_Credential_Encryption::get_rotation_status();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'rotation_count', $status );
		$this->assertArrayHasKey( 'is_due', $status );
		$this->assertArrayHasKey( 'days_remaining', $status );
	}

	/**
	 * Test rotation due check.
	 */
	public function test_is_rotation_due() {
		$is_due = WP_MCP_AI_Credential_Encryption::is_rotation_due();
		$this->assertIsBool( $is_due );
	}

	/**
	 * Test days until rotation.
	 */
	public function test_days_until_rotation() {
		$days = WP_MCP_AI_Credential_Encryption::get_days_until_rotation();
		$this->assertIsInt( $days );
	}

	/**
	 * Test decrypting invalid data.
	 */
	public function test_decrypt_invalid() {
		$result = WP_MCP_AI_Credential_Encryption::decrypt( 'invalid-base64!' );
		$this->assertFalse( $result );
	}

	/**
	 * Test decrypting empty string.
	 */
	public function test_decrypt_empty() {
		$result = WP_MCP_AI_Credential_Encryption::decrypt( '' );
		$this->assertFalse( $result );
	}

	/**
	 * Ensure key rotation rolls back credentials on mid-rotation failures.
	 */
	public function test_master_key_rotation_rolls_back_on_mid_rotation_failure() {
		if ( ! WP_MCP_AI_Credential_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL encryption not available' );
		}

		delete_option( WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION );
		delete_option( 'wp_mcp_ai_openai_api_key' );
		delete_option( 'wp_mcp_ai_gemini_api_key' );

		$old_master_key = WP_MCP_AI_Credential_Encryption::generate_key();
		$this->assertNotFalse( $old_master_key, 'Failed to generate master key for test setup.' );

		update_option( WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION, $old_master_key, false );

		$secrets = array(
			'wp_mcp_ai_openai_api_key' => 'first-secret',
			'wp_mcp_ai_gemini_api_key' => 'second-secret',
		);

		$original_ciphertexts = array();
		foreach ( $secrets as $option_key => $plaintext ) {
			$cipher = WP_MCP_AI_Credential_Encryption::encrypt( $plaintext );
			$this->assertNotFalse( $cipher );

			update_option( $option_key, $cipher );
			$original_ciphertexts[ $option_key ] = $cipher;
		}

		$call_count = 0;

		try {
			WP_MCP_AI_Credential_Encryption::set_encrypt_override_for_testing(
				function( $plaintext ) use ( &$call_count ) {
					$call_count++;

					if ( 2 === $call_count ) {
						return false;
					}

					return null;
				}
			);

			$result = WP_MCP_AI_Credential_Encryption::rotate_master_key();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 're_encryption_failed', $result->get_error_code() );
		} finally {
			WP_MCP_AI_Credential_Encryption::set_encrypt_override_for_testing();
		}

		$this->assertEquals( $old_master_key, get_option( WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION ) );

		update_option( WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION, $old_master_key, false );

		foreach ( $secrets as $option_key => $plaintext ) {
			$stored_cipher = get_option( $option_key );
			$this->assertSame( $original_ciphertexts[ $option_key ], $stored_cipher );
			$this->assertSame( $plaintext, WP_MCP_AI_Credential_Encryption::decrypt( $stored_cipher ) );
		}
	}
}
