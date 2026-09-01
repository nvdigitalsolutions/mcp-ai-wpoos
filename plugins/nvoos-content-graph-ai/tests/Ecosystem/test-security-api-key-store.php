<?php
/**
 * API key store port tests (Wave D4b).
 *
 * Characterization suite for `ApiKeyStore`. Assertions mirror the base
 * plugin's encrypted key store: constants, encrypted set/get roundtrip,
 * transparent plaintext migration on read, empty-value deletion, labels,
 * remaining-plaintext detection, and bulk migration — exercised against
 * the active crypto seam (base `WP_MCP_AI_Encryption` monolith / parent
 * `Remote\Crypto` standalone).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\ApiKeyStore;

/**
 * @group security
 */
class Test_Api_Key_Store extends \WP_UnitTestCase {

	public function tearDown(): void {
		foreach ( ApiKeyStore::MANAGED_KEYS as $suffix => $label ) {
			\delete_option( ApiKeyStore::OPTION_PREFIX . $suffix );
		}

		parent::tearDown();
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_', ApiKeyStore::OPTION_PREFIX );
		$this->assertArrayHasKey( 'openai_api_key', ApiKeyStore::MANAGED_KEYS );
		$this->assertArrayHasKey( 'webhook_secret', ApiKeyStore::MANAGED_KEYS );
		$this->assertSame(
			array( 'openai_api_key', 'stability_api_key', 'google_maps_api_key', 'removebg_api_key', 'yahoo_client_secret', 'webhook_secret', 'pro_chat_continuation_secret' ),
			ApiKeyStore::get_managed_key_suffixes()
		);
	}

	public function test_get_returns_empty_for_missing_key(): void {
		$this->assertSame( '', ApiKeyStore::get( 'openai_api_key' ) );
	}

	public function test_set_get_roundtrip_is_encrypted_at_rest(): void {
		$this->assertTrue( ApiKeyStore::set( 'stability_api_key', 'sk-stability-secret-123' ) );

		$raw = \get_option( ApiKeyStore::OPTION_PREFIX . 'stability_api_key', '' );
		$this->assertNotEmpty( $raw );
		$this->assertStringNotContainsString( 'sk-stability-secret-123', $raw, 'Plaintext must not be stored at rest.' );

		$this->assertSame( 'sk-stability-secret-123', ApiKeyStore::get( 'stability_api_key' ) );
	}

	public function test_set_empty_value_deletes_the_option(): void {
		ApiKeyStore::set( 'google_maps_api_key', 'gmaps-key' );
		$this->assertNotEmpty( \get_option( ApiKeyStore::OPTION_PREFIX . 'google_maps_api_key', '' ) );

		$this->assertTrue( ApiKeyStore::set( 'google_maps_api_key', '' ) );
		$this->assertFalse( \get_option( ApiKeyStore::OPTION_PREFIX . 'google_maps_api_key', false ) );
	}

	public function test_plaintext_value_is_migrated_on_read(): void {
		\update_option( ApiKeyStore::OPTION_PREFIX . 'removebg_api_key', 'plain-removebg-key' );

		$this->assertSame( 'plain-removebg-key', ApiKeyStore::get( 'removebg_api_key' ) );

		$raw = \get_option( ApiKeyStore::OPTION_PREFIX . 'removebg_api_key', '' );
		$this->assertStringNotContainsString( 'plain-removebg-key', $raw, 'Value should be encrypted after first read.' );
	}

	public function test_delete_removes_the_key(): void {
		ApiKeyStore::set( 'yahoo_client_secret', 'yahoo-secret' );
		ApiKeyStore::delete( 'yahoo_client_secret' );

		$this->assertSame( '', ApiKeyStore::get( 'yahoo_client_secret' ) );
	}

	public function test_labels(): void {
		$this->assertSame( 'Google Maps API Key', ApiKeyStore::get_label( 'google_maps_api_key' ) );
		$this->assertSame( 'unknown_key', ApiKeyStore::get_label( 'unknown_key' ) );
	}

	public function test_find_remaining_plaintext(): void {
		\update_option( ApiKeyStore::OPTION_PREFIX . 'webhook_secret', 'plain-webhook' );
		ApiKeyStore::set( 'stability_api_key', 'sk-encrypted' );

		$plaintext = ApiKeyStore::find_remaining_plaintext();

		$this->assertSame( array( 'webhook_secret' ), $plaintext );
	}

	public function test_migrate_all_encrypts_plaintext_keys(): void {
		\update_option( ApiKeyStore::OPTION_PREFIX . 'webhook_secret', 'plain-webhook' );
		\update_option( ApiKeyStore::OPTION_PREFIX . 'yahoo_client_secret', 'plain-yahoo' );
		ApiKeyStore::set( 'stability_api_key', 'sk-already-encrypted' );

		$result = ApiKeyStore::migrate_all();

		$this->assertSame( 2, $result['migrated'] );
		$this->assertSame( array(), $result['failures'] );
		$this->assertSame( array(), ApiKeyStore::find_remaining_plaintext() );

		// Encrypted values remain readable.
		$this->assertSame( 'plain-webhook', ApiKeyStore::get( 'webhook_secret' ) );
		$this->assertSame( 'sk-already-encrypted', ApiKeyStore::get( 'stability_api_key' ) );
	}

	public function test_second_migration_is_a_noop(): void {
		\update_option( ApiKeyStore::OPTION_PREFIX . 'webhook_secret', 'plain-webhook' );

		$first  = ApiKeyStore::migrate_all();
		$second = ApiKeyStore::migrate_all();

		$this->assertSame( 1, $first['migrated'] );
		$this->assertSame( 0, $second['migrated'] );
	}
}
