<?php
/**
 * Tests for Cloudflare provider saving in Assistant CPT.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test that Cloudflare provider can be saved in assistant posts.
 */
class WP_MCP_AI_Cloudflare_Provider_Save_Test extends WP_UnitTestCase {

	/**
	 * Set up an admin user: save_post() requires edit_post capability and
	 * nonces bind to the current user ID (pattern: create user first).
	 */
	public function setUp(): void {
		parent::setUp();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Test that sanitize_provider_meta accepts cloudflare.
	 */
	public function test_sanitize_provider_meta_accepts_cloudflare() {
		$result = WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( 'cloudflare' );
		$this->assertEquals( 'cloudflare', $result, 'Cloudflare should be accepted as a valid provider.' );
	}

	/**
	 * Test that sanitize_provider_meta accepts all standard providers.
	 */
	public function test_sanitize_provider_meta_accepts_all_standard_providers() {
		$providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare' );

		foreach ( $providers as $provider ) {
			$this->assertEquals(
				$provider,
				WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( $provider ),
				"Provider '{$provider}' should be accepted as valid."
			);
		}
	}

	/**
	 * Test that sanitize_provider_meta rejects invalid providers.
	 */
	public function test_sanitize_provider_meta_rejects_invalid_providers() {
		$invalid_providers = array( 'invalid', 'fake-provider', 'test', '' );

		foreach ( $invalid_providers as $provider ) {
			$result = WP_MCP_AI_Assistant_CPT::sanitize_provider_meta( $provider );
			$this->assertEquals( '', $result, "Invalid provider '{$provider}' should be rejected." );
		}
	}

	/**
	 * Test that cloudflare provider can be saved to post meta.
	 */
	public function test_cloudflare_provider_can_be_saved_to_post_meta() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Create a test assistant post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Cloudflare Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Simulate form submission with cloudflare provider.
		$_POST['wp_mcp_ai_defaults_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_defaults_meta' );
		$_POST['wp_mcp_ai_provider']            = 'cloudflare';
		$_POST['wp_mcp_ai_model']               = '@cf/meta/llama-3.1-8b-instruct';
		$_POST['wp_mcp_ai_temperature']         = '0.7';
		$_POST['wp_mcp_ai_system_prompt']       = 'Test system prompt';

		// Trigger the save_post hook.
		$post = get_post( $post_id );
		$cpt->save_post( $post_id, $post );

		// Verify the provider was saved correctly.
		$saved_provider = get_post_meta( $post_id, '_wp_mcp_ai_provider', true );
		$this->assertEquals( 'cloudflare', $saved_provider, 'Cloudflare provider should be saved to post meta.' );

		// Verify other fields were saved.
		$saved_model = get_post_meta( $post_id, '_wp_mcp_ai_model', true );
		$this->assertEquals( '@cf/meta/llama-3.1-8b-instruct', $saved_model );

		$saved_temp = get_post_meta( $post_id, '_wp_mcp_ai_temperature', true );
		$this->assertEquals( 0.7, $saved_temp );

		// Clean up.
		wp_delete_post( $post_id, true );
		unset( $_POST['wp_mcp_ai_defaults_meta_nonce'] );
		unset( $_POST['wp_mcp_ai_provider'] );
		unset( $_POST['wp_mcp_ai_model'] );
		unset( $_POST['wp_mcp_ai_temperature'] );
		unset( $_POST['wp_mcp_ai_system_prompt'] );
	}

	/**
	 * Test that all providers can be saved and retrieved.
	 */
	public function test_all_providers_can_be_saved_and_retrieved() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		$providers = array(
			'openai'      => 'gpt-4',
			'anthropic'   => 'claude-3-opus-20240229',
			'gemini'      => 'gemini-pro',
			'huggingface' => 'gpt2',
			'ollama'      => 'llama2',
			'lm_studio'   => 'local-model',
			'cloudflare'  => '@cf/meta/llama-3.1-8b-instruct',
		);

		foreach ( $providers as $provider => $model ) {
			// Create a test assistant post.
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_assistant',
					'post_title'  => "Test {$provider} Assistant",
					'post_status' => 'publish',
				)
			);

			$this->assertNotWPError( $post_id );

			// Simulate form submission.
			$_POST['wp_mcp_ai_defaults_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_defaults_meta' );
			$_POST['wp_mcp_ai_provider']            = $provider;
			$_POST['wp_mcp_ai_model']               = $model;

			// Trigger the save_post hook.
			$post = get_post( $post_id );
			$cpt->save_post( $post_id, $post );

			// Verify the provider was saved correctly.
			$saved_provider = get_post_meta( $post_id, '_wp_mcp_ai_provider', true );
			$this->assertEquals(
				$provider,
				$saved_provider,
				"Provider '{$provider}' should be saved and retrieved correctly."
			);

			// Verify the model was saved correctly.
			$saved_model = get_post_meta( $post_id, '_wp_mcp_ai_model', true );
			$this->assertEquals(
				$model,
				$saved_model,
				"Model '{$model}' for provider '{$provider}' should be saved correctly."
			);

			// Clean up.
			wp_delete_post( $post_id, true );
		}

		// Clean up POST data.
		unset( $_POST['wp_mcp_ai_defaults_meta_nonce'] );
		unset( $_POST['wp_mcp_ai_provider'] );
		unset( $_POST['wp_mcp_ai_model'] );
	}

	/**
	 * Test that provider filter applies correctly.
	 */
	public function test_provider_filter_includes_cloudflare() {
		$default_providers = apply_filters(
			'wp_mcp_ai_allowed_providers',
			array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare' )
		);

		$this->assertContains( 'cloudflare', $default_providers, 'Default providers should include cloudflare.' );
		$this->assertContains( 'openai', $default_providers, 'Default providers should include openai.' );
		$this->assertContains( 'anthropic', $default_providers, 'Default providers should include anthropic.' );
		$this->assertContains( 'gemini', $default_providers, 'Default providers should include gemini.' );
		$this->assertContains( 'huggingface', $default_providers, 'Default providers should include huggingface.' );
		$this->assertContains( 'ollama', $default_providers, 'Default providers should include ollama.' );
		$this->assertContains( 'lm_studio', $default_providers, 'Default providers should include lm_studio.' );
	}
}
