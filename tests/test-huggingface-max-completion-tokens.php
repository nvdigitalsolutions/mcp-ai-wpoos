<?php
/**
 * Tests for Hugging Face max_completion_tokens fix.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Hugging Face max_completion_tokens enforcement.
 *
 * @group huggingface-client
 * @group huggingface-max-tokens
 */
class WP_MCP_AI_Huggingface_Max_Completion_Tokens_Tests extends WP_UnitTestCase {

	/**
	 * Hugging Face client instance.
	 *
	 * @var WP_MCP_AI_Huggingface_Client
	 */
	protected $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client = new WP_MCP_AI_Huggingface_Client();

		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that build_payload uses max_completion_tokens instead of max_tokens.
	 */
	public function test_build_payload_uses_max_completion_tokens() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'Qwen/Qwen3-Coder-30B-A3B-Instruct',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Write a function',
			),
		);

		$options = array(
			'max_tokens' => 10000,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options, 'Qwen/Qwen3-Coder-30B-A3B-Instruct' );

		// Verify max_completion_tokens is set instead of max_tokens.
		$this->assertArrayHasKey( 'max_completion_tokens', $payload, 'Payload should use max_completion_tokens' );
		$this->assertArrayNotHasKey( 'max_tokens', $payload, 'Payload should not use max_tokens' );
	}

	/**
	 * Test that model-specific max_completion_tokens limit is enforced.
	 */
	public function test_model_limit_is_enforced_for_qwen_models() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'Qwen/Qwen3-Coder-30B-A3B-Instruct',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Write a function',
			),
		);

		// Request more tokens than the model limit.
		$options = array(
			'max_tokens' => 20000,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options, 'Qwen/Qwen3-Coder-30B-A3B-Instruct' );

		// Verify the limit is enforced (8192 is the max for Qwen3-Coder-30B-A3B-Instruct).
		$this->assertArrayHasKey( 'max_completion_tokens', $payload );
		$this->assertLessThanOrEqual( 8192, $payload['max_completion_tokens'], 'max_completion_tokens should be capped at 8192 for Qwen3-Coder-30B-A3B-Instruct' );
		$this->assertEquals( 8192, $payload['max_completion_tokens'], 'max_completion_tokens should be exactly 8192 when requesting more' );
	}

	/**
	 * Test that model limit is enforced when no explicit max_tokens is provided.
	 */
	public function test_model_limit_enforced_with_resource_manager_default() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'Qwen/Qwen2.5-72B-Instruct',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		// No max_tokens specified - should use Resource Manager default.
		$options = array();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options, 'Qwen/Qwen2.5-72B-Instruct' );

		// Verify the limit is enforced even with Resource Manager default.
		$this->assertArrayHasKey( 'max_completion_tokens', $payload );
		$this->assertLessThanOrEqual( 8192, $payload['max_completion_tokens'], 'max_completion_tokens should be capped at 8192 for Qwen models even with Resource Manager default' );
	}

	/**
	 * Test that model config has max_completion_tokens for Qwen models.
	 */
	public function test_qwen_models_have_max_completion_tokens_in_config() {
		$qwen_models = array(
			'Qwen/Qwen3-Coder-30B-A3B-Instruct',
			'Qwen/Qwen2.5-72B-Instruct',
			'Qwen/Qwen2.5-32B-Instruct',
			'Qwen/Qwen2.5-7B-Instruct',
		);

		foreach ( $qwen_models as $model ) {
			$config = WP_MCP_AI_Model_Config::get_model_config( $model );
			$this->assertIsArray( $config, "Config for $model should be an array" );
			$this->assertArrayHasKey( 'max_completion_tokens', $config, "Config for $model should have max_completion_tokens" );
			$this->assertEquals( 8192, $config['max_completion_tokens'], "max_completion_tokens for $model should be 8192" );
		}
	}

	/**
	 * Test that payload respects lower explicit max_tokens value.
	 */
	public function test_explicit_lower_value_is_respected() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'huggingface_model' => 'Qwen/Qwen3-Coder-30B-A3B-Instruct',
			)
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Short answer please',
			),
		);

		// Request fewer tokens than the model limit.
		$options = array(
			'max_tokens' => 500,
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options, 'Qwen/Qwen3-Coder-30B-A3B-Instruct' );

		// Verify the explicit lower value is used.
		$this->assertArrayHasKey( 'max_completion_tokens', $payload );
		$this->assertEquals( 500, $payload['max_completion_tokens'], 'Should use explicit lower value when below model limit' );
	}
}
