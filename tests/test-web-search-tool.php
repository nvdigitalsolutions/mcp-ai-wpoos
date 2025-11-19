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
		$this->assertSame(
			'The web search results are not ready yet. Try again in a few seconds.',
			$result->get_error_message()
		);

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 202, $data['status'] );
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
		add_filter( 'wp_mcp_ai_web_search_rate_limit', function() {
			return 3; // Only 3 searches per minute.
		} );

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
}
