<?php
/**
 * Tests for Cloudflare Workers AI token usage tracking.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare token tracking functionality.
 */
class Test_Cloudflare_Token_Tracking extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance for testing.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the Cloudflare client class.
		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
		}

		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that Cloudflare response includes usage data when provided by API.
	 */
	public function test_usage_extraction_from_api_response() {
		$api_response = array(
			'result' => array(
				'response' => 'This is a test response from Cloudflare Workers AI.',
			),
			'usage'   => array(
				'prompt_tokens'     => 15,
				'completion_tokens' => 10,
				'total_tokens'      => 25,
			),
			'success' => true,
		);

		$normalized = $this->invoke_normalize_response( $api_response, '@cf/meta/llama-3.1-8b-instruct' );

		// Check that usage data is extracted.
		$this->assertArrayHasKey( 'usage', $normalized );
		$this->assertEquals( 15, $normalized['usage']['prompt_tokens'] );
		$this->assertEquals( 10, $normalized['usage']['completion_tokens'] );
		$this->assertEquals( 25, $normalized['usage']['total_tokens'] );

		// Check that provider and model are added to usage.
		$this->assertEquals( 'cloudflare', $normalized['usage']['provider'] );
		$this->assertEquals( '@cf/meta/llama-3.1-8b-instruct', $normalized['usage']['model'] );
	}

	/**
	 * Test that Cloudflare response includes usage data from result object.
	 */
	public function test_usage_extraction_from_result_object() {
		$api_response = array(
			'result'  => array(
				'response' => 'Another test response.',
				'usage'    => array(
					'prompt_tokens'     => 20,
					'completion_tokens' => 30,
					'total_tokens'      => 50,
				),
			),
			'success' => true,
		);

		$normalized = $this->invoke_normalize_response( $api_response, '@cf/meta/llama-3.2-3b-instruct' );

		// Check that usage data is extracted from result object.
		$this->assertArrayHasKey( 'usage', $normalized );
		$this->assertEquals( 20, $normalized['usage']['prompt_tokens'] );
		$this->assertEquals( 30, $normalized['usage']['completion_tokens'] );
		$this->assertEquals( 50, $normalized['usage']['total_tokens'] );
	}

	/**
	 * Test that usage is estimated when not provided by API.
	 */
	public function test_usage_estimation_when_missing() {
		$api_response = array(
			'result'  => array(
				'response' => 'This is a response with approximately 40 characters for estimation purposes.',
			),
			'success' => true,
		);

		$normalized = $this->invoke_normalize_response( $api_response, '@cf/mistral/mistral-7b-instruct-v0.1' );

		// Check that usage is estimated (not zero).
		$this->assertArrayHasKey( 'usage', $normalized );
		$this->assertGreaterThan( 0, $normalized['usage']['completion_tokens'] );
		$this->assertGreaterThan( 0, $normalized['usage']['total_tokens'] );

		// Prompt tokens should be 0 since we can't estimate without request data.
		$this->assertEquals( 0, $normalized['usage']['prompt_tokens'] );

		// Check estimation (~4 chars per token, so ~80 chars / 4 = ~20 tokens).
		$expected_tokens = ceil( 80 / 4 );
		$this->assertEquals( $expected_tokens, $normalized['usage']['completion_tokens'] );
	}

	/**
	 * Test that provider field is included in response.
	 */
	public function test_provider_field_in_response() {
		$api_response = array(
			'result'  => array(
				'response' => 'Test response.',
			),
			'success' => true,
		);

		$normalized = $this->invoke_normalize_response( $api_response, '@cf/meta/llama-2-7b-chat-int4' );

		// Check that provider is included at top level.
		$this->assertArrayHasKey( 'provider', $normalized );
		$this->assertEquals( 'cloudflare', $normalized['provider'] );
	}

	/**
	 * Test that model is included in response.
	 */
	public function test_model_field_in_response() {
		$api_response = array(
			'result'  => array(
				'response' => 'Test response.',
			),
			'success' => true,
		);

		$model      = '@cf/qwen/qwen1.5-0.5b-chat';
		$normalized = $this->invoke_normalize_response( $api_response, $model );

		// Check that model is included.
		$this->assertArrayHasKey( 'model', $normalized );
		$this->assertEquals( $model, $normalized['model'] );
	}

	/**
	 * Test Cloudflare model pricing in usage tracker.
	 */
	public function test_cloudflare_pricing() {
		if ( ! class_exists( 'WP_MCP_AI_Usage_Tracker' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Usage_Tracker class not available' );
		}

		// Test Llama 3.2 1B pricing.
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'cloudflare', '@cf/meta/llama-3.2-1b-instruct', 1000, 1000 );
		$this->assertEquals( 0.000027 + 0.000201, $cost, 'Llama 3.2 1B pricing incorrect', 0.000001 );

		// Test Llama 3.1 8B pricing.
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'cloudflare', '@cf/meta/llama-3.1-8b-instruct', 1000, 1000 );
		$this->assertEquals( 0.000282 + 0.000827, $cost, 'Llama 3.1 8B pricing incorrect', 0.000001 );

		// Test Mistral pricing.
		$cost = WP_MCP_AI_Usage_Tracker::calculate_cost( 'cloudflare', '@cf/mistral/mistral-7b-instruct-v0.1', 1000, 1000 );
		$this->assertEquals( 0.000110 + 0.000190, $cost, 'Mistral 7B pricing incorrect', 0.000001 );
	}

	/**
	 * Test provider display name for Cloudflare.
	 */
	public function test_cloudflare_provider_display_name() {
		if ( ! class_exists( 'WP_MCP_AI_Token_Usage_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Token_Usage_Service class not available' );
		}

		$display_name = WP_MCP_AI_Token_Usage_Service::get_provider_display_name( 'cloudflare' );
		$this->assertEquals( 'Cloudflare Workers AI', $display_name );
	}

	/**
	 * Test provider display name for Hugging Face.
	 */
	public function test_huggingface_provider_display_name() {
		if ( ! class_exists( 'WP_MCP_AI_Token_Usage_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Token_Usage_Service class not available' );
		}

		$display_name = WP_MCP_AI_Token_Usage_Service::get_provider_display_name( 'huggingface' );
		$this->assertEquals( 'Hugging Face', $display_name );
	}

	/**
	 * Helper method to invoke the protected normalize_response method.
	 *
	 * @param array  $decoded Decoded API response.
	 * @param string $model   Model name.
	 * @return array Normalized response.
	 */
	private function invoke_normalize_response( array $decoded, $model ) {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		return $method->invoke( $this->client, $decoded, $model );
	}
}
