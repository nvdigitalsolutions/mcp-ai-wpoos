<?php
/**
 * Submit Document Prompt Tool
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */


require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php';

/**
 * Tests for the submit document prompt tool.
 */
class WP_MCP_AI_Submit_Document_Prompt_Tool_Test extends WP_UnitTestCase {
	/**
	 * Clean up globals between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Ensure the tool uploads attachments and forwards the prompt to OpenAI.
	 */
	public function test_execute_submits_attachment_and_prompt() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 45;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		list( $attachment_id ) = $this->create_text_attachment( 'analysis.txt', 'Attachment contents' );

		$captured_request = array();
		$upload_counter   = 0;

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request, &$upload_counter ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT === $url ) {
				++$upload_counter;

				return array(
					'body'     => wp_json_encode(
						array(
							'id'         => 'file-tool-' . $upload_counter,
							'created_at' => time(),
							'status'     => 'processed',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			if ( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT === $url ) {
				$captured_request = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'index'   => 0,
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Document summary output.',
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			return false;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Submit_Document_Prompt();
		$result = $tool->execute(
			array(
				'prompt'        => 'Summarise the attachment.',
				'attachment_id' => $attachment_id,
				'model'         => 'gpt-test',
			),
			array(
				'user_id'          => $user_id,
				'assistant_config' => array(),
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertSame( 'Document summary output.', $result );
		$this->assertSame( 1, $upload_counter );
		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'model', $payload );
		$this->assertSame( 'gpt-test', $payload['model'] );
		$this->assertArrayHasKey( 'input', $payload );

		$user_message = $payload['input'][0];
		$this->assertArrayHasKey( 'content', $user_message );
		$this->assertCount( 2, $user_message['content'] );
		$this->assertSame( 'Summarise the attachment.', $user_message['content'][0]['text'] );
		$this->assertSame( 'input_text', $user_message['content'][0]['type'] );
		$this->assertSame( 'input_file', $user_message['content'][1]['type'] );
		$this->assertSame( 'file-tool-1', $user_message['content'][1]['file_id'] );
	}

	/**
	 * Ensure the tool returns an error when no prompt is provided.
	 */
	public function test_execute_requires_prompt() {
		$tool = new WP_MCP_AI_Tool_Submit_Document_Prompt();

		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Ensure the tool returns an error when no document references are supplied.
	 */
	public function test_execute_requires_attachment_reference() {
		$tool = new WP_MCP_AI_Tool_Submit_Document_Prompt();

		$result = $tool->execute( array( 'prompt' => 'Hello' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_document', $result->get_error_code() );
	}

	/**
	 * Ensure the tool accepts a bare OpenAI file_id that has no WordPress attachment.
	 */
	public function test_execute_with_bare_openai_file_id() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 45;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$captured_request = array();

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			// Handle file retrieval verification request.
			if ( false !== strpos( $url, '/files/file-remote-abc123' ) && 'GET' === $args['method'] ) {
				return array(
					'body'     => wp_json_encode(
						array(
							'id'         => 'file-remote-abc123',
							'object'     => 'file',
							'bytes'      => 2048,
							'created_at' => time(),
							'filename'   => 'report.pdf',
							'purpose'    => 'assistants',
							'status'     => 'processed',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			// Handle Responses API call.
			if ( WP_MCP_AI_OpenAI_Client::RESPONSES_ENDPOINT === $url ) {
				$captured_request = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'index'   => 0,
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Analysis of OpenAI file complete.',
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			return false;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Submit_Document_Prompt();
		$result = $tool->execute(
			array(
				'prompt'  => 'Analyse the attached report.',
				'file_id' => 'file-remote-abc123',
				'model'   => 'gpt-test',
			),
			array(
				'user_id'          => $user_id,
				'assistant_config' => array(),
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( 'Analysis of OpenAI file complete.', $result );
		$this->assertNotEmpty( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'input', $payload );

		$user_message = $payload['input'][0];
		$this->assertCount( 2, $user_message['content'] );
		$this->assertSame( 'input_file', $user_message['content'][1]['type'] );
		$this->assertSame( 'file-remote-abc123', $user_message['content'][1]['file_id'] );
	}

	/**
	 * Image attachments must travel as input_image segments, which the client
	 * converts into image_url content blocks for vision models. Before this
	 * fix images were routed through the file-segment path and rejected with
	 * wp_mcp_ai_attachment_unsupported_mime on every provider.
	 */
	public function test_execute_routes_image_attachment_as_image_segment() {
		$settings                    = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key']  = 'sk-test';
		$settings['request_timeout'] = 45;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		list( $attachment_id ) = $this->create_image_attachment( 'pack-shot.png' );

		$captured_request = array();

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			if ( WP_MCP_AI_OpenAI_Client::FILES_ENDPOINT === $url ) {
				return array(
					'body'     => wp_json_encode(
						array(
							'id'         => 'file-tool-image-1',
							'created_at' => time(),
							'status'     => 'processed',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			if ( WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT === $url ) {
				$captured_request = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'index'   => 0,
									'message' => array(
										'role'    => 'assistant',
										'content' => 'A product pack shot.',
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			return false;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Submit_Document_Prompt();
		$result = $tool->execute(
			array(
				'prompt'        => 'Describe the pack shot.',
				'attachment_id' => $attachment_id,
				'model'         => 'gpt-4o',
			),
			array(
				'user_id'          => $user_id,
				'assistant_config' => array( 'provider' => 'openai' ),
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertSame( 'A product pack shot.', $result );
		$this->assertNotEmpty( $captured_request );
		$this->assertSame( WP_MCP_AI_OpenAI_Client::CHAT_COMPLETIONS_ENDPOINT, $captured_request['url'] );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );

		$user_message = $payload['messages'][0];
		$this->assertArrayHasKey( 'content', $user_message );
		$segments = $user_message['content'];
		$this->assertCount( 2, $segments );
		$this->assertSame( 'text', $segments[0]['type'] );
		$this->assertSame( 'image_url', $segments[1]['type'] );
		$this->assertNotEmpty( $segments[1]['image_url']['url'] );
	}

	/**
	 * On a DeepSeek-configured assistant the tool must use the DeepSeek
	 * client and translate the image into DeepSeek's image_url block format.
	 */
	public function test_execute_deepseek_provider_receives_image_url_block() {
		$settings                     = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['deepseek_api_key'] = 'sk-deepseek-test';
		$settings['request_timeout']  = 45;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		list( $attachment_id ) = $this->create_image_attachment( 'pack-shot.png' );

		$captured_request = array();

		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			if ( 'https://api.deepseek.com/chat/completions' === $url ) {
				$captured_request = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'body'     => wp_json_encode(
						array(
							'id'      => 'resp-1',
							'model'   => 'deepseek-flash',
							'choices' => array(
								array(
									'index'   => 0,
									'message' => array(
										'role'    => 'assistant',
										'content' => 'It is a black jacket pack shot.',
									),
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);
			}

			return false;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Submit_Document_Prompt();
		$result = $tool->execute(
			array(
				'prompt'        => 'Describe the pack shot.',
				'attachment_id' => $attachment_id,
				'model'         => 'deepseek-flash',
			),
			array(
				'user_id'          => $user_id,
				'assistant_config' => array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-flash',
				),
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertSame( 'It is a black jacket pack shot.', $result );
		$this->assertNotEmpty( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( 'deepseek-flash', $payload['model'] );

		$user_message = $payload['messages'][0];
		$this->assertArrayHasKey( 'content', $user_message );
		$segments = $user_message['content'];
		$this->assertCount( 2, $segments );
		$this->assertSame( 'image_url', $segments[1]['type'] );
		$this->assertNotEmpty( $segments[1]['image_url']['url'] );
	}

	/**
	 * Create a real image attachment and return the ID and path.
	 *
	 * @param string $filename File name.
	 * @return array
	 */
	protected function create_image_attachment( $filename ) {
		// 1x1 transparent PNG fixture (base64 is a fixed test fixture, not obfuscation).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a static PNG fixture for an image-upload test.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
		$this->assertNotEmpty( $png );

		$upload = wp_upload_bits( $filename, null, $png );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'Pack Shot',
				'post_status' => 'inherit',
			)
		);

		return array( $attachment_id, $upload['file'] );
	}

	/**
	 * Create a text attachment and return the ID and path.
	 *
	 * @param string $filename File name.
	 * @param string $contents File contents.
	 * @return array
	 */
	protected function create_text_attachment( $filename, $contents ) {
		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_title'  => 'Tool Attachment',
				'post_status' => 'inherit',
			)
		);

		return array( $attachment_id, $upload['file'] );
	}
}
