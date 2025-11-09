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
	 * Ensure credentials are not saved when prerequisites are missing.
	 */
	public function test_encryption_unavailable_returns_error_and_skips_storage() {
		add_filter( 'wp_mcp_ai_credential_encryption_available', '__return_false' );

		delete_option( WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION );

		$updates = 0;
		$tracker = function ( $value, $old_value, $option ) use ( &$updates ) {
			$updates++;
			return $value;
		};
		add_filter(
			'pre_update_option_' . WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION,
			$tracker,
			10,
			3
		);

		$result = WP_MCP_AI_Credential_Encryption::encrypt( 'sensitive-secret' );

		remove_filter( 'pre_update_option_' . WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION, $tracker, 10 );
		remove_filter( 'wp_mcp_ai_credential_encryption_available', '__return_false' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $updates, 'Encryption prerequisites failure should not persist master key changes.' );
		$this->assertFalse( get_option( WP_MCP_AI_Credential_Encryption::MASTER_KEY_OPTION ) );
	}
}
