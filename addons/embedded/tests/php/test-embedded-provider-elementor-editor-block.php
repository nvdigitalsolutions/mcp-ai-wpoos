<?php
/**
 * Tests for blocking embedded provider in Elementor editor.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Test that embedded provider scripts are blocked in Elementor editor mode.
 */
class Test_Embedded_Provider_Elementor_Editor_Block extends WP_UnitTestCase {

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Original GET values to restore after tests.
	 *
	 * @var array
	 */
	private $original_get = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Save original GET state.
		$this->original_get = $_GET;

		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-assistant-cpt.php';
		}

		// Create test assistant with embedded provider.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Embedded Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test embedded assistant',
			)
		);
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f32_1-MLC' );
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tearDown(): void {
		// Restore original GET state.
		$_GET = $this->original_get;

		wp_delete_post( $this->assistant_id, true );

		parent::tearDown();
	}

	/**
	 * Test that embedded provider scripts are NOT enqueued in Elementor editor.
	 */
	public function test_embedded_provider_blocked_in_elementor_editor() {
		// Skip if base version (embedded provider not available).
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded provider is only available in Pro version.' );
		}

		// Simulate Elementor editor page load.
		$_GET['action'] = 'elementor';

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render widget with embedded provider.
		$output = $shortcode->render_shortcode(
			array(
				'assistant' => $this->assistant_id,
			)
		);

		// The output should still render (not empty).
		$this->assertNotEmpty( $output, 'Widget should render in Elementor editor' );

		// But embedded provider scripts should NOT be enqueued.
		$this->assertFalse(
			wp_script_is( 'webllm-loader', 'enqueued' ),
			'WebLLM loader should NOT be enqueued in Elementor editor'
		);
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'enqueued' ),
			'Embedded LLM client should NOT be enqueued in Elementor editor'
		);

		// Chat script should still be registered.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-chat', 'registered' ),
			'Chat script should be registered in Elementor editor'
		);
	}

	/**
	 * Test that embedded provider scripts ARE enqueued in normal frontend.
	 */
	public function test_embedded_provider_allowed_in_frontend() {
		// Skip if base version (embedded provider not available).
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded provider is only available in Pro version.' );
		}

		// Simulate normal frontend page (no Elementor editor).
		unset( $_GET['action'] );

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render widget with embedded provider.
		$output = $shortcode->render_shortcode(
			array(
				'assistant' => $this->assistant_id,
			)
		);

		// The output should render.
		$this->assertNotEmpty( $output, 'Widget should render in frontend' );

		// Embedded provider scripts should be enqueued.
		$this->assertTrue(
			wp_script_is( 'webllm-loader', 'enqueued' ) || wp_script_is( 'webllm-loader', 'registered' ),
			'WebLLM loader should be enqueued or registered in frontend'
		);
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ),
			'Embedded LLM client should be enqueued or registered in frontend'
		);

		// Chat script should have embedded provider dependency.
		global $wp_scripts;
		$chat_script = $wp_scripts->registered['wp-mcp-ai-chat'] ?? null;
		$this->assertNotNull( $chat_script, 'Chat script should be registered' );
		$this->assertContains(
			'wp-mcp-ai-embedded-llm-client',
			$chat_script->deps,
			'Chat script should depend on embedded LLM client in frontend'
		);
	}

	/**
	 * Test that non-embedded providers work normally in Elementor editor.
	 */
	public function test_non_embedded_providers_work_in_elementor_editor() {
		// Create non-embedded assistant.
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

		// Simulate Elementor editor page load.
		$_GET['action'] = 'elementor';

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render widget with OpenAI provider.
		$output = $shortcode->render_shortcode(
			array(
				'assistant' => $openai_assistant_id,
			)
		);

		// The output should render.
		$this->assertNotEmpty( $output, 'OpenAI widget should render in Elementor editor' );

		// Embedded provider scripts should NOT be enqueued (since provider is not embedded).
		$this->assertFalse(
			wp_script_is( 'webllm-loader', 'enqueued' ),
			'WebLLM loader should NOT be enqueued for non-embedded provider'
		);
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'enqueued' ),
			'Embedded LLM client should NOT be enqueued for non-embedded provider'
		);

		// Chat script should still be registered.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-chat', 'registered' ),
			'Chat script should be registered for non-embedded provider'
		);

		// Clean up.
		wp_delete_post( $openai_assistant_id, true );
	}
}
