<?php
/**
 * Tests covering social reporting tools.
 */
class WP_MCP_AI_Social_Reporting_Tools_Test extends WP_UnitTestCase {

	/**
	 * Ensure the Meta insights tool is registered and dispatches a request.
	 */
	public function test_get_facebook_instagram_insights_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_facebook_instagram_insights' );

		$this->assertInstanceOf( WP_MCP_AI_Pro_Tool_Get_Facebook_Instagram_Insights::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'graph.facebook.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url' => $url,
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'data'   => array(
							array(
								'name'   => 'page_impressions',
								'period' => 'day',
								'values' => array(),
							),
						),
						'paging' => array(),
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'platform'     => 'facebook',
				'access_token' => 'valid-token',
				'target_id'    => '123',
				'metrics'      => array( 'page_impressions' ),
				'period'       => 'day',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'facebook', $result['platform'] );
		$this->assertSame( '123', $result['target_id'] );
		$this->assertCount( 1, $result['metrics'] );
		$this->assertCount( 1, $requests );

		$parsed = wp_parse_url( $requests[0]['url'] );
		parse_str( $parsed['query'], $params );

		$this->assertSame( 'page_impressions', $params['metric'] );
		$this->assertSame( 'day', $params['period'] );
	}

	/**
	 * Ensure the TikTok insights tool issues the expected payload.
	 */
	public function test_get_tiktok_insights_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_tiktok_insights' );

		$this->assertInstanceOf( WP_MCP_AI_Pro_Tool_Get_Tiktok_Insights::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'open-api.tiktok.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'  => $url,
				'body' => isset( $parsed_args['body'] ) ? $parsed_args['body'] : '',
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'data' => array(
							array(
								'metric' => 'views',
								'value'  => 100,
							),
						),
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'access_token' => 'token123',
				'open_id'      => 'open123',
				'metrics'      => 'views,likes',
				'start_time'   => '2024-01-01T00:00:00Z',
				'end_time'     => '2024-01-07T00:00:00Z',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'open123', $result['open_id'] );
		$this->assertCount( 1, $requests );

		$decoded = json_decode( $requests[0]['body'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( array( 'views', 'likes' ), $decoded['metrics'] );
		$this->assertSame( '2024-01-01T00:00:00Z', $decoded['start_time'] );
	}

	/**
	 * Ensure the Google Business insights tool issues an authenticated request.
	 */
	public function test_get_google_business_insights_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_google_business_insights' );

		$this->assertInstanceOf( WP_MCP_AI_Tool_Get_Google_Business_Insights::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'mybusiness.googleapis.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'     => $url,
				'body'    => isset( $parsed_args['body'] ) ? $parsed_args['body'] : '',
				'headers' => isset( $parsed_args['headers'] ) ? $parsed_args['headers'] : array(),
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'locationMetrics' => array(
							array(
								'locationName' => 'accounts/1/locations/2',
								'metricValues' => array(),
							),
						),
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'access_token' => 'googletoken',
				'location'     => 'accounts/1/locations/2',
				'metrics'      => array( 'BUSINESS_IMPRESSIONS_SEARCH' ),
				'start_time'   => '2024-01-01T00:00:00Z',
				'end_time'     => '2024-01-07T00:00:00Z',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'accounts/1/locations/2', $result['location'] );
		$this->assertCount( 1, $result['locationMetrics'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'Bearer googletoken', $requests[0]['headers']['Authorization'] );

		$decoded = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'BUSINESS_IMPRESSIONS_SEARCH', $decoded['basicRequest']['metricRequests'][0]['metric'] );
	}

	/**
	 * Ensure the LinkedIn insights tool performs an authenticated request.
	 */
	public function test_get_linkedin_insights_executes_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_linkedin_insights' );

		$this->assertInstanceOf( WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights::class, $tool );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$requests = array();

		$filter = function ( $preempt, $parsed_args, $url ) use ( &$requests ) {
			if ( false === strpos( $url, 'api.linkedin.com' ) ) {
				return $preempt;
			}

			$requests[] = array(
				'url'     => $url,
				'headers' => isset( $parsed_args['headers'] ) ? $parsed_args['headers'] : array(),
			);

			return array(
				'body'     => wp_json_encode(
					array(
						'elements' => array(
							array(
								'organizationalEntity' => 'urn:li:organization:123',
								'totalShareStatistics' => array( 'shareCount' => 5 ),
							),
						),
					)
				),
				'response' => array( 'code' => 200 ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = $tool->execute(
			array(
				'access_token'          => 'linkedin-token',
				'organization'          => 'urn:li:organization:123',
				'timeframe_start'       => '1704067200000',
				'timeframe_end'         => '1704585600000',
				'time_granularity_type' => 'DAY',
			),
			array( 'user_id' => $admin_id )
		);

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'urn:li:organization:123', $result['organization'] );
		$this->assertCount( 1, $result['statistics'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'Bearer linkedin-token', $requests[0]['headers']['Authorization'] );

		$parsed = wp_parse_url( $requests[0]['url'] );
		parse_str( $parsed['query'], $params );

		$this->assertSame( 'urn:li:organization:123', $params['organizationalEntity'] );
		$this->assertSame( 'DAY', $params['timeIntervals'][0]['timeGranularity'] );
		$this->assertSame( '1704067200000', (string) $params['timeIntervals'][0]['timeRange']['start'] );
	}
}
