<?php
/**
 * Tests for the Crawl4AI price lookup tool.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Crawl4AI_Price_Lookup_Tool_Test extends WP_UnitTestCase {

	/**
	 * Reset the current user after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Ensure the tool falls back to the local web search when Crawl4AI is not configured.
	 */
	public function test_execute_uses_local_fallback_when_crawl4ai_not_configured() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$responses = array(
			'bjs.com'      => array(
				'body'     => wp_json_encode(
					array(
						'RelatedTopics' => array(
							array(
								'Text'     => 'BJ\'s Club Value Pack',
								'FirstURL' => 'https://www.bjs.com/product/value-pack',
								'Result'   => '<a href="https://www.bjs.com/product/value-pack">BJ\'s deal for just $12.99 today.</a>',
							),
						),
					)
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'response' => array( 'code' => 200 ),
			),
			'samsclub.com' => array(
				'body'     => wp_json_encode(
					array(
						'RelatedTopics' => array(
							array(
								'Text'     => 'Sam\'s Club Mega Bundle',
								'FirstURL' => 'https://www.samsclub.com/p/mega-bundle',
								'Result'   => '<a href="https://www.samsclub.com/p/mega-bundle">Member savings at $15.49.</a>',
							),
						),
					)
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'response' => array( 'code' => 200 ),
			),
			'costco.com'   => array(
				'body'     => wp_json_encode(
					array(
						'RelatedTopics' => array(
							array(
								'Text'     => 'Costco Bulk Essentials',
								'FirstURL' => 'https://www.costco.com/product/bulk-essentials',
								'Result'   => '<a href="https://www.costco.com/product/bulk-essentials">Get it for $17.99.</a>',
							),
						),
					)
				),
				'headers'  => array( 'content-type' => 'application/json' ),
				'response' => array( 'code' => 200 ),
			),
		);

		$filter = function ( $preempt, $args, $url ) use ( &$responses ) {
			foreach ( $responses as $needle => $response ) {
				if ( false !== strpos( $url, $needle ) ) {
					return $response;
				}
			}

			return $preempt;
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Crawl4AI_Price_Lookup();
		$result = $tool->execute( array( 'product' => 'paper towels' ), array( 'user_id' => $user_id ) );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'paper towels', $result['product'] );
		$this->assertSame( 'local', $result['metadata']['lookup_provider'] );
		$this->assertCount( 3, $result['brands'] );
		$this->assertSame( 'success', $result['brands'][0]['status'] );
		$this->assertSame( 12.99, $result['brands'][0]['price'] );
		$this->assertSame( 'success', $result['brands'][1]['status'] );
		$this->assertSame( 15.49, $result['brands'][1]['price'] );
		$this->assertSame( 'success', $result['brands'][2]['status'] );
		$this->assertSame( 17.99, $result['brands'][2]['price'] );
	}

	/**
	 * Ensure the tool respects capability requirements.
	 */
	public function test_execute_requires_manage_options() {
		$this->configure_crawl4ai_settings();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Crawl4AI_Price_Lookup();
		$result = $tool->execute( array( 'product' => 'paper towels' ), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure the tool queries Crawl4AI and extracts pricing information.
	 */
	public function test_execute_extracts_prices_from_responses() {
		$this->configure_crawl4ai_settings();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$responses = array(
			array(
				'body'     => wp_json_encode(
					array(
						'results' => array(
							array(
								'title'   => "BJ's Club Value Pack",
								'url'     => 'https://www.bjs.com/product/value-pack',
								'snippet' => 'Save more with this pack for just $12.99 today.',
							),
						),
					)
				),
				'headers'  => array(),
				'response' => array( 'code' => 200 ),
			),
			array(
				'body'     => wp_json_encode(
					array(
						'results' => array(
							array(
								'title'   => "Sam's Club Mega Bundle",
								'url'     => 'https://www.samsclub.com/p/mega-bundle',
								'snippet' => 'Mega savings available now at $15.49.',
							),
						),
					)
				),
				'headers'  => array(),
				'response' => array( 'code' => 200 ),
			),
			array(
				'body'     => wp_json_encode(
					array(
						'results' => array(
							array(
								'title'   => 'Costco Bulk Essentials',
								'url'     => 'https://www.costco.com/product/bulk-essentials',
								'snippet' => 'Members get it for $17.99 with same-day delivery.',
							),
						),
					)
				),
				'headers'  => array(),
				'response' => array( 'code' => 200 ),
			),
		);

		$requests = array();

		$callback = function ( $preempt, $args, $url ) use ( &$responses, &$requests ) {
			if ( empty( $responses ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'  => $url,
				'body' => isset( $args['body'] ) ? $args['body'] : '',
			);

			return array_shift( $responses );
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_Crawl4AI_Price_Lookup();
		$result = $tool->execute(
			array(
				'product'     => 'paper towels',
				'max_results' => 3,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'paper towels', $result['product'] );
		$this->assertSame( 3, $result['metadata']['max_results'] );
		$this->assertSame( 'crawl4ai', $result['metadata']['lookup_provider'] );
		$this->assertCount( 3, $result['brands'] );

		$this->assertSame( 'success', $result['brands'][0]['status'] );
		$this->assertSame( 12.99, $result['brands'][0]['price'] );
		$this->assertSame( 'https://www.bjs.com/product/value-pack', $result['brands'][0]['source']['url'] );

		$this->assertSame( 'success', $result['brands'][1]['status'] );
		$this->assertSame( 15.49, $result['brands'][1]['price'] );

		$this->assertSame( 'success', $result['brands'][2]['status'] );
		$this->assertSame( 17.99, $result['brands'][2]['price'] );

		$this->assertCount( 3, $requests );
		$this->assertStringEndsWith( '/web_search', $requests[0]['url'] );
		$this->assertStringContainsString( 'site:bjs.com', $requests[0]['body'] );
		$this->assertStringContainsString( 'site:samsclub.com', $requests[1]['body'] );
		$this->assertStringContainsString( 'site:costco.com', $requests[2]['body'] );
	}

	/**
	 * Helper to configure Crawl4AI settings for the tests.
	 */
	protected function configure_crawl4ai_settings() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();

		$settings['crawl4ai_base_url'] = 'https://api.example.com';
		$settings['crawl4ai_api_key']  = 'test-token';
		$settings['request_timeout']   = 5;

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}
}
