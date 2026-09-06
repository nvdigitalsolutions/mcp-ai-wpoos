<?php
/**
 * Tests for the encrypted CredentialStore and its integration points.
 *
 * Covers:
 *  - set/get/delete roundtrips with encryption at rest
 *  - transparent migration of legacy plaintext keys
 *  - the pre_update_option strip/route filter (defense in depth)
 *  - the render-masking filter
 *  - CredentialResolver priority chain with the credential store
 *  - ApiKeys section sanitize semantics (blank deletes, placeholder keeps)
 *
 * @package NvoosContentGraphAi\Tests
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\Admin\Sections\ApiKeys;
use NvoosContentGraphAi\Security\CredentialStore;

/**
 * @group integration
 * @group credentials
 */
class Test_CredentialStore extends \WP_UnitTestCase {

	/**
	 * Reset credential-related options before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'nvoos_content_graph_settings' );
		delete_option( CredentialStore::OPTION_NAME );
		delete_option( CredentialStore::MIGRATION_FLAG );
		CredentialResolver::clearCache();
	}

	/**
	 * Clean up environment variables after each test.
	 */
	public function tearDown(): void {
		putenv( 'OPENAI_API_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test-only env cleanup.
		CredentialResolver::clearCache();
		parent::tearDown();
	}

	// ─── Store roundtrips ─────────────────────────────────────────

	/**
	 * set() stores an encrypted value and get() returns the plaintext.
	 */
	public function test_set_and_get_roundtrip(): void {
		$this->assertTrue( CredentialStore::set( 'openai', 'sk-test-123' ) );

		$this->assertSame( 'sk-test-123', CredentialStore::get( 'openai' ) );
		$this->assertTrue( CredentialStore::has( 'openai' ) );

		$stored = get_option( CredentialStore::OPTION_NAME, array() );
		$this->assertIsArray( $stored );
		$this->assertNotSame( 'sk-test-123', $stored['openai'], 'Key must not be stored as plaintext' );
	}

	/**
	 * delete() removes the key from the encrypted store.
	 */
	public function test_delete(): void {
		CredentialStore::set( 'gemini', 'ai-gemini-key' );
		$this->assertTrue( CredentialStore::has( 'gemini' ) );

		CredentialStore::delete( 'gemini' );

		$this->assertNull( CredentialStore::get( 'gemini' ) );
		$this->assertFalse( CredentialStore::has( 'gemini' ) );
	}

	/**
	 * A tampered / undecryptable value resolves to null, never garbage.
	 */
	public function test_tampered_ciphertext_returns_null(): void {
		update_option( CredentialStore::OPTION_NAME, array( 'openai' => 'gcm:AAAA' ), false );

		$this->assertNull( CredentialStore::get( 'openai' ) );
	}

	/**
	 * The placeholder is never persisted — set() keeps the stored key.
	 */
	public function test_placeholder_keeps_existing_key(): void {
		CredentialStore::set( 'openai', 'sk-existing' );

		$this->assertTrue( CredentialStore::set( 'openai', CredentialStore::MASKED_PLACEHOLDER ) );
		$this->assertSame( 'sk-existing', CredentialStore::get( 'openai' ) );
	}

	// ─── Legacy plaintext migration ───────────────────────────────

	/**
	 * get() transparently migrates a legacy plaintext key on first read.
	 */
	public function test_migrate_on_read_from_legacy_plaintext(): void {
		// add_option bypasses the pre_update_option strip filter, seeding
		// the exact pre-1.0.4 plaintext state.
		add_option( 'nvoos_content_graph_settings', array( 'ai_api_key_gemini' => 'plain-gemini' ) );

		$this->assertSame( 'plain-gemini', CredentialStore::get( 'gemini' ) );

		$creds = get_option( CredentialStore::OPTION_NAME, array() );
		$this->assertNotSame( 'plain-gemini', $creds['gemini'], 'Migrated key must be encrypted' );

		$settings = get_option( 'nvoos_content_graph_settings', array() );
		$this->assertArrayNotHasKey( 'ai_api_key_gemini', $settings, 'Plaintext copy must be stripped from settings' );
	}

	/**
	 * The legacy bare openai_api_key field is honored for the openai suffix.
	 */
	public function test_legacy_bare_openai_key_resolves(): void {
		add_option( 'nvoos_content_graph_settings', array( 'openai_api_key' => 'plain-bare-openai' ) );

		$this->assertSame( 'plain-bare-openai', CredentialStore::get( 'openai' ) );

		$settings = get_option( 'nvoos_content_graph_settings', array() );
		$this->assertArrayNotHasKey( 'openai_api_key', $settings, 'Legacy bare key must be stripped after migration' );
	}

	/**
	 * migrateAll() encrypts every legacy plaintext key in one pass.
	 */
	public function test_migrate_all(): void {
		add_option(
			'nvoos_content_graph_settings',
			array(
				'ai_api_key_deepseek' => 'plain-deepseek',
				'openai_api_key'      => 'plain-openai-legacy',
				'ai_default_model'    => 'gpt-4o',
			)
		);

		$result = CredentialStore::migrateAll();

		$this->assertSame( 2, $result['migrated'] );
		$this->assertEmpty( $result['failures'] );

		$this->assertSame( 'plain-deepseek', CredentialStore::get( 'deepseek' ) );
		$this->assertSame( 'plain-openai-legacy', CredentialStore::get( 'openai' ) );

		$settings = get_option( 'nvoos_content_graph_settings', array() );
		$this->assertArrayNotHasKey( 'ai_api_key_deepseek', $settings );
		$this->assertArrayNotHasKey( 'openai_api_key', $settings );
		$this->assertSame( 'gpt-4o', $settings['ai_default_model'], 'Non-secret settings must be untouched' );
	}

	/**
	 * migrateAll() with nothing to migrate reports zero.
	 */
	public function test_migrate_all_noop(): void {
		$result = CredentialStore::migrateAll();

		$this->assertSame( 0, $result['migrated'] );
		$this->assertEmpty( $result['failures'] );
	}

	// ─── Save-path defense in depth ───────────────────────────────

	/**
	 * The pre_update_option filter routes secrets to the store and strips
	 * them from the settings option.
	 */
	public function test_settings_save_routes_secrets_to_store(): void {
		$incoming = array(
			'ai_api_key_openai' => 'sk-via-save',
			'ai_default_model'  => 'gpt-4o',
		);

		update_option( 'nvoos_content_graph_settings', $incoming );

		$stored = get_option( 'nvoos_content_graph_settings', array() );
		$this->assertArrayNotHasKey( 'ai_api_key_openai', $stored, 'Secrets must never land in the settings option' );
		$this->assertSame( 'gpt-4o', $stored['ai_default_model'] );

		$this->assertSame( 'sk-via-save', CredentialStore::get( 'openai' ) );
	}

	/**
	 * A blank secret submitted through a save keeps the stored key.
	 */
	public function test_settings_save_blank_keeps_stored_key(): void {
		CredentialStore::set( 'openai', 'sk-stored' );

		update_option(
			'nvoos_content_graph_settings',
			array(
				'ai_api_key_openai' => '',
				'ai_default_model'  => 'gpt-4o',
			)
		);

		$this->assertSame( 'sk-stored', CredentialStore::get( 'openai' ), 'Blank saves must not wipe stored keys' );
	}

	/**
	 * The render-mask filter never lets a secret value through.
	 */
	public function test_render_mask_filter(): void {
		CredentialStore::set( 'openai', 'sk-stored' );

		// Stored key + empty rendered value → placeholder.
		$masked = apply_filters( 'nvoos_content_graph/section_field_value', '', 'openai_api_key', array() );
		$this->assertSame( CredentialStore::MASKED_PLACEHOLDER, $masked );

		// Any non-empty secret value (e.g. legacy plaintext still in the
		// option) → placeholder, never the real value.
		$masked = apply_filters( 'nvoos_content_graph/section_field_value', 'sk-leaked-plaintext', 'ai_api_key_gemini', array() );
		$this->assertSame( CredentialStore::MASKED_PLACEHOLDER, $masked );

		// Non-secret fields pass through untouched.
		$passed = apply_filters( 'nvoos_content_graph/section_field_value', 'keep-me', 'ai_default_model', array() );
		$this->assertSame( 'keep-me', $passed );
	}

	// ─── Resolver integration ─────────────────────────────────────

	/**
	 * The resolver prefers the encrypted credential store.
	 */
	public function test_resolver_uses_credential_store_first(): void {
		CredentialStore::set( 'openai', 'sk-store' );

		$this->assertSame( 'sk-store', CredentialResolver::getApiKey( 'openai' ) );
		$this->assertSame( 'credential_store', CredentialResolver::getKeySource( 'openai' ) );
	}

	/**
	 * The resolver falls back to environment variables when the store is empty.
	 */
	public function test_resolver_env_fallback_when_store_empty(): void {
		putenv( 'OPENAI_API_KEY=sk-env' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test-only env manipulation, cleaned up in tearDown().

		$this->assertSame( 'sk-env', CredentialResolver::getApiKey( 'openai' ) );
		$this->assertSame( 'env_var', CredentialResolver::getKeySource( 'openai' ) );
	}

	/**
	 * The lm_studio router slug resolves the lmstudio settings suffix.
	 */
	public function test_resolver_lm_studio_alias(): void {
		CredentialStore::set( 'lmstudio', 'sk-lm' );

		$this->assertSame( 'sk-lm', CredentialResolver::getApiKey( 'lm_studio' ) );
	}

	/**
	 * hasCredentials() still reports local providers without keys as ready.
	 */
	public function test_resolver_no_key_providers(): void {
		$this->assertTrue( CredentialResolver::hasCredentials( 'ollama' ) );
		$this->assertTrue( CredentialResolver::hasCredentials( 'lm_studio' ) );
	}

	// ─── ApiKeys section semantics ────────────────────────────────

	/**
	 * ApiKeys::sanitize encrypts new keys, keeps placeholder values, and
	 * deletes on blank — while never returning secrets into the settings merge.
	 */
	public function test_api_keys_sanitize_semantics(): void {
		$section = new ApiKeys();

		// New key → stored, not returned.
		$out = $section->sanitize( array( 'ai_api_key_openai' => 'sk-new-key' ) );
		$this->assertArrayNotHasKey( 'ai_api_key_openai', $out );
		$this->assertSame( 'sk-new-key', CredentialStore::get( 'openai' ) );

		// Placeholder → keeps the stored key.
		$out = $section->sanitize( array( 'ai_api_key_openai' => CredentialStore::MASKED_PLACEHOLDER ) );
		$this->assertArrayNotHasKey( 'ai_api_key_openai', $out );
		$this->assertSame( 'sk-new-key', CredentialStore::get( 'openai' ) );

		// Blank → deletes the stored key.
		$out = $section->sanitize( array( 'ai_api_key_openai' => '' ) );
		$this->assertArrayNotHasKey( 'ai_api_key_openai', $out );
		$this->assertNull( CredentialStore::get( 'openai' ) );

		// Non-secret fields pass through as usual.
		$out = $section->sanitize( array( 'ollama_base_url' => 'http://localhost:9999' ) );
		$this->assertSame( 'http://localhost:9999', $out['ollama_base_url'] );
	}

	/**
	 * The SettingsStore adapter routes secret writes to the credential store
	 * and never exposes secrets via all().
	 */
	public function test_settings_store_routes_secrets(): void {
		$store = new \NvoosContentGraphAi\Adapter\ContentGraphSettingsStore();

		$store->set( 'ai_api_key_anthropic', 'sk-ant-123' );
		$this->assertSame( 'sk-ant-123', $store->getApiKey( 'anthropic' ) );

		$all = $store->all();
		$this->assertSame( '', $all['ai_api_key_anthropic'], 'all() must not expose stored secrets' );
	}
}
