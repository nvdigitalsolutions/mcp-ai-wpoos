<?php
/**
 * Tests for master key rotation functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * @group encryption
 * @group security
 * @group key-rotation
 */
class WP_MCP_AI_Master_Key_Rotation_Tests extends WP_UnitTestCase {

	/**
	 * Test post IDs for cleanup.
	 *
	 * @var array
	 */
	protected $test_posts = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing master key.
		delete_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );

		// Clear test posts.
		$this->test_posts = array();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test posts.
		foreach ( $this->test_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Clear master key.
		delete_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );

		parent::tearDown();
	}

	/**
	 * Test that master key is generated if not exists.
	 */
	public function test_master_key_generation() {
		$key = WP_MCP_AI_Encryption::get_master_key();

		$this->assertNotEmpty( $key );
		$this->assertIsString( $key );

		// Verify it's stored in options.
		$stored_key = get_option( WP_MCP_AI_Encryption::MASTER_KEY_OPTION );
		$this->assertEquals( $key, $stored_key );
	}

	/**
	 * Test encryption and decryption of data.
	 */
	public function test_encrypt_decrypt() {
		$original = 'my-secret-api-key-12345';

		$encrypted = WP_MCP_AI_Encryption::encrypt( $original );
		$this->assertNotFalse( $encrypted );
		$this->assertNotEquals( $original, $encrypted );

		$decrypted = WP_MCP_AI_Encryption::decrypt( $encrypted );
		$this->assertEquals( $original, $decrypted );
	}

	/**
	 * Test encrypting empty data returns false.
	 */
	public function test_encrypt_empty_data() {
		$this->assertFalse( WP_MCP_AI_Encryption::encrypt( '' ) );
		$this->assertFalse( WP_MCP_AI_Encryption::encrypt( null ) );
	}

	/**
	 * Test decrypting invalid data returns false.
	 */
	public function test_decrypt_invalid_data() {
		$this->assertFalse( WP_MCP_AI_Encryption::decrypt( 'invalid-data' ) );
		$this->assertFalse( WP_MCP_AI_Encryption::decrypt( '' ) );
		$this->assertFalse( WP_MCP_AI_Encryption::decrypt( null ) );
	}

	/**
	 * Test decrypting with wrong key returns false.
	 */
	public function test_decrypt_with_wrong_key() {
		$original = 'secret-data';
		$key1     = WP_MCP_AI_Encryption::generate_key();
		$key2     = WP_MCP_AI_Encryption::generate_key();

		$encrypted = WP_MCP_AI_Encryption::encrypt( $original, $key1 );
		$this->assertNotFalse( $encrypted );

		// Try to decrypt with wrong key.
		$decrypted = WP_MCP_AI_Encryption::decrypt( $encrypted, $key2 );
		$this->assertFalse( $decrypted );
	}

	/**
	 * Test successful master key rotation with no secrets.
	 */
	public function test_rotate_master_key_no_secrets() {
		$old_key = WP_MCP_AI_Encryption::get_master_key();

		$result = WP_MCP_AI_Encryption::rotate_master_key();

		$this->assertTrue( $result );

		$new_key = WP_MCP_AI_Encryption::get_master_key();
		$this->assertNotEquals( $old_key, $new_key );
	}

	/**
	 * Test successful master key rotation with secrets.
	 */
	public function test_rotate_master_key_with_secrets() {
		// Create test post with encrypted secret.
		$post_id            = $this->factory->post->create();
		$this->test_posts[] = $post_id;

		$secret1    = 'api-key-secret-1';
		$secret2    = 'api-key-secret-2';
		$encrypted1 = WP_MCP_AI_Encryption::encrypt( $secret1 );
		$encrypted2 = WP_MCP_AI_Encryption::encrypt( $secret2 );

		update_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, $encrypted1 );

		// Create another post with encrypted secret.
		$post_id2           = $this->factory->post->create();
		$this->test_posts[] = $post_id2;
		update_post_meta( $post_id2, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, $encrypted2 );

		$old_key = WP_MCP_AI_Encryption::get_master_key();

		// Rotate the key.
		$result = WP_MCP_AI_Encryption::rotate_master_key();

		$this->assertTrue( $result );

		$new_key = WP_MCP_AI_Encryption::get_master_key();
		$this->assertNotEquals( $old_key, $new_key );

		// Verify secrets can still be decrypted with new key.
		$new_encrypted1 = get_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, true );
		$new_encrypted2 = get_post_meta( $post_id2, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, true );

		$this->assertNotEmpty( $new_encrypted1 );
		$this->assertNotEmpty( $new_encrypted2 );

		// Should not be the same as old encrypted values.
		$this->assertNotEquals( $encrypted1, $new_encrypted1 );
		$this->assertNotEquals( $encrypted2, $new_encrypted2 );

		// Decrypt with new key should work.
		$decrypted1 = WP_MCP_AI_Encryption::decrypt( $new_encrypted1 );
		$decrypted2 = WP_MCP_AI_Encryption::decrypt( $new_encrypted2 );

		$this->assertEquals( $secret1, $decrypted1 );
		$this->assertEquals( $secret2, $decrypted2 );

		// Old encrypted values should NOT decrypt with new key.
		$this->assertFalse( WP_MCP_AI_Encryption::decrypt( $encrypted1 ) );
		$this->assertFalse( WP_MCP_AI_Encryption::decrypt( $encrypted2 ) );
	}

	/**
	 * Test that failed decrypt during rotation triggers rollback.
	 */
	public function test_rotate_master_key_rollback_on_decrypt_failure() {
		// Create test post with corrupted encrypted data.
		$post_id            = $this->factory->post->create();
		$this->test_posts[] = $post_id;

		// Store invalid encrypted data that will fail to decrypt.
		update_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, 'invalid-encrypted-data' );

		$old_key = WP_MCP_AI_Encryption::get_master_key();

		// Attempt rotation - should fail and rollback.
		$result = WP_MCP_AI_Encryption::rotate_master_key();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_decrypt_failed', $result->get_error_code() );

		// Verify master key was NOT changed.
		$current_key = WP_MCP_AI_Encryption::get_master_key();
		$this->assertEquals( $old_key, $current_key );
	}

	/**
	 * Test rollback when partial re-encryption succeeds but later one fails.
	 */
	public function test_rotate_master_key_rollback_on_partial_failure() {
		// Create first post with valid secret.
		$post_id1           = $this->factory->post->create();
		$this->test_posts[] = $post_id1;

		$secret1    = 'valid-secret-1';
		$encrypted1 = WP_MCP_AI_Encryption::encrypt( $secret1 );
		update_post_meta( $post_id1, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, $encrypted1 );

		// Create second post with invalid encrypted data.
		$post_id2           = $this->factory->post->create();
		$this->test_posts[] = $post_id2;
		update_post_meta( $post_id2, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, 'corrupted-data' );

		$old_key = WP_MCP_AI_Encryption::get_master_key();

		// Store original encrypted value for verification.
		$original_encrypted1 = get_post_meta( $post_id1, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, true );

		// Attempt rotation - should fail on second secret.
		$result = WP_MCP_AI_Encryption::rotate_master_key();

		$this->assertWPError( $result );

		// Verify master key was NOT changed.
		$current_key = WP_MCP_AI_Encryption::get_master_key();
		$this->assertEquals( $old_key, $current_key );

		// Verify first secret was NOT re-encrypted (rollback occurred).
		$current_encrypted1 = get_post_meta( $post_id1, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, true );

		// First secret should still be decryptable with old key.
		$decrypted1 = WP_MCP_AI_Encryption::decrypt( $current_encrypted1 );
		$this->assertEquals( $secret1, $decrypted1 );
	}

	/**
	 * Test that empty string from failed decrypt is not re-encrypted.
	 *
	 * This tests the specific vulnerability mentioned in the problem statement
	 * where a failed decrypt returns false, which could be passed into encrypt()
	 * and saved as an empty secret.
	 */
	public function test_failed_decrypt_not_re_encrypted() {
		// Create test post with corrupted data.
		$post_id            = $this->factory->post->create();
		$this->test_posts[] = $post_id;

		// This will fail to decrypt.
		$corrupted = base64_encode( 'too-short' );
		update_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, $corrupted );

		$old_key = WP_MCP_AI_Encryption::get_master_key();

		// Attempt rotation.
		$result = WP_MCP_AI_Encryption::rotate_master_key();

		// Should return an error, not silently fail.
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_decrypt_failed', $result->get_error_code() );

		// Master key should be unchanged.
		$this->assertEquals( $old_key, WP_MCP_AI_Encryption::get_master_key() );

		// Original corrupted data should be unchanged.
		$current_value = get_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, true );
		$this->assertEquals( $corrupted, $current_value );
	}

	/**
	 * Test multiple secrets are all re-encrypted or all rolled back.
	 */
	public function test_rotate_master_key_all_or_nothing() {
		// Create 5 posts with valid secrets.
		$secrets = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$post_id             = $this->factory->post->create();
			$this->test_posts[]  = $post_id;
			$secrets[ $post_id ] = "secret-value-$i";
			$encrypted           = WP_MCP_AI_Encryption::encrypt( $secrets[ $post_id ] );
			update_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, $encrypted );
		}

		// Rotate successfully.
		$result = WP_MCP_AI_Encryption::rotate_master_key();
		$this->assertTrue( $result );

		// All secrets should be decryptable with new key.
		foreach ( $secrets as $post_id => $original_secret ) {
			$encrypted = get_post_meta( $post_id, WP_MCP_AI_Encryption::ENCRYPTED_SECRET_META_KEY, true );
			$decrypted = WP_MCP_AI_Encryption::decrypt( $encrypted );
			$this->assertEquals( $original_secret, $decrypted, "Secret for post $post_id should match" );
		}
	}

	/**
	 * Test is_encrypted helper function.
	 */
	public function test_is_encrypted() {
		$secret    = 'my-secret';
		$encrypted = WP_MCP_AI_Encryption::encrypt( $secret );

		$this->assertTrue( WP_MCP_AI_Encryption::is_encrypted( $encrypted ) );
		$this->assertFalse( WP_MCP_AI_Encryption::is_encrypted( $secret ) );
		$this->assertFalse( WP_MCP_AI_Encryption::is_encrypted( '' ) );
		$this->assertFalse( WP_MCP_AI_Encryption::is_encrypted( null ) );
		$this->assertFalse( WP_MCP_AI_Encryption::is_encrypted( 123 ) );
	}

	/**
	 * Test that generate_key produces unique keys.
	 */
	public function test_generate_key_uniqueness() {
		$key1 = WP_MCP_AI_Encryption::generate_key();
		$key2 = WP_MCP_AI_Encryption::generate_key();
		$key3 = WP_MCP_AI_Encryption::generate_key();

		$this->assertNotEquals( $key1, $key2 );
		$this->assertNotEquals( $key2, $key3 );
		$this->assertNotEquals( $key1, $key3 );
	}

	/**
	 * Test encrypting with custom key.
	 */
	public function test_encrypt_with_custom_key() {
		$secret     = 'test-secret';
		$custom_key = WP_MCP_AI_Encryption::generate_key();

		$encrypted = WP_MCP_AI_Encryption::encrypt( $secret, $custom_key );
		$this->assertNotFalse( $encrypted );

		// Should decrypt with same custom key.
		$decrypted = WP_MCP_AI_Encryption::decrypt( $encrypted, $custom_key );
		$this->assertEquals( $secret, $decrypted );

		// Should NOT decrypt with master key.
		$decrypted_with_master = WP_MCP_AI_Encryption::decrypt( $encrypted );
		$this->assertFalse( $decrypted_with_master );
	}
}
