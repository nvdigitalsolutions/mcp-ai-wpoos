<?php
/**
 * Tests for the Find Similar Media tool (perceptual-hash media lookup).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class WP_MCP_AI_Find_Similar_Media_Test
 *
 * Tests capability gating, input validation, exact-duplicate matching,
 * threshold filtering, and target exclusion.
 *
 * These tests require the GD extension (present in the Docker test image).
 */
class WP_MCP_AI_Find_Similar_Media_Test extends WP_UnitTestCase {

	/**
	 * Skip hash tests when GD is unavailable (local dev without ext-gd).
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'The GD extension is required for find_similar_media tests.' );
		}
	}

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

		$tool   = new WP_MCP_AI_Tool_Find_Similar_Media();
		$result = $tool->execute(
			array(
				'attachment_id' => 1,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_find_similar_media_forbidden', $result->get_error_code() );
	}

	/**
	 * At least one reference input must be provided.
	 */
	public function test_requires_reference_image() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Find_Similar_Media();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_find_similar_media_missing_image', $result->get_error_code() );
	}

	/**
	 * A duplicate in the library must be found with distance 0 and the
	 * reference attachment itself must be excluded.
	 */
	public function test_finds_exact_duplicate_and_excludes_target() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Two independent uploads of the same source file.
		$target_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$dup_id    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$tool   = new WP_MCP_AI_Tool_Find_Similar_Media();
		$result = $tool->execute(
			array(
				'attachment_id' => $target_id,
				'threshold'     => 5,
				'limit'         => 5,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data      = $result;
		$match_ids = wp_list_pluck( $data['matches'], 'attachment_id' );

		$this->assertContains( $dup_id, $match_ids );
		$this->assertNotContains( $target_id, $match_ids );

		$match = $data['matches'][ array_search( $dup_id, $match_ids, true ) ];
		$this->assertSame( 0, $match['distance'] );
		$this->assertSame( 100.0, $match['similarity'] );
	}

	/**
	 * An unrelated image must not match at a strict threshold.
	 */
	public function test_unrelated_image_does_not_match() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$target_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/33772.jpg' );

		$tool   = new WP_MCP_AI_Tool_Find_Similar_Media();
		$result = $tool->execute(
			array(
				'attachment_id' => $target_id,
				'threshold'     => 5,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['match_count'] );
	}

	/**
	 * A nonexistent attachment must return a clear error.
	 */
	public function test_nonexistent_attachment_returns_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Find_Similar_Media();
		$result = $tool->execute(
			array(
				'attachment_id' => 99999999,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_dhash_invalid_attachment', $result->get_error_code() );
	}
}
