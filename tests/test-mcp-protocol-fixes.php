<?php
/**
 * Tests for MCP protocol fixes.
 *
 * This test suite validates the fixes for:
 * 1. Preserving structured tool responses (High priority)
 * 2. Safeguards around tool payload encoding (Medium priority)
 * 3. Invalid resource entries validation (Low priority)
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_MCP_Protocol_Fixes_Test extends WP_UnitTestCase {

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
	 * REST controller instance with access to protected methods.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Reflection class for testing protected methods.
	 *
	 * @var ReflectionClass
	 */
	protected $reflection;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test MCP Protocol Assistant',
			)
		);

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Bootstrap REST controller.
		$this->bootstrap_rest_controller();

		// Setup reflection for accessing protected methods.
		$this->reflection = new ReflectionClass( $this->rest_controller );
	}

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
	 * Call a protected method on the REST controller.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed
	 */
	protected function call_protected_method( $method, $args = array() ) {
		$method = $this->reflection->getMethod( $method );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->rest_controller, $args );
	}

	/**
	 * Test that is_mcp_content_array correctly identifies valid MCP content.
	 */
	public function test_is_mcp_content_array_validates_text_content() {
		$valid_text_content = array(
			array(
				'type' => 'text',
				'text' => 'Hello, world!',
			),
		);

		$result = $this->call_protected_method( 'is_mcp_content_array', array( $valid_text_content ) );
		$this->assertTrue( $result, 'Valid text content should be recognized' );
	}

	/**
	 * Test that is_mcp_content_array validates multiple content items.
	 */
	public function test_is_mcp_content_array_validates_multiple_items() {
		$multiple_content = array(
			array(
				'type' => 'text',
				'text' => 'First item',
			),
			array(
				'type' => 'text',
				'text' => 'Second item',
			),
		);

		$result = $this->call_protected_method( 'is_mcp_content_array', array( $multiple_content ) );
		$this->assertTrue( $result, 'Multiple content items should be valid' );
	}

	/**
	 * Test that is_mcp_content_array validates image content.
	 */
	public function test_is_mcp_content_array_validates_image_content() {
		$image_content = array(
			array(
				'type'     => 'image',
				'data'     => 'base64data',
				'mimeType' => 'image/png',
			),
		);

		$result = $this->call_protected_method( 'is_mcp_content_array', array( $image_content ) );
		$this->assertTrue( $result, 'Valid image content should be recognized' );
	}

	/**
	 * Test that is_mcp_content_array rejects invalid content.
	 */
	public function test_is_mcp_content_array_rejects_non_array() {
		$result = $this->call_protected_method( 'is_mcp_content_array', array( 'not an array' ) );
		$this->assertFalse( $result, 'Non-array should be rejected' );
	}

	/**
	 * Test that is_mcp_content_array rejects empty arrays.
	 */
	public function test_is_mcp_content_array_rejects_empty_array() {
		$result = $this->call_protected_method( 'is_mcp_content_array', array( array() ) );
		$this->assertFalse( $result, 'Empty array should be rejected' );
	}

	/**
	 * Test that is_mcp_content_array rejects associative arrays.
	 */
	public function test_is_mcp_content_array_rejects_associative_array() {
		$result = $this->call_protected_method( 'is_mcp_content_array', array( array( 'key' => 'value' ) ) );
		$this->assertFalse( $result, 'Associative array should be rejected' );
	}

	/**
	 * Test that is_mcp_content_array rejects items without type field.
	 */
	public function test_is_mcp_content_array_rejects_missing_type() {
		$invalid_content = array(
			array(
				'text' => 'Missing type field',
			),
		);

		$result = $this->call_protected_method( 'is_mcp_content_array', array( $invalid_content ) );
		$this->assertFalse( $result, 'Content without type field should be rejected' );
	}

	/**
	 * Test that is_mcp_content_array rejects text content without text field.
	 */
	public function test_is_mcp_content_array_rejects_text_without_text_field() {
		$invalid_content = array(
			array(
				'type' => 'text',
			),
		);

		$result = $this->call_protected_method( 'is_mcp_content_array', array( $invalid_content ) );
		$this->assertFalse( $result, 'Text content without text field should be rejected' );
	}

	/**
	 * Test that is_mcp_content_array rejects image content without data or url.
	 */
	public function test_is_mcp_content_array_rejects_image_without_data_or_url() {
		$invalid_content = array(
			array(
				'type'     => 'image',
				'mimeType' => 'image/png',
			),
		);

		$result = $this->call_protected_method( 'is_mcp_content_array', array( $invalid_content ) );
		$this->assertFalse( $result, 'Image content without data or url should be rejected' );
	}

	/**
	 * Test convert_to_text_content handles strings correctly.
	 */
	public function test_convert_to_text_content_handles_strings() {
		$result = $this->call_protected_method( 'convert_to_text_content', array( 'test string' ) );
		$this->assertSame( 'test string', $result );
	}

	/**
	 * Test convert_to_text_content handles integers correctly.
	 */
	public function test_convert_to_text_content_handles_integers() {
		$result = $this->call_protected_method( 'convert_to_text_content', array( 42 ) );
		$this->assertSame( '42', $result );
	}

	/**
	 * Test convert_to_text_content handles booleans correctly.
	 */
	public function test_convert_to_text_content_handles_booleans() {
		$result = $this->call_protected_method( 'convert_to_text_content', array( true ) );
		$this->assertSame( 'true', $result );

		$result = $this->call_protected_method( 'convert_to_text_content', array( false ) );
		$this->assertSame( 'false', $result );
	}

	/**
	 * Test convert_to_text_content handles null correctly.
	 */
	public function test_convert_to_text_content_handles_null() {
		$result = $this->call_protected_method( 'convert_to_text_content', array( null ) );
		$this->assertSame( 'null', $result );
	}

	/**
	 * Test convert_to_text_content handles arrays correctly.
	 */
	public function test_convert_to_text_content_handles_arrays() {
		$input  = array(
			'key'    => 'value',
			'number' => 123,
		);
		$result = $this->call_protected_method( 'convert_to_text_content', array( $input ) );

		$this->assertIsString( $result );
		$decoded = json_decode( $result, true );
		$this->assertSame( $input, $decoded );
	}

	/**
	 * Test convert_to_text_content handles objects correctly.
	 */
	public function test_convert_to_text_content_handles_objects() {
		$input  = (object) array(
			'key'    => 'value',
			'number' => 123,
		);
		$result = $this->call_protected_method( 'convert_to_text_content', array( $input ) );

		$this->assertIsString( $result );
		$decoded = json_decode( $result, true );
		$this->assertSame(
			array(
				'key'    => 'value',
				'number' => 123,
			),
			$decoded
		);
	}

	/**
	 * Test mcp_resources_list skips invalid attachment IDs.
	 */
	public function test_resources_list_skips_invalid_file_ids() {
		// Set memory files with invalid IDs.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_memory_files',
			array( 0, 99999, -1 )
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'resources/list',
					'params'  => array(
						'assistant_id' => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'] );
		$this->assertEmpty( $data['result']['resources'], 'Resources should be empty for invalid IDs' );
	}

	/**
	 * Test mcp_resources_list skips non-attachment posts.
	 */
	public function test_resources_list_skips_non_attachments() {
		// Create a regular post (not an attachment).
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Regular Post',
			)
		);

		// Set as memory file.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_memory_files',
			array( $post_id )
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'resources/list',
					'params'  => array(
						'assistant_id' => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'] );
		$this->assertEmpty( $data['result']['resources'], 'Resources should skip non-attachments' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test mcp_resources_list includes valid attachments.
	 */
	public function test_resources_list_includes_valid_attachments() {
		// Create a test attachment.
		$filename   = 'test-file.txt';
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename;

		// Create a simple text file.
		$written = file_put_contents( $file_path, 'Test content' );
		if ( false === $written ) {
			$this->markTestSkipped( 'Could not create test file' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Test File',
				'post_content'   => '',
				'post_excerpt'   => 'Test description',
				'post_status'    => 'inherit',
			),
			$file_path
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file_path ) );

		// Set as memory file.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_memory_files',
			array( $attachment_id )
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'resources/list',
					'params'  => array(
						'assistant_id' => $this->assistant_id,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'] );
		$this->assertCount( 1, $data['result']['resources'], 'Should include valid attachment' );

		$resource = $data['result']['resources'][0];
		$this->assertArrayHasKey( 'uri', $resource );
		$this->assertArrayHasKey( 'name', $resource );
		$this->assertArrayHasKey( 'mimeType', $resource );
		$this->assertNotEmpty( $resource['uri'], 'URI should not be empty' );
		$this->assertSame( 'Test File', $resource['name'] );
		$this->assertSame( 'text/plain', $resource['mimeType'] );

		// Cleanup.
		wp_delete_attachment( $attachment_id, true );
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
	}
}
