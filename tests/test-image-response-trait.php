<?php
/**
 * Tests for the WP_MCP_AI_Tool_Image_Response trait.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for image response trait functionality.
 */
class Test_Image_Response_Trait extends WP_UnitTestCase {

	/**
	 * Test that the trait adds image HTML to single image responses.
	 */
	public function test_adds_image_html_to_single_image_response() {
		// Create a test image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertNotEmpty( $attachment_id );

		// Create a mock tool using the trait.
		$tool = new class() {
			use WP_MCP_AI_Tool_Image_Response;

			public function test_add_image_html( $result ) {
				return $this->add_image_html_to_response( $result );
			}
		};

		// Test data mimicking image generation tool result.
		$result = array(
			'attachment_id'  => $attachment_id,
			'url'            => wp_get_attachment_url( $attachment_id ),
			'prompt'         => 'A beautiful sunset',
			'text'           => 'Successfully generated image (ID: ' . $attachment_id . ').',
		);

		// Add image HTML.
		$result = $tool->test_add_image_html( $result );

		// Verify message field was created.
		$this->assertArrayHasKey( 'message', $result );
		$this->assertNotEmpty( $result['message'] );

		// Verify message contains the text.
		$this->assertStringContainsString( 'Successfully generated image', $result['message'] );

		// Verify message contains an img tag.
		$this->assertStringContainsString( '<img', $result['message'] );
		$this->assertStringContainsString( 'src=', $result['message'] );

		// Verify alt text is included.
		$this->assertStringContainsString( 'alt="', $result['message'] );

		// Verify CSS class is included.
		$this->assertStringContainsString( 'class="wp-mcp-ai-generated-image"', $result['message'] );

		// Verify loading lazy attribute.
		$this->assertStringContainsString( 'loading="lazy"', $result['message'] );
	}

	/**
	 * Test that the trait adds image HTML to multiple image responses.
	 */
	public function test_adds_image_html_to_multiple_image_response() {
		// Create test image attachments.
		$attachment_id_1 = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$attachment_id_2 = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/waffles.jpg' );

		$this->assertNotEmpty( $attachment_id_1 );
		$this->assertNotEmpty( $attachment_id_2 );

		// Create a mock tool using the trait.
		$tool = new class() {
			use WP_MCP_AI_Tool_Image_Response;

			public function test_add_multiple_images_html( $result ) {
				return $this->add_multiple_images_html_to_response( $result );
			}
		};

		// Test data mimicking image variation tool result.
		$result = array(
			'success' => true,
			'data'    => array(
				'images' => array(
					array(
						'attachment_id' => $attachment_id_1,
						'url'           => wp_get_attachment_url( $attachment_id_1 ),
					),
					array(
						'attachment_id' => $attachment_id_2,
						'url'           => wp_get_attachment_url( $attachment_id_2 ),
					),
				),
				'count'   => 2,
				'text'    => 'Successfully created 2 variation(s).',
			),
		);

		// Add image HTML.
		$result = $tool->test_add_multiple_images_html( $result );

		// Verify message field was created.
		$this->assertArrayHasKey( 'message', $result['data'] );
		$this->assertNotEmpty( $result['data']['message'] );

		// Verify message contains the text.
		$this->assertStringContainsString( 'Successfully created 2', $result['data']['message'] );

		// Verify message contains multiple img tags.
		$img_count = substr_count( $result['data']['message'], '<img' );
		$this->assertEquals( 2, $img_count, 'Should have 2 img tags' );
	}

	/**
	 * Test that the trait handles missing attachment ID gracefully.
	 */
	public function test_handles_missing_attachment_id_gracefully() {
		// Create a mock tool using the trait.
		$tool = new class() {
			use WP_MCP_AI_Tool_Image_Response;

			public function test_add_image_html( $result ) {
				return $this->add_image_html_to_response( $result );
			}
		};

		// Test data without attachment_id.
		$result = array(
			'url'  => 'https://example.com/image.jpg',
			'text' => 'Image result without attachment ID.',
		);

		// Add image HTML (should not fail).
		$result = $tool->test_add_image_html( $result );

		// Result should be unchanged (no message added).
		$this->assertArrayNotHasKey( 'message', $result );
	}

	/**
	 * Test that generated image HTML is properly escaped.
	 */
	public function test_image_html_is_properly_escaped() {
		// Create a test image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		// Create a mock tool using the trait.
		$tool = new class() {
			use WP_MCP_AI_Tool_Image_Response;

			public function test_add_image_html( $result ) {
				return $this->add_image_html_to_response( $result );
			}
		};

		// Test data with potentially dangerous content in prompt.
		$result = array(
			'attachment_id'  => $attachment_id,
			'prompt'         => 'Test<script>alert("xss")</script>image',
			'text'           => 'Image generated.',
		);

		// Add image HTML.
		$result = $tool->test_add_image_html( $result );

		// Verify script tag is escaped in alt text.
		$this->assertStringNotContainsString( '<script>', $result['message'] );
		$this->assertStringContainsString( 'Test&lt;script&gt;', $result['message'] );
	}

	/**
	 * Test alt text truncation for long prompts.
	 */
	public function test_alt_text_truncation() {
		// Create a test image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		// Create a mock tool using the trait.
		$tool = new class() {
			use WP_MCP_AI_Tool_Image_Response;

			public function test_get_alt_text( $result ) {
				return $this->get_image_alt_text( $result );
			}
		};

		// Very long prompt.
		$long_prompt = str_repeat( 'This is a very long prompt that should be truncated. ', 10 );

		$result = array(
			'prompt' => $long_prompt,
		);

		$alt_text = $tool->test_get_alt_text( $result );

		// Verify alt text is truncated.
		$this->assertLessThanOrEqual( 150, strlen( $alt_text ), 'Alt text should be truncated to reasonable length' );
	}
}
