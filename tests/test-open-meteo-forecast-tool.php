<?php
/**
 * Tests for the Open-Meteo forecast tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for the Open-Meteo forecast tool.
 */
class Test_Open_Meteo_Forecast_Tool extends WP_UnitTestCase {
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
	 * Test that the tool includes API error details in the error message when a 400 status is returned.
	 */
	public function test_execute_includes_api_error_details_on_400_status(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		// Mock response body with error details from the API.
		$error_body = wp_json_encode(
			array(
				'error'  => 'BadRequest',
				'reason' => 'Invalid hourly parameter: temperature2m',
			)
		);

		$http_interceptor = function ( $preempt, $args, $url ) use ( $error_body ) {
			return array(
				'headers'  => array(),
				'body'     => $error_body,
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$result = $tool->execute(
			array(
				'latitude'  => 52.52,
				'longitude' => 13.41,
				'hourly'    => 'temperature2m', // Invalid parameter (missing underscore).
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forecast_http_error', $result->get_error_code() );

		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'The weather service returned an unexpected HTTP status: 400', $error_message );
		$this->assertStringContainsString( 'Invalid hourly parameter: temperature2m', $error_message );
	}

	/**
	 * Test that the tool includes API error message when only 'message' field is present.
	 */
	public function test_execute_includes_api_message_field_on_error(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		// Mock response body with only 'message' field.
		$error_body = wp_json_encode(
			array(
				'message' => 'Missing required parameter: latitude',
			)
		);

		$http_interceptor = function ( $preempt, $args, $url ) use ( $error_body ) {
			return array(
				'headers'  => array(),
				'body'     => $error_body,
				'response' => array(
					'code'    => 400,
					'message' => 'Bad Request',
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$result = $tool->execute(
			array(
				'longitude' => 13.41,
				'hourly'    => 'temperature_2m',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forecast_http_error', $result->get_error_code() );

		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'Missing required parameter: latitude', $error_message );
	}

	/**
	 * Test that the tool handles errors gracefully when API returns non-JSON error body.
	 */
	public function test_execute_handles_non_json_error_response(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		// Non-JSON error body.
		$error_body = 'Server encountered an error';

		$http_interceptor = function ( $preempt, $args, $url ) use ( $error_body ) {
			return array(
				'headers'  => array(),
				'body'     => $error_body,
				'response' => array(
					'code'    => 500,
					'message' => 'Internal Server Error',
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$result = $tool->execute(
			array(
				'latitude'  => 52.52,
				'longitude' => 13.41,
				'hourly'    => 'temperature_2m',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forecast_http_error', $result->get_error_code() );

		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'The weather service returned an unexpected HTTP status: 500', $error_message );
		// Should not crash when parsing non-JSON response.
		$this->assertIsString( $error_message );
	}

	/**
	 * Test successful request to ensure we didn't break the happy path.
	 */
	public function test_execute_returns_success_on_200_status(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 52.52,
				'longitude'             => 13.41,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 0,
				'timezone'              => 'UTC',
				'timezone_abbreviation' => 'UTC',
				'elevation'             => 38.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m' => array( 5.3, 4.9 ),
				),
			)
		);

		$http_interceptor = function ( $preempt, $args, $url ) use ( $mock_body ) {
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

		$result = $tool->execute(
			array(
				'latitude'  => 52.52,
				'longitude' => 13.41,
				'hourly'    => 'temperature_2m',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'latitude', $result );
		$this->assertSame( 52.52, $result['latitude'] );
		$this->assertArrayHasKey( 'longitude', $result );
		$this->assertSame( 13.41, $result['longitude'] );
		$this->assertArrayHasKey( 'hourly', $result );
		$this->assertArrayHasKey( 'temperature_2m', $result['hourly'] );
		$this->assertSame( array( 5.3, 4.9 ), $result['hourly']['temperature_2m'] );
	}
}
