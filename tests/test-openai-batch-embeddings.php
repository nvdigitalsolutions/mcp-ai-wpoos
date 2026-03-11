<?php
/**
 * Tests for OpenAI batch embeddings convenience method.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for WP_MCP_AI_OpenAI_Client::create_batch_embeddings().
 */
class WP_MCP_AI_OpenAI_Batch_Embeddings_Test extends WP_UnitTestCase {

	/**
	 * Set up default settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		remove_all_filters( 'pre_http_request' );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up HTTP filter after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * create_batch_embeddings() must exist.
	 */
	public function test_method_exists() {
		$client = new WP_MCP_AI_OpenAI_Client();
		$this->assertTrue( method_exists( $client, 'create_batch_embeddings' ) );
	}

	/**
	 * Missing API key must return a WP_Error.
	 */
	public function test_requires_api_key() {
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch_embeddings( array( 'Hello world' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Empty texts array must return a WP_Error.
	 */
	public function test_requires_non_empty_texts() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch_embeddings( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_batch_texts', $result->get_error_code() );
	}

	/**
	 * Array of only empty strings must return a WP_Error.
	 */
	public function test_filters_out_empty_strings() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch_embeddings( array( '', '  ', '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_batch_texts', $result->get_error_code() );
	}

	/**
	 * create_batch_embeddings() must upload a JSONL file and create a batch job.
	 *
	 * Mocks both the file-upload endpoint and the batches endpoint, and asserts
	 * that the batch was created targeting /v1/embeddings.
	 */
	public function test_creates_batch_job_for_embeddings() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test-batch-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$urls_called  = array();
		$batch_body   = null;
		$upload_body  = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$urls_called, &$batch_body, &$upload_body ) {
				$urls_called[] = $url;

				if ( strpos( $url, '/files' ) !== false && 'POST' === $args['method'] ) {
					// Simulate file upload response.
					$upload_body = $args['body'];
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'       => 'file-abc123',
								'object'   => 'file',
								'purpose'  => 'batch',
								'filename' => 'batch-embeddings.jsonl',
							)
						),
						'headers'  => array(),
					);
				}

				if ( strpos( $url, '/batches' ) !== false ) {
					// Simulate batch creation response.
					$batch_body = json_decode( $args['body'], true );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'                 => 'batch-xyz789',
								'object'             => 'batch',
								'endpoint'           => '/v1/embeddings',
								'input_file_id'      => 'file-abc123',
								'completion_window'  => '24h',
								'status'             => 'validating',
							)
						),
						'headers'  => array(),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch_embeddings(
			array( 'First text', 'Second text', 'Third text' ),
			array( 'model' => 'text-embedding-3-small' )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );

		// Verify batch was created targeting /v1/embeddings.
		$this->assertNotNull( $batch_body );
		$this->assertArrayHasKey( 'endpoint', $batch_body );
		$this->assertSame( '/v1/embeddings', $batch_body['endpoint'] );

		// Verify the file ID was passed to the batch.
		$this->assertArrayHasKey( 'input_file_id', $batch_body );
		$this->assertSame( 'file-abc123', $batch_body['input_file_id'] );

		// Verify the returned batch info.
		$this->assertSame( 'batch-xyz789', $result['id'] );
		$this->assertSame( 'validating', $result['status'] );
	}

	/**
	 * The JSONL file uploaded must contain one line per text, each with a custom_id.
	 */
	public function test_jsonl_contains_one_line_per_text() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test-jsonl-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$upload_multipart_body = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$upload_multipart_body ) {
				if ( strpos( $url, '/files' ) !== false && 'POST' === $args['method'] ) {
					$upload_multipart_body = $args['body'];
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'id' => 'file-001', 'purpose' => 'batch' ) ),
						'headers'  => array(),
					);
				}

				if ( strpos( $url, '/batches' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'                => 'batch-001',
								'endpoint'          => '/v1/embeddings',
								'input_file_id'     => 'file-001',
								'completion_window' => '24h',
								'status'            => 'validating',
							)
						),
						'headers'  => array(),
					);
				}

				return $preempt;
			},
			10,
			3
		);

		$texts  = array( 'Alpha', 'Beta', 'Gamma' );
		$client = new WP_MCP_AI_OpenAI_Client();
		$client->create_batch_embeddings( $texts );

		// The upload body is a multipart form; find the JSONL content embedded in it.
		$this->assertNotNull( $upload_multipart_body );

		// Count occurrences of custom_id patterns (embed-0, embed-1, embed-2).
		$this->assertStringContainsString( 'embed-0', $upload_multipart_body );
		$this->assertStringContainsString( 'embed-1', $upload_multipart_body );
		$this->assertStringContainsString( 'embed-2', $upload_multipart_body );
		$this->assertStringContainsString( '/v1/embeddings', $upload_multipart_body );
	}

	/**
	 * The default model must be text-embedding-3-small when not specified.
	 */
	public function test_uses_default_model() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['openai_api_key'] = 'sk-test-default-model';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$upload_body_raw = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$upload_body_raw ) {
				if ( strpos( $url, '/files' ) !== false ) {
					$upload_body_raw = $args['body'];
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'id' => 'file-002', 'purpose' => 'batch' ) ),
						'headers'  => array(),
					);
				}
				if ( strpos( $url, '/batches' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'id' => 'batch-002', 'endpoint' => '/v1/embeddings', 'status' => 'validating' ) ),
						'headers'  => array(),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$client = new WP_MCP_AI_OpenAI_Client();
		$client->create_batch_embeddings( array( 'Test text' ) );

		$this->assertNotNull( $upload_body_raw );
		$this->assertStringContainsString( 'text-embedding-3-small', $upload_body_raw );
	}
}
