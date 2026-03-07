<?php
/**
 * Web Search Tool
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php';

/**
 * Tests for the web search tool.
 */
class WP_MCP_AI_Web_Search_Tool_Test extends WP_UnitTestCase {
	/**
	 * Ensure each test starts with a logged-out user and clean filters.
	 */
	public function set_up() {
		parent::set_up();
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
	}

	/**
	 * Clean up after each test run.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The tool requires a user with the "read" capability to perform searches.
	 */
	public function test_execute_requires_authenticated_user() {
		$tool   = new WP_MCP_AI_Tool_Web_Search();
		$result = $tool->execute(
			array(
				'query' => 'latest news',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * A query argument is mandatory.
	 */
	public function test_execute_requires_query_argument() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Web_Search();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_query', $result->get_error_code() );
	}

	/**
	 * Network failures should surface a helpful error.
	 */
	public function test_execute_returns_error_when_request_fails() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'site reliability best practices',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_failed', $result->get_error_code() );
		$this->assertSame( 'timeout', $result->get_error_data() );
	}

	/**
	 * Unexpected HTTP status codes should be surfaced to the caller.
	 */
	public function test_execute_returns_error_for_unexpected_http_status() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 500,
				),
				'body'     => '',
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'status codes',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_http_error', $result->get_error_code() );
		$this->assertStringContainsString( '500', $result->get_error_message() );
	}

	/**
	 * Invalid JSON responses should be surfaced as a decoding error.
	 */
	public function test_execute_returns_error_for_invalid_json_response() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => '{invalid json',
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'json decoding',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_bad_json', $result->get_error_code() );
	}

	/**
	 * A successful response should return sanitized and truncated results.
	 */
	public function test_execute_returns_sanitized_results_respecting_max_results() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'Heading'       => 'Sample Heading',
						'AbstractText'  => 'Abstract text with <strong>markup</strong>.',
						'AbstractURL'   => 'https://example.com/abstract',
						'RelatedTopics' => array(
							array(
								'Topics' => array(
									array(
										'FirstURL' => 'https://example.com/first',
										'Text'     => 'Result 1',
										'Result'   => '<a href="https://example.com/first">Result 1</a> - details',
									),
									array(
										'FirstURL' => 'https://example.com/second',
										'Text'     => 'Result 2',
										'Result'   => '<a href="https://example.com/second">Result 2</a>',
									),
								),
							),
							array(
								'FirstURL' => 'https://example.com/third',
								'Text'     => 'Result 3',
								'Result'   => '<a href="https://example.com/third">Result 3</a>',
							),
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query'       => '  curated topics  ',
				'max_results' => 2,
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'curated topics', $result['query'] );
		$this->assertCount( 2, $result['results'] );
		$this->assertArrayHasKey( 'provider', $result );
		$this->assertArrayHasKey( 'result_count', $result );
		$this->assertSame( 2, $result['result_count'] );

		$first = $result['results'][0];
		$this->assertSame( 'Sample Heading', $first['title'] );
		$this->assertSame( 'https://example.com/abstract', $first['url'] );
		$this->assertSame( 'Abstract text with markup.', $first['snippet'] );
		$this->assertSame( 'duckduckgo', $first['source'] );
		$this->assertSame( 'abstract', $first['type'] );

		$second = $result['results'][1];
		$this->assertSame( 'Result 1', $second['title'] );
		$this->assertSame( 'https://example.com/first', $second['url'] );
		$this->assertSame( 'Result 1 - details', $second['snippet'] );
		$this->assertSame( 'result', $second['type'] );
	}

	/**
	 * Empty datasets should return a helpful note instead of an error.
	 */
	public function test_execute_returns_note_when_no_results_found() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode( array() ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'obscure topic',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'obscure topic', $result['query'] );
		$this->assertSame( array(), $result['results'] );
		$this->assertArrayHasKey( 'note', $result );
		$this->assertArrayHasKey( 'provider', $result );
		$this->assertSame(
			'No web search results were found for this query.',
			$result['note']
		);
	}

	/**
	 * The tool should surface a helpful pending error when the remote service
	 * returns HTTP 202, signalling that the search is still being processed.
	 */
	public function test_execute_returns_pending_error_when_service_accepted_request() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 202,
				),
				'headers'  => array(
					'retry-after' => '7',
				),
				'body'     => '',
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'hurricane updates',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_pending', $result->get_error_code() );
		$this->assertStringContainsString(
			'temporarily processing',
			$result->get_error_message()
		);
		$this->assertStringContainsString(
			'alternative information sources',
			$result->get_error_message()
		);

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 202, $data['status'] );
		$this->assertTrue( $data['is_pending'] );
		$this->assertFalse( $data['should_wait'] );
		$this->assertSame( '7', $data['retry_after'] );
	}

	/**
	 * Results should include metadata for caching and loop prevention.
	 */
	public function test_execute_includes_metadata_in_response() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'Heading'      => 'Test Result',
						'AbstractText' => 'Test abstract text.',
						'AbstractURL'  => 'https://example.com/test',
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'test query',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'provider', $result );
		$this->assertSame( 'duckduckgo', $result['provider'] );
		$this->assertArrayHasKey( 'result_count', $result );
		$this->assertSame( 1, $result['result_count'] );
		$this->assertArrayHasKey( 'timestamp', $result );
		$this->assertIsInt( $result['timestamp'] );
		$this->assertArrayHasKey( 'cached', $result );
		$this->assertFalse( $result['cached'] );
	}

	/**
	 * Duplicate URLs should be removed from results.
	 */
	public function test_execute_deduplicates_results_by_url() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'RelatedTopics' => array(
							array(
								'FirstURL' => 'https://example.com/page1',
								'Text'     => 'Result 1',
								'Result'   => 'First occurrence',
							),
							array(
								'FirstURL' => 'https://example.com/page1/', // Duplicate with trailing slash.
								'Text'     => 'Result 1 Duplicate',
								'Result'   => 'Second occurrence',
							),
							array(
								'FirstURL' => 'https://example.com/page2',
								'Text'     => 'Result 2',
								'Result'   => 'Unique result',
							),
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query'       => 'test deduplication',
				'max_results' => 10,
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		// Should only have 2 results (duplicate removed).
		$this->assertCount( 2, $result['results'] );
		$this->assertSame( 'https://example.com/page1', $result['results'][0]['url'] );
		$this->assertSame( 'https://example.com/page2', $result['results'][1]['url'] );
	}

	/**
	 * Rate limiting should prevent excessive searches.
	 */
	public function test_execute_enforces_rate_limiting() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode( array() ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Set a low rate limit for testing.
		add_filter(
			'wp_mcp_ai_web_search_rate_limit',
			function () {
				return 3; // Only 3 searches per minute.
			}
		);

		// First 3 searches should succeed.
		for ( $i = 1; $i <= 3; $i++ ) {
			$result = $tool->execute(
				array(
					'query' => 'test query ' . $i,
				),
				array(
					'user_id' => $user_id,
				)
			);

			$this->assertIsArray( $result, "Search #{$i} should succeed" );
		}

		// Fourth search should fail with rate limit error.
		$result = $tool->execute(
			array(
				'query' => 'test query 4',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
		$this->assertStringContainsString( 'rate limit exceeded', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Brave Search should also handle HTTP 202 pending responses correctly.
	 */
	public function test_brave_search_returns_pending_error_on_202_status() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Mock settings to enable Brave Search.
		add_filter(
			'option_wp_mcp_ai_settings',
			function () {
				return array(
					'web_search_provider'  => 'brave',
					'brave_search_api_key' => 'test_api_key_123',
				);
			}
		);

		$http_stub = static function ( $preempt, $args, $url ) {
			// Verify this is a Brave Search request.
			if ( strpos( $url, 'api.search.brave.com' ) !== false ) {
				return array(
					'response' => array(
						'code' => 202,
					),
					'headers'  => array(
						'retry-after' => '5',
					),
					'body'     => '',
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'breaking news',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_all_filters( 'option_wp_mcp_ai_settings' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_pending', $result->get_error_code() );
		$this->assertStringContainsString(
			'temporarily processing',
			$result->get_error_message()
		);

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 202, $data['status'] );
		$this->assertTrue( $data['is_pending'] );
		$this->assertFalse( $data['should_wait'] );
		$this->assertSame( '5', $data['retry_after'] );
	}

	/**
	 * Successful search results should be cached to reduce API calls.
	 */
	public function test_execute_caches_successful_results() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$call_count = 0;

		$http_stub = static function ( $preempt, $args, $url ) use ( &$call_count ) {
			++$call_count;
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'Heading'      => 'Test Result',
						'AbstractText' => 'Test abstract text.',
						'AbstractURL'  => 'https://example.com/test',
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Ensure caching is enabled for this test.
		add_filter( 'wp_mcp_ai_cache_enabled', '__return_true' );

		// First call should hit the API.
		$result1 = $tool->execute(
			array(
				'query' => 'test caching',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertIsArray( $result1 );
		$this->assertFalse( $result1['cached'] );
		$this->assertSame( 1, $call_count );

		// Second call with same query should use cache.
		$result2 = $tool->execute(
			array(
				'query' => 'test caching',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_filter( 'wp_mcp_ai_cache_enabled', '__return_true' );

		$this->assertIsArray( $result2 );
		$this->assertTrue( $result2['cached'] );
		// Call count should still be 1 (cached result, no second API call).
		$this->assertSame( 1, $call_count );
	}

	/**
	 * Verify that web search returns immediately on HTTP 202 without blocking retries.
	 * The tool should make a single request and return pending status to orchestration layer.
	 */
	public function test_execute_returns_pending_immediately_on_202() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$request_count = 0;
		$start_time    = microtime( true );

		$http_stub = static function ( $preempt, $args, $url ) use ( &$request_count ) {
			++$request_count;
			return array(
				'response' => array(
					'code' => 202,
				),
				'headers'  => array(
					'retry-after' => '5',
				),
				'body'     => '',
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'test immediate return on 202',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$elapsed_time = microtime( true ) - $start_time;

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Should receive WP_Error with pending status immediately.
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_pending', $result->get_error_code() );

		// Should make only 1 request (no retries).
		$this->assertSame( 1, $request_count, 'Should make only 1 HTTP request without retries' );

		// Should return quickly (< 15 seconds for network request + processing).
		$this->assertLessThan( 15.0, $elapsed_time, 'Should return immediately without blocking retries' );

		// Verify retry_after is passed through for orchestration layer.
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( '5', $data['retry_after'] );
	}

	/**
	 * Verify that web search succeeds immediately on HTTP 200 response.
	 * The tool should make a single request and return results when successful.
	 */
	public function test_execute_succeeds_immediately_on_200() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$request_count = 0;
		$start_time    = microtime( true );

		$http_stub = static function ( $preempt, $args, $url ) use ( &$request_count ) {
			++$request_count;

			// Return success immediately.
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'Heading'      => 'Test Result',
						'AbstractText' => 'Test abstract text.',
						'AbstractURL'  => 'https://example.com/test',
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'test successful search',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$elapsed_time = microtime( true ) - $start_time;

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Should succeed with results.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'query', $result );
		$this->assertSame( 'test successful search', $result['query'] );

		// Should have made exactly 1 request.
		$this->assertSame( 1, $request_count, 'Should make only 1 HTTP request' );

		// Should return quickly (< 15 seconds for network request + processing).
		$this->assertLessThan( 15.0, $elapsed_time, 'Should return immediately on successful response' );
	}

	/**
	 * Test that web_search tool includes a 'system_message' field in results for chat client visibility.
	 */
	public function test_execute_includes_text_field_in_results() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'RelatedTopics' => array(
							array(
								'FirstURL' => 'https://example.com/result1',
								'Text'     => 'First Result Title',
								'Result'   => 'First result snippet',
							),
							array(
								'FirstURL' => 'https://example.com/result2',
								'Text'     => 'Second Result Title',
								'Result'   => 'Second result snippet',
							),
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'test query',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'system_message', $result, 'Result should include system_message field' );
		$this->assertStringContainsString( 'Found 2 web search results', $result['system_message'] );
		$this->assertStringContainsString( 'test query', $result['system_message'] );
		$this->assertStringContainsString( 'Top result: First Result Title', $result['system_message'] );
	}

	/**
	 * Test that web_search tool includes system_message field even when no results found.
	 */
	public function test_execute_includes_text_field_when_no_results() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode( array() ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query' => 'no results query',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'system_message', $result, 'Result should include system_message field' );
		$this->assertStringContainsString( 'Web search completed for "no results query"', $result['system_message'] );
		$this->assertStringContainsString( 'no results were found', $result['system_message'] );
	}

	/**
	 * Test that sanitize_for_llm reduces token usage by condensing results.
	 */
	public function test_sanitize_for_llm_condenses_results() {
		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Simulate a full search result with 5 results including snippets.
		$full_result = array(
			'query'        => 'test query',
			'result_count' => 5,
			'text'         => 'Found 5 web search results for "test query"',
			'provider'     => 'duckduckgo',
			'cached'       => false,
			'timestamp'    => time(),
			'results'      => array(
				array(
					'title'   => 'First Result',
					'url'     => 'https://example.com/1',
					'snippet' => 'This is a long snippet with lots of text that would consume tokens unnecessarily for the LLM context.',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					'title'   => 'Second Result',
					'url'     => 'https://example.com/2',
					'snippet' => 'Another long snippet with detailed information that is not needed by the LLM.',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					'title'   => 'Third Result',
					'url'     => 'https://example.com/3',
					'snippet' => 'Yet another snippet with more content.',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					'title'   => 'Fourth Result',
					'url'     => 'https://example.com/4',
					'snippet' => 'Fourth snippet.',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					'title'   => 'Fifth Result',
					'url'     => 'https://example.com/5',
					'snippet' => 'Fifth snippet.',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
			),
		);

		$sanitized = $tool->sanitize_for_llm( $full_result );

		// Should preserve essential fields.
		$this->assertArrayHasKey( 'query', $sanitized );
		$this->assertArrayHasKey( 'result_count', $sanitized );
		$this->assertArrayHasKey( 'text', $sanitized );
		$this->assertArrayHasKey( 'provider', $sanitized );

		// Should have condensed results array.
		$this->assertArrayHasKey( 'results', $sanitized );

		// Should only include top 3 results (not all 5).
		$this->assertCount( 3, $sanitized['results'], 'Should condense to top 3 results for LLM' );

		// Each result should have title, URL, and a trimmed snippet for LLM grounding.
		foreach ( $sanitized['results'] as $result ) {
			$this->assertArrayHasKey( 'title', $result );
			$this->assertArrayHasKey( 'url', $result );
			$this->assertArrayHasKey( 'snippet', $result, 'Snippets should be included for LLM grounding' );
			$this->assertArrayNotHasKey( 'source', $result );
			$this->assertArrayNotHasKey( 'type', $result );
		}

		// Should not include timestamp (not essential for LLM).
		$this->assertArrayNotHasKey( 'timestamp', $sanitized );
	}

	/**
	 * Test that validate_and_normalize_result ensures data integrity.
	 */
	public function test_validate_and_normalize_result_handles_invalid_utf8() {
		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Use reflection to call the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'validate_and_normalize_result' );
		$method->setAccessible( true );

		// Simulate a result with potentially problematic UTF-8 sequences.
		$raw_result = array(
			'query'        => 'test query',
			'provider'     => 'duckduckgo',
			'cached'       => false,
			'result_count' => 2,
			'results'      => array(
				array(
					'title'   => 'Normal Title',
					'url'     => 'https://example.com/1',
					'snippet' => 'Normal snippet text',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					'title'   => 'Title with special chars: café résumé',
					'url'     => 'https://example.com/2',
					'snippet' => 'Snippet with UTF-8: 中文 日本語 한국어',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
			),
		);

		$normalized = $method->invoke( $tool, $raw_result, 'test query', 'duckduckgo' );

		// Should preserve valid structure.
		$this->assertIsArray( $normalized );
		$this->assertArrayHasKey( 'query', $normalized );
		$this->assertArrayHasKey( 'results', $normalized );
		$this->assertArrayHasKey( 'result_count', $normalized );

		// Should have 2 valid results.
		$this->assertCount( 2, $normalized['results'] );

		// The entire result should be JSON-encodable.
		$encoded = wp_json_encode( $normalized );
		$this->assertNotFalse( $encoded, 'Normalized result should be JSON-encodable' );

		// Decoded result should match the structure.
		$decoded = json_decode( $encoded, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'test query', $decoded['query'] );
	}

	/**
	 * Test that validate_and_normalize_result filters out invalid items.
	 */
	public function test_validate_and_normalize_result_filters_invalid_items() {
		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Use reflection to call the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'validate_and_normalize_result' );
		$method->setAccessible( true );

		// Simulate a result with invalid items (no title, no URL).
		$raw_result = array(
			'query'        => 'test query',
			'provider'     => 'duckduckgo',
			'cached'       => false,
			'result_count' => 3,
			'results'      => array(
				array(
					'title'   => 'Valid Result',
					'url'     => 'https://example.com/valid',
					'snippet' => 'Valid snippet',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					// Invalid: no title, no URL.
					'snippet' => 'Orphan snippet',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
				array(
					// Valid: has URL even without title.
					'url'     => 'https://example.com/no-title',
					'snippet' => 'No title snippet',
					'source'  => 'duckduckgo',
					'type'    => 'result',
				),
			),
		);

		$normalized = $method->invoke( $tool, $raw_result, 'test query', 'duckduckgo' );

		// Should only have 2 valid results (invalid one filtered out).
		$this->assertCount( 2, $normalized['results'], 'Invalid items should be filtered out' );
		$this->assertSame( 2, $normalized['result_count'], 'Result count should reflect validated items' );

		// First result should be the valid one.
		$this->assertSame( 'Valid Result', $normalized['results'][0]['title'] );

		// Second result should be the one with URL but no title.
		$this->assertSame( 'https://example.com/no-title', $normalized['results'][1]['url'] );
	}

	/**
	 * Test that wp_mcp_ai_web_search_completed action fires outside of agentic loop.
	 *
	 * @since 1.0.0
	 */
	public function test_action_fires_outside_agentic_loop() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Mock HTTP request.
		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'AbstractText' => 'Test abstract',
						'AbstractURL'  => 'https://example.com',
						'Heading'      => 'Test heading',
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Track if action fired.
		$action_fired = false;
		$action_data  = array();

		$action_callback = function ( $result, $arguments, $context ) use ( &$action_fired, &$action_data ) {
			$action_fired = true;
			$action_data  = array(
				'result'    => $result,
				'arguments' => $arguments,
				'context'   => $context,
			);
		};

		add_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10, 3 );

		// Execute without agentic_loop flag (standalone API call).
		$result = $tool->execute(
			array(
				'query' => 'test query',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10 );
		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertTrue( $action_fired, 'Action should fire outside of agentic loop' );
		$this->assertIsArray( $action_data['result'] );
		$this->assertSame( 'test query', $action_data['arguments']['query'] );
	}

	/**
	 * Test that wp_mcp_ai_web_search_completed action does NOT fire inside agentic loop.
	 *
	 * @since 1.0.0
	 */
	public function test_action_does_not_fire_inside_agentic_loop() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Mock HTTP request.
		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'AbstractText' => 'Test abstract',
						'AbstractURL'  => 'https://example.com',
						'Heading'      => 'Test heading',
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Track if action fired.
		$action_fired = false;

		$action_callback = function ( $result, $arguments, $context ) use ( &$action_fired ) {
			$action_fired = true;
		};

		add_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10, 3 );

		// Execute WITH agentic_loop flag (chat flow).
		$result = $tool->execute(
			array(
				'query' => 'test query',
			),
			array(
				'user_id'      => $user_id,
				'agentic_loop' => true,
			)
		);

		remove_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10 );
		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertFalse( $action_fired, 'Action should NOT fire inside agentic loop' );
		$this->assertIsArray( $result, 'Tool should still return results' );
	}

	/**
	 * Test that the filter can override action firing behavior.
	 *
	 * @since 1.0.0
	 */
	public function test_filter_can_override_action_firing() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Mock HTTP request.
		$http_stub = static function ( $preempt, $args, $url ) {
			return array(
				'response' => array(
					'code' => 200,
				),
				'body'     => wp_json_encode(
					array(
						'AbstractText' => 'Test abstract',
						'AbstractURL'  => 'https://example.com',
						'Heading'      => 'Test heading',
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Override filter to force action to fire even in agentic loop.
		add_filter(
			'wp_mcp_ai_web_search_should_fire_completed_action',
			function ( $should_fire, $result, $arguments, $context, $is_agentic_loop ) {
				// Always fire for testing purposes.
				return true;
			},
			10,
			5
		);

		// Track if action fired.
		$action_fired = false;

		$action_callback = function ( $result, $arguments, $context ) use ( &$action_fired ) {
			$action_fired = true;
		};

		add_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10, 3 );

		// Execute WITH agentic_loop flag but filter overrides.
		$result = $tool->execute(
			array(
				'query' => 'test query',
			),
			array(
				'user_id'      => $user_id,
				'agentic_loop' => true,
			)
		);

		remove_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10 );
		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_all_filters( 'wp_mcp_ai_web_search_should_fire_completed_action' );

		$this->assertTrue( $action_fired, 'Filter should allow overriding default behavior' );
		$this->assertIsArray( $result );
	}

	/**
	 * Test that action does not fire for WP_Error results.
	 *
	 * @since 1.0.0
	 */
	public function test_action_does_not_fire_for_errors() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		// Mock HTTP request to fail.
		$http_stub = static function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Network error' );
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// Track if action fired.
		$action_fired = false;

		$action_callback = function ( $result, $arguments, $context ) use ( &$action_fired ) {
			$action_fired = true;
		};

		add_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10, 3 );

		// Execute without agentic_loop flag.
		$result = $tool->execute(
			array(
				'query' => 'test query',
			),
			array(
				'user_id' => $user_id,
			)
		);

		remove_action( 'wp_mcp_ai_web_search_completed', $action_callback, 10 );
		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertFalse( $action_fired, 'Action should NOT fire for error results' );
		$this->assertWPError( $result );
	}

	/**
	 * Brave Search should include country, search_lang, and freshness in the request URL.
	 */
	public function test_brave_search_passes_country_language_freshness_params() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		add_filter(
			'option_wp_mcp_ai_settings',
			function () {
				return array(
					'web_search_provider'  => 'brave',
					'brave_search_api_key' => 'test_brave_key',
				);
			}
		);

		$captured_url = '';
		$http_stub    = static function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'web' => array( 'results' => array() ) ) ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'query'     => 'climate news',
				'country'   => 'DE',
				'language'  => 'de',
				'freshness' => 'pw',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_all_filters( 'option_wp_mcp_ai_settings' );

		$this->assertStringContainsString( 'api.search.brave.com', $captured_url );
		$this->assertStringContainsString( 'country=DE', $captured_url );
		$this->assertStringContainsString( 'search_lang=de', $captured_url );
		$this->assertStringContainsString( 'freshness=pw', $captured_url );
		$this->assertStringContainsString( 'extra_snippets=1', $captured_url );
	}

	/**
	 * DuckDuckGo should append the kl region parameter when country + language are provided.
	 */
	public function test_duckduckgo_passes_kl_region_param() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$captured_url = '';
		$http_stub    = static function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'query'    => 'news',
				'country'  => 'GB',
				'language' => 'en',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertStringContainsString( 'api.duckduckgo.com', $captured_url );
		$this->assertStringContainsString( 'kl=gb-en', $captured_url );
	}

	/**
	 * DuckDuckGo should append the kl region parameter when only language is provided (no country).
	 */
	public function test_duckduckgo_passes_kl_language_only_param() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$captured_url = '';
		$http_stub    = static function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array(
				'query'    => 'news',
				'language' => 'fr',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertStringContainsString( 'api.duckduckgo.com', $captured_url );
		$this->assertStringContainsString( 'kl=fr', $captured_url );
	}

	/**
	 * Invalid country codes should be silently ignored rather than forwarded to the API.
	 */
	public function test_invalid_country_code_is_rejected() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$captured_url = '';
		$http_stub    = static function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array() ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		// 'XX123' is not a valid ISO 3166-1 alpha-2 code.
		$tool->execute(
			array(
				'query'   => 'test',
				'country' => 'XX123',
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// The invalid country should not appear in the URL.
		$this->assertStringNotContainsString( 'country=XX123', $captured_url );
	}

	/**
	 * Tavily provider returns structured results with content snippets and published dates.
	 */
	public function test_tavily_search_returns_structured_results() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		add_filter(
			'option_wp_mcp_ai_settings',
			function () {
				return array(
					'web_search_provider' => 'tavily',
					'tavily_api_key'      => 'tvly-test-key-12345',
				);
			}
		);

		$http_stub = static function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'api.tavily.com' ) !== false ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'query'   => 'AI trends 2025',
							'results' => array(
								array(
									'title'          => 'AI Trends Report 2025',
									'url'            => 'https://example.com/ai-trends',
									'content'        => 'Artificial intelligence is transforming industries in 2025.',
									'score'          => 0.92,
									'published_date' => '2025-01-15',
								),
								array(
									'title'   => 'Machine Learning Advances',
									'url'     => 'https://example.com/ml-advances',
									'content' => 'New breakthroughs in large language models.',
									'score'   => 0.85,
								),
							),
						)
					),
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$result = $tool->execute(
			array(
				'query'       => 'AI trends 2025',
				'max_results' => 5,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_all_filters( 'option_wp_mcp_ai_settings' );

		$this->assertIsArray( $result );
		$this->assertSame( 'AI trends 2025', $result['query'] );
		$this->assertSame( 'tavily', $result['provider'] );
		$this->assertCount( 2, $result['results'] );
		$this->assertSame( 2, $result['result_count'] );

		$first = $result['results'][0];
		$this->assertSame( 'AI Trends Report 2025', $first['title'] );
		$this->assertSame( 'https://example.com/ai-trends', $first['url'] );
		$this->assertSame( 'Artificial intelligence is transforming industries in 2025.', $first['snippet'] );
		$this->assertSame( 'tavily', $first['source'] );
		$this->assertSame( '2025-01-15', $first['published_date'] );

		// Second result has no published_date — key should not be set.
		$second = $result['results'][1];
		$this->assertArrayNotHasKey( 'published_date', $second );
	}

	/**
	 * Tavily provider should surface a helpful error when no API key is configured.
	 */
	public function test_tavily_search_requires_api_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		add_filter(
			'option_wp_mcp_ai_settings',
			function () {
				return array(
					'web_search_provider' => 'tavily',
					'tavily_api_key'      => '',
				);
			}
		);

		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'option_wp_mcp_ai_settings' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Tavily sends a POST request with a JSON body including the query.
	 */
	public function test_tavily_search_uses_post_with_json_body() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		add_filter(
			'option_wp_mcp_ai_settings',
			function () {
				return array(
					'web_search_provider' => 'tavily',
					'tavily_api_key'      => 'tvly-test',
				);
			}
		);

		$captured_args = array();
		$http_stub     = static function ( $preempt, $args, $url ) use ( &$captured_args ) {
			$captured_args = $args;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'results' => array() ) ),
			);
		};

		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool->execute(
			array( 'query' => 'openai news' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );
		remove_all_filters( 'option_wp_mcp_ai_settings' );

		$this->assertSame( 'POST', $captured_args['method'] );

		$body = json_decode( $captured_args['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'openai news', $body['query'] );
		$this->assertArrayHasKey( 'max_results', $body );
		$this->assertArrayHasKey( 'search_depth', $body );

		// Authorization Bearer header should be set.
		$this->assertStringContainsString( 'Bearer tvly-test', $captured_args['headers']['Authorization'] );
	}
}
