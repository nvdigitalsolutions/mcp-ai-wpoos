<?php
/**
 * Tests for the Language Model Router class.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Language_Model_Router_Test extends WP_UnitTestCase {

	/**
	 * Ensure the router uses the provider_priority_list when no provider is specified.
	 */
	public function test_router_uses_provider_priority_list() {
		// Set provider_priority_list with gemini first.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'provider_priority_list' => array( 'gemini', 'openai', 'ollama', 'lm_studio' ),
			)
		);

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect gemini_client to be called (first in priority list).
		$gemini_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call without specifying a provider - should use first from priority list.
		$result = $router->create_chat_completion( array(), array() );

		$this->assertIsArray( $result );
	}

	/**
	 * Ensure the router falls back to next provider when first one fails.
	 */
	public function test_router_falls_back_to_next_provider_on_failure() {
		// Set provider_priority_list with gemini first, openai second.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'provider_priority_list' => array( 'gemini', 'openai', 'ollama', 'lm_studio' ),
			)
		);

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Gemini fails.
		$gemini_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( new WP_Error( 'gemini_error', 'Gemini failed' ) );

		// OpenAI succeeds (fallback).
		$openai_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response from openai' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call without specifying a provider - should try gemini, then fall back to openai.
		$result = $router->create_chat_completion( array(), array() );

		$this->assertIsArray( $result );
		$this->assertEquals( 'test response from openai', $result['content'] );
	}

	/**
	 * Ensure the router uses OpenAI as fallback when no priority list is configured.
	 */
	public function test_router_uses_default_priority_list_when_not_configured() {
		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect openai_client to be called (default priority list starts with openai).
		$openai_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call without specifying a provider - should use default priority list.
		$result = $router->create_chat_completion( array(), array() );

		$this->assertIsArray( $result );
	}

	/**
	 * Ensure the router returns error when all providers in priority list fail.
	 */
	public function test_router_returns_error_when_all_providers_fail() {
		// Set provider_priority_list.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'provider_priority_list' => array( 'gemini', 'openai' ),
			)
		);

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Both providers fail.
		$gemini_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( new WP_Error( 'gemini_error', 'Gemini failed' ) );

		$openai_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( new WP_Error( 'openai_error', 'OpenAI failed' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call without specifying a provider - should try both and return error.
		$result = $router->create_chat_completion( array(), array() );

		$this->assertWPError( $result );
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
	 * Ensure the router respects explicit provider override and does not use priority list.
	 */
	public function test_router_respects_explicit_provider_override() {
		// Set provider_priority_list with gemini first.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'provider_priority_list' => array( 'gemini', 'openai', 'ollama', 'lm_studio' ),
			)
		);

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );

		// Expect ollama_client to be called despite gemini being first in priority.
		$ollama_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		// Gemini should not be called when provider is explicitly set.
		$gemini_client->expects( $this->never() )
			->method( 'create_chat_completion' );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client );

		// Call with ollama provider explicitly - should override priority list.
		$result = $router->create_chat_completion( array(), array( 'provider' => 'ollama' ) );

		$this->assertIsArray( $result );
	}
}
