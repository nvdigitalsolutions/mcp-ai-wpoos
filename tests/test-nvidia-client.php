<?php
/**
 * Tests for WP_MCP_AI_Nvidia_Client.
 *
 * Covers the offline-safe surface of the NVIDIA NIM client: accessors,
 * the DEFAULT_ENDPOINT_URL constant, error-message extraction across the
 * three documented response shapes, and the missing-credential / missing-model
 * short-circuits in test_connection() and create_chat_completion().
 *
 * Network-bound paths are exercised only for their input-validation early
 * returns to keep the suite fast and offline-safe.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the NVIDIA NIM API client.
 */
class Test_Nvidia_Client extends WP_UnitTestCase {

	/**
	 * Client instance.
	 *
	 * @var WP_MCP_AI_Nvidia_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_Nvidia_Client();
		wp_cache_flush();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		remove_all_filters( 'wp_mcp_ai_nvidia_fallback_model' );
		wp_cache_flush();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constants & accessors.
	// -------------------------------------------------------------------------

	/**
	 * Test DEFAULT_ENDPOINT_URL constant has expected value.
	 */
	public function test_default_endpoint_url_constant() {
		$this->assertSame(
			'https://integrate.api.nvidia.com/v1',
			WP_MCP_AI_Nvidia_Client::DEFAULT_ENDPOINT_URL
		);
	}

	/**
	 * Test get_api_key() returns empty string when not configured.
	 */
	public function test_get_api_key_returns_empty_when_unconfigured() {
		$this->assertSame( '', $this->client->get_api_key() );
	}

	/**
	 * Test get_api_key() returns the configured value verbatim.
	 */
	public function test_get_api_key_returns_configured_value() {
		update_option( 'wp_mcp_ai_settings', array( 'nvidia_api_key' => 'nvapi-test-123' ) );
		$this->assertSame( 'nvapi-test-123', $this->client->get_api_key() );
	}

	/**
	 * Test get_endpoint_url() falls back to DEFAULT_ENDPOINT_URL when not set.
	 */
	public function test_get_endpoint_url_falls_back_to_default() {
		$this->assertSame(
			WP_MCP_AI_Nvidia_Client::DEFAULT_ENDPOINT_URL,
			$this->client->get_endpoint_url()
		);
	}

	/**
	 * Test get_endpoint_url() also falls back when value is an empty string.
	 */
	public function test_get_endpoint_url_falls_back_when_empty_string() {
		update_option( 'wp_mcp_ai_settings', array( 'nvidia_endpoint_url' => '' ) );
		$this->assertSame(
			WP_MCP_AI_Nvidia_Client::DEFAULT_ENDPOINT_URL,
			$this->client->get_endpoint_url()
		);
	}

	/**
	 * Test get_endpoint_url() honours a configured value.
	 */
	public function test_get_endpoint_url_returns_configured_value() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'nvidia_endpoint_url' => 'https://nim.example.com/v1' )
		);
		$this->assertSame( 'https://nim.example.com/v1', $this->client->get_endpoint_url() );
	}

	/**
	 * Test get_model() returns empty string when not configured.
	 */
	public function test_get_model_returns_empty_when_unconfigured() {
		$this->assertSame( '', $this->client->get_model() );
	}

	/**
	 * Test get_model() returns the configured value.
	 */
	public function test_get_model_returns_configured_value() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'nvidia_model' => 'meta/llama-3.1-70b-instruct' )
		);
		$this->assertSame( 'meta/llama-3.1-70b-instruct', $this->client->get_model() );
	}

	// -------------------------------------------------------------------------
	// extract_error_message() — three response shapes.
	// -------------------------------------------------------------------------

	/**
	 * Test extract_error_message() prefers OpenAI-compatible error.message.
	 */
	public function test_extract_error_message_openai_format() {
		$message = $this->invoke_extract_error(
			array(
				'error' => array(
					'message' => 'Invalid API key',
					'type'    => 'authentication_error',
				),
			)
		);

		$this->assertSame( 'Invalid API key', $message );
	}

	/**
	 * Test extract_error_message() prepends the title to the detail
	 * for the NVIDIA-native shape with both fields present.
	 */
	public function test_extract_error_message_nvidia_native_format() {
		$message = $this->invoke_extract_error(
			array(
				'status' => 404,
				'title'  => 'Not Found',
				'detail' => 'The requested model does not exist',
			)
		);

		$this->assertSame( 'Not Found: The requested model does not exist', $message );
	}

	/**
	 * Test extract_error_message() handles the flat string error shape.
	 */
	public function test_extract_error_message_flat_string_format() {
		$message = $this->invoke_extract_error(
			array( 'error' => 'something went wrong' )
		);

		$this->assertSame( 'something went wrong', $message );
	}

	/**
	 * Test extract_error_message() falls back to the supplied default.
	 */
	public function test_extract_error_message_uses_fallback_when_unknown_shape() {
		$message = $this->invoke_extract_error( array( 'unknown_field' => 'value' ), 'fallback msg' );

		$this->assertSame( 'fallback msg', $message );
	}

	/**
	 * Test extract_error_message() returns the localized default when no
	 * fallback is provided and the shape is unrecognised.
	 */
	public function test_extract_error_message_returns_localized_default() {
		$message = $this->invoke_extract_error( array( 'unknown_field' => 'value' ) );

		$this->assertSame( 'Unexpected response from NVIDIA NIM.', $message );
	}

	// -------------------------------------------------------------------------
	// test_connection() short-circuits.
	// -------------------------------------------------------------------------

	/**
	 * Test test_connection() returns WP_Error when API key is missing.
	 */
	public function test_test_connection_errors_when_api_key_missing() {
		$result = $this->client->test_connection();

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_nvidia_api_key', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------------
	// create_chat_completion() short-circuits.
	// -------------------------------------------------------------------------

	/**
	 * Test create_chat_completion() returns WP_Error when API key is missing.
	 */
	public function test_chat_completion_errors_when_api_key_missing() {
		$result = $this->client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_nvidia_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_chat_completion() returns WP_Error when no model can be
	 * resolved (no options.model, no nvidia_model setting, no fallback).
	 */
	public function test_chat_completion_errors_when_model_missing() {
		update_option( 'wp_mcp_ai_settings', array( 'nvidia_api_key' => 'nvapi-test' ) );

		// Force the fallback filter to return an empty string so resolve_model()
		// has nothing to fall back to.
		add_filter( 'wp_mcp_ai_nvidia_fallback_model', '__return_empty_string' );

		$result = $this->client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_nvidia_model', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Invoke the protected extract_error_message() method via reflection.
	 *
	 * @param array  $decoded  Decoded JSON response body.
	 * @param string $fallback Fallback message.
	 * @return string Extracted message.
	 */
	private function invoke_extract_error( array $decoded, $fallback = '' ) {
		$reflection = new ReflectionMethod( $this->client, 'extract_error_message' );
		$reflection->setAccessible( true );

		return $reflection->invoke( $this->client, $decoded, $fallback );
	}
}
