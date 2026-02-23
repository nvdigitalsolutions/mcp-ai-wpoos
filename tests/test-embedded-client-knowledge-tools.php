<?php
/**
 * Test for embedded client maintaining assistant knowledge and tool access.
 *
 * This test verifies the fix for the issue where the embedded chat client
 * was not maintaining the assistant's knowledge (system prompt) and tool access.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded client knowledge and tool access.
 */
class Test_Embedded_Client_Knowledge_Tools extends WP_UnitTestCase {

	/**
	 * Test data storage.
	 *
	 * @var array
	 */
	private $test_data = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if base version (embedded provider not available).
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded provider not available in base version.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-assistant-cpt.php';
		}

		// Create test assistant with embedded provider, system prompt, and tools.
		$this->test_data['assistant_id'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Knowledge Test Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test assistant with knowledge and tools',
			)
		);

		// Set provider and model.
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f16_1-MLC' );

		// Set system prompt (assistant's knowledge).
		$system_prompt = 'You are a helpful assistant with specialized knowledge. Always be polite and professional.';
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_system_prompt', $system_prompt );

		// Set temperature.
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_temperature', 0.7 );

		// Set tools (assistant's capabilities).
		$tools = array( 'get_weather', 'search_posts' );
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_tools', $tools );
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tearDown(): void {
		if ( ! empty( $this->test_data['assistant_id'] ) ) {
			wp_delete_post( $this->test_data['assistant_id'], true );
		}

		parent::tearDown();
	}

	/**
	 * Test that system prompt is included in the config for embedded provider.
	 *
	 * This verifies that the assistant's knowledge (system prompt) is passed
	 * to the JavaScript client.
	 */
	public function test_system_prompt_in_config() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Capture the output to trigger script enqueuing.
		ob_start();
		$shortcode->render(
			array(
				'assistant' => $this->test_data['assistant_id'],
			)
		);
		$output = ob_get_clean();

		// Get the localized script data.
		global $wp_scripts;
		$localized = $wp_scripts->get_data( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'data' );

		// Parse the localized data to extract config.
		$this->assertNotEmpty( $localized, 'Script should have localized data' );

		// Extract the JSON config from the localized script data.
		// The format is: var wpMcpAiChatInstances = {...}.
		preg_match( '/wpMcpAiChatInstances\s*=\s*({.*?});/s', $localized, $matches );
		$this->assertNotEmpty( $matches[1], 'Should find wpMcpAiChatInstances data' );

		$instances = json_decode( $matches[1], true );
		$this->assertIsArray( $instances, 'Instances should be an array' );

		// Find the config for our assistant.
		$config = null;
		foreach ( $instances as $instance_config ) {
			if ( isset( $instance_config['assistantId'] ) && intval( $instance_config['assistantId'] ) === $this->test_data['assistant_id'] ) {
				$config = $instance_config;
				break;
			}
		}

		$this->assertNotNull( $config, 'Should find config for test assistant' );

		// Verify provider is embedded.
		$this->assertArrayHasKey( 'provider', $config, 'Config should have provider' );
		$this->assertEquals( 'embedded', $config['provider'], 'Provider should be embedded' );

		// Verify system prompt is included.
		$this->assertArrayHasKey( 'systemPrompt', $config, 'Config should have systemPrompt' );
		$this->assertStringContainsString( 'helpful assistant', $config['systemPrompt'], 'System prompt should contain expected text' );

		// Verify temperature is included.
		$this->assertArrayHasKey( 'temperature', $config, 'Config should have temperature' );
		$this->assertEquals( 0.7, $config['temperature'], 'Temperature should match' );
	}

	/**
	 * Test that tools are included in the config for embedded provider.
	 *
	 * This verifies that the assistant's tool access is passed to the
	 * JavaScript client in OpenAI-compatible format.
	 */
	public function test_tools_in_config() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Capture the output to trigger script enqueuing.
		ob_start();
		$shortcode->render(
			array(
				'assistant' => $this->test_data['assistant_id'],
			)
		);
		$output = ob_get_clean();

		// Get the localized script data.
		global $wp_scripts;
		$localized = $wp_scripts->get_data( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'data' );

		// Extract the JSON config.
		preg_match( '/wpMcpAiChatInstances\s*=\s*({.*?});/s', $localized, $matches );
		$this->assertNotEmpty( $matches[1], 'Should find wpMcpAiChatInstances data' );

		$instances = json_decode( $matches[1], true );

		// Find the config for our assistant.
		$config = null;
		foreach ( $instances as $instance_config ) {
			if ( isset( $instance_config['assistantId'] ) && intval( $instance_config['assistantId'] ) === $this->test_data['assistant_id'] ) {
				$config = $instance_config;
				break;
			}
		}

		$this->assertNotNull( $config, 'Should find config for test assistant' );

		// Verify tools are included.
		$this->assertArrayHasKey( 'tools', $config, 'Config should have tools array' );
		$this->assertIsArray( $config['tools'], 'Tools should be an array' );
		$this->assertNotEmpty( $config['tools'], 'Tools array should not be empty' );

		// Verify tools are in OpenAI-compatible format.
		foreach ( $config['tools'] as $tool ) {
			$this->assertIsArray( $tool, 'Each tool should be an array' );
			$this->assertArrayHasKey( 'type', $tool, 'Tool should have type field' );
			$this->assertEquals( 'function', $tool['type'], 'Tool type should be function' );
			$this->assertArrayHasKey( 'function', $tool, 'Tool should have function field' );
			$this->assertArrayHasKey( 'name', $tool['function'], 'Tool function should have name' );
			$this->assertArrayHasKey( 'description', $tool['function'], 'Tool function should have description' );
		}
	}

	/**
	 * Test that model is included in the config for embedded provider.
	 */
	public function test_model_in_config() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Capture the output to trigger script enqueuing.
		ob_start();
		$shortcode->render(
			array(
				'assistant' => $this->test_data['assistant_id'],
			)
		);
		$output = ob_get_clean();

		// Get the localized script data.
		global $wp_scripts;
		$localized = $wp_scripts->get_data( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'data' );

		// Extract the JSON config.
		preg_match( '/wpMcpAiChatInstances\s*=\s*({.*?});/s', $localized, $matches );
		$this->assertNotEmpty( $matches[1], 'Should find wpMcpAiChatInstances data' );

		$instances = json_decode( $matches[1], true );

		// Find the config for our assistant.
		$config = null;
		foreach ( $instances as $instance_config ) {
			if ( isset( $instance_config['assistantId'] ) && intval( $instance_config['assistantId'] ) === $this->test_data['assistant_id'] ) {
				$config = $instance_config;
				break;
			}
		}

		$this->assertNotNull( $config, 'Should find config for test assistant' );

		// Verify model is included.
		$this->assertArrayHasKey( 'model', $config, 'Config should have model' );
		$this->assertEquals( 'Llama-3.2-1B-Instruct-q4f16_1-MLC', $config['model'], 'Model should match' );
	}

	/**
	 * Test that enhanced WebLLM scripts are enqueued when assistant has tools.
	 *
	 * This verifies that the tool adapter and function calling scripts are
	 * enqueued to enable tool calling in the embedded client.
	 */
	public function test_enhanced_scripts_enqueued_with_tools() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Capture the output to trigger script enqueuing.
		ob_start();
		$shortcode->render(
			array(
				'assistant' => $this->test_data['assistant_id'],
			)
		);
		$output = ob_get_clean();

		// Get the global scripts registry.
		global $wp_scripts;

		// Verify base embedded scripts are enqueued.
		$this->assertTrue(
			wp_script_is( 'webllm-loader', 'enqueued' ),
			'WebLLM loader should be enqueued'
		);
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'enqueued' ),
			'Embedded LLM client should be enqueued'
		);

		// Verify enhanced scripts are enqueued for tool support.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-webllm-tool-adapter', 'enqueued' ),
			'WebLLM tool adapter should be enqueued when assistant has tools'
		);
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-webllm-function-calling', 'enqueued' ),
			'WebLLM function calling client should be enqueued when assistant has tools'
		);
	}

	/**
	 * Test that enhanced WebLLM scripts are enqueued when assistant has system prompt.
	 *
	 * Even if assistant doesn't have tools, the enhanced scripts should be enqueued
	 * if it has a system prompt to ensure knowledge is maintained properly.
	 */
	public function test_enhanced_scripts_enqueued_with_system_prompt() {
		// Create assistant with only system prompt (no tools).
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Knowledge Only Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test assistant with only knowledge',
			)
		);

		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f16_1-MLC' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', 'You are a helpful assistant.' );
		// No tools set.

		$shortcode = new WP_MCP_AI_Shortcode();

		// Capture the output to trigger script enqueuing.
		ob_start();
		$shortcode->render(
			array(
				'assistant' => $assistant_id,
			)
		);
		$output = ob_get_clean();

		// Verify enhanced scripts are still enqueued for knowledge support.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-webllm-tool-adapter', 'enqueued' ),
			'WebLLM tool adapter should be enqueued when assistant has system prompt'
		);
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-webllm-function-calling', 'enqueued' ),
			'WebLLM function calling client should be enqueued when assistant has system prompt'
		);

		// Clean up.
		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test that enhanced WebLLM scripts are enqueued when a profession attribute is supplied.
	 *
	 * When an embedded assistant has NO own system prompt and NO tools but the shortcode
	 * includes a profession attribute, the professional role prompt will be built and sent
	 * as the system prompt.  The enhanced WebLLM function-calling scripts must therefore be
	 * enqueued so the WP_MCP_AI_WebLLM_FunctionCalling client is available in the browser.
	 */
	public function test_enhanced_scripts_enqueued_with_profession_attribute() {
		// Create an embedded assistant with NO system prompt and NO tools.
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Profession Role Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Embedded assistant without its own system prompt',
			)
		);

		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f16_1-MLC' );
		// Intentionally no system prompt, no tools, no knowledge.

		// Create a minimal profession post to act as the profession attribute value.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession',
				'post_status' => 'publish',
			)
		);

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render with the profession attribute to simulate the professional-roles flow.
		ob_start();
		$shortcode->render(
			array(
				'assistant' => $assistant_id,
				'profession' => $profession_id,
			)
		);
		ob_get_clean();

		// Enhanced scripts must be enqueued even though the assistant has no own system prompt,
		// because the profession attribute signals that a professional role prompt will be used.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-webllm-tool-adapter', 'enqueued' ),
			'WebLLM tool adapter should be enqueued when profession attribute is supplied'
		);
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-webllm-function-calling', 'enqueued' ),
			'WebLLM function calling client should be enqueued when profession attribute is supplied'
		);

		// Clean up.
		wp_delete_post( $assistant_id, true );
		wp_delete_post( $profession_id, true );
	}
}
