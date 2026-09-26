<?php
/**
 * Tests for the Describe Image Layout tool (layout composer).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Class WP_MCP_AI_Describe_Image_Layout_Test
 *
 * Tests quadrant math, size buckets, Google-vertices parsing, prose output,
 * malformed input handling, and auto-detect key gating.
 */
class WP_MCP_AI_Describe_Image_Layout_Test extends WP_UnitTestCase {

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

		$tool   = new WP_MCP_AI_Tool_Describe_Image_Layout();
		$result = $tool->execute(
			array(
				'boxes' => wp_json_encode( array() ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_describe_image_layout_forbidden', $result->get_error_code() );
	}

	/**
	 * Quadrant math: a box in the top-right corner must be classified as such.
	 */
	public function test_quadrant_classification() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$boxes = array(
			array(
				'label' => 'logo',
				'box'   => array( 0.8, 0.05, 0.95, 0.2 ),
				'score' => 0.9,
			),
			array(
				'label' => 'person',
				'box'   => array( 0.3, 0.3, 0.7, 0.7 ),
				'score' => 0.8,
			),
			array(
				'label' => 'footer',
				'box'   => array( 0.0, 0.7, 1.0, 1.0 ),
				'score' => 0.7,
			),
		);

		$tool   = new WP_MCP_AI_Tool_Describe_Image_Layout();
		$result = $tool->execute(
			array(
				'boxes' => wp_json_encode( $boxes ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data    = $result;
		$objects = $data['objects'];

		$this->assertSame( 'top-right', $objects[0]['quadrant'] );
		$this->assertSame( 'center', $objects[1]['quadrant'] );
		$this->assertSame( 'bottom-center', $objects[2]['quadrant'] );

		$this->assertSame( 'small', $objects[0]['size'] );
		$this->assertSame( 'medium', $objects[1]['size'] );
		$this->assertSame( 'large', $objects[2]['size'] );

		$this->assertStringContainsString( 'logo occupies the top-right quadrant', $data['layout_prose'] );
	}

	/**
	 * Google normalizedVertices format must parse into boxes.
	 */
	public function test_parses_source_tool_result() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$source = array(
			'responses' => array(
				array(
					'localizedObjectAnnotations' => array(
						array(
							'name'         => 'Dog',
							'score'        => 0.91,
							'boundingPoly' => array(
								'normalizedVertices' => array(
									array(
										'x' => 0.1,
										'y' => 0.2,
									),
									array(
										'x' => 0.4,
										'y' => 0.2,
									),
									array(
										'x' => 0.4,
										'y' => 0.6,
									),
									array(
										'x' => 0.1,
										'y' => 0.6,
									),
								),
							),
						),
					),
				),
			),
		);

		$tool   = new WP_MCP_AI_Tool_Describe_Image_Layout();
		$result = $tool->execute(
			array(
				'source_tool_result' => wp_json_encode( $source ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( 1, $data['object_count'] );
		$this->assertSame( 'Dog', $data['objects'][0]['label'] );
		$this->assertSame( 'center-left', $data['objects'][0]['quadrant'] );
	}

	/**
	 * Malformed JSON must return a clear error.
	 */
	public function test_malformed_json_returns_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Describe_Image_Layout();
		$result = $tool->execute(
			array(
				'boxes' => '{not valid json',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_describe_image_layout_invalid_json', $result->get_error_code() );
	}

	/**
	 * Empty boxes must return a clear error.
	 */
	public function test_no_boxes_returns_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Describe_Image_Layout();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_describe_image_layout_no_boxes', $result->get_error_code() );
	}

	/**
	 * auto_detect must short-circuit without a configured key (no HTTP).
	 */
	public function test_auto_detect_requires_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Describe_Image_Layout();

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
				'auto_detect' => true,
				'image_url'   => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_missing_api_key', $result->get_error_code() );
		$this->assertFalse( $request_made );
	}

	/**
	 * auto_detect with a key must send an OBJECT_LOCALIZATION request.
	 */
	public function test_auto_detect_sends_localization_request() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key' => 'test-vision-key',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Describe_Image_Layout();

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
							'message' => 'Unauthorized',
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
				'auto_detect' => true,
				'image_url'   => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertSame( 'OBJECT_LOCALIZATION', $body['requests'][0]['features'][0]['type'] );
	}
}
