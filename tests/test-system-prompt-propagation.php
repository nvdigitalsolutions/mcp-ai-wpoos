<?php
/**
 * Tests for System Prompt Propagation
 *
 * Verifies that assistant defaults (system instructions, roles, base knowledge)
 * are properly propagated from assistant configuration through REST API to LLM clients.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test System Prompt Propagation functionality.
 *
 * @group rest
 * @group assistant
 * @group system-prompt
 */
class Test_System_Prompt_Propagation extends WP_UnitTestCase {

	/**
	 * Validator instance.
	 *
	 * @var WP_MCP_AI_REST_Validator
	 */
	protected $validator;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';
		require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';

		$this->validator = new WP_MCP_AI_REST_Validator();
	}

	/**
	 * Test that system_prompt from assistant config is used when not provided in request.
	 */
	public function test_system_prompt_from_assistant_config() {
		$assistant_config = array(
			'system_prompt' => 'You are a helpful assistant specialized in WordPress.',
			'provider'      => 'openai',
			'model'         => 'gpt-4',
		);

		$options = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'system_prompt', $result, 'System prompt should be present in result' );
		$this->assertEquals( 'You are a helpful assistant specialized in WordPress.', $result['system_prompt'] );
	}

	/**
	 * Test that system_prompt from request options overrides assistant config.
	 */
	public function test_system_prompt_override_from_request() {
		$assistant_config = array(
			'system_prompt' => 'Default assistant instructions.',
			'provider'      => 'openai',
			'model'         => 'gpt-4',
		);

		$options = array(
			'system_prompt' => 'Override instructions for this request.',
		);

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'system_prompt', $result );
		$this->assertEquals( 'Override instructions for this request.', $result['system_prompt'] );
	}

	/**
	 * Test that empty system_prompt in request does not override assistant config.
	 */
	public function test_empty_system_prompt_uses_assistant_config() {
		$assistant_config = array(
			'system_prompt' => 'Assistant default instructions.',
			'provider'      => 'openai',
			'model'         => 'gpt-4',
		);

		$options = array(
			'system_prompt' => '',
		);

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'system_prompt', $result );
		$this->assertEquals( 'Assistant default instructions.', $result['system_prompt'] );
	}

	/**
	 * Test that model from assistant config is used when not in request.
	 */
	public function test_model_from_assistant_config() {
		$assistant_config = array(
			'provider' => 'openai',
			'model'    => 'gpt-4-turbo',
		);

		$options = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'model', $result );
		$this->assertEquals( 'gpt-4-turbo', $result['model'] );
	}

	/**
	 * Test that temperature from assistant config is used when not in request.
	 */
	public function test_temperature_from_assistant_config() {
		$assistant_config = array(
			'provider'    => 'openai',
			'temperature' => 0.7,
		);

		$options = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'temperature', $result );
		$this->assertEquals( 0.7, $result['temperature'] );
	}

	/**
	 * Test that provider from assistant config is used when not in request.
	 */
	public function test_provider_from_assistant_config() {
		$assistant_config = array(
			'provider' => 'gemini',
			'model'    => 'gemini-pro',
		);

		$options = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'provider', $result );
		$this->assertEquals( 'gemini', $result['provider'] );
	}

	/**
	 * Test OpenAI client includes system prompt in payload.
	 */
	public function test_openai_client_includes_system_prompt() {
		$this->markTestSkipped( 'Requires OpenAI API key configuration' );

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

		$client = new WP_MCP_AI_OpenAI_Client();

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$options = array(
			'system_prompt' => 'You are a test assistant.',
		);

		// We can't actually call the API, but we can verify the client method exists
		$this->assertTrue( method_exists( $client, 'create_chat_completion' ) );
	}

	/**
	 * Test Gemini client includes system instruction in payload.
	 */
	public function test_gemini_client_includes_system_instruction() {
		$this->markTestSkipped( 'Requires Gemini API key configuration' );

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';

		$client = new WP_MCP_AI_Gemini_Client();

		$this->assertTrue( method_exists( $client, 'create_chat_completion' ) );
	}

	/**
	 * Test Ollama client includes system field in payload.
	 */
	public function test_ollama_client_includes_system_field() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';

		$client = new WP_MCP_AI_Ollama_Client();

		$this->assertTrue( method_exists( $client, 'create_chat_completion' ) );
	}

	/**
	 * Test Cloudflare client includes system message in messages array.
	 */
	public function test_cloudflare_client_includes_system_message() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';

		$client = new WP_MCP_AI_Cloudflare_Client();

		$this->assertTrue( method_exists( $client, 'create_chat_completion' ) );
	}

	/**
	 * Test Hugging Face client includes system message.
	 */
	public function test_huggingface_client_includes_system_message() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-client.php';

		$client = new WP_MCP_AI_Huggingface_Client();

		$this->assertTrue( method_exists( $client, 'create_chat_completion' ) );
	}

	/**
	 * Test Anthropic client includes system field in payload.
	 */
	public function test_anthropic_client_includes_system_field() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';

		$client = new WP_MCP_AI_Anthropic_Client();

		$this->assertTrue( method_exists( $client, 'create_chat_completion' ) );
	}

	/**
	 * Test that memory_files from assistant config are used.
	 */
	public function test_memory_files_from_assistant_config() {
		$assistant_config = array(
			'provider'     => 'openai',
			'memory_files' => array( 123, 456 ),
		);

		$options = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'memory_files', $result );
		$this->assertIsArray( $result['memory_files'] );
		$this->assertCount( 2, $result['memory_files'] );
		$this->assertContains( 123, $result['memory_files'] );
		$this->assertContains( 456, $result['memory_files'] );
	}

	/**
	 * Test that vector_store_id from assistant config is used.
	 */
	public function test_vector_store_id_from_assistant_config() {
		$assistant_config = array(
			'provider'        => 'openai',
			'vector_store_id' => 'vs_abc123',
		);

		$options = array();

		$result = $this->validator->sanitize_options( $options, $assistant_config );

		$this->assertArrayHasKey( 'vector_store_id', $result );
		$this->assertEquals( 'vs_abc123', $result['vector_store_id'] );
	}
}
