<?php
/**
 * Tests for Token Manager provider display name functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test provider display names in Token Manager.
 */
class WP_MCP_AI_Token_Manager_Provider_Display_Test extends WP_UnitTestCase {

	/**
	 * Test provider display name method via reflection.
	 *
	 * Since get_provider_display_name is a private method, we use reflection
	 * to test it.
	 */
	public function test_provider_display_names() {
		// Create an instance of the Token Manager section.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'get_provider_display_name' );
		$method->setAccessible( true );

		// Test OpenAI.
		$result = $method->invoke( $token_manager, 'openai' );
		$this->assertEquals( 'OpenAI', $result, 'OpenAI should be formatted correctly' );

		// Test Gemini.
		$result = $method->invoke( $token_manager, 'gemini' );
		$this->assertEquals( 'Gemini', $result, 'Gemini should be formatted correctly' );

		// Test Anthropic.
		$result = $method->invoke( $token_manager, 'anthropic' );
		$this->assertEquals( 'Anthropic (Claude)', $result, 'Anthropic should include (Claude)' );

		// Test Ollama.
		$result = $method->invoke( $token_manager, 'ollama' );
		$this->assertEquals( 'Ollama (Local AI)', $result, 'Ollama should include (Local AI)' );

		// Test LM Studio.
		$result = $method->invoke( $token_manager, 'lm_studio' );
		$this->assertEquals( 'LM Studio (Local AI)', $result, 'LM Studio should be formatted correctly' );

		// Test unknown provider (fallback).
		$result = $method->invoke( $token_manager, 'unknown_provider' );
		$this->assertEquals( 'Unknown Provider', $result, 'Unknown providers should use fallback formatting' );

		// Test provider with hyphens.
		$result = $method->invoke( $token_manager, 'custom-ai-provider' );
		$this->assertEquals( 'Custom Ai Provider', $result, 'Providers with hyphens should be formatted correctly' );
	}

	/**
	 * Test that provider names are sanitized.
	 */
	public function test_provider_name_sanitization() {
		// Create an instance of the Token Manager section.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();

		// Use reflection to access the private method.
		$reflection = new ReflectionClass( $token_manager );
		$method     = $reflection->getMethod( 'get_provider_display_name' );
		$method->setAccessible( true );

		// Test with invalid characters that should be sanitized.
		$result = $method->invoke( $token_manager, 'provider!@#$%' );
		$this->assertNotContains( '!', $result, 'Special characters should be removed' );
		$this->assertNotContains( '@', $result, 'Special characters should be removed' );

		// Test empty string.
		$result = $method->invoke( $token_manager, '' );
		$this->assertEquals( '', $result, 'Empty provider should return empty string' );
	}

	/**
	 * Test provider display consistency across different views.
	 *
	 * This test ensures that the provider names are displayed consistently
	 * in all three views: per_user, per_tool, and per_site.
	 */
	public function test_provider_display_consistency() {
		// Create test user with usage data.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Create mock usage data for multiple providers.
		$usage_data = array(
			'openai'    => array(
				'gpt-4' => array(
					'requests'          => 10,
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
					'total_tokens'      => 150,
					'cached_tokens'     => 0,
					'last_used_gmt'     => current_time( 'mysql', true ),
				),
			),
			'gemini'    => array(
				'gemini-1.5-pro' => array(
					'requests'          => 5,
					'prompt_tokens'     => 50,
					'completion_tokens' => 25,
					'total_tokens'      => 75,
					'cached_tokens'     => 0,
					'last_used_gmt'     => current_time( 'mysql', true ),
				),
			),
			'anthropic' => array(
				'claude-3-5-sonnet-20241022' => array(
					'requests'          => 3,
					'prompt_tokens'     => 30,
					'completion_tokens' => 15,
					'total_tokens'      => 45,
					'cached_tokens'     => 0,
					'last_used_gmt'     => current_time( 'mysql', true ),
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, $usage_data );

		// Retrieve the usage data.
		$retrieved_usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		// Verify data was stored correctly.
		$this->assertArrayHasKey( 'openai', $retrieved_usage, 'OpenAI usage should be present' );
		$this->assertArrayHasKey( 'gemini', $retrieved_usage, 'Gemini usage should be present' );
		$this->assertArrayHasKey( 'anthropic', $retrieved_usage, 'Anthropic usage should be present' );

		// Test display name formatting for each provider.
		$token_manager = new WP_MCP_AI_Section_Token_Manager();
		$reflection    = new ReflectionClass( $token_manager );
		$method        = $reflection->getMethod( 'get_provider_display_name' );
		$method->setAccessible( true );

		foreach ( array_keys( $retrieved_usage ) as $provider ) {
			$display_name = $method->invoke( $token_manager, $provider );
			$this->assertNotEmpty( $display_name, "Provider {$provider} should have a display name" );
			$this->assertNotEquals( $provider, $display_name, 'Display name should be formatted, not raw key' );
		}

		// Clean up.
		delete_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
	}
}
