<?php
/**
 * Test Gemini batch embedding functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Gemini batch embedding API.
 */
class Test_Gemini_Batch_Embed extends WP_UnitTestCase {

	/**
	 * Test batch_embed_content method signature exists.
	 */
	public function test_batch_embed_content_method_exists() {
		$client = new WP_MCP_AI_Gemini_Client();
		$this->assertTrue( method_exists( $client, 'batch_embed_content' ) );
	}

	/**
	 * Test batch embedding with empty API key returns error.
	 */
	public function test_batch_embed_empty_api_key() {
		// Clear API key.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => '',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$texts  = array( 'Test text 1', 'Test text 2' );
		$result = $client->batch_embed_content( $texts );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_gemini_api_key', $result->get_error_code() );
	}

	/**
	 * Test batch embedding with empty texts array returns error.
	 */
	public function test_batch_embed_empty_texts() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->batch_embed_content( array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_texts', $result->get_error_code() );
	}

	/**
	 * Test batch embedding with non-array input returns error.
	 */
	public function test_batch_embed_non_array_input() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->batch_embed_content( 'not an array' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_texts', $result->get_error_code() );
	}

	/**
	 * Test batch embedding filters empty strings.
	 */
	public function test_batch_embed_filters_empty_strings() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$texts  = array( '', '  ', '' );

		// Use http filter to intercept request and check payload.
		$payload_sent = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'batchEmbedContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'embeddings' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $client->batch_embed_content( $texts );

		// Should get error since all texts are empty after sanitization.
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_empty_batch', $result->get_error_code() );
	}

	/**
	 * Test batch embedding builds correct payload structure.
	 */
	public function test_batch_embed_payload_structure() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$texts  = array( 'Text one', 'Text two', 'Text three' );

		// Use http filter to intercept request and check payload.
		$payload_sent = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'batchEmbedContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'embeddings' => array(
									array( 'values' => array( 0.1, 0.2, 0.3 ) ),
									array( 'values' => array( 0.4, 0.5, 0.6 ) ),
									array( 'values' => array( 0.7, 0.8, 0.9 ) ),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $client->batch_embed_content( $texts );

		// Verify payload structure.
		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'requests', $payload_sent );
		$this->assertCount( 3, $payload_sent['requests'] );

		// Check first request structure.
		$this->assertArrayHasKey( 'content', $payload_sent['requests'][0] );
		$this->assertArrayHasKey( 'parts', $payload_sent['requests'][0]['content'] );
		$this->assertEquals( 'Text one', $payload_sent['requests'][0]['content']['parts'][0]['text'] );
	}

	/**
	 * Test batch embedding with task type option.
	 */
	public function test_batch_embed_with_task_type() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$texts  = array( 'Document text' );

		// Use http filter to intercept request and check payload.
		$payload_sent = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$payload_sent ) {
				if ( strpos( $url, 'batchEmbedContent' ) !== false ) {
					$payload_sent = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'embeddings' => array( array( 'values' => array() ) ) ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $client->batch_embed_content(
			$texts,
			array(
				'task_type' => 'RETRIEVAL_DOCUMENT',
			)
		);

		// Verify task type is included.
		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'taskType', $payload_sent['requests'][0] );
		$this->assertEquals( 'RETRIEVAL_DOCUMENT', $payload_sent['requests'][0]['taskType'] );
	}

	/**
	 * Test batch embedding filter hook.
	 */
	public function test_batch_embed_filter_hook() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$texts  = array( 'Test text' );

		$filter_called = false;
		add_filter(
			'wp_mcp_ai_gemini_batch_embedding_payload',
			function ( $payload, $options, $texts ) use ( &$filter_called ) {
				$filter_called = true;
				$this->assertArrayHasKey( 'requests', $payload );
				return $payload;
			},
			10,
			3
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'batchEmbedContent' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'embeddings' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client->batch_embed_content( $texts );

		$this->assertTrue( $filter_called, 'Batch embedding filter was not called' );
	}

	/**
	 * Test batch embedding uses correct API endpoint.
	 */
	public function test_batch_embed_uses_correct_endpoint() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test_key',
			)
		);

		$client       = new WP_MCP_AI_Gemini_Client();
		$texts        = array( 'Test' );
		$url_captured = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$url_captured ) {
				$url_captured = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'embeddings' => array() ) ),
				);
			},
			10,
			3
		);

		$client->batch_embed_content( $texts );

		$this->assertNotNull( $url_captured );
		$this->assertStringContainsString( 'batchEmbedContent', $url_captured );
		$this->assertStringContainsString( 'text-embedding-004', $url_captured );
	}
}
