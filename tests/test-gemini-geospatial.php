<?php
/**
 * Tests for the Gemini Geospatial API integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Gemini Geospatial Query functionality.
 */
class WP_MCP_AI_Gemini_Geospatial_Test extends WP_UnitTestCase {

	/**
	 * Ensure an error is returned when the Gemini API key is missing.
	 */
	public function test_geospatial_query_requires_api_key() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->create_geospatial_query( 'Find coffee shops near Central Park', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $response->get_error_code() );

		$data = $response->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'configure_gemini_api_key', $data['actions'] );
	}

	/**
	 * Ensure an error is returned when the query is empty.
	 */
	public function test_geospatial_query_requires_query_text() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'gsk-test';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client   = new WP_MCP_AI_Gemini_Client();
		$response = $client->create_geospatial_query( '', array() );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_missing_query', $response->get_error_code() );
	}

	/**
	 * Test successful geospatial query with Google Maps context token.
	 */
	public function test_geospatial_query_success_with_context_token() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-1.5-flash';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'                     => array(
									'parts' => array(
										array( 'text' => 'Here are some great coffee shops near Central Park: Blue Bottle Coffee, Starbucks Reserve...' ),
									),
								),
								'finishReason'                => 'STOP',
								'googleMapsWidgetContextToken' => 'test-context-token-123',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 15,
							'candidatesTokenCount' => 50,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$response = $client->create_geospatial_query( 'Find coffee shops near Central Park', array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotWPError( $response );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'content', $response );
		$this->assertArrayHasKey( 'google_maps_context_token', $response );
		$this->assertSame( 'test-context-token-123', $response['google_maps_context_token'] );

		// Verify the request payload includes Google Maps grounding.
		$this->assertNotNull( $captured_request );
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'tools', $body );
		$this->assertIsArray( $body['tools'] );

		// Check for Google Maps tool.
		$has_maps_tool = false;
		foreach ( $body['tools'] as $tool ) {
			if ( isset( $tool['google_maps'] ) ) {
				$has_maps_tool = true;
				$this->assertTrue( $tool['google_maps']['enabled'] );
				break;
			}
		}
		$this->assertTrue( $has_maps_tool, 'Request should include Google Maps grounding tool' );
	}

	/**
	 * Test geospatial query with optional location context.
	 */
	public function test_geospatial_query_with_location_context() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-1.5-flash';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$client           = new WP_MCP_AI_Gemini_Client();
		$captured_request = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_request ) {
			$captured_request = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => 'Based on your location, here are nearby restaurants...' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 12,
							'candidatesTokenCount' => 40,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$response = $client->create_geospatial_query(
			'Find restaurants nearby',
			array(
				'location' => array(
					'latitude'  => 40.7580,
					'longitude' => -73.9855,
				),
			)
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotWPError( $response );

		// Verify location context was included in request.
		$this->assertNotNull( $captured_request );
		$body = json_decode( $captured_request['args']['body'], true );
		$this->assertArrayHasKey( 'location_context', $body );
		$this->assertSame( 40.7580, $body['location_context']['latitude'] );
		$this->assertSame( -73.9855, $body['location_context']['longitude'] );
	}

	/**
	 * Test the geospatial query tool integration.
	 */
	public function test_geospatial_query_tool() {
		$defaults                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key'] = 'gsk-test';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		// Create a test user with appropriate capabilities.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$tool = new WP_MCP_AI_Tool_Gemini_Geospatial_Query();

		$filter_callback = function ( $preempt, $args, $url ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => 'Test response about parks in Seattle.' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 10,
							'candidatesTokenCount' => 25,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$result = $tool->execute(
			array( 'query' => 'Tell me about parks in Seattle' ),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'query', $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'has_map_context', $result );
		$this->assertSame( 'Tell me about parks in Seattle', $result['query'] );
	}

	/**
	 * Test that geospatial query tool requires authentication.
	 */
	public function test_geospatial_query_tool_requires_authentication() {
		$tool   = new WP_MCP_AI_Tool_Gemini_Geospatial_Query();
		$result = $tool->execute(
			array( 'query' => 'Find museums' ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test tool capability flags.
	 */
	public function test_geospatial_query_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Gemini_Geospatial_Query();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'ai-powered', $flags );
	}

	/**
	 * Test tool parameters schema.
	 */
	public function test_geospatial_query_tool_schema() {
		$tool   = new WP_MCP_AI_Tool_Gemini_Geospatial_Query();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'query', $schema['properties'] );
		$this->assertArrayHasKey( 'latitude', $schema['properties'] );
		$this->assertArrayHasKey( 'longitude', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'query', $schema['required'] );
	}
}
