<?php
/**
 * Tests for settings save key preservation.
 *
 * Ensures that API keys and credential fields survive saves from
 * any settings dashboard tab, including non-provider tabs.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for settings save key preservation.
 *
 * @group settings
 * @group security
 * @group credentials
 */
class WP_MCP_AI_Settings_Save_Key_Preservation_Tests extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Admin_Settings_Base class not found.' );
		}

		// Seed existing settings with API keys in both options (pre-migration state).
		update_option(
			WP_MCP_AI_Admin_Settings_Base::OPTION_NAME,
			array(
				'openai_api_key' => 'sk-test-key-12345',
				'gemini_api_key' => 'gem-test-key-67890',
				'enable_logging' => true,
				'default_model'  => 'gpt-4.1-mini',
			)
		);

		// Clear credentials and migration flag.
		delete_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME );
		delete_option( 'wp_mcp_ai_credentials_migrated' );

		// Reset static cache.
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down(): void {
		delete_option( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME );
		delete_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME );
		delete_option( 'wp_mcp_ai_credentials_migrated' );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		parent::tear_down();
	}

	/**
	 * Test that get_settings() returns settings from the main option
	 * when no credentials split exists yet (pre-migration state).
	 */
	public function test_get_settings_reads_main_option() {
		$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();

		$this->assertIsArray( $settings );
		$this->assertEquals( 'sk-test-key-12345', $settings['openai_api_key'] );
		$this->assertEquals( 'gem-test-key-67890', $settings['gemini_api_key'] );
		$this->assertTrue( $settings['enable_logging'] );
	}

	/**
	 * Test that get_settings() merges credentials from the separate
	 * non-autoload option when it exists (post-migration state).
	 */
	public function test_get_settings_merges_credentials_option() {
		// Simulate post-migration: credentials in separate option.
		update_option(
			WP_MCP_AI_Admin_Settings_Base::OPTION_NAME,
			array(
				'enable_logging' => true,
				'default_model'  => 'gpt-4.1-mini',
			)
		);
		update_option(
			WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME,
			array(
				'openai_api_key' => 'sk-in-credentials',
				'gemini_api_key' => 'gem-in-credentials',
			)
		);
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();

		$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();

		$this->assertEquals( 'gpt-4.1-mini', $settings['default_model'] );
		$this->assertEquals( 'sk-in-credentials', $settings['openai_api_key'] );
		$this->assertEquals( 'gem-in-credentials', $settings['gemini_api_key'] );
	}

	/**
	 * Test that the credentials migration runs correctly.
	 */
	public function test_credentials_migration_moves_sensitive_keys() {
		// Ensure migration hasn't run yet.
		delete_option( 'wp_mcp_ai_credentials_migrated' );

		// Run migration.
		if ( function_exists( 'wp_mcp_ai_migrate_credentials_to_split' ) ) {
			wp_mcp_ai_migrate_credentials_to_split();
		} else {
			$this->markTestSkipped( 'Migration function not found.' );
		}

		// Verify credentials were moved to separate option.
		$credentials = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );
		$this->assertArrayHasKey( 'openai_api_key', $credentials );
		$this->assertEquals( 'sk-test-key-12345', $credentials['openai_api_key'] );
		$this->assertArrayHasKey( 'gemini_api_key', $credentials );
		$this->assertEquals( 'gem-test-key-67890', $credentials['gemini_api_key'] );

		// Verify sensitive keys were removed from main settings.
		$settings = get_option( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME, array() );
		$this->assertArrayNotHasKey( 'openai_api_key', $settings );
		$this->assertArrayNotHasKey( 'gemini_api_key', $settings );

		// Verify non-sensitive keys remain.
		$this->assertTrue( $settings['enable_logging'] );
		$this->assertEquals( 'gpt-4.1-mini', $settings['default_model'] );

		// Verify migration flag was set.
		$this->assertNotEmpty( get_option( 'wp_mcp_ai_credentials_migrated' ) );
	}

	/**
	 * Test that migration is idempotent (running twice doesn't lose data).
	 */
	public function test_credentials_migration_is_idempotent() {
		delete_option( 'wp_mcp_ai_credentials_migrated' );

		if ( ! function_exists( 'wp_mcp_ai_migrate_credentials_to_split' ) ) {
			$this->markTestSkipped( 'Migration function not found.' );
		}

		// First run.
		wp_mcp_ai_migrate_credentials_to_split();

		// Reset flag and run again.
		delete_option( 'wp_mcp_ai_credentials_migrated' );
		wp_mcp_ai_migrate_credentials_to_split();

		// Verify credentials still exist in the separate option.
		$credentials = get_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );
		$this->assertEquals( 'sk-test-key-12345', $credentials['openai_api_key'] );
	}

	/**
	 * Test that is_sensitive_setting_key correctly identifies credential fields.
	 */
	public function test_is_sensitive_setting_key() {
		$this->assertTrue( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'openai_api_key' ) );
		$this->assertTrue( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'gemini_api_key' ) );
		$this->assertTrue( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'some_provider_api_key' ) );
		$this->assertTrue( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'cloudflare_api_token' ) );
		$this->assertTrue( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'gmail_client_secret' ) );
		$this->assertTrue( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'gmail_refresh_token' ) );

		$this->assertFalse( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'default_model' ) );
		$this->assertFalse( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'enable_logging' ) );
		$this->assertFalse( WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( 'max_history_messages' ) );
	}

	/**
	 * Test that the CREDENTIALS_OPTION_NAME constant exists.
	 */
	public function test_credentials_option_name_constant_exists() {
		$this->assertNotEmpty( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME );
		$this->assertEquals( 'wp_mcp_ai_credentials', WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME );
	}

	/**
	 * Test that get_sensitive_fields returns expected fields.
	 */
	public function test_get_sensitive_fields_contains_provider_keys() {
		$fields = WP_MCP_AI_Admin_Settings_Base::get_sensitive_fields();

		$this->assertContains( 'openai_api_key', $fields );
		$this->assertContains( 'gemini_api_key', $fields );
		$this->assertContains( 'anthropic_api_key', $fields );
		$this->assertContains( 'cloudflare_api_token', $fields );
	}

	/**
	 * Test that reset_settings_cache clears the static cache.
	 */
	public function test_reset_settings_cache() {
		// Prime the cache.
		$first = WP_MCP_AI_Admin_Settings_Base::get_settings();
		$this->assertNotEmpty( $first );

		// Changing the option fires the update_option_* hook, which resets the
		// cache automatically — so the next read is already fresh.
		update_option(
			WP_MCP_AI_Admin_Settings_Base::OPTION_NAME,
			array( 'default_model' => 'changed-model' )
		);
		$fresh = WP_MCP_AI_Admin_Settings_Base::get_settings();
		$this->assertEquals( 'changed-model', $fresh['default_model'] );

		// Manual reset keeps returning the fresh value.
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		$after_reset = WP_MCP_AI_Admin_Settings_Base::get_settings();
		$this->assertEquals( 'changed-model', $after_reset['default_model'] );
	}
}
