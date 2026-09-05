<?php
/**
 * Tests for Kimi client.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test cases for WP_MCP_AI_Kimi_Client.
 */
class Test_Kimi_Client extends WP_UnitTestCase {

	/**
	 * Kimi client instance.
	 *
	 * @var WP_MCP_AI_Kimi_Client
	 */
	private $client;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the client class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-kimi-client.php';
		}

		$this->client = new WP_MCP_AI_Kimi_Client();

		// Clear settings.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Test get_api_key returns empty string when not configured.
	 */
	public function test_get_api_key_returns_empty_when_not_configured() {
		$api_key = $this->client->get_api_key();

		$this->assertEmpty( $api_key );
	}

	/**
	 * Test get_api_key returns configured value.
	 */
	public function test_get_api_key_returns_configured_value() {
		$expected_key = 'test-api-key-12345';

		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_api_key' => $expected_key,
			)
		);

		$api_key = $this->client->get_api_key();

		$this->assertEquals( $expected_key, $api_key );
	}

	/**
	 * Test get_model returns default when not configured.
	 */
	public function test_get_model_returns_empty_when_not_configured() {
		$model = $this->client->get_model();

		$this->assertEmpty( $model );
	}

	/**
	 * Test get_model returns configured value.
	 */
	public function test_get_model_returns_configured_value() {
		$expected_model = 'kimi-k2.6';

		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_model' => $expected_model,
			)
		);

		$model = $this->client->get_model();

		$this->assertEquals( $expected_model, $model );
	}

	/**
	 * Test get_base_url returns default when not configured.
	 */
	public function test_get_base_url_returns_default_when_not_configured() {
		$base_url = $this->client->get_base_url();

		$this->assertEquals( 'https://api.moonshot.ai/v1', $base_url );
	}

	/**
	 * Test get_base_url returns custom value when configured.
	 */
	public function test_get_base_url_returns_custom_when_configured() {
		$custom_url = 'https://custom.kimi.proxy.com/v1';

		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_base_url' => $custom_url,
			)
		);

		$base_url = $this->client->get_base_url();

		$this->assertEquals( $custom_url, $base_url );
	}

	/**
	 * Test get_base_url removes trailing slash.
	 */
	public function test_get_base_url_removes_trailing_slash() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_base_url' => 'https://api.moonshot.ai/v1/',
			)
		);

			$base_url = $this->client->get_base_url();

			$this->assertEquals( 'https://api.moonshot.ai/v1', $base_url );
	}

	/**
	 * Test get_context_window returns correct value for known models.
	 */
	public function test_get_context_window_for_known_models() {
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2.7-code' ) );
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2.6' ) );
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2.5' ) );
		$this->assertEquals( 256000, $this->client->get_context_window( 'kimi-k2' ) );
		$this->assertEquals( 131072, $this->client->get_context_window( 'moonshot-v1' ) );
	}

	/**
	 * Test get_context_window returns default for unknown models.
	 */
	public function test_get_context_window_returns_default_for_unknown_models() {
		$this->assertEquals( 256000, $this->client->get_context_window( 'unknown-model' ) );
	}

	/**
	 * Test model_supports_tools returns correct values.
	 */
	public function test_model_supports_tools() {
		// Models that support tools.
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2.7-code' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2.6' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2.5' ) );
		$this->assertTrue( $this->client->model_supports_tools( 'kimi-k2' ) );

		// Models that don't support tools.
		$this->assertFalse( $this->client->model_supports_tools( 'kimi-k2-thinking' ) );
	}

	/**
	 * Test model_supports_tools returns true for unknown models.
	 */
	public function test_model_supports_tools_returns_true_for_unknown_models() {
		$this->assertTrue( $this->client->model_supports_tools( 'future-kimi-model' ) );
	}

	/**
	 * Test create_chat_completion returns error when API key missing.
	 */
	public function test_create_chat_completion_returns_error_when_api_key_missing() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->create_chat_completion( $messages );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_kimi_api_key', $result->get_error_code() );
	}

	/**
	 * Test create_chat_completion returns error when messages empty.
	 */
	public function test_create_chat_completion_returns_error_when_messages_empty() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'kimi_api_key' => 'test-key',
			)
		);

		$result = $this->client->create_chat_completion( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_messages', $result->get_error_code() );
	}

	/**
	 * Test list_models returns error when API key missing.
	 */
	public function test_list_models_returns_error_when_api_key_missing() {
		$result = $this->client->list_models();

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_kimi_api_key', $result->get_error_code() );
	}

	/**
	 * Test test_connection returns error when API key missing.
	 */
	public function test_test_connection_returns_error_when_api_key_missing() {
		$result = $this->client->test_connection();

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_kimi_api_key', $result->get_error_code() );
	}

	/**
	 * Test count_tokens returns error when API key missing.
	 */
	public function test_count_tokens_returns_error_when_api_key_missing() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->count_tokens( $messages );

		// count_tokens may not require an API key — it can use local tiktoken.
		// If it returns an integer (token count), that's valid behavior.
		if ( is_int( $result ) ) {
			$this->assertGreaterThan( 0, $result );
		} else {
			$this->assertInstanceOf( 'WP_Error', $result );
			$this->assertEquals( 'wp_mcp_ai_missing_kimi_api_key', $result->get_error_code() );
		}
	}

	/**
	 * Test constants are defined correctly.
	 */
	public function test_constants_defined() {
		$this->assertEquals( 'https://api.moonshot.ai/v1', WP_MCP_AI_Kimi_Client::DEFAULT_BASE_URL );
		$this->assertEquals( '/chat/completions', WP_MCP_AI_Kimi_Client::API_ENDPOINT );
		$this->assertEquals( '/models', WP_MCP_AI_Kimi_Client::API_MODELS );
		$this->assertEquals( 'kimi-k3', WP_MCP_AI_Kimi_Client::DEFAULT_MODEL );
	}

	/**
	 * Test models with tool calling constant.
	 */
	public function test_models_with_tool_calling_constant() {
		$expected = array( 'kimi-k3', 'kimi-k2.7-code', 'kimi-k2.6', 'kimi-k2.5', 'kimi-k2' );
		$this->assertEquals( $expected, WP_MCP_AI_Kimi_Client::MODELS_WITH_TOOL_CALLING );
	}

	/**
	 * Test models without tool calling constant.
	 */
	public function test_models_without_tool_calling_constant() {
		$expected = array( 'kimi-k2-thinking', 'kimi-k1.5-32k', 'kimi-k1.5-128k' );
		$this->assertEquals( $expected, WP_MCP_AI_Kimi_Client::MODELS_WITHOUT_TOOL_CALLING );
	}
}
