<?php
/**
 * Tests for the Language Model Router class.
 */
class WP_MCP_AI_Language_Model_Router_Test extends WP_UnitTestCase {

	/**
	 * Ensure the router uses the default_provider setting when no provider is specified.
	 */
	public function test_router_uses_default_provider_from_settings() {
		// Set default_provider to gemini.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array( 'default_provider' => 'gemini' ) );

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect gemini_client to be called.
		$gemini_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call without specifying a provider - should use default from settings.
		$result = $router->create_chat_completion( array(), array() );

		$this->assertIsArray( $result );
	}

	/**
	 * Ensure the router uses OpenAI as fallback when no default_provider is configured.
	 */
	public function test_router_uses_openai_as_ultimate_fallback() {
		// Clear default_provider setting.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect openai_client to be called as fallback.
		$openai_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call without specifying a provider - should use OpenAI as fallback.
		$result = $router->create_chat_completion( array(), array() );

		$this->assertIsArray( $result );
	}

	/**
	 * Ensure the router routes to LM Studio when specified.
	 */
	public function test_router_routes_to_lm_studio() {
		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect lm_studio_client to be called.
		$lm_studio_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call with lm_studio provider explicitly.
		$result = $router->create_chat_completion( array(), array( 'provider' => 'lm_studio' ) );

		$this->assertIsArray( $result );
	}

	/**
	 * Ensure the router respects explicit provider override even when default is set.
	 */
	public function test_router_respects_explicit_provider_override() {
		// Set default_provider to openai.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array( 'default_provider' => 'openai' ) );

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect ollama_client to be called despite OpenAI being default.
		$ollama_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call with ollama provider explicitly - should override default.
		$result = $router->create_chat_completion( array(), array( 'provider' => 'ollama' ) );

		$this->assertIsArray( $result );
	}
}
