<?php
/**
 * Tests for Vision API tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Class WP_MCP_AI_Vision_Tools_Test
 *
 * Tests the vision tools implementation including product search.
 */
class WP_MCP_AI_Vision_Tools_Test extends WP_UnitTestCase {

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper method to create a Google authentication error response.
	 *
	 * @return array Mock HTTP response with authentication error.
	 */
	private function get_auth_error_response() {
		return array(
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 401,
						'message' => 'Request is missing required authentication credential. Expected OAuth 2 access token, login cookie or other valid authentication credential.',
						'status'  => 'UNAUTHENTICATED',
					),
				)
			),
			'response' => array( 'code' => 401 ),
			'headers'  => array(),
		);
	}

	/**
	 * Product Search tool should block users lacking the required capability.
	 */
	public function test_product_search_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Vision_Product_Search();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/product.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_forbidden', $result->get_error_code() );
	}

	/**
	 * Object Localization tool should block users lacking the required capability.
	 */
	public function test_object_localization_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Vision_Object_Localization();
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
	 * Product Search should require image input.
	 */
	public function test_product_search_requires_image() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Vision_Product_Search();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_missing_image', $result->get_error_code() );
	}

	/**
	 * Object Localization should require image input.
	 */
	public function test_object_localization_requires_image() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Vision_Object_Localization();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_missing_image', $result->get_error_code() );
	}

	/**
	 * Product Search should fail with API error when no authentication is provided.
	 */
	public function test_product_search_fails_without_auth() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Vision_Product_Search();

		$http_stub = function ( $preempt, $args, $url ) {
			return $this->get_auth_error_response();
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'image_url'   => 'https://example.com/product.jpg',
				'max_results' => 5,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_api_error', $result->get_error_code() );
		$this->assertStringContainsString( 'authentication', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * Object Localization should fail with API error when no authentication is provided.
	 */
	public function test_object_localization_fails_without_auth() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Vision_Object_Localization();

		$http_stub = function ( $preempt, $args, $url ) {
			// Simulate Google API authentication error.
			return array(
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 401,
							'message' => 'Request is missing required authentication credential. Expected OAuth 2 access token, login cookie or other valid authentication credential.',
							'status'  => 'UNAUTHENTICATED',
						),
					)
				),
				'response' => array( 'code' => 401 ),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'image_url'   => 'https://example.com/scene.jpg',
				'max_results' => 10,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_vision_api_error', $result->get_error_code() );
		$this->assertStringContainsString( 'authentication', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * Product Search should make unauthenticated requests.
	 */
	public function test_product_search_sends_no_auth_header() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Vision_Product_Search();

		$captured_request = null;
		$http_stub        = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

			// Simulate authentication error.
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

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'image_url' => 'https://example.com/product.jpg',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'vision.googleapis.com', $captured_request['url'] );

		// Verify NO Authorization header is present.
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayNotHasKey( 'Authorization', $captured_request['args']['headers'] );

		// Verify request body is properly formatted.
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'requests', $body );
		$this->assertCount( 1, $body['requests'] );
		$this->assertSame( 'PRODUCT_SEARCH', $body['requests'][0]['features'][0]['type'] );
	}

	/**
	 * Object Localization should make unauthenticated requests.
	 */
	public function test_object_localization_sends_no_auth_header() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Vision_Object_Localization();

		$captured_request = null;
		$http_stub        = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'url'  => $url,
				'args' => $args,
			);

			// Simulate authentication error.
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

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'image_url' => 'https://example.com/scene.jpg',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertNotNull( $captured_request );
		$this->assertStringContainsString( 'vision.googleapis.com', $captured_request['url'] );

		// Verify NO Authorization header is present.
		$this->assertArrayHasKey( 'headers', $captured_request['args'] );
		$this->assertArrayNotHasKey( 'Authorization', $captured_request['args']['headers'] );

		// Verify request body is properly formatted.
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertIsArray( $body );
		$this->assertArrayHasKey( 'requests', $body );
		$this->assertCount( 1, $body['requests'] );
		$this->assertSame( 'OBJECT_LOCALIZATION', $body['requests'][0]['features'][0]['type'] );
	}

	/**
	 * Product Search should handle base64 image content.
	 */
	public function test_product_search_with_image_content() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Vision_Product_Search();

		$captured_request = null;
		$http_stub        = function ( $preempt, $args, $url ) use ( &$captured_request ) {
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

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'image_content' => 'base64encodedimage',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'content', $body['requests'][0]['image'] );
		$this->assertSame( 'base64encodedimage', $body['requests'][0]['image']['content'] );
	}
}
