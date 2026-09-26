<?php
/**
 * Tests for the Identify Image escalation orchestrator.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class WP_MCP_AI_Identify_Image_Test
 *
 * Tests ladder order, key-gated degradation, web-search opt-in, confidence
 * math, and capability gating.
 *
 * These tests require the GD extension for the media-lookup rung
 * (present in the Docker test image).
 */
class WP_MCP_AI_Identify_Image_Test extends WP_UnitTestCase {

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Subscribers must be blocked.
	 */
	public function test_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Identify_Image();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_identify_image_forbidden', $result->get_error_code() );
	}

	/**
	 * At least one input must be provided.
	 */
	public function test_requires_input() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Identify_Image();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_identify_image_missing_input', $result->get_error_code() );
	}

	/**
	 * Without any credentials the local rungs still run and the external rung
	 * is skipped (never an error, never an HTTP request).
	 */
	public function test_ladder_runs_locally_without_keys() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Canola flowers' );

		$tool = new WP_MCP_AI_Tool_Identify_Image();

		$request_made = false;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Filter stub signature.
		$http_stub = function ( $preempt, $args, $url ) use ( &$request_made ) {
			$request_made = true;
			return $preempt;
		};
		// phpcs:enable

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;

		// Rung 1 completed with metadata.
		$this->assertSame( 'Canola flowers', $data['metadata']['alt_text'] );

		// Rung 2 completed (media lookup against the library).
		$this->assertArrayHasKey( 'media_matches', $data );

		// Rung 3 skipped because no Cloud Vision credentials exist.
		$this->assertSame( 'no_cloud_vision_credentials', $data['skipped']['detection'] );

		// Layout is included by default (opt-out); without detection it is
		// recorded as skipped with the same reason.
		$this->assertSame( 'no_cloud_vision_credentials', $data['skipped']['layout'] );

		// Rung 4 skipped because web search is opt-in.
		$this->assertSame( 'not_requested', $data['skipped']['web_search'] );

		// No HTTP request may have left the server.
		$this->assertFalse( $request_made );
	}

	/**
	 * With a Cloud Vision key the detection rung runs and normalization is
	 * applied to the response.
	 */
	public function test_detection_runs_with_key() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-vision-key',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$vision_response = array(
			'responses' => array(
				array(
					'labelAnnotations' => array(
						array(
							'description' => 'Flower',
							'score'       => 0.95,
							'topicality'  => 0.9,
						),
					),
				),
			),
		);

		$tool = new WP_MCP_AI_Tool_Identify_Image();

		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Filter stub signature.
		$http_stub = function ( $preempt, $args, $url ) use ( $vision_response ) {
			return array(
				'body'     => wp_json_encode( $vision_response ),
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			);
		};
		// phpcs:enable

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( 'Flower', $data['detections']['labels'][0]['description'] );
		$this->assertNotContains( 'detection', $data['skipped'] );
	}

	/**
	 * An exact media-library duplicate must produce full confidence and a
	 * 'sufficient' escalation hint.
	 */
	public function test_exact_duplicate_yields_sufficient_confidence() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$target_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$tool   = new WP_MCP_AI_Tool_Identify_Image();
		$result = $tool->execute(
			array(
				'attachment_id' => $target_id,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( 1.0, $data['confidence'] );
		$this->assertSame( 'sufficient', $data['escalation_hint'] );
	}

	/**
	 * With no signals the hint must suggest falling back to a vision LLM.
	 */
	public function test_no_signals_suggests_vision_fallback() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Identify_Image();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/unknown.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( 'suggest_analyze_image', $data['escalation_hint'] );
		$this->assertLessThan( 0.6, $data['confidence'] );
	}

	/**
	 * Web search must be reported as requiring the Pro addon when it is
	 * requested in base mode.
	 */
	public function test_web_search_skipped_without_pro() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Identify_Image();
		$result = $tool->execute(
			array(
				'image_url'          => 'https://example.com/scene.jpg',
				'include_web_search' => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;

		$reason = $data['skipped']['web_search'];
		$this->assertTrue( in_array( $reason, array( 'pro_addon_required', 'no_web_search_credentials' ), true ) );
	}
}
