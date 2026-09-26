<?php
/**
 * Tests for the Detect Image Content tool (classic Cloud Vision features).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class WP_MCP_AI_Detect_Image_Content_Test
 *
 * Tests the detect_image_content tool: capability gating, credential
 * short-circuiting, feature mapping, request shape, and response
 * normalization.
 */
class WP_MCP_AI_Detect_Image_Content_Test extends WP_UnitTestCase {

	/**
	 * Seed a Gemini API key so the tool makes its HTTP request when expected.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-vision-key',
			)
		);
	}

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Helper: a Google authentication error response.
	 *
	 * @return array Mock HTTP response.
	 */
	private function get_auth_error_response() {
		return array(
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 401,
						'message' => 'Request is missing required authentication credential.',
						'status'  => 'UNAUTHENTICATED',
					),
				)
			),
			'response' => array( 'code' => 401 ),
			'headers'  => array(),
		);
	}

	/**
	 * Subscribers must be blocked.
	 */
	public function test_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Detect_Image_Content();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_forbidden', $result->get_error_code() );
	}

	/**
	 * At least one image input must be provided.
	 */
	public function test_requires_image_input() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Detect_Image_Content();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_missing_image', $result->get_error_code() );
	}

	/**
	 * Missing API key must short-circuit before any HTTP request.
	 */
	public function test_missing_api_key_does_not_request() {
		delete_option( 'wp_mcp_ai_settings' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Detect_Image_Content();

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
				'image_url' => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_missing_api_key', $result->get_error_code() );
		$this->assertFalse( $request_made );
	}

	/**
	 * Invalid feature names must fail before any HTTP request.
	 */
	public function test_invalid_features_return_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Detect_Image_Content();

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
				'image_url' => 'https://example.com/scene.jpg',
				'features'  => array( 'bogus_feature' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_invalid_features', $result->get_error_code() );
		$this->assertFalse( $request_made );
	}

	/**
	 * Requested features must map to the correct Cloud Vision feature types.
	 */
	public function test_request_body_contains_requested_features() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Detect_Image_Content();

		$captured_request = null;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Filter stub signature.
		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);
			return array(
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 401,
							'message' => 'Request is missing required authentication credential.',
							'status'  => 'UNAUTHENTICATED',
						),
					)
				),
				'response' => array( 'code' => 401 ),
				'headers'  => array(),
			);
		};
		// phpcs:enable

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'image_url'   => 'https://example.com/scene.jpg',
				'features'    => array( 'labels', 'text', 'safe_search' ),
				'max_results' => 5,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'vision.googleapis.com', $captured_request['url'] );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'requests', $body );
		$this->assertCount( 1, $body['requests'] );

		$feature_types = wp_list_pluck( $body['requests'][0]['features'], 'type' );
		$this->assertSame( array( 'LABEL_DETECTION', 'TEXT_DETECTION', 'SAFE_SEARCH_DETECTION' ), $feature_types );

		foreach ( $body['requests'][0]['features'] as $feature ) {
			$this->assertSame( 5, $feature['maxResults'] );
		}
	}

	/**
	 * A successful API response must normalize into the canonical envelope.
	 */
	public function test_normalizes_success_response() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Detect_Image_Content();

		$vision_response = array(
			'responses' => array(
				array(
					'labelAnnotations'     => array(
						array(
							'description' => 'Cat',
							'score'       => 0.98,
							'topicality'  => 0.97,
						),
						array(
							'description' => 'Animal',
							'score'       => 0.9,
							'topicality'  => 0.9,
						),
					),
					'textAnnotations'      => array(
						array(
							'description' => 'Hello world',
							'locale'      => 'en',
						),
					),
					'safeSearchAnnotation' => array(
						'adult'    => 'VERY_UNLIKELY',
						'spoof'    => 'VERY_UNLIKELY',
						'medical'  => 'VERY_UNLIKELY',
						'violence' => 'VERY_UNLIKELY',
						'racy'     => 'VERY_UNLIKELY',
					),
				),
			),
		);

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
				'features'  => array( 'labels', 'text', 'safe_search' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertCount( 2, $data['labels'] );
		$this->assertSame( 'Cat', $data['labels'][0]['description'] );
		$this->assertSame( 'Hello world', $data['text']['full_text'] );
		$this->assertSame( 'VERY_UNLIKELY', $data['safe_search']['adult'] );
	}

	/**
	 * An attachment_id must resolve to its local URL before detection.
	 */
	public function test_attachment_id_resolves_to_url() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$tool = new WP_MCP_AI_Tool_Detect_Image_Content();

		$captured_request = null;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Filter stub signature.
		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);
			return $this->get_auth_error_response();
		};
		// phpcs:enable

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'attachment_id' => $attachment_id,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'source', $body['requests'][0]['image'] );
		$this->assertStringContainsString( 'canola', $body['requests'][0]['image']['source']['imageUri'] );
	}

	/**
	 * API errors must surface as WP_Error with the mapped status.
	 */
	public function test_api_error_maps_to_wp_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Detect_Image_Content();

		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Filter stub signature.
		$http_stub = function ( $preempt, $args, $url ) {
			return $this->get_auth_error_response();
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

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_api_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 401, $data['status'] );
	}
}
