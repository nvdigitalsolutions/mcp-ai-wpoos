<?php
/**
 * Tests for the pure-PHP image dHash helper.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-image-dhash.php';

/**
 * Class WP_MCP_AI_Image_DHash_Test
 *
 * Tests hash computation, distance math, validation, and post-meta caching.
 *
 * These tests require the GD extension (present in the Docker test image).
 */
class WP_MCP_AI_Image_DHash_Test extends WP_UnitTestCase {

	/**
	 * Skip hash tests when GD is unavailable (local dev without ext-gd).
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'The GD extension is required for dHash tests.' );
		}
	}

	/**
	 * Identical files must hash to distance 0.
	 */
	public function test_identical_files_have_zero_distance() {
		$hash_a = WP_MCP_AI_Image_DHash::compute( DIR_TESTDATA . '/images/canola.jpg' );
		$hash_b = WP_MCP_AI_Image_DHash::compute( DIR_TESTDATA . '/images/canola.jpg' );

		$this->assertNotInstanceOf( WP_Error::class, $hash_a );
		$this->assertTrue( WP_MCP_AI_Image_DHash::is_valid_hash( $hash_a ) );
		$this->assertSame( $hash_a, $hash_b );

		$distance = WP_MCP_AI_Image_DHash::distance( $hash_a, $hash_b );
		$this->assertSame( 0, $distance );
	}

	/**
	 * Unrelated images must produce a large distance.
	 */
	public function test_different_images_have_large_distance() {
		$hash_canola = WP_MCP_AI_Image_DHash::compute( DIR_TESTDATA . '/images/canola.jpg' );
		$hash_other  = WP_MCP_AI_Image_DHash::compute( DIR_TESTDATA . '/images/33772.jpg' );

		$this->assertNotInstanceOf( WP_Error::class, $hash_canola );
		$this->assertNotInstanceOf( WP_Error::class, $hash_other );

		$distance = WP_MCP_AI_Image_DHash::distance( $hash_canola, $hash_other );
		$this->assertGreaterThan( 10, $distance );
	}

	/**
	 * A resized copy must stay well within the similarity threshold.
	 */
	public function test_resized_copy_stays_similar() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$file_path     = get_attached_file( $attachment_id );

		$original = WP_MCP_AI_Image_DHash::compute( DIR_TESTDATA . '/images/canola.jpg' );
		$resized  = WP_MCP_AI_Image_DHash::compute( $file_path );

		// create_upload_object may produce a slightly re-encoded copy; the
		// perceptual hash must still land within the default threshold.
		$this->assertNotInstanceOf( WP_Error::class, $original );
		$this->assertNotInstanceOf( WP_Error::class, $resized );

		$distance = WP_MCP_AI_Image_DHash::distance( $original, $resized );
		$this->assertLessThanOrEqual( 10, $distance );
	}

	/**
	 * Malformed hashes must be rejected before comparison.
	 */
	public function test_malformed_hashes_are_rejected() {
		$result = WP_MCP_AI_Image_DHash::distance( 'zzzz', 'not-a-hash' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_dhash_invalid_hash', $result->get_error_code() );
	}

	/**
	 * Missing files must return a clear error.
	 */
	public function test_missing_file_returns_error() {
		$result = WP_MCP_AI_Image_DHash::compute( '/nonexistent/path/image.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_dhash_file_missing', $result->get_error_code() );
	}

	/**
	 * Non-image data must return a clear error.
	 */
	public function test_non_image_data_returns_error() {
		$tmp_file = wp_tempnam( 'dhash-not-an-image.bin' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing test fixture to system temp.
		file_put_contents( $tmp_file, 'this is not an image' );

		$result = WP_MCP_AI_Image_DHash::compute( $tmp_file );

		wp_delete_file( $tmp_file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_dhash_invalid_image', $result->get_error_code() );
	}

	/**
	 * Attachment hashes must be cached in post meta.
	 */
	public function test_attachment_hash_is_cached_in_meta() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$hash = WP_MCP_AI_Image_DHash::get_attachment_hash( $attachment_id );

		$this->assertNotInstanceOf( WP_Error::class, $hash );
		$this->assertSame( $hash, strtolower( (string) get_post_meta( $attachment_id, WP_MCP_AI_Image_DHash::HASH_META_KEY, true ) ) );

		// A second lookup must return the cached value unchanged.
		$this->assertSame( $hash, WP_MCP_AI_Image_DHash::get_attachment_hash( $attachment_id ) );
	}

	/**
	 * Invalid attachment IDs must be rejected.
	 */
	public function test_invalid_attachment_returns_error() {
		$result = WP_MCP_AI_Image_DHash::get_attachment_hash( 99999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_dhash_invalid_attachment', $result->get_error_code() );
	}
}
