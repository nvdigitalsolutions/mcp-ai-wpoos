<?php
/**
 * Test for Gemini schema composition keyword handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Gemini oneOf/anyOf/allOf handling.
 */
class WP_MCP_AI_Gemini_Composition_Test extends WP_UnitTestCase {

	/**
	 * Test that oneOf is converted to first option.
	 */
	public function test_sanitize_parameters_converts_one_of_to_first_option() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-3-pro-preview';

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
										array( 'text' => 'Test response' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 10,
							'candidatesTokenCount' => 5,
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

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		// Tool with oneOf in items schema (like create-google-calendar-event).
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_one_of',
					'description' => 'Test oneOf handling',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'attendees' => array(
								'type'        => 'array',
								'description' => 'Attendee list',
								'items'       => array(
									'oneOf' => array(
										array(
											'type' => 'string',
										),
										array(
											'type'       => 'object',
											'properties' => array(
												'email' => array( 'type' => 'string' ),
												'name'  => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$this->assertArrayHasKey( 'tools', $payload );
		$this->assertIsArray( $payload['tools'] );
		$this->assertCount( 1, $payload['tools'] );

		$declaration = $payload['tools'][0]['functionDeclarations'][0];

		// Verify oneOf was removed and first option was used.
		$items_schema = $declaration['parameters']['properties']['attendees']['items'];
		$this->assertArrayNotHasKey( 'oneOf', $items_schema, 'oneOf should be removed' );
		$this->assertArrayHasKey( 'type', $items_schema, 'items should have type from first oneOf option' );
		$this->assertEquals( 'string', $items_schema['type'], 'Type should be string (from first oneOf option)' );
	}

	/**
	 * Test that anyOf is converted to first option.
	 */
	public function test_sanitize_parameters_converts_any_of_to_first_option() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-3-pro-preview';

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
										array( 'text' => 'Test response' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 10,
							'candidatesTokenCount' => 5,
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

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		// Tool with anyOf at parameter level.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_any_of',
					'description' => 'Test anyOf handling',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'value' => array(
								'anyOf' => array(
									array(
										'type' => 'number',
									),
									array(
										'type' => 'string',
									),
								),
							),
						),
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$declaration = $payload['tools'][0]['functionDeclarations'][0];

		// Verify anyOf was removed and first option was used.
		$value_schema = $declaration['parameters']['properties']['value'];
		$this->assertArrayNotHasKey( 'anyOf', $value_schema, 'anyOf should be removed' );
		$this->assertArrayHasKey( 'type', $value_schema, 'property should have type from first anyOf option' );
		$this->assertEquals( 'number', $value_schema['type'], 'Type should be number (from first anyOf option)' );
	}

	/**
	 * Test that allOf is converted by merging first option.
	 */
	public function test_sanitize_parameters_converts_all_of_to_first_option() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-3-pro-preview';

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
										array( 'text' => 'Test response' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 10,
							'candidatesTokenCount' => 5,
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

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		// Tool with allOf.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_all_of',
					'description' => 'Test allOf handling',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'restricted_number' => array(
								'allOf' => array(
									array(
										'type'    => 'number',
										'minimum' => 0,
										'maximum' => 100,
									),
									array(
										'multipleOf' => 5,
									),
								),
							),
						),
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$declaration = $payload['tools'][0]['functionDeclarations'][0];

		// Verify allOf was removed and first option was used.
		$param_schema = $declaration['parameters']['properties']['restricted_number'];
		$this->assertArrayNotHasKey( 'allOf', $param_schema, 'allOf should be removed' );
		$this->assertArrayHasKey( 'type', $param_schema, 'property should have type from first allOf option' );
		$this->assertEquals( 'number', $param_schema['type'], 'Type should be number (from first allOf option)' );
		$this->assertEquals( 0, $param_schema['minimum'], 'minimum constraint should be preserved' );
		$this->assertEquals( 100, $param_schema['maximum'], 'maximum constraint should be preserved' );
	}

	/**
	 * Test complex nested schema with composition keywords.
	 */
	public function test_sanitize_parameters_handles_complex_nested_composition() {
		$defaults                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['gemini_api_key']       = 'gsk-test';
		$defaults['default_gemini_model'] = 'gemini-3-pro-preview';

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
										array( 'text' => 'Test response' ),
									),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 10,
							'candidatesTokenCount' => 5,
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

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		// Complex nested schema with multiple levels of composition.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_complex',
					'description' => 'Test complex nested composition',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'data' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'value'    => array(
											'oneOf' => array(
												array( 'type' => 'string' ),
												array( 'type' => 'number' ),
											),
										),
										'metadata' => array(
											'anyOf' => array(
												array(
													'type' => 'object',
													'properties' => array(
														'id' => array( 'type' => 'integer' ),
													),
												),
												array( 'type' => 'null' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$response = $client->create_chat_completion( $messages, array( 'tools' => $tools ) );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $response );
		$this->assertNotNull( $captured_request );

		$payload = json_decode( $captured_request['args']['body'], true );

		$declaration = $payload['tools'][0]['functionDeclarations'][0];

		// Verify nested composition keywords were all removed.
		$items = $declaration['parameters']['properties']['data']['items'];
		$this->assertArrayHasKey( 'properties', $items );

		$value_schema = $items['properties']['value'];
		$this->assertArrayNotHasKey( 'oneOf', $value_schema );
		$this->assertArrayHasKey( 'type', $value_schema );
		$this->assertEquals( 'string', $value_schema['type'] );

		$metadata_schema = $items['properties']['metadata'];
		$this->assertArrayNotHasKey( 'anyOf', $metadata_schema );
		$this->assertArrayHasKey( 'type', $metadata_schema );
		$this->assertEquals( 'object', $metadata_schema['type'] );
		$this->assertArrayHasKey( 'properties', $metadata_schema );
		$this->assertArrayHasKey( 'id', $metadata_schema['properties'] );
	}
}
