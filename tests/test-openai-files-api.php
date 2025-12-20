<?php
/**
 * Tests for OpenAI Files API integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test OpenAI Files API methods.
 */
class Test_OpenAI_Files_API extends WP_UnitTestCase {
	/**
	 * OpenAI client instance.
	 *
	 * @var WP_MCP_AI_OpenAI_Client
	 */
	protected $client;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_OpenAI_Client();
	}

	/**
	 * Test list_files requires API key.
	 */
	public function test_list_files_requires_api_key() {
		// Clear API key.
		update_option( 'wp_mcp_ai_settings', array() );

		$result = $this->client->list_files();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Test list_files with mocked response.
	 */
	public function test_list_files_success() {
		// Set API key.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'   => 'test-key',
				'request_timeout'  => 30,
			)
		);

		// Mock HTTP response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v1/files' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'id'         => 'file-123',
										'object'     => 'file',
										'bytes'      => 1024,
										'created_at' => time(),
										'filename'   => 'test.txt',
										'purpose'    => 'assistants',
									),
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

		$result = $this->client->list_files();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'file-123', $result['data'][0]['id'] );
	}

	/**
	 * Test retrieve_file requires file ID.
	 */
	public function test_retrieve_file_requires_file_id() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'test-key' )
		);

		$result = $this->client->retrieve_file( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_file_id', $result->get_error_code() );
	}

	/**
	 * Test retrieve_file with mocked response.
	 */
	public function test_retrieve_file_success() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'  => 'test-key',
				'request_timeout' => 30,
			)
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v1/files/file-123' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'         => 'file-123',
								'object'     => 'file',
								'bytes'      => 2048,
								'created_at' => time(),
								'filename'   => 'document.pdf',
								'purpose'    => 'assistants',
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->client->retrieve_file( 'file-123' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 'file-123', $result['id'] );
		$this->assertSame( 2048, $result['bytes'] );
	}
}
