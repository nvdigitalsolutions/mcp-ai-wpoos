<?php
/**
 * Tests for the GDACS events tool.
 *
 * @package WP_MCP_AI
 */
class Test_GDACS_Tool extends WP_UnitTestCase {
	/**
	 * Acting administrator user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Ensure the tool requests the updated GDACS MAP endpoint and sanitises the payload.
	 */
	public function test_execute_uses_map_endpoint_with_default_event_types(): void {
		$tool          = new WP_MCP_AI_Tool_Get_GDACS_Events();
		$requested_url = null;
		$mock_body     = wp_json_encode(
			array(
				'type'     => 'FeatureCollection',
				'features' => array(
					array(
						'type'       => 'Feature',
						'properties' => array(
							'eventid' => 123,
							'name'    => 'Example Event',
						),
					),
				),
			)
		);

		$http_interceptor = function ( $preempt, $args, $url ) use ( &$requested_url, $mock_body ) {
			$requested_url = $url;

			return array(
				'headers'  => array(),
				'body'     => $mock_body,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'model', $result );
		$this->assertSame( 'gpt-5', $result['model'] );
		$this->assertNull( $result['from_date'] );
		$this->assertNull( $result['to_date'] );

		$parts = wp_parse_url( $requested_url );
		$this->assertSame( 'https', $parts['scheme'] );
		$this->assertSame( 'www.gdacs.org', $parts['host'] );
		$this->assertSame( '/gdacsapi/api/events/geteventlist/MAP', $parts['path'] );

		parse_str( $parts['query'], $query_args );
		$this->assertArrayHasKey( 'eventtypes', $query_args );
		$this->assertSame( 'TC,FL', $query_args['eventtypes'] );

		$this->assertArrayHasKey( 'events', $result );
		$this->assertSame( 'FeatureCollection', $result['events']['type'] );
		$this->assertSame( 'Example Event', $result['events']['features'][0]['properties']['name'] );
	}

	/**
	 * The tool should append validated date filters to the query string.
	 */
	public function test_execute_appends_date_filters(): void {
		$tool          = new WP_MCP_AI_Tool_Get_GDACS_Events();
		$requested_url = null;

		$http_interceptor = function ( $preempt, $args, $url ) use ( &$requested_url ) {
			$requested_url = $url;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array() ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$from = '2025-10-25';
		$to   = '2025-11-05';

		$result = $tool->execute(
			array(
				'from_date' => $from,
				'to_date'   => $to,
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$parts = wp_parse_url( $requested_url );
		parse_str( $parts['query'], $query_args );

		$this->assertSame( 'TC,FL', $query_args['eventtypes'] );
		$this->assertSame( $from, $query_args['fromdate'] );
		$this->assertSame( $to, $query_args['todate'] );

		$this->assertArrayHasKey( 'model', $result );
		$this->assertSame( 'gpt-5', $result['model'] );
		$this->assertSame( $from, $result['from_date'] );
		$this->assertSame( $to, $result['to_date'] );
	}
}
