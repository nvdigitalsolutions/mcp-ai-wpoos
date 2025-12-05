<?php
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
	 * Verify that web search executes with automatic retry logic for HTTP 202 responses.
	 * When receiving HTTP 202 repeatedly, it should retry up to 13 times (14 total requests)
	 * with exponential backoff before returning pending error to orchestration layer.
	 */
	public function test_execute_retries_on_202_with_exponential_backoff() {
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
				'query' => 'test retry with exponential backoff',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$elapsed_time = microtime( true ) - $start_time;

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Should receive WP_Error with pending status after all retries exhausted
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_search_pending', $result->get_error_code() );

		// Should make 14 requests total (initial + 13 retries)
		$this->assertSame( 14, $request_count, 'Should make 14 HTTP requests (initial + 13 retries)' );

		// Should have taken approximately 300 seconds (5 minutes) based on retry sequence
		// Allow some tolerance for test execution overhead
		$this->assertGreaterThan( 295.0, $elapsed_time, 'Should have waited through retry sequence (~300 seconds)' );
		$this->assertLessThan( 310.0, $elapsed_time, 'Should not exceed expected wait time by much' );

		// Verify retry_after is passed through for orchestration layer
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( '5', $data['retry_after'] );
	}

	/**
	 * Verify that web search succeeds when HTTP 200 is returned after some retries.
	 * The tool should stop retrying once it receives a successful response.
	 */
	public function test_execute_succeeds_after_few_retries() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$request_count = 0;
		$start_time    = microtime( true );

		$http_stub = static function ( $preempt, $args, $url ) use ( &$request_count ) {
			++$request_count;
			
			// Return 202 for first 3 attempts, then 200 on 4th attempt
			if ( $request_count <= 3 ) {
				return array(
					'response' => array(
						'code' => 202,
					),
					'headers'  => array(
						'retry-after' => '2',
					),
					'body'     => '',
				);
			}
			
			// Return success on 4th attempt
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
				'query' => 'test successful retry',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$elapsed_time = microtime( true ) - $start_time;

		remove_filter( 'pre_http_request', $http_stub, 10 );

		// Should succeed with results
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertArrayHasKey( 'query', $result );
		$this->assertSame( 'test successful retry', $result['query'] );

		// Should have made exactly 4 requests (initial + 3 retries, then success)
		$this->assertSame( 4, $request_count, 'Should make 4 HTTP requests before succeeding' );

		// Should have taken approximately 2 + 2 + 2 = 6 seconds (using retry-after header)
		// Allow some tolerance for test execution overhead
		$this->assertGreaterThan( 5.5, $elapsed_time, 'Should have waited through partial retry sequence' );
		$this->assertLessThan( 8.0, $elapsed_time, 'Should not exceed expected wait time by much' );
	}

	/**
	 * Test that web_search tool includes a 'text' field in results for chat client visibility.
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
		$this->assertArrayHasKey( 'text', $result, 'Result should include text field' );
		$this->assertStringContainsString( 'Found 2 web search results', $result['text'] );
		$this->assertStringContainsString( 'test query', $result['text'] );
		$this->assertStringContainsString( 'Top result: First Result Title', $result['text'] );
	}

	/**
	 * Test that web_search tool includes text field even when no results found.
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
		$this->assertArrayHasKey( 'text', $result, 'Result should include text field' );
		$this->assertStringContainsString( 'Web search completed for "no results query"', $result['text'] );
		$this->assertStringContainsString( 'no results were found', $result['text'] );
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

		// Each result should only have title and URL, no snippets.
		foreach ( $sanitized['results'] as $result ) {
			$this->assertArrayHasKey( 'title', $result );
			$this->assertArrayHasKey( 'url', $result );
			$this->assertArrayNotHasKey( 'snippet', $result, 'Snippets should be removed to save tokens' );
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
}

