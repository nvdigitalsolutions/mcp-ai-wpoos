<?php
/**
 * Tests for the LM Studio image generation tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-lm-studio-image.php';

/**
 * Test class for WP_MCP_AI_Tool_Generate_LM_Studio_Image.
 */
class WP_MCP_AI_LM_Studio_Image_Tool_Test extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that the tool requires authentication.
	 */
	public function test_requires_authentication() {
		$tool = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();

		$result = $tool->execute(
			array( 'prompt' => 'A test image' ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that the tool requires a prompt.
	 */
	public function test_requires_prompt() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();

		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test prompt enhancement only (without image generation).
	 */
	public function test_prompt_enhancement_only() {
		// Configure LM Studio settings.
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['lm_studio_endpoint_url']   = 'http://localhost:1234';
		$settings['lm_studio_model']          = 'google/gemma-3-12b';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();
		$captured_request = null;

		// Mock LM Studio response.
		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			// Mock enhanced prompt response from LM Studio.
			$payload = array(
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => array(
								array(
									'type' => 'text',
									'text' => 'A photorealistic image of a majestic cat sitting on a windowsill, bathed in warm golden hour sunlight. The cat has striking amber eyes, detailed fur texture, and a serene expression. Shallow depth of field with bokeh background showing a garden. Professional photography, high detail, natural lighting.',
								),
							),
						),
						'finish_reason' => 'stop',
					),
				),
				'model'    => 'google/gemma-3-12b',
				'provider' => 'lm_studio',
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'         => 'a cat',
				'enhance_prompt' => true,
				'generate_image' => false,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'original_prompt', $result );
		$this->assertSame( 'a cat', $result['original_prompt'] );
		$this->assertArrayHasKey( 'enhanced_prompt', $result );
		$this->assertNotEmpty( $result['enhanced_prompt'] );
		$this->assertStringContainsString( 'photorealistic', $result['enhanced_prompt'] );
		$this->assertArrayHasKey( 'lm_studio_used', $result );
		$this->assertTrue( $result['lm_studio_used'] );
		$this->assertArrayHasKey( 'image_generated', $result );
		$this->assertFalse( $result['image_generated'] );
		$this->assertArrayNotHasKey( 'attachment_id', $result );
	}

	/**
	 * Test full workflow: enhance prompt then generate image.
	 */
	public function test_full_workflow_with_openai() {
		// Configure settings for both LM Studio and OpenAI.
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['lm_studio_endpoint_url']   = 'http://localhost:1234';
		$settings['lm_studio_model']          = 'google/gemma-3-12b';
		$settings['openai_api_key']           = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool              = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();
		$request_count     = 0;
		$lm_studio_request = null;
		$openai_request    = null;

		// Mock both LM Studio and OpenAI responses.
		$http_stub = function ( $preempt, $args, $url ) use ( &$request_count, &$lm_studio_request, &$openai_request ) {
			$request_count++;

			// First request: LM Studio prompt enhancement.
			if ( false !== strpos( $url, 'localhost:1234' ) ) {
				$lm_studio_request = array(
					'args' => $args,
					'url'  => $url,
				);

				$payload = array(
					'choices' => array(
						array(
							'index'         => 0,
							'message'       => array(
								'role'    => 'assistant',
								'content' => array(
									array(
										'type' => 'text',
										'text' => 'A photorealistic portrait of a golden retriever puppy with soft fur, bright eyes, natural outdoor lighting',
									),
								),
							),
							'finish_reason' => 'stop',
						),
					),
					'model'    => 'google/gemma-3-12b',
					'provider' => 'lm_studio',
				);

				return array(
					'body'     => wp_json_encode( $payload ),
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			// Second request: OpenAI image generation.
			if ( false !== strpos( $url, 'api.openai.com' ) ) {
				$openai_request = array(
					'args' => $args,
					'url'  => $url,
				);

				// Return a small PNG image in base64.
				$png_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YwH0e0AAAAASUVORK5CYII=';

				$payload = array(
					'created' => time(),
					'data'    => array(
						array(
							'b64_json'       => $png_base64,
							'revised_prompt' => 'Enhanced puppy portrait',
						),
					),
				);

				return array(
					'body'     => wp_json_encode( $payload ),
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'          => 'a puppy',
				'enhance_prompt'  => true,
				'generate_image'  => true,
				'image_provider'  => 'openai',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Verify LM Studio was called for enhancement.
		$this->assertNotNull( $lm_studio_request );
		$this->assertStringContainsString( 'localhost:1234', $lm_studio_request['url'] );

		// Verify OpenAI was called for image generation.
		$this->assertNotNull( $openai_request );
		$this->assertStringContainsString( 'api.openai.com', $openai_request['url'] );

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'original_prompt', $result );
		$this->assertSame( 'a puppy', $result['original_prompt'] );
		$this->assertArrayHasKey( 'enhanced_prompt', $result );
		$this->assertStringContainsString( 'photorealistic', $result['enhanced_prompt'] );
		$this->assertArrayHasKey( 'lm_studio_used', $result );
		$this->assertTrue( $result['lm_studio_used'] );
		$this->assertArrayHasKey( 'image_generated', $result );
		$this->assertTrue( $result['image_generated'] );
		$this->assertArrayHasKey( 'image_provider', $result );
		$this->assertSame( 'openai', $result['image_provider'] );
		$this->assertArrayHasKey( 'attachment_id', $result );

		// Clean up attachment.
		if ( ! empty( $result['attachment_id'] ) ) {
			wp_delete_attachment( $result['attachment_id'], true );
		}
	}

	/**
	 * Test that the tool returns essential metadata when sanitized for LLM.
	 */
	public function test_sanitize_for_llm() {
		$tool = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();

		$result = array(
			'original_prompt'  => 'a cat',
			'enhanced_prompt'  => 'A detailed cat portrait',
			'lm_studio_used'   => true,
			'image_generated'  => true,
			'image_provider'   => 'openai',
			'attachment_id'    => 123,
			'url'              => 'http://example.com/image.png',
			'file_name'        => 'image.png',
			'mime_type'        => 'image/png',
			'content'          => array(
				'data'     => 'base64encodeddata...',
				'data_url' => 'data:image/png;base64,base64encodeddata...',
				'encoding' => 'base64',
			),
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'original_prompt', $sanitized );
		$this->assertArrayHasKey( 'enhanced_prompt', $sanitized );
		$this->assertArrayHasKey( 'lm_studio_used', $sanitized );
		$this->assertArrayHasKey( 'image_generated', $sanitized );
		$this->assertArrayHasKey( 'attachment_id', $sanitized );

		// Verify base64 data was removed.
		$this->assertArrayNotHasKey( 'content', $sanitized );
	}

	/**
	 * Test tool metadata and interface implementation.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();

		$this->assertSame( 'generate_lm_studio_image', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'prompt', $schema['required'] );

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'local-ai-compatible', $flags );
	}

	/**
	 * Test that style guidance is passed to LM Studio.
	 */
	public function test_style_guidance() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['lm_studio_endpoint_url']   = 'http://localhost:1234';
		$settings['lm_studio_model']          = 'google/gemma-3-12b';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool             = new WP_MCP_AI_Tool_Generate_LM_Studio_Image();
		$captured_request = null;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			$payload = array(
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => array(
								array(
									'type' => 'text',
									'text' => 'A minimalist technical diagram showing system architecture',
								),
							),
						),
						'finish_reason' => 'stop',
					),
				),
				'model'    => 'google/gemma-3-12b',
				'provider' => 'lm_studio',
			);

			return array(
				'body'     => wp_json_encode( $payload ),
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'prompt'          => 'system diagram',
				'enhance_prompt'  => true,
				'generate_image'  => false,
				'style_guidance'  => 'technical diagram',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );

		// Verify request body contains style guidance.
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'messages', $body );

		// Check that style guidance is mentioned in the message.
		$message_content = '';
		foreach ( $body['messages'] as $message ) {
			if ( isset( $message['content'] ) ) {
				$message_content .= is_array( $message['content'] ) ? implode( ' ', $message['content'] ) : $message['content'];
			}
		}
		$this->assertStringContainsString( 'technical diagram', $message_content );
	}
}
