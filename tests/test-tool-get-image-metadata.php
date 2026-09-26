<?php
/**
 * Tests for the Get Image Metadata tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class WP_MCP_AI_Get_Image_Metadata_Test
 *
 * Tests capability gating, input validation, attachment metadata
 * aggregation, and URL-only degradation.
 */
class WP_MCP_AI_Get_Image_Metadata_Test extends WP_UnitTestCase {

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Subscribers must be blocked.
	 */
	public function test_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Image_Metadata();
		$result = $tool->execute(
			array(
				'attachment_id' => 1,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_get_image_metadata_forbidden', $result->get_error_code() );
	}

	/**
	 * At least one input must be provided.
	 */
	public function test_requires_input() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Image_Metadata();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_get_image_metadata_missing_input', $result->get_error_code() );
	}

	/**
	 * An attachment must aggregate alt text, title, caption, description,
	 * dimensions, and EXIF.
	 */
	public function test_returns_attachment_metadata() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => 'Canola Field',
				'post_excerpt' => 'A field of canola flowers.',
				'post_content' => 'Description body.',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Yellow canola flowers in a field' );

		$tool   = new WP_MCP_AI_Tool_Get_Image_Metadata();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( $attachment_id, $data['attachment_id'] );
		$this->assertSame( 'Yellow canola flowers in a field', $data['alt_text'] );
		$this->assertSame( 'Canola Field', $data['title'] );
		$this->assertSame( 'A field of canola flowers.', $data['caption'] );
		$this->assertSame( 'Description body.', $data['description'] );
		// create_upload_object dedupes names across tests (e.g. canola-39.jpg).
		$this->assertStringContainsString( 'canola', $data['filename'] );
		$this->assertSame( 'image/jpeg', $data['mime_type'] );
		$this->assertGreaterThan( 0, $data['dimensions']['width'] );
		$this->assertStringContainsString( 'canola', $data['url'] );
	}

	/**
	 * A nonexistent attachment must return a clear error.
	 */
	public function test_nonexistent_attachment_returns_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Image_Metadata();
		$result = $tool->execute(
			array(
				'attachment_id' => 99999999,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_attachment', $result->get_error_code() );
	}

	/**
	 * External URLs must degrade to URL-derivable fields with a note.
	 */
	public function test_external_url_returns_limited_metadata() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Image_Metadata();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/photos/logo.png',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( 0, $data['attachment_id'] );
		$this->assertSame( 'logo.png', $data['filename'] );
		$this->assertSame( 'image/png', $data['mime_type'] );
		$this->assertArrayHasKey( 'note', $data );
	}
}
