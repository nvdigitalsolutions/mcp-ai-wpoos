<?php
/**
 * Tests for media integration.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Media_Integration_Test extends WP_UnitTestCase {

	/**
	 * Media integration instance.
	 *
	 * @var WP_MCP_AI_Media
	 */
	private $media_integration;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Get the media integration instance.
		$this->media_integration = WP_MCP_AI_Media::get_instance();

		// Reset settings.
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );

		parent::tearDown();
	}

	/**
	 * Test that media integration is a singleton.
	 */
	public function test_media_integration_is_singleton() {
		$instance1 = WP_MCP_AI_Media::get_instance();
		$instance2 = WP_MCP_AI_Media::get_instance();

		$this->assertSame( $instance1, $instance2, 'Media integration should return the same instance' );
	}

	/**
	 * Test that the attachment hook is registered.
	 */
	public function test_add_attachment_hook_is_registered() {
		$this->assertGreaterThan(
			0,
			has_action( 'add_attachment', array( $this->media_integration, 'process_new_attachment' ) ),
			'add_attachment hook should be registered'
		);
	}

	/**
	 * Test that processing is skipped when feature is disabled.
	 */
	public function test_processing_skipped_when_feature_disabled() {
		// Disable the feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_media_library' => false,
			)
		);

		// Create a test image attachment.
		$attachment_id = $this->create_test_image_attachment();

		// Get the alt text - should be empty as processing was skipped.
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$this->assertEmpty( $alt_text, 'Alt text should not be generated when feature is disabled' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that non-image attachments are skipped.
	 */
	public function test_non_image_attachments_are_skipped() {
		// Enable the feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_media_library'    => true,
				'ai_media_generate_alt_text' => true,
			)
		);

		// Create a non-image attachment (plain text file).
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => 'test.txt',
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Test Text File',
			)
		);

		// Trigger the hook manually.
		do_action( 'add_attachment', $attachment_id );

		// Get the alt text - should be empty as it's not an image.
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$this->assertEmpty( $alt_text, 'Alt text should not be generated for non-image attachments' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that existing alt text is not overwritten when overwrite setting is false.
	 */
	public function test_existing_alt_text_not_overwritten_when_disabled() {
		// Enable the feature but disable overwrite.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_media_library'     => true,
				'ai_media_generate_alt_text'  => true,
				'ai_media_overwrite_existing' => false,
			)
		);

		// Create a test image attachment with existing alt text.
		$attachment_id = $this->create_test_image_attachment();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Existing alt text' );

		// Trigger the hook manually.
		do_action( 'add_attachment', $attachment_id );

		// Get the alt text - should still be the original.
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$this->assertEquals( 'Existing alt text', $alt_text, 'Existing alt text should not be overwritten when overwrite is disabled' );

		// Clean up.
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test that settings are properly read.
	 */
	public function test_settings_are_properly_read() {
		// Set specific settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_media_library'     => true,
				'ai_media_generate_alt_text'  => true,
				'ai_media_generate_caption'   => false,
				'ai_media_overwrite_existing' => true,
			)
		);

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$this->assertTrue( $settings['enable_ai_media_library'], 'Feature should be enabled' );
		$this->assertTrue( $settings['ai_media_generate_alt_text'], 'Alt text generation should be enabled' );
		$this->assertFalse( $settings['ai_media_generate_caption'], 'Caption generation should be disabled' );
		$this->assertTrue( $settings['ai_media_overwrite_existing'], 'Overwrite should be enabled' );
	}

	/**
	 * Test that logging works when enabled.
	 */
	public function test_logging_when_enabled() {
		// Enable logging.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging' => true,
			)
		);

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Trigger a log by calling a private method through reflection.
		// (Note: In a real scenario, you'd test this through the public API).
		$reflection = new ReflectionClass( $this->media_integration );
		$method     = $reflection->getMethod( 'log_activity' );
		$method->setAccessible( true );
		$method->invoke( $this->media_integration, 'Test activity', 123 );

		$activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		$this->assertNotEmpty( $activity, 'Activity log should not be empty' );
		$this->assertStringContainsString( 'Test activity', $activity[0]['message'], 'Activity message should be logged' );
		$this->assertStringContainsString( '123', $activity[0]['message'], 'Attachment ID should be in log' );
	}

	/**
	 * Test that logging is skipped when disabled.
	 */
	public function test_logging_skipped_when_disabled() {
		// Disable logging.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging' => false,
			)
		);

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Try to log.
		$reflection = new ReflectionClass( $this->media_integration );
		$method     = $reflection->getMethod( 'log_activity' );
		$method->setAccessible( true );
		$method->invoke( $this->media_integration, 'Test activity', 123 );

		$activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		$this->assertEmpty( $activity, 'Activity log should be empty when logging is disabled' );
	}

	/**
	 * Helper method to create a test image attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function create_test_image_attachment() {
		// Create a test image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		// Set metadata to simulate a real image upload.
		$metadata = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}
}
