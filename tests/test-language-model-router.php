<?php
/**
 * Tests for the Language Model Router class.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

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
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

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
	 * Ensure the router uses the default priority list when no settings exist.
	 */
	public function test_router_uses_default_priority_list_when_not_configured() {
		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// The default priority list is derived from the Model Config catalog
		// (alphabetically sorted), so resolve the first provider from the same
		// source instead of hardcoding an order that the catalog can change.
		$defaults       = WP_MCP_AI_Admin_Settings::get_default_settings();
		$first_provider = isset( $defaults['provider_priority_list'][0] ) ? $defaults['provider_priority_list'][0] : 'openai';

		$openai_client    = $this->createMock( WP_MCP_AI_OpenAI_Client::class );
		$gemini_client    = $this->createMock( WP_MCP_AI_Gemini_Client::class );
		$ollama_client    = $this->createMock( WP_MCP_AI_Ollama_Client::class );
		$lm_studio_client = $this->createMock( WP_MCP_AI_LM_Studio_Client::class );
		$anthropic_client = $this->createMock( WP_MCP_AI_Anthropic_Client::class );

		$clients = array(
			'openai'    => $openai_client,
			'gemini'    => $gemini_client,
			'ollama'    => $ollama_client,
			'lm_studio' => $lm_studio_client,
			'anthropic' => $anthropic_client,
		);

		$this->assertArrayHasKey( $first_provider, $clients, 'Expected the first default provider to be mockable in this test.' );

		// Expect the first provider in the default priority list to be called.
		$clients[ $first_provider ]->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( array( 'content' => 'test response' ) );

		$router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client, $ollama_client, $lm_studio_client, $anthropic_client );

		// Call without specifying a provider - should use the default priority list.
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
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

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
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

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
