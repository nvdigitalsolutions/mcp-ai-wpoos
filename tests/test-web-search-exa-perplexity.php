<?php
/**
 * Tests for Exa AI and Perplexity web search providers.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php';

/**
 * Tests for the Exa AI and Perplexity Sonar search providers added to
 * WP_MCP_AI_Tool_Web_Search.
 */
class WP_MCP_AI_Web_Search_Exa_Perplexity_Test extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/**
	 * Set up an authenticated user and clean settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		remove_all_filters( 'pre_http_request' );

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Override the web_search_provider setting and optionally an API key.
	 *
	 * @param string $provider   Provider slug ('exa' or 'perplexity').
	 * @param string $api_key    API key value.
	 * @param string $key_option Setting key for the API key.
	 */
	private function set_provider( $provider, $api_key = 'test-api-key', $key_option = '' ) {
		$settings                       = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['web_search_provider'] = $provider;
		if ( '' !== $key_option ) {
			$settings[ $key_option ] = $api_key;
		}
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	// -------------------------------------------------------------------------
	// Exa AI
	// -------------------------------------------------------------------------

	/**
	 * Exa provider must return an error when no API key is configured.
	 */
	public function test_exa_requires_api_key() {
		$this->set_provider( 'exa', '', 'exa_api_key' );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Should not be called.
				$this->fail( 'HTTP request was made without API key' );
				return $preempt;
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Web_Search();
		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_exa_api_key', $result->get_error_code() );
	}

	/**
	 * Exa provider must POST to api.exa.ai/search.
	 */
	public function test_exa_calls_correct_endpoint() {
		$this->set_provider( 'exa', 'exa-test-123', 'exa_api_key' );

		$url_captured = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$url_captured ) {
				$url_captured = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'results' => array(
								array(
									'title' => 'Test Result',
									'url'   => 'https://example.com',
									'text'  => 'Test snippet for Exa result.',
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Web_Search();
		$tool->execute(
			array( 'query' => 'AI search test' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotNull( $url_captured );
		$this->assertStringContainsString( 'exa.ai', $url_captured );
	}

	/**
	 * Exa provider must send the API key in the x-api-key header.
	 */
	public function test_exa_sends_api_key_header() {
		$this->set_provider( 'exa', 'exa-secret-key', 'exa_api_key' );

		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'results' => array() ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Web_Search();
		$tool->execute(
			array( 'query' => 'header test' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'headers', $captured_args );
		$this->assertArrayHasKey( 'x-api-key', $captured_args['headers'] );
		$this->assertSame( 'exa-secret-key', $captured_args['headers']['x-api-key'] );
	}

	/**
	 * Exa provider must return a normalized result array on success.
	 */
	public function test_exa_returns_normalized_results() {
		$this->set_provider( 'exa', 'exa-test-key', 'exa_api_key' );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'results' => array(
								array(
									'title' => 'AI Article',
									'url'   => 'https://ai-article.com/post',
									'text'  => 'This is a snippet about AI.',
								),
								array(
									'title' => 'Another Article',
									'url'   => 'https://another.com/article',
									'text'  => 'More content here.',
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Web_Search();
		$result = $tool->execute(
			array( 'query' => 'AI test' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
		$this->assertCount( 2, $result['results'] );
		$this->assertSame( 'AI Article', $result['results'][0]['title'] );
		$this->assertSame( 'exa', $result['provider'] );
	}

	/**
	 * Exa freshness filter must map to startPublishedDate in the request payload.
	 */
	public function test_exa_maps_freshness_to_start_published_date() {
		$this->set_provider( 'exa', 'exa-test-key', 'exa_api_key' );

		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'results' => array() ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Web_Search();
		$tool->execute(
			array( 'query' => 'fresh news', 'freshness' => 'pw' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'startPublishedDate', $payload_sent, 'Freshness pw should add startPublishedDate' );
	}

	// -------------------------------------------------------------------------
	// Perplexity
	// -------------------------------------------------------------------------

	/**
	 * Perplexity provider must return an error when no API key is configured.
	 */
	public function test_perplexity_requires_api_key() {
		$this->set_provider( 'perplexity', '', 'perplexity_api_key' );

		$tool   = new WP_MCP_AI_Tool_Web_Search();
		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_perplexity_api_key', $result->get_error_code() );
	}

	/**
	 * Perplexity provider must POST to api.perplexity.ai.
	 */
	public function test_perplexity_calls_correct_endpoint() {
		$this->set_provider( 'perplexity', 'pplx-test-key', 'perplexity_api_key' );

		$url_captured = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$url_captured ) {
				$url_captured = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'The answer is 42.',
									),
								),
							),
							'citations' => array( 'https://example.com' ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Web_Search();
		$tool->execute(
			array( 'query' => 'What is the meaning of life?' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotNull( $url_captured );
		$this->assertStringContainsString( 'perplexity.ai', $url_captured );
	}

	/**
	 * Perplexity provider must send a Bearer token in Authorization header.
	 */
	public function test_perplexity_sends_bearer_token() {
		$this->set_provider( 'perplexity', 'pplx-secret-key', 'perplexity_api_key' );

		$captured_args = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array( 'message' => array( 'role' => 'assistant', 'content' => 'ok' ) ),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Web_Search();
		$tool->execute(
			array( 'query' => 'bearer test' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotNull( $captured_args );
		$this->assertArrayHasKey( 'headers', $captured_args );
		$this->assertArrayHasKey( 'Authorization', $captured_args['headers'] );
		$this->assertStringContainsString( 'pplx-secret-key', $captured_args['headers']['Authorization'] );
	}

	/**
	 * Perplexity provider must return the AI-generated answer in the result.
	 */
	public function test_perplexity_returns_answer_and_citations() {
		$this->set_provider( 'perplexity', 'pplx-test-key', 'perplexity_api_key' );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices'   => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'Machine learning is a subset of AI that enables computers to learn.',
									),
								),
							),
							'citations' => array(
								'https://en.wikipedia.org/wiki/Machine_learning',
								'https://www.ibm.com/cloud/learn/machine-learning',
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Web_Search();
		$result = $tool->execute(
			array( 'query' => 'What is machine learning?' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'answer', $result );
		$this->assertStringContainsString( 'Machine learning', $result['answer'] );
		$this->assertArrayHasKey( 'citations', $result );
		$this->assertCount( 2, $result['citations'] );
		$this->assertSame( 'perplexity', $result['provider'] );
	}

	/**
	 * Perplexity freshness must map to search_recency_filter in the request.
	 */
	public function test_perplexity_maps_freshness_to_recency_filter() {
		$this->set_provider( 'perplexity', 'pplx-test-key', 'perplexity_api_key' );

		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array( 'message' => array( 'role' => 'assistant', 'content' => 'ok' ) ),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Tool_Web_Search();
		$tool->execute(
			array( 'query' => 'latest news', 'freshness' => 'pd' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'search_recency_filter', $payload_sent );
		$this->assertSame( 'day', $payload_sent['search_recency_filter'] );
	}

	// -------------------------------------------------------------------------
	// Settings / defaults
	// -------------------------------------------------------------------------

	/**
	 * Default settings must include exa_api_key and perplexity_api_key as empty strings.
	 */
	public function test_default_settings_include_new_provider_keys() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'exa_api_key', $defaults );
		$this->assertSame( '', $defaults['exa_api_key'] );

		$this->assertArrayHasKey( 'perplexity_api_key', $defaults );
		$this->assertSame( '', $defaults['perplexity_api_key'] );
	}
}
