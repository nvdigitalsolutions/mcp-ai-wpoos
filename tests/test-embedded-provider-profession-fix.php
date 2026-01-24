<?php
/**
 * Test for embedded provider fix with profession tests.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded provider script loading for profession tests.
 */
class Test_Embedded_Provider_Profession_Fix extends WP_UnitTestCase {

	/**
	 * Test assistant IDs.
	 *
	 * @var array
	 */
	private $test_data = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-assistant-cpt.php';
		}

		// Create test assistant with embedded provider.
		$this->test_data['assistant_id'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Embedded Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test embedded assistant for profession',
			)
		);
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $this->test_data['assistant_id'], '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f32_1-MLC' );

		// Create profession associated with this assistant.
		$this->test_data['profession_id'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Profession',
				'post_status'  => 'publish',
				'post_content' => 'Test profession content',
			)
		);
		update_post_meta( $this->test_data['profession_id'], '_wp_mcp_ai_profession_associated_assistant', $this->test_data['assistant_id'] );
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tearDown(): void {
		if ( ! empty( $this->test_data['assistant_id'] ) ) {
			wp_delete_post( $this->test_data['assistant_id'], true );
		}
		if ( ! empty( $this->test_data['profession_id'] ) ) {
			wp_delete_post( $this->test_data['profession_id'], true );
		}

		parent::tearDown();
	}

	/**
	 * Test that embedded LLM scripts are enqueued for profession tests
	 * when the associated assistant uses embedded provider.
	 */
	public function test_embedded_scripts_enqueued_for_profession_with_embedded_assistant() {
		// Skip if WP_MCP_AI_BASE_VERSION is true (embedded provider not available in base version).
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded provider not available in base version.' );
		}

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render shortcode with profession attribute.
		// This uses the profession_XXX format which triggers profession test logic.
		$output = $shortcode->render(
			array(
				'assistant' => 'profession_' . $this->test_data['profession_id'],
			)
		);

		// Verify output is not an error message.
		$this->assertStringNotContainsString( 'not available', $output );

		// Verify embedded LLM scripts were enqueued.
		$this->assertTrue( wp_script_is( 'webllm-loader', 'enqueued' ), 'WebLLM loader should be enqueued' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'enqueued' ), 'Embedded LLM client should be enqueued' );

		// Verify chat script has embedded client as dependency.
		global $wp_scripts;
		$this->assertTrue(
			isset( $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ] ),
			'Chat script should be registered'
		);
		$this->assertTrue(
			isset( $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ]->deps ),
			'Chat script should have dependencies'
		);
		$this->assertTrue(
			in_array( 'wp-mcp-ai-embedded-llm-client', $wp_scripts->registered[ WP_MCP_AI_Shortcode::SCRIPT_HANDLE ]->deps, true ),
			'Chat script should depend on embedded LLM client'
		);
	}

	/**
	 * Test that embedded LLM scripts are NOT enqueued for profession tests
	 * when the associated assistant uses a different provider (e.g., OpenAI).
	 */
	public function test_embedded_scripts_not_enqueued_for_profession_with_non_embedded_assistant() {
		// Create another assistant with OpenAI provider.
		$openai_assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'OpenAI Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test OpenAI assistant',
			)
		);
		update_post_meta( $openai_assistant_id, '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( $openai_assistant_id, '_wp_mcp_ai_model', 'gpt-4' );

		// Create profession associated with OpenAI assistant.
		$openai_profession_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'OpenAI Profession',
				'post_status'  => 'publish',
				'post_content' => 'Test profession with OpenAI',
			)
		);
		update_post_meta( $openai_profession_id, '_wp_mcp_ai_profession_associated_assistant', $openai_assistant_id );

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render shortcode with profession attribute.
		$output = $shortcode->render(
			array(
				'assistant' => 'profession_' . $openai_profession_id,
			)
		);

		// Verify output is not an error message.
		$this->assertStringNotContainsString( 'not available', $output );

		// Verify embedded LLM scripts were NOT enqueued.
		$this->assertFalse( wp_script_is( 'webllm-loader', 'enqueued' ), 'WebLLM loader should NOT be enqueued for non-embedded provider' );
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'enqueued' ), 'Embedded LLM client should NOT be enqueued for non-embedded provider' );

		// Cleanup.
		wp_delete_post( $openai_assistant_id, true );
		wp_delete_post( $openai_profession_id, true );
	}
}
