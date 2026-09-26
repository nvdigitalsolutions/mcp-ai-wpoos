<?php
/**
 * Tests for the Classic OCR Image tool (tesseract path).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Class WP_MCP_AI_Ocr_Image_Classic_Test
 *
 * Tests capability gating, input validation, local file resolution, and the
 * tesseract-only routing (no VLM). The happy OCR path requires a worker or
 * system tesseract, so the routing test asserts the deterministic
 * "not available" error in a bare test environment.
 */
class WP_MCP_AI_Ocr_Image_Classic_Test extends WP_UnitTestCase {

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

		$tool   = new WP_MCP_AI_Tool_Ocr_Image_Classic();
		$result = $tool->execute(
			array(
				'attachment_id' => 1,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_ocr_image_classic_forbidden', $result->get_error_code() );
	}

	/**
	 * At least one input must be provided.
	 */
	public function test_requires_input() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Ocr_Image_Classic();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_ocr_image_classic_missing_input', $result->get_error_code() );
	}

	/**
	 * A nonexistent attachment must return a clear error.
	 */
	public function test_nonexistent_attachment_returns_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Ocr_Image_Classic();
		$result = $tool->execute(
			array(
				'attachment_id' => 99999999,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_ocr_image_classic_missing_file', $result->get_error_code() );
	}

	/**
	 * Routing must stay tesseract-only: without a worker or system tesseract
	 * the service returns its deterministic "not available" error — never a
	 * fallback to a vision LLM.
	 */
	public function test_tesseract_only_routing() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$tool   = new WP_MCP_AI_Tool_Ocr_Image_Classic();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'language'      => 'eng',
			),
			array( 'user_id' => $user_id )
		);

		// In a bare test environment (no worker, no system tesseract) the
		// service reports unavailability deterministically.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'tesseract_not_found', 'tesseract_failed', 'wp_mcp_ai_sidecar_not_configured', 'wp_mcp_ai_sidecar_error' ),
			true
		);
	}

	/**
	 * Blocked (private) image URLs must be rejected by the SSRF guard before
	 * any download attempt.
	 */
	public function test_blocked_url_is_rejected() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Ocr_Image_Classic();
		$result = $tool->execute(
			array(
				'image_url' => 'http://127.0.0.1/secret.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_ocr_image_classic_blocked_url', $result->get_error_code() );
	}
}
