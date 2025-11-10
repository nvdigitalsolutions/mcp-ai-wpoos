<?php
/**
 * Tests for assistant preconfig display on single post pages.
 */
class WP_MCP_AI_Assistant_Preconfig_Display_Test extends WP_UnitTestCase {

	/**
	 * Test that the preconfig filter is registered.
	 */
	public function test_preconfig_filter_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		$this->assertTrue(
			has_filter( 'the_content', array( $cpt, 'add_preconfig_to_single_post' ) ) !== false,
			'The the_content filter should be registered.'
		);
	}

	/**
	 * Test that the style enqueue action is registered.
	 */
	public function test_style_enqueue_action_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		$this->assertTrue(
			has_action( 'wp_enqueue_scripts', array( $cpt, 'enqueue_single_post_styles' ) ) !== false,
			'The wp_enqueue_scripts action should be registered.'
		);
	}

	/**
	 * Test that preconfig HTML is generated with proper escaping.
	 */
	public function test_preconfig_html_escaping() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, '0.7' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, 'You are a helpful assistant.' );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$cpt    = new WP_MCP_AI_Assistant_CPT( $registry );
		$method = new ReflectionMethod( $cpt, 'render_preconfig_html' );
		$method->setAccessible( true );

		$html = $method->invoke( $cpt, $config );

		// Check that HTML contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-assistant-preconfig', $html );
		$this->assertStringContainsString( 'Assistant Configuration', $html );
		$this->assertStringContainsString( 'OpenAI', $html );
		$this->assertStringContainsString( 'gpt-4', $html );
		$this->assertStringContainsString( '0.70', $html );
		$this->assertStringContainsString( 'You are a helpful assistant.', $html );

		// Verify proper escaping - no unescaped HTML should be present.
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * Test that preconfig HTML includes XSS protection.
	 */
	public function test_preconfig_xss_protection() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$malicious_prompt = '<script>alert("XSS")</script>';
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, '<img src=x onerror=alert(1)>' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, $malicious_prompt );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$cpt    = new WP_MCP_AI_Assistant_CPT( $registry );
		$method = new ReflectionMethod( $cpt, 'render_preconfig_html' );
		$method->setAccessible( true );

		$html = $method->invoke( $cpt, $config );

		// Verify that malicious content is properly escaped.
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'alert("XSS")', $html );
		$this->assertStringNotContainsString( 'onerror=', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * Test provider label conversion.
	 */
	public function test_provider_label_conversion() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt    = new WP_MCP_AI_Assistant_CPT( $registry );
		$method = new ReflectionMethod( $cpt, 'get_provider_label' );
		$method->setAccessible( true );

		$this->assertSame( 'OpenAI', $method->invoke( $cpt, 'openai' ) );
		$this->assertSame( 'Anthropic', $method->invoke( $cpt, 'anthropic' ) );
		$this->assertSame( 'Gemini', $method->invoke( $cpt, 'gemini' ) );
		$this->assertSame( 'Ollama', $method->invoke( $cpt, 'ollama' ) );
		$this->assertSame( 'LM Studio', $method->invoke( $cpt, 'lm_studio' ) );
		$this->assertSame( 'Custom Provider', $method->invoke( $cpt, 'custom_provider' ) );
		$this->assertSame( '', $method->invoke( $cpt, '' ) );
	}

	/**
	 * Test that content is not modified on non-singular pages.
	 */
	public function test_preconfig_not_added_on_archive_pages() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		$content  = 'Original content';
		$filtered = $cpt->add_preconfig_to_single_post( $content );

		// On non-singular pages, content should remain unchanged.
		$this->assertSame( $content, $filtered );
	}

	/**
	 * Test that tools are displayed when available.
	 */
	public function test_tools_display_in_preconfig() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant with Tools',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'get_recent_posts', 'get_site_summary' ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$cpt    = new WP_MCP_AI_Assistant_CPT( $registry );
		$method = new ReflectionMethod( $cpt, 'render_preconfig_html' );
		$method->setAccessible( true );

		$html = $method->invoke( $cpt, $config );

		// Check that tools section exists.
		$this->assertStringContainsString( 'Available Tools', $html );
		$this->assertStringContainsString( 'wp-mcp-ai-assistant-preconfig__tools', $html );
		$this->assertStringContainsString( 'wp-mcp-ai-assistant-preconfig__tools-list', $html );
	}
}
