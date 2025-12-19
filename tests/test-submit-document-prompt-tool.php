<?php
/**
 * Tests for Submit document prompt tool.
 *
 * @package WP_MCP_AI
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
