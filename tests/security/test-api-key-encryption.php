<?php
/**
 * Tests for WP_MCP_AI_Api_Key_Store — encrypted API key storage.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for API key encryption.
 *
 * @group security
 * @group api-keys
 * @group encryption
 */
class WP_MCP_AI_Api_Key_Store_Tests extends WP_UnitTestCase {

	/**
	 * Clean up stored keys between tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_test_key' );
		delete_option( 'wp_mcp_ai_master_key' );
		parent::tearDown();
	}

	/**
	 * Test that storing a value encrypts it (option value is not plaintext).
	 */
	public function test_set_encrypts_value() {
		$plaintext = 'sk-test-key-12345';
		$result    = WP_MCP_AI_Api_Key_Store::set( 'test_key', $plaintext );

		$this->assertTrue( $result, 'Set should return true.' );

		$stored = get_option( 'wp_mcp_ai_test_key', '' );
		$this->assertNotEmpty( $stored, 'Option should be stored.' );
		$this->assertNotEquals( $plaintext, $stored, 'Stored value should not be plaintext.' );
		$this->assertStringStartsWith( 'v2:', $stored, 'Stored value should have v2: prefix.' );
	}

	/**
	 * Test that get() returns the original plaintext after encrypted storage.
	 */
	public function test_get_decrypts_value() {
		$plaintext = 'sk-test-key-67890';
		WP_MCP_AI_Api_Key_Store::set( 'test_key', $plaintext );

		$retrieved = WP_MCP_AI_Api_Key_Store::get( 'test_key' );
		$this->assertSame( $plaintext, $retrieved, 'Retrieved value should match original.' );
	}

	/**
	 * Test that empty values are not stored.
	 */
	public function test_set_empty_value_deletes_option() {
		// First set a value.
		WP_MCP_AI_Api_Key_Store::set( 'test_key', 'some-value' );
		$this->assertNotEmpty( get_option( 'wp_mcp_ai_test_key' ), 'Option should exist.' );

		// Set empty.
		WP_MCP_AI_Api_Key_Store::set( 'test_key', '' );
		$this->assertEmpty( get_option( 'wp_mcp_ai_test_key' ), 'Option should be deleted.' );
	}

	/**
	 * Test that get() returns empty string for non-existent keys.
	 */
	public function test_get_nonexistent_key_returns_empty() {
		$value = WP_MCP_AI_Api_Key_Store::get( 'nonexistent_key' );
		$this->assertSame( '', $value, 'Non-existent key should return empty string.' );
	}

	/**
	 * Test transparent migration: plaintext stored directly is auto-encrypted on read.
	 */
	public function test_get_migrates_plaintext_to_encrypted() {
		$plaintext = 'sk-legacy-plaintext-key';

		// Simulate a pre-existing plaintext value (before encryption was added).
		update_option( 'wp_mcp_ai_test_key', $plaintext );

		// Read via the store — should return plaintext AND migrate.
		$retrieved = WP_MCP_AI_Api_Key_Store::get( 'test_key' );
		$this->assertSame( $plaintext, $retrieved, 'Should return original value.' );

		// Verify the option was migrated to encrypted.
		$stored = get_option( 'wp_mcp_ai_test_key', '' );
		$this->assertStringStartsWith( 'v2:', $stored, 'Should be migrated to encrypted format.' );
		$this->assertNotEquals( $plaintext, $stored, 'Stored value should no longer be plaintext.' );
	}

	/**
	 * Test that get() on an already-encrypted value decrypts correctly.
	 */
	public function test_get_already_encrypted_value() {
		$plaintext = 'sk-already-encrypted';
		WP_MCP_AI_Api_Key_Store::set( 'test_key', $plaintext );

		// Read twice — second read should find it already encrypted.
		$first  = WP_MCP_AI_Api_Key_Store::get( 'test_key' );
		$second = WP_MCP_AI_Api_Key_Store::get( 'test_key' );

		$this->assertSame( $plaintext, $first );
		$this->assertSame( $plaintext, $second, 'Multiple reads should be consistent.' );
	}

	/**
	 * Test that managed keys include expected entries.
	 */
	public function test_managed_keys_list() {
		$suffixes = WP_MCP_AI_Api_Key_Store::get_managed_key_suffixes();

		$this->assertContains( 'openai_api_key', $suffixes );
		$this->assertContains( 'stability_api_key', $suffixes );
		$this->assertContains( 'webhook_secret', $suffixes );
	}

	/**
	 * Test get_label returns human-readable names.
	 */
	public function test_get_label() {
		$this->assertSame( 'OpenAI API Key', WP_MCP_AI_Api_Key_Store::get_label( 'openai_api_key' ) );
		$this->assertSame( 'Webhook HMAC Secret', WP_MCP_AI_Api_Key_Store::get_label( 'webhook_secret' ) );

		// Unknown suffix returns itself.
		$this->assertSame( 'unknown_key', WP_MCP_AI_Api_Key_Store::get_label( 'unknown_key' ) );
	}

	/**
	 * Test delete removes the option.
	 */
	public function test_delete_removes_option() {
		WP_MCP_AI_Api_Key_Store::set( 'test_key', 'delete-me' );
		$this->assertNotEmpty( get_option( 'wp_mcp_ai_test_key' ) );

		WP_MCP_AI_Api_Key_Store::delete( 'test_key' );
		$this->assertEmpty( get_option( 'wp_mcp_ai_test_key' ) );
	}

	/**
	 * Test find_remaining_plaintext detects plaintext values.
	 */
	public function test_find_remaining_plaintext() {
		// Store a plaintext value directly (simulating pre-encryption).
		update_option( 'wp_mcp_ai_openai_api_key', 'sk-plaintext-direct' );

		$plaintext = WP_MCP_AI_Api_Key_Store::find_remaining_plaintext();
		$this->assertContains( 'openai_api_key', $plaintext, 'Should detect plaintext key.' );

		// Migrate and verify it's no longer detected.
		WP_MCP_AI_Api_Key_Store::migrate_all();
		$plaintext_after = WP_MCP_AI_Api_Key_Store::find_remaining_plaintext();
		$this->assertNotContains( 'openai_api_key', $plaintext_after, 'Should not detect after migration.' );
	}

	/**
	 * Test migrate_all returns correct counts.
	 */
	public function test_migrate_all() {
		update_option( 'wp_mcp_ai_openai_api_key', 'sk-before-migration' );
		update_option( 'wp_mcp_ai_stability_api_key', 'sk-stability-plain' );

		$result = WP_MCP_AI_Api_Key_Store::migrate_all();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'migrated', $result );
		$this->assertArrayHasKey( 'failures', $result );
		$this->assertGreaterThanOrEqual( 1, $result['migrated'], 'At least one key should be migrated.' );
	}
}
