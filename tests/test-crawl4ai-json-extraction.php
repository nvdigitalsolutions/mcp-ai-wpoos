<?php
/**
 * Tests for Crawl4AI tool JSON extraction and display handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that crawl4ai tool properly extracts and displays JSON results.
 */
class WP_MCP_AI_Crawl4AI_JSON_Extraction_Test extends WP_UnitTestCase {

	/**
	 * Reset the current user between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that crawl4ai results with proper structure are not truncated by default.
	 */
	public function test_crawl4ai_result_token_limit_increased() {
		$tool     = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$response = array(
			'status'  => 'completed',
			'task_id' => 'test-123',
			'results' => array(
				array(
					'url'      => 'https://example.com',
					'markdown' => str_repeat( 'Test content. ', 10000 ), // 140,000 chars (14 chars * 10000).
				),
			),
		);

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enforce_result_size_limits' );
		$method->setAccessible( true );

		$limited = $method->invoke( $tool, $response );

		// Should not be truncated with new 100k token limit (400k chars).
		$this->assertFalse( isset( $limited['metadata']['truncated'] ) );
	}

	/**
	 * Test that very large crawl results are still truncated when exceeding limits.
	 */
	public function test_crawl4ai_result_truncation_for_extremely_large_content() {
		$tool     = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$response = array(
			'status'  => 'completed',
			'task_id' => 'test-456',
			'results' => array(
				array(
					'url'      => 'https://example.com',
					'markdown' => str_repeat( 'Large content. ', 50000 ), // 750,000 chars (15 chars * 50000).
				),
			),
		);

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enforce_result_size_limits' );
		$method->setAccessible( true );

		$limited = $method->invoke( $tool, $response );

		// Should be truncated when exceeding 100k token limit.
		$this->assertTrue( isset( $limited['metadata']['truncated'] ) );
		$this->assertSame( true, $limited['metadata']['truncated'] );
		$this->assertSame( 100000, $limited['metadata']['approximate_token_limit'] );
	}

	/**
	 * Test that crawl4ai results are properly structured for JSON extraction.
	 */
	public function test_local_crawl_returns_proper_json_structure() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

		// Mock HTTP response.
		$callback = function ( $pre, $args, $url ) {
			return array(
				'body'     => '<html><head><title>Test Page</title></head><body><h1>Hello World</h1><p>Test content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'url' => 'https://example.com',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertIsArray( $result['results'] );
		$this->assertCount( 1, $result['results'] );

		$crawl_result = $result['results'][0];
		$this->assertArrayHasKey( 'url', $crawl_result );
		$this->assertArrayHasKey( 'status_code', $crawl_result );
		$this->assertArrayHasKey( 'markdown', $crawl_result );
		$this->assertArrayHasKey( 'text', $crawl_result );
		$this->assertSame( 'https://example.com', $crawl_result['url'] );
		$this->assertSame( 200, $crawl_result['status_code'] );
		$this->assertNotEmpty( $crawl_result['markdown'] );
		$this->assertNotEmpty( $crawl_result['text'] );
	}

	/**
	 * Test that crawl4ai handles errors gracefully without breaking.
	 */
	public function test_local_crawl_handles_errors_without_breaking() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

		// Mock HTTP error.
		$callback = function ( $pre, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection timeout' );
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'url' => 'https://example.com',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_crawl4ai_local_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Connection timeout', $result->get_error_message() );
	}

	/**
	 * Test that multiple URLs are processed correctly.
	 */
	public function test_local_crawl_handles_multiple_urls() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

		$request_count = 0;
		$callback      = function ( $pre, $args, $url ) use ( &$request_count ) {
			++$request_count;
			return array(
				'body'     => '<html><body><h1>Page ' . $request_count . '</h1></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html' ),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array(
					'https://example.com/page1',
					'https://example.com/page2',
					'https://example.com/page3',
				),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertIsArray( $result['results'] );
		$this->assertCount( 3, $result['results'] );
		$this->assertSame( 3, $request_count );
	}

	/**
	 * Test that partial failures are handled (some URLs succeed, some fail).
	 */
	public function test_local_crawl_handles_partial_failures() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

		$urls_seen = array();
		$callback  = function ( $pre, $args, $url ) use ( &$urls_seen ) {
			$urls_seen[] = $url;

			// Make page2 fail.
			if ( strpos( $url, 'page2' ) !== false ) {
				return new WP_Error( 'http_request_failed', 'Page 2 not found' );
			}

			return array(
				'body'     => '<html><body><p>Success</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html' ),
			);
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array(
					'https://example.com/page1',
					'https://example.com/page2',
					'https://example.com/page3',
				),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback );

		// Should succeed with partial results.
		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertIsArray( $result['results'] );
		$this->assertCount( 2, $result['results'] ); // Only 2 succeeded.

		// Should have error metadata.
		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertArrayHasKey( 'errors', $result['metadata'] );
		$this->assertArrayHasKey( 'https://example.com/page2', $result['metadata']['errors'] );
		$this->assertStringContainsString( 'Page 2 not found', $result['metadata']['errors']['https://example.com/page2'] );
	}

	/**
	 * Test that the token limit filter works correctly.
	 */
	public function test_crawl4ai_token_limit_filter() {
		$tool     = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$response = array(
			'status'  => 'completed',
			'task_id' => 'test-filter',
			'results' => array(
				array(
					'url'      => 'https://example.com',
					'markdown' => str_repeat( 'Content. ', 5000 ), // ~45,000 chars.
				),
			),
		);

		// Set a custom lower limit via filter.
		$filter = function () {
			return 10000; // 10k tokens = 40k chars.
		};
		add_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', $filter );

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'enforce_result_size_limits' );
		$method->setAccessible( true );

		$limited = $method->invoke( $tool, $response );

		remove_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', $filter );

		// Should be truncated with custom limit.
		$this->assertTrue( isset( $limited['metadata']['truncated'] ) );
		$this->assertSame( 10000, $limited['metadata']['approximate_token_limit'] );
	}
}
