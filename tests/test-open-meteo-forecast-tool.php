<?php
/**
 * Tests for the Open-Meteo forecast tool.
 *
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

	/**
	 * Test that daily-only variables like precipitation_sum are rejected with a helpful error message.
	 */
	public function test_execute_rejects_daily_only_variables_as_hourly(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$result = $tool->execute(
			array(
				'latitude'  => 25.76,  // Miami coordinates.
				'longitude' => -80.19,
				'hourly'    => 'precipitation_sum',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_hourly_variable', $result->get_error_code() );

		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'precipitation_sum', $error_message );
		$this->assertStringContainsString( 'only available for daily forecasts', $error_message );
		$this->assertStringContainsString( 'use "precipitation" instead', $error_message );
	}

	/**
	 * Test that multiple daily-only variables are all reported in the error message.
	 */
	public function test_execute_rejects_multiple_daily_only_variables(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$result = $tool->execute(
			array(
				'latitude'  => 25.76,
				'longitude' => -80.19,
				'hourly'    => array( 'temperature_2m', 'precipitation_sum', 'rain_sum', 'temperature_2m_max' ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_hourly_variable', $result->get_error_code() );

		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'precipitation_sum', $error_message );
		$this->assertStringContainsString( 'rain_sum', $error_message );
		$this->assertStringContainsString( 'temperature_2m_max', $error_message );
	}

	/**
	 * Test that valid hourly variables like precipitation (not precipitation_sum) work correctly.
	 */
	public function test_execute_accepts_valid_hourly_precipitation_variable(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 25.76,
				'longitude'             => -80.19,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 0,
				'timezone'              => 'UTC',
				'timezone_abbreviation' => 'UTC',
				'elevation'             => 5.0,
				'hourly_units'          => array(
					'time'          => 'iso8601',
					'precipitation' => 'mm',
				),
				'hourly'                => array(
					'time'          => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'precipitation' => array( 0.0, 0.5 ),
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
				'latitude'  => 25.76,
				'longitude' => -80.19,
				'hourly'    => 'precipitation',  // Valid hourly variable.
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'latitude', $result );
		$this->assertSame( 25.76, $result['latitude'] );
		$this->assertArrayHasKey( 'hourly', $result );
		$this->assertArrayHasKey( 'precipitation', $result['hourly'] );
		$this->assertSame( array( 0.0, 0.5 ), $result['hourly']['precipitation'] );
	}

	/**
	 * Test that output_format chart returns chart data structure.
	 */
	public function test_execute_returns_chart_output_when_requested(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 52.52,
				'longitude'             => 13.41,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 0,
				'timezone'              => 'Europe/Berlin',
				'timezone_abbreviation' => 'CET',
				'elevation'             => 38.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00', '2023-11-05T02:00' ),
					'temperature_2m' => array( 5.3, 4.9, 4.5 ),
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
				'latitude'      => 52.52,
				'longitude'     => 13.41,
				'hourly'        => 'temperature_2m',
				'output_format' => 'chart',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'output_format', $result );
		$this->assertSame( 'chart', $result['output_format'] );
		$this->assertArrayHasKey( 'chart_type', $result );
		$this->assertSame( 'line', $result['chart_type'] );
		$this->assertArrayHasKey( 'html', $result );
		$this->assertStringContainsString( 'Chart', $result['html'] );
		$this->assertStringContainsString( 'canvas', $result['html'] );
		// Verify SRI (Subresource Integrity) is included for security.
		$this->assertStringContainsString( 'integrity=', $result['html'] );
		$this->assertStringContainsString( 'crossorigin="anonymous"', $result['html'] );
		$this->assertArrayHasKey( 'chart_config', $result );
		$this->assertIsArray( $result['chart_config'] );
		$this->assertArrayHasKey( 'data', $result );
	}

	/**
	 * Test that chart output includes correct chart type when specified.
	 */
	public function test_execute_chart_respects_chart_type_parameter(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 40.71,
				'longitude'             => -74.01,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => -18000,
				'timezone'              => 'America/New_York',
				'timezone_abbreviation' => 'EST',
				'elevation'             => 10.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m' => array( 12.5, 11.8 ),
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
				'latitude'      => 40.71,
				'longitude'     => -74.01,
				'hourly'        => 'temperature_2m',
				'output_format' => 'chart',
				'chart_type'    => 'bar',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'bar', $result['chart_type'] );
		$this->assertSame( 'bar', $result['chart_config']['type'] );
	}

	/**
	 * Test that custom chart title is applied when provided.
	 */
	public function test_execute_chart_uses_custom_title(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 34.05,
				'longitude'             => -118.24,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => -28800,
				'timezone'              => 'America/Los_Angeles',
				'timezone_abbreviation' => 'PST',
				'elevation'             => 89.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m' => array( 18.2, 17.5 ),
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

		$custom_title = 'Los Angeles Weather Forecast';

		$result = $tool->execute(
			array(
				'latitude'      => 34.05,
				'longitude'     => -118.24,
				'hourly'        => 'temperature_2m',
				'output_format' => 'chart',
				'chart_title'   => $custom_title,
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( $custom_title, $result['chart_title'] );
		$this->assertSame( $custom_title, $result['chart_config']['options']['plugins']['title']['text'] );
	}

	/**
	 * Test that chart dimensions are respected within bounds.
	 */
	public function test_execute_chart_respects_dimension_parameters(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 51.51,
				'longitude'             => -0.13,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 0,
				'timezone'              => 'Europe/London',
				'timezone_abbreviation' => 'GMT',
				'elevation'             => 25.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m' => array( 8.3, 7.9 ),
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
				'latitude'      => 51.51,
				'longitude'     => -0.13,
				'hourly'        => 'temperature_2m',
				'output_format' => 'chart',
				'chart_width'   => 1200,
				'chart_height'  => 600,
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 1200, $result['width'] );
		$this->assertSame( 600, $result['height'] );
		$this->assertStringContainsString( 'width="1200"', $result['html'] );
		$this->assertStringContainsString( 'height="600"', $result['html'] );
	}

	/**
	 * Test that chart output includes multiple datasets for multiple variables.
	 */
	public function test_execute_chart_handles_multiple_variables(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 48.86,
				'longitude'             => 2.35,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 3600,
				'timezone'              => 'Europe/Paris',
				'timezone_abbreviation' => 'CET',
				'elevation'             => 35.0,
				'hourly_units'          => array(
					'time'                 => 'iso8601',
					'temperature_2m'       => '°C',
					'relative_humidity_2m' => '%',
				),
				'hourly'                => array(
					'time'                 => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m'       => array( 10.2, 9.8 ),
					'relative_humidity_2m' => array( 75, 78 ),
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
				'latitude'      => 48.86,
				'longitude'     => 2.35,
				'hourly'        => array( 'temperature_2m', 'relative_humidity_2m' ),
				'output_format' => 'chart',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'chart_config', $result );
		$this->assertCount( 2, $result['chart_config']['data']['datasets'] );
	}

	/**
	 * Test that json output_format returns standard data (backward compatibility).
	 */
	public function test_execute_json_format_returns_standard_data(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 35.68,
				'longitude'             => 139.69,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 32400,
				'timezone'              => 'Asia/Tokyo',
				'timezone_abbreviation' => 'JST',
				'elevation'             => 40.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m' => array( 15.5, 14.8 ),
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
				'latitude'      => 35.68,
				'longitude'     => 139.69,
				'hourly'        => 'temperature_2m',
				'output_format' => 'json',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		// JSON format should NOT have chart-specific keys.
		$this->assertArrayNotHasKey( 'output_format', $result );
		$this->assertArrayNotHasKey( 'html', $result );
		$this->assertArrayNotHasKey( 'chart_config', $result );
		// Should have standard weather data keys.
		$this->assertArrayHasKey( 'latitude', $result );
		$this->assertArrayHasKey( 'longitude', $result );
		$this->assertArrayHasKey( 'hourly', $result );
	}

	/**
	 * Test that default output_format is json (backward compatibility).
	 */
	public function test_execute_default_output_format_is_json(): void {
		$tool = new WP_MCP_AI_Tool_Get_Open_Meteo_Forecast();

		$mock_body = wp_json_encode(
			array(
				'latitude'              => 55.75,
				'longitude'             => 37.62,
				'generationtime_ms'     => 0.5,
				'utc_offset_seconds'    => 10800,
				'timezone'              => 'Europe/Moscow',
				'timezone_abbreviation' => 'MSK',
				'elevation'             => 156.0,
				'hourly_units'          => array(
					'time'           => 'iso8601',
					'temperature_2m' => '°C',
				),
				'hourly'                => array(
					'time'           => array( '2023-11-05T00:00', '2023-11-05T01:00' ),
					'temperature_2m' => array( -2.5, -3.1 ),
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

		// Note: No output_format specified - should default to json.
		$result = $tool->execute(
			array(
				'latitude'  => 55.75,
				'longitude' => 37.62,
				'hourly'    => 'temperature_2m',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertIsArray( $result );
		// Default should be JSON format (no chart keys).
		$this->assertArrayNotHasKey( 'output_format', $result );
		$this->assertArrayNotHasKey( 'html', $result );
		$this->assertArrayHasKey( 'latitude', $result );
		$this->assertArrayHasKey( 'hourly', $result );
	}
}
