<?php
/**
 * Tests for multiple chat widgets with embedded provider.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Test multiple chat widgets with embedded provider functionality.
 */
class Test_Multiple_Widgets_Embedded_Provider extends WP_UnitTestCase {

	/**
	 * Test assistant IDs.
	 *
	 * @var array
	 */
	private $assistant_ids = array();

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

		// Create test assistants with different providers.
		// Assistant 1: Regular OpenAI provider.
		$this->assistant_ids['openai'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'OpenAI Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test OpenAI assistant',
			)
		);
		update_post_meta( $this->assistant_ids['openai'], '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( $this->assistant_ids['openai'], '_wp_mcp_ai_model', 'gpt-4' );

		// Assistant 2: Embedded provider.
		$this->assistant_ids['embedded'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Embedded Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test embedded assistant',
			)
		);
		update_post_meta( $this->assistant_ids['embedded'], '_wp_mcp_ai_provider', 'embedded' );
		update_post_meta( $this->assistant_ids['embedded'], '_wp_mcp_ai_model', 'Llama-3.2-1B-Instruct-q4f32_1-MLC' );

		// Assistant 3: Another regular provider (Gemini).
		$this->assistant_ids['gemini'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Gemini Assistant',
				'post_status'  => 'publish',
				'post_content' => 'Test Gemini assistant',
			)
		);
		update_post_meta( $this->assistant_ids['gemini'], '_wp_mcp_ai_provider', 'gemini' );
		update_post_meta( $this->assistant_ids['gemini'], '_wp_mcp_ai_model', 'gemini-pro' );
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->assistant_ids as $assistant_id ) {
			wp_delete_post( $assistant_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Test that multiple widgets render without breaking each other.
	 */
	public function test_multiple_widgets_render_correctly() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Render widget 1 (OpenAI).
		$output1 = $shortcode->render(
			array(
				'assistant' => $this->assistant_ids['openai'],
			)
		);

		// Render widget 2 (Embedded).
		$output2 = $shortcode->render(
			array(
				'assistant' => $this->assistant_ids['embedded'],
			)
		);

		// Render widget 3 (Gemini).
		$output3 = $shortcode->render(
			array(
				'assistant' => $this->assistant_ids['gemini'],
			)
		);

		// Render widget 4 (Another OpenAI).
		$output4 = $shortcode->render(
			array(
				'assistant' => $this->assistant_ids['openai'],
			)
		);

		// All outputs should be non-empty strings.
		$this->assertNotEmpty( $output1, 'Widget 1 should render' );
		$this->assertNotEmpty( $output2, 'Widget 2 should render' );
		$this->assertNotEmpty( $output3, 'Widget 3 should render' );
		$this->assertNotEmpty( $output4, 'Widget 4 should render' );

		// Each output should contain a unique instance ID.
		$this->assertStringContainsString( 'wp-mcp-ai-chat-', $output1, 'Widget 1 should have instance ID' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat-', $output2, 'Widget 2 should have instance ID' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat-', $output3, 'Widget 3 should have instance ID' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat-', $output4, 'Widget 4 should have instance ID' );

		// Extract instance IDs from outputs.
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output1, $matches1 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output2, $matches2 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output3, $matches3 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output4, $matches4 );

		$instance_id1 = $matches1[1] ?? '';
		$instance_id2 = $matches2[1] ?? '';
		$instance_id3 = $matches3[1] ?? '';
		$instance_id4 = $matches4[1] ?? '';

		// All instance IDs should be unique.
		$this->assertNotEquals( $instance_id1, $instance_id2, 'Widget 1 and 2 should have different IDs' );
		$this->assertNotEquals( $instance_id1, $instance_id3, 'Widget 1 and 3 should have different IDs' );
		$this->assertNotEquals( $instance_id1, $instance_id4, 'Widget 1 and 4 should have different IDs' );
		$this->assertNotEquals( $instance_id2, $instance_id3, 'Widget 2 and 3 should have different IDs' );
		$this->assertNotEquals( $instance_id2, $instance_id4, 'Widget 2 and 4 should have different IDs' );
		$this->assertNotEquals( $instance_id3, $instance_id4, 'Widget 3 and 4 should have different IDs' );
	}

	/**
	 * Test that script is only registered once with proper dependencies.
	 */
	public function test_script_registration_with_embedded_provider() {
		// Skip if base version (embedded provider not available).
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Embedded provider is only available in Pro version.' );
		}

		$shortcode = new WP_MCP_AI_Shortcode();

		// Render widget with embedded provider.
		$shortcode->render(
			array(
				'assistant' => $this->assistant_ids['embedded'],
			)
		);

		// Check that chat script is registered.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chat', 'registered' ), 'Chat script should be registered' );

		// Check that embedded LLM client is registered.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ), 'Embedded LLM client should be registered' );

		// Check that WebLLM is registered.
		$this->assertTrue( wp_script_is( 'webllm', 'registered' ), 'WebLLM should be registered' );

		// Check that chat script depends on embedded LLM client.
		global $wp_scripts;
		$chat_script = $wp_scripts->registered['wp-mcp-ai-chat'] ?? null;
		$this->assertNotNull( $chat_script, 'Chat script should exist in registered scripts' );
		$this->assertContains( 'wp-mcp-ai-embedded-llm-client', $chat_script->deps, 'Chat script should depend on embedded LLM client' );
	}

	/**
	 * Test that configurations are stored correctly for all widgets.
	 */
	public function test_configurations_stored_for_all_widgets() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Render all widgets.
		$output1 = $shortcode->render( array( 'assistant' => $this->assistant_ids['openai'] ) );
		$output2 = $shortcode->render( array( 'assistant' => $this->assistant_ids['embedded'] ) );
		$output3 = $shortcode->render( array( 'assistant' => $this->assistant_ids['gemini'] ) );
		$output4 = $shortcode->render( array( 'assistant' => $this->assistant_ids['openai'] ) );

		// Extract instance IDs.
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output1, $matches1 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output2, $matches2 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output3, $matches3 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output4, $matches4 );

		$instance_id1 = $matches1[1] ?? '';
		$instance_id2 = $matches2[1] ?? '';
		$instance_id3 = $matches3[1] ?? '';
		$instance_id4 = $matches4[1] ?? '';

		// Check that configurations are stored in global.
		$this->assertArrayHasKey( $instance_id1, $GLOBALS['wp_mcp_ai_chat_configs'] ?? array(), 'Config 1 should be stored' );
		$this->assertArrayHasKey( $instance_id2, $GLOBALS['wp_mcp_ai_chat_configs'] ?? array(), 'Config 2 should be stored' );
		$this->assertArrayHasKey( $instance_id3, $GLOBALS['wp_mcp_ai_chat_configs'] ?? array(), 'Config 3 should be stored' );
		$this->assertArrayHasKey( $instance_id4, $GLOBALS['wp_mcp_ai_chat_configs'] ?? array(), 'Config 4 should be stored' );

		// Verify each config has correct assistant ID.
		$this->assertEquals( $this->assistant_ids['openai'], $GLOBALS['wp_mcp_ai_chat_configs'][ $instance_id1 ]['assistantId'] ?? null, 'Config 1 should have correct assistant ID' );
		$this->assertEquals( $this->assistant_ids['embedded'], $GLOBALS['wp_mcp_ai_chat_configs'][ $instance_id2 ]['assistantId'] ?? null, 'Config 2 should have correct assistant ID' );
		$this->assertEquals( $this->assistant_ids['gemini'], $GLOBALS['wp_mcp_ai_chat_configs'][ $instance_id3 ]['assistantId'] ?? null, 'Config 3 should have correct assistant ID' );
		$this->assertEquals( $this->assistant_ids['openai'], $GLOBALS['wp_mcp_ai_chat_configs'][ $instance_id4 ]['assistantId'] ?? null, 'Config 4 should have correct assistant ID' );

		// Verify embedded provider config has provider and model.
		$embedded_config = $GLOBALS['wp_mcp_ai_chat_configs'][ $instance_id2 ] ?? array();
		$this->assertArrayHasKey( 'provider', $embedded_config, 'Embedded config should have provider' );
		$this->assertArrayHasKey( 'model', $embedded_config, 'Embedded config should have model' );
		$this->assertEquals( 'embedded', $embedded_config['provider'] ?? '', 'Embedded config should have correct provider' );
	}

	/**
	 * Test that inline scripts are added for all widgets.
	 */
	public function test_inline_scripts_added_for_all_widgets() {
		$shortcode = new WP_MCP_AI_Shortcode();

		// Render all widgets.
		$output1 = $shortcode->render( array( 'assistant' => $this->assistant_ids['openai'] ) );
		$output2 = $shortcode->render( array( 'assistant' => $this->assistant_ids['embedded'] ) );
		$output3 = $shortcode->render( array( 'assistant' => $this->assistant_ids['gemini'] ) );
		$output4 = $shortcode->render( array( 'assistant' => $this->assistant_ids['openai'] ) );

		// Extract instance IDs.
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output1, $matches1 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output2, $matches2 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output3, $matches3 );
		preg_match( '/id="(wp-mcp-ai-chat-[^"]+)"/', $output4, $matches4 );

		$instance_id1 = $matches1[1] ?? '';
		$instance_id2 = $matches2[1] ?? '';
		$instance_id3 = $matches3[1] ?? '';
		$instance_id4 = $matches4[1] ?? '';

		// Get inline scripts.
		global $wp_scripts;
		$chat_script = $wp_scripts->registered['wp-mcp-ai-chat'] ?? null;
		$this->assertNotNull( $chat_script, 'Chat script should be registered' );

		$inline_scripts = $wp_scripts->get_data( 'wp-mcp-ai-chat', 'before' ) ?? array();
		$inline_script  = is_array( $inline_scripts ) ? implode( "\n", $inline_scripts ) : $inline_scripts;

		// Each instance ID should be in the inline scripts.
		$this->assertStringContainsString( $instance_id1, $inline_script, 'Instance 1 should be in inline scripts' );
		$this->assertStringContainsString( $instance_id2, $inline_script, 'Instance 2 should be in inline scripts' );
		$this->assertStringContainsString( $instance_id3, $inline_script, 'Instance 3 should be in inline scripts' );
		$this->assertStringContainsString( $instance_id4, $inline_script, 'Instance 4 should be in inline scripts' );

		// Each instance should have wpMcpAiChatInstances assignment.
		$this->assertStringContainsString( 'window.wpMcpAiChatInstances', $inline_script, 'Inline scripts should initialize wpMcpAiChatInstances' );
	}
}
