<?php
/**
 * Tests for vision-tool API key resolution.
 *
 * API keys are stored in the separate `wp_mcp_ai_credentials` option by the
 * settings dashboard. The vision tools previously read the raw
 * `wp_mcp_ai_settings` option, so a key saved from the provider page was
 * invisible to them and they returned "OpenAI API key is not configured."
 * These tests pin the resolver fallback: with the key stored ONLY in
 * `wp_mcp_ai_credentials`, the tools must resolve it and authenticate.
 *
 * @package WP_MCP_AI
 */

/**
 * Test vision tools credential resolution.
 */
class Test_Vision_Tools_Credential_Resolution extends WP_UnitTestCase {

	/**
	 * Test OpenAI key in wp_mcp_ai_credentials resolves for generate_image_alt_text.
	 */
	public function test_alt_text_resolves_openai_key_from_credentials_option() {
		$this->seed_openai_key_in_credentials_option();
		$this->mock_openai_http();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text.php';

		$tool   = new WP_MCP_AI_Tool_Generate_Image_Alt_Text();
		$method = new ReflectionMethod( $tool, 'call_openai_vision' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'https://example.com/img.jpg', '', 'Describe.', array(), 30 );

		$this->assertNotWPError( $result );
		$this->assertSame( 'A test image.', $result['text'] );
		$this->assertSame( 'openai', $result['provider'] );
	}

	/**
	 * Test OpenAI key in wp_mcp_ai_credentials resolves for generate_image_caption.
	 */
	public function test_caption_resolves_openai_key_from_credentials_option() {
		$this->seed_openai_key_in_credentials_option();
		$this->mock_openai_http();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-generate-image-caption.php';

		$tool   = new WP_MCP_AI_Tool_Generate_Image_Caption();
		$method = new ReflectionMethod( $tool, 'call_openai_vision' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'https://example.com/img.jpg', '', 'Caption this.', array(), 30 );

		$this->assertNotWPError( $result );
		$this->assertSame( 'A test image.', $result['text'] );
		$this->assertSame( 'openai', $result['provider'] );
	}

	/**
	 * Test OpenAI key in wp_mcp_ai_credentials resolves for analyze_comment_content.
	 */
	public function test_comment_analysis_resolves_openai_key_from_credentials_option() {
		$this->seed_openai_key_in_credentials_option();
		$this->mock_openai_http();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-analyze-comment-content.php';

		$tool   = new WP_MCP_AI_Tool_Analyze_Comment_Content();
		$method = new ReflectionMethod( $tool, 'call_openai' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'Analyze this comment.', array() );

		$this->assertNotWPError( $result );
		$this->assertSame( 'A test image.', $result['text'] );
		$this->assertSame( 'openai', $result['provider'] );
	}

	/**
	 * Test the tools keep failing cleanly when no key exists anywhere.
	 */
	public function test_alt_text_still_errors_when_no_key_configured() {
		update_option( 'wp_mcp_ai_settings', array() );
		delete_option( 'wp_mcp_ai_credentials' );
		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text.php';

		$tool   = new WP_MCP_AI_Tool_Generate_Image_Alt_Text();
		$method = new ReflectionMethod( $tool, 'call_openai_vision' );
		$method->setAccessible( true );

		$result = $method->invoke( $tool, 'https://example.com/img.jpg', '', 'Describe.', array(), 30 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Store an OpenAI key in the credentials option only, leaving the
	 * settings option keyless (mirrors the settings dashboard split).
	 */
	private function seed_openai_key_in_credentials_option() {
		update_option( 'wp_mcp_ai_settings', array() );
		update_option( 'wp_mcp_ai_credentials', array( 'openai_api_key' => 'sk-test-vision-123' ) );
		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
	}

	/**
	 * Intercept OpenAI HTTP calls: assert the resolved key is sent and
	 * return a canned success response.
	 */
	private function mock_openai_http() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'api.openai.com' ) ) {
					$this->assertSame( 'Bearer sk-test-vision-123', $args['headers']['Authorization'] );

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'model'   => 'gpt-4o-mini',
								'choices' => array(
									array(
										'message' => array( 'content' => 'A test image.' ),
									),
								),
								'usage'   => array(
									'prompt_tokens'     => 10,
									'completion_tokens' => 5,
									'total_tokens'      => 15,
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_credentials' );
		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		parent::tearDown();
	}
}
