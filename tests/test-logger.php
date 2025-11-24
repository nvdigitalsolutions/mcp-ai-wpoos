<?php
/**
 * Tests for the logging helper.
 */
class WP_MCP_AI_Logger_Test extends WP_UnitTestCase {

	/**
	 * Ensure chat interaction logging removes oversized payloads from the context.
	 */
	public function test_log_chat_interaction_redacts_large_payloads() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		$attachments = array(
			array(
				'id'       => 123,
				'filename' => 'large-file.txt',
				'data'     => str_repeat( 'A', 2048 ),
			),
		);

		$memory_documents = array(
			array(
				'id'      => 'doc-1',
				'content' => str_repeat( 'B', 500 ),
			),
			array(
				'id'      => 'doc-2',
				'content' => 'short',
			),
		);

		$options  = array(
			'attachments'      => $attachments,
			'memory_documents' => $memory_documents,
			'temperature'      => 0.5,
		);
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => str_repeat( 'C', 800 ),
					),
				),
			),
			'usage'   => array(
				'prompt_tokens' => 42,
			),
		);

		$captured_entry = null;
		$filter         = function ( $entry, $type, $message, $raw_context ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 4 );

		try {
			WP_MCP_AI_Logger::log_chat_interaction(
				456,
				array(
					array(
						'role'    => 'user',
						'content' => str_repeat( 'D', 240 ),
					),
				),
				$options,
				$response,
				789
			);
		} finally {
			remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );
		}

		$this->assertNotNull( $captured_entry, 'Expected the log entry to be captured.' );
		$this->assertSame( 'chat_interaction', $captured_entry['type'] );
		$this->assertSame( 'Chat request executed.', $captured_entry['message'] );

		$context = $captured_entry['context'];

		$this->assertArrayHasKey( 'options', $context );
		$this->assertArrayHasKey( 'attachments', $context['options'] );
		$this->assertSame( '[redacted]', $context['options']['attachments'][0]['data'] );
		$this->assertSame( str_repeat( 'A', 2048 ), $options['attachments'][0]['data'], 'Original attachments should remain untouched.' );

		$this->assertArrayHasKey( 'memory_documents', $context['options'] );
		$this->assertSame( 2, $context['options']['memory_documents']['count'] );
		$this->assertCount( 2, $context['options']['memory_documents']['preview'] );
		$this->assertStringEndsWith( '…', $context['options']['memory_documents']['preview'][0]['content'] );
		$this->assertSame( str_repeat( 'B', 500 ), $options['memory_documents'][0]['content'], 'Memory documents should remain unchanged in the original payload.' );

		$this->assertArrayHasKey( 'response', $context );
		$this->assertIsArray( $context['response'] );
		$this->assertArrayHasKey( 'preview', $context['response'] );
		$preview_length = function_exists( 'mb_strlen' )
			? mb_strlen( $context['response']['preview'], 'UTF-8' )
			: strlen( $context['response']['preview'] );

		$this->assertLessThanOrEqual( 401, $preview_length );
		$this->assertTrue( $context['response']['truncated'] );

		$this->assertIsArray( $response['choices'] );
		$this->assertArrayHasKey( 'message', $response['choices'][0] );
		$this->assertSame( str_repeat( 'C', 800 ), $response['choices'][0]['message']['content'], 'Original response data should not be mutated.' );
	}
}
