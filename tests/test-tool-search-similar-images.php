<?php
/**
 * Tests for the Search Similar Images tool (reverse-image web search).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Class WP_MCP_AI_Search_Similar_Images_Test
 *
 * Tests capability gating, credential gating, provider resolution, SSRF
 * blocking, SerpApi request shape, and response normalization.
 */
class WP_MCP_AI_Search_Similar_Images_Test extends WP_UnitTestCase {

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Allowlist example.com so URL-guard validation is DNS-independent.
	 *
	 * @return void
	 */
	private function allow_example_com() {
		add_filter(
			'wp_mcp_ai_http_allowed_host',
			static function ( $allowed, $host ) {
				if ( 'example.com' === $host ) {
					$allowed[] = 'example.com';
				}
				return $allowed;
			},
			10,
			2
		);
	}

	/**
	 * Subscribers must be blocked.
	 */
	public function test_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/product.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_search_similar_images_forbidden', $result->get_error_code() );
	}

	/**
	 * At least one input must be provided.
	 */
	public function test_requires_input() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_search_similar_images_missing_input', $result->get_error_code() );
	}

	/**
	 * Without any provider credentials the tool must refuse before any
	 * network activity.
	 */
	public function test_no_credentials_returns_error() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( WP_MCP_AI_Tool_Search_Similar_Images::has_credentials() );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/product.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_search_similar_images_no_credentials', $result->get_error_code() );
	}

	/**
	 * An explicitly selected provider without its key must return the
	 * missing-key error.
	 */
	public function test_missing_provider_key_returns_error() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_serpapi_api_key' => 'test-serpapi-key',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(
				'image_url' => 'https://example.com/product.jpg',
				'provider'  => 'bing',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_search_similar_images_missing_api_key', $result->get_error_code() );
	}

	/**
	 * The has_credentials() helper must reflect configured keys.
	 */
	public function test_has_credentials_detects_keys() {
		$this->assertFalse( WP_MCP_AI_Tool_Search_Similar_Images::has_credentials() );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_bing_visual_search_key' => 'test-bing-key',
			)
		);

		$this->assertTrue( WP_MCP_AI_Tool_Search_Similar_Images::has_credentials() );
	}

	/**
	 * SerpApi requires a URL — image_content alone must be rejected.
	 */
	public function test_serpapi_requires_url() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_serpapi_api_key'         => 'test-serpapi-key',
				'va_reverse_search_provider' => 'serpapi',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(
				'image_content' => base64_encode( 'fake-image-bytes' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_search_similar_images_serpapi_requires_url', $result->get_error_code() );
	}

	/**
	 * Blocked (private) image URLs must be rejected by the SSRF guard.
	 */
	public function test_blocked_url_is_rejected() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_serpapi_api_key'         => 'test-serpapi-key',
				'va_reverse_search_provider' => 'serpapi',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(
				'image_url' => 'http://127.0.0.1/secret.jpg',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_search_similar_images_blocked_url', $result->get_error_code() );
	}

	/**
	 * The SerpApi request must target the Google Lens engine and normalize
	 * the response into the common shape.
	 */
	public function test_serpapi_request_and_normalization() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_serpapi_api_key'         => 'test-serpapi-key',
				'va_reverse_search_provider' => 'serpapi',
			)
		);

		$this->allow_example_com();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$serpapi_response = array(
			'knowledge_graph' => array(
				'title' => 'Wireless Headphones',
				'type'  => 'Product',
			),
			'visual_matches'  => array(
				array(
					'position'  => 1,
					'title'     => 'Acme Wireless Headphones',
					'link'      => 'https://shop.example.com/headphones',
					'source'    => 'Acme Store',
					'thumbnail' => 'https://shop.example.com/thumb.jpg',
				),
				array(
					'position'  => 2,
					'title'     => 'Best headphones 2026',
					'link'      => 'https://reviews.example.com/best',
					'source'    => 'Reviews',
					'thumbnail' => 'https://reviews.example.com/thumb.jpg',
				),
			),
		);

		$captured_url = null;
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Filter stub signature.
		$http_stub = function ( $preempt, $args, $url ) use ( &$captured_url, $serpapi_response ) {
			$captured_url = $url;
			return array(
				'body'     => wp_json_encode( $serpapi_response ),
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
			);
		};
		// phpcs:enable

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Search_Similar_Images();
		$result = $tool->execute(
			array(
				'image_url'   => 'https://example.com/product.jpg',
				'max_results' => 5,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// The envelope trait flattens array data into the response.
		$data = $result;
		$this->assertSame( 'serpapi', $data['provider'] );
		$this->assertSame( 'Wireless Headphones — Product', $data['best_guess'] );
		$this->assertSame( 2, $data['match_count'] );
		$this->assertSame( 'Acme Wireless Headphones', $data['matches'][0]['title'] );
		$this->assertSame( 'https://shop.example.com/headphones', $data['matches'][0]['url'] );

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'engine=google_lens', $captured_url );
		$this->assertStringContainsString( 'serpapi.com', $captured_url );
	}
}
