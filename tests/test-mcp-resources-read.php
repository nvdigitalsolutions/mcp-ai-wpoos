<?php
/**
 * Tests for the MCP resources/read endpoint.
 *
 * Validates that the resources/read method correctly returns
 * resource content from the assistant's memory files allowlist.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_MCP_Resources_Read_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Resources Assistant',
				'post_name'   => 'test-resources-assistant',
			)
		);

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Issue a credential — permissions_check_mcp() refuses bare nonce auth.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->admin_id );
			if ( is_array( $credential ) && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}

		// Bootstrap REST controller.
		$this->bootstrap_rest_controller();
	}

	/**
	 * Bearer token for the suite assistant.
	 *
	 * @var string
	 */
	protected $bearer_token = '';

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$this->rest_controller                = new WP_MCP_AI_REST( $registry, $mock_client );
		$GLOBALS['wp_mcp_ai_rest_controller'] = $this->rest_controller;

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Send a JSON-RPC request to the MCP endpoint.
	 *
	 * @param array $message JSON-RPC message.
	 * @return WP_REST_Response
	 */
	protected function send_mcp_request( $message ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body( wp_json_encode( $message ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Create a text attachment and assign it to the assistant's memory files.
	 *
	 * @param string $filename File name.
	 * @param string $content  File content.
	 * @param string $mime     MIME type.
	 * @return int Attachment ID.
	 */
	protected function create_memory_file( $filename, $content, $mime = 'text/plain' ) {
		// Create a temporary file.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test helper.
		$result = file_put_contents( $file_path, $content );
		if ( false === $result ) {
			$this->fail( 'Failed to create test file: ' . $filename );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => $filename,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path
		);

		// Add the attachment to the assistant's memory files.
		$memory_files   = get_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, true );
		$memory_files   = is_array( $memory_files ) ? $memory_files : array();
		$memory_files[] = $attachment_id;
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_MEMORY_FILES, $memory_files );

		return $attachment_id;
	}

	/**
	 * Test that resources/read returns error when URI is missing.
	 */
	public function test_resources_read_missing_uri() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'resources/read',
				'params'  => array(),
			)
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertStringContainsString( 'uri', $data['error']['message'] );
	}

	/**
	 * Test that resources/read returns error for an unknown URI.
	 */
	public function test_resources_read_unknown_uri() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'resources/read',
				'params'  => array(
					'uri' => 'http://example.com/not-a-real-resource.txt',
				),
			)
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'not found', strtolower( $data['error']['message'] ) );
	}

	/**
	 * Test that resources/read returns text content for a valid text file.
	 */
	public function test_resources_read_text_file() {
		$file_content  = 'Hello, this is test content for MCP resources/read.';
		$attachment_id = $this->create_memory_file( 'test-resource.txt', $file_content );

		$attachment_url = wp_get_attachment_url( $attachment_id );

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'resources/read',
				'params'  => array(
					'uri' => $attachment_url,
				),
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'contents', $data['result'] );
		$this->assertNotEmpty( $data['result']['contents'] );

		$content_item = $data['result']['contents'][0];
		$this->assertSame( $attachment_url, $content_item['uri'] );
		$this->assertSame( 'text/plain', $content_item['mimeType'] );
		$this->assertArrayHasKey( 'text', $content_item );
		$this->assertSame( $file_content, $content_item['text'] );
	}

	/**
	 * Test that resources/read returns blob content for binary files.
	 */
	public function test_resources_read_binary_file() {
		// Create a minimal binary file (fake PNG header).
		$binary_content = "\x89PNG\r\n\x1a\n";
		$attachment_id  = $this->create_memory_file( 'test-image.png', $binary_content, 'image/png' );

		$attachment_url = wp_get_attachment_url( $attachment_id );

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'resources/read',
				'params'  => array(
					'uri' => $attachment_url,
				),
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'contents', $data['result'] );

		$content_item = $data['result']['contents'][0];
		$this->assertSame( $attachment_url, $content_item['uri'] );
		$this->assertSame( 'image/png', $content_item['mimeType'] );
		$this->assertArrayHasKey( 'blob', $content_item );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding to verify test content.
		$this->assertSame( $binary_content, base64_decode( $content_item['blob'] ) );
	}

	/**
	 * Test that resources/read prevents path traversal by rejecting non-allowlisted URIs.
	 */
	public function test_resources_read_rejects_non_allowlisted_uri() {
		// Create a file but don't add it to memory files.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/secret-file.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test helper.
		$result = file_put_contents( $file_path, 'secret content' );
		if ( false === $result ) {
			$this->fail( 'Failed to create test file: secret-file.txt' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'secret-file.txt',
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path
		);

		// Intentionally NOT adding to memory_files.
		$attachment_url = wp_get_attachment_url( $attachment_id );

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'resources/read',
				'params'  => array(
					'uri' => $attachment_url,
				),
			)
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'not found', strtolower( $data['error']['message'] ) );
	}

	/**
	 * Test that resources/read requires authentication.
	 */
	public function test_resources_read_requires_auth() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 6,
					'method'  => 'resources/read',
					'params'  => array(
						'uri' => 'http://example.com/test.txt',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status(), 'Should require authentication' );
	}

	/**
	 * Test that resources/read is listed in the method router.
	 */
	public function test_resources_read_method_is_routed() {
		// Call with an empty URI to verify the method is found (not "method not found").
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'resources/read',
				'params'  => array(
					'uri' => '',
				),
			)
		);

		$data = $response->get_data();

		// The method should be routed (not return -32601 "Method not found").
		if ( isset( $data['error'] ) ) {
			$this->assertNotSame( -32601, $data['error']['code'], 'resources/read should be a recognized method' );
		}
	}
}
