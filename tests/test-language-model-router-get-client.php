<?php
/**
 * Tests for Language Model Router get_client method.
 *
 * Verifies that the router's get_client method properly handles assistant configuration
 * and returns the router instance for making LLM API calls.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Language Model Router get_client method.
 */
class WP_MCP_AI_Language_Model_Router_Get_Client_Test extends WP_UnitTestCase {

	/**
	 * Router instance.
	 *
	 * @var WP_MCP_AI_Language_Model_Router
	 */
	protected $router;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-language-model-router.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';
		}

		// Create router instance with mock clients.
		$openai_client = new WP_MCP_AI_OpenAI_Client();
		$gemini_client = new WP_MCP_AI_Gemini_Client();

		$this->router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client );
	}

	/**
	 * Test that get_client returns the router instance.
	 */
	public function test_get_client_returns_router_instance() {
		$assistant_config = array(
			'system_prompt' => 'You are a helpful assistant.',
			'model'         => 'gpt-4',
			'provider'      => 'openai',
			'tools'         => array( 'search', 'calculator' ),
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should not return WP_Error' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'get_client should return router instance' );
		$this->assertSame( $this->router, $client, 'get_client should return the same router instance' );
	}

	/**
	 * Test that get_client works with minimal assistant config.
	 */
	public function test_get_client_with_minimal_config() {
		$assistant_config = array(
			'provider' => 'openai',
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should work with minimal config' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'Should return router instance' );
	}

	/**
	 * Test that get_client works with empty config.
	 */
	public function test_get_client_with_empty_config() {
		$assistant_config = array();

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should work with empty config' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'Should return router instance' );
	}

	/**
	 * Test that get_client handles system_prompt in config.
	 */
	public function test_get_client_handles_system_prompt() {
		$assistant_config = array(
			'system_prompt' => 'You are a helpful AI assistant specialized in WordPress development.',
			'model'         => 'gpt-4',
			'provider'      => 'openai',
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should handle system_prompt' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'Should return router instance' );
	}

	/**
	 * Test that get_client handles Ollama provider config.
	 */
	public function test_get_client_handles_ollama_provider() {
		$assistant_config = array(
			'system_prompt' => 'You are a local AI assistant.',
			'model'         => 'llama2',
			'provider'      => 'ollama',
			'temperature'   => 0.7,
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should handle ollama provider' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'Should return router instance' );
	}

	/**
	 * Test that get_client handles embedded provider config.
	 */
	public function test_get_client_handles_embedded_provider() {
		$assistant_config = array(
			'system_prompt' => 'You are a browser-based AI assistant.',
			'model'         => 'Phi-3.5-mini-instruct-q4f16_1-MLC',
			'provider'      => 'embedded',
			'tools'         => array( 'search', 'calculator', 'file_reader' ),
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should handle embedded provider' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'Should return router instance' );
	}

	/**
	 * Test that get_client handles tools configuration.
	 */
	public function test_get_client_handles_tools_config() {
		$assistant_config = array(
			'system_prompt' => 'You have access to various tools.',
			'provider'      => 'openai',
			'tools'         => array(
				'web_search',
				'calculator',
				'file_reader',
				'wordpress_query',
			),
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertNotWPError( $client, 'get_client should handle tools config' );
		$this->assertInstanceOf( 'WP_MCP_AI_Language_Model_Router', $client, 'Should return router instance' );
	}

	/**
	 * Test that returned client has create_chat_completion method.
	 */
	public function test_client_has_create_chat_completion_method() {
		$assistant_config = array(
			'system_prompt' => 'You are a helpful assistant.',
			'provider'      => 'openai',
		);

		$client = $this->router->get_client( $assistant_config );

		$this->assertTrue( method_exists( $client, 'create_chat_completion' ), 'Client should have create_chat_completion method' );
	}
}
