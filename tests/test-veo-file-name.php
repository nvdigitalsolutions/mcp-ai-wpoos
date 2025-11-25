<?php
/**
 * Test that Veo video generation returns file_name in response
 *
 * This test verifies that when a video is generated, the response includes
 * the file_name field so the chat client can display the correct filename.
 *
 * @package WP_MCP_AI
 */

class Test_Veo_File_Name extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Video_Generation_Service
	 */
	protected $service;

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Veo_Video
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
		
		$this->service = new WP_MCP_AI_Gemini_Video_Generation_Service();
		$this->tool    = new WP_MCP_AI_Tool_Generate_Veo_Video();
		
		// Set up mock API key.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-api-key',
			)
		);
	}

	/**
	 * Test that Media URL Utils build_attachment_result includes file_name.
	 */
	public function test_media_url_utils_includes_file_name() {
		// Create a mock upload result similar to what wp_upload_bits returns.
		$upload = array(
			'file' => '/var/www/html/wp-content/uploads/2024/11/veo-video-test123.mp4',
			'url'  => 'http://example.org/wp-content/uploads/2024/11/veo-video-test123.mp4',
			'type' => 'video/mp4',
		);

		$result = WP_MCP_AI_Media_URL_Utils::build_attachment_result( 123, $upload );

		// Verify result structure.
		$this->assertIsArray( $result, 'build_attachment_result should return array' );
		$this->assertArrayHasKey( 'attachment_id', $result, 'Result should have attachment_id' );
		$this->assertArrayHasKey( 'url', $result, 'Result should have url' );
		$this->assertArrayHasKey( 'file_name', $result, 'Result should have file_name' );

		// Verify values.
		$this->assertEquals( 123, $result['attachment_id'], 'attachment_id should match' );
		$this->assertEquals( $upload['url'], $result['url'], 'url should match' );
		$this->assertEquals( 'veo-video-test123.mp4', $result['file_name'], 'file_name should be extracted from file path' );
	}

	/**
	 * Test that save_video_to_media returns file_name.
	 */
	public function test_service_save_video_includes_file_name() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		
		$result = array(
			'video_data'   => 'fake-video-data',
			'prompt'       => 'Test video',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->service );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		$save_result = $method->invoke( $this->service, $result, $user_id );

		// Verify it's not an error.
		$this->assertNotWPError( $save_result, 'save_video_to_media should succeed' );
		
		// Verify file_name is included.
		$this->assertArrayHasKey( 'file_name', $save_result, 'Result should have file_name' );
		$this->assertNotEmpty( $save_result['file_name'], 'file_name should not be empty' );
		
		// Verify file_name has .mp4 extension.
		$this->assertStringEndsWith( '.mp4', $save_result['file_name'], 'file_name should end with .mp4' );
		
		// Verify file_name starts with veo-video-.
		$this->assertStringStartsWith( 'veo-video-', $save_result['file_name'], 'file_name should start with veo-video-' );
	}

	/**
	 * Test that tool's save_video_to_media method includes file_name.
	 */
	public function test_tool_save_video_includes_file_name() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		
		$result = array(
			'video_data'   => 'fake-video-data',
			'prompt'       => 'Test video',
			'duration'     => 5,
			'aspect_ratio' => '16:9',
			'resolution'   => '720p',
			'model'        => 'veo-3.1-generate-preview',
			'provider'     => 'gemini',
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'save_video_to_media' );
		$method->setAccessible( true );

		$save_result = $method->invoke( $this->tool, $result, $user_id );

		// Verify it's not an error.
		$this->assertNotWPError( $save_result, 'save_video_to_media should succeed' );
		
		// Verify file_name is included.
		$this->assertArrayHasKey( 'file_name', $save_result, 'Result should have file_name' );
		$this->assertNotEmpty( $save_result['file_name'], 'file_name should not be empty' );
		
		// Verify file_name has .mp4 extension.
		$this->assertStringEndsWith( '.mp4', $save_result['file_name'], 'file_name should end with .mp4' );
	}

	/**
	 * Test that sanitize_for_llm preserves file_name.
	 */
	public function test_sanitize_for_llm_preserves_file_name() {
		$result = array(
			'success'       => true,
			'attachment_id' => 123,
			'url'           => 'http://example.org/wp-content/uploads/2024/11/veo-video-test.mp4',
			'file_name'     => 'veo-video-test.mp4',
			'edit_url'      => 'http://example.org/wp-admin/post.php?post=123&action=edit',
			'prompt'        => 'Test video',
			'duration'      => 5,
			'aspect_ratio'  => '16:9',
			'resolution'    => '720p',
			'model'         => 'veo-3.1-generate-preview',
			'provider'      => 'gemini',
			'text'          => 'Successfully generated video',
			// This should be stripped.
			'video_data'    => base64_encode( 'large-binary-data' ),
		);

		$sanitized = $this->tool->sanitize_for_llm( $result );

		// Verify file_name is preserved.
		$this->assertArrayHasKey( 'file_name', $sanitized, 'Sanitized result should have file_name' );
		$this->assertEquals( 'veo-video-test.mp4', $sanitized['file_name'], 'file_name should be preserved' );
		
		// Verify other essential fields are preserved.
		$this->assertArrayHasKey( 'attachment_id', $sanitized );
		$this->assertArrayHasKey( 'url', $sanitized );
		$this->assertArrayHasKey( 'prompt', $sanitized );
		
		// Verify binary data is stripped.
		$this->assertArrayNotHasKey( 'video_data', $sanitized, 'video_data should be stripped' );
	}
}
