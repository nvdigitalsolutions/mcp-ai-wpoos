<?php
/**
 * Tests for SVG file validation based on provider.
 *
 * @package WP_MCP_AI
 */

/**
 * Test SVG file validation for different AI providers.
 */
class WP_MCP_AI_SVG_Validation_Test extends WP_UnitTestCase {

	/**
	 * Test that SVG is excluded from OpenAI allowed MIME types.
	 */
	public function test_svg_excluded_from_openai_mime_types() {
		$image_mimes = WP_MCP_AI_Message_Attachments::get_allowed_mime_types( 'image', 'openai' );
		
		$this->assertIsArray( $image_mimes );
		$this->assertNotContains( 'image/svg+xml', $image_mimes, 'SVG should not be in OpenAI allowed MIME types' );
		$this->assertContains( 'image/png', $image_mimes, 'PNG should be allowed for OpenAI' );
		$this->assertContains( 'image/jpeg', $image_mimes, 'JPEG should be allowed for OpenAI' );
		$this->assertContains( 'image/webp', $image_mimes, 'WebP should be allowed for OpenAI' );
	}

	/**
	 * Test that SVG is included in Gemini allowed MIME types.
	 */
	public function test_svg_included_in_gemini_mime_types() {
		$image_mimes = WP_MCP_AI_Message_Attachments::get_allowed_mime_types( 'image', 'gemini' );
		
		$this->assertIsArray( $image_mimes );
		$this->assertContains( 'image/svg+xml', $image_mimes, 'SVG should be in Gemini allowed MIME types' );
		$this->assertContains( 'image/png', $image_mimes, 'PNG should be allowed for Gemini' );
		$this->assertContains( 'image/jpeg', $image_mimes, 'JPEG should be allowed for Gemini' );
	}

	/**
	 * Test that SVG is included in Google provider allowed MIME types.
	 */
	public function test_svg_included_in_google_mime_types() {
		$image_mimes = WP_MCP_AI_Message_Attachments::get_allowed_mime_types( 'image', 'google' );
		
		$this->assertIsArray( $image_mimes );
		$this->assertContains( 'image/svg+xml', $image_mimes, 'SVG should be in Google allowed MIME types' );
	}

	/**
	 * Test that base image formats are supported by all providers.
	 */
	public function test_base_image_formats_supported() {
		$base_formats = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
		);

		foreach ( array( 'openai', 'gemini', 'google' ) as $provider ) {
			$image_mimes = WP_MCP_AI_Message_Attachments::get_allowed_mime_types( 'image', $provider );
			
			foreach ( $base_formats as $format ) {
				$this->assertContains(
					$format,
					$image_mimes,
					"$format should be supported by $provider"
				);
			}
		}
	}

	/**
	 * Test provider parameter defaults to 'openai'.
	 */
	public function test_provider_defaults_to_openai() {
		$default_mimes = WP_MCP_AI_Message_Attachments::get_allowed_mime_types( 'image' );
		$openai_mimes  = WP_MCP_AI_Message_Attachments::get_allowed_mime_types( 'image', 'openai' );
		
		$this->assertEquals( $default_mimes, $openai_mimes, 'Default provider should be OpenAI' );
		$this->assertNotContains( 'image/svg+xml', $default_mimes, 'Default should not include SVG' );
	}

	/**
	 * Test that is_image_mime_type respects provider.
	 */
	public function test_is_image_mime_type_respects_provider() {
		// SVG should NOT be considered an image for OpenAI.
		$is_image_openai = WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/svg+xml', 'openai' );
		$this->assertFalse( $is_image_openai, 'SVG should not be an image for OpenAI' );
		
		// SVG should be considered an image for Gemini.
		$is_image_gemini = WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/svg+xml', 'gemini' );
		$this->assertTrue( $is_image_gemini, 'SVG should be an image for Gemini' );
		
		// PNG should be an image for both.
		$is_png_openai = WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/png', 'openai' );
		$is_png_gemini = WP_MCP_AI_Message_Attachments::is_image_mime_type( 'image/png', 'gemini' );
		$this->assertTrue( $is_png_openai, 'PNG should be an image for OpenAI' );
		$this->assertTrue( $is_png_gemini, 'PNG should be an image for Gemini' );
	}
}
