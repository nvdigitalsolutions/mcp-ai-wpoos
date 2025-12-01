<?php
/**
 * Tests for Gemini tool parameter sanitization.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client tool sanitization.
 */
class WP_MCP_AI_Gemini_Tool_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that additionalProperties is stripped from tool parameters.
	 */
	public function test_sanitize_parameters_strips_additional_properties() {
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

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'Test tool',
					'parameters'  => array(
						'type'                 => 'object',
						'properties'           => array(
							'param1' => array(
								'type'        => 'string',
								'description' => 'Test parameter',
							),
						),
						'additionalProperties' => false,
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

		$function_declarations = $payload['tools'][0]['functionDeclarations'];
		$this->assertIsArray( $function_declarations );
		$this->assertCount( 1, $function_declarations );

		$declaration = $function_declarations[0];
		$this->assertEquals( 'test_tool', $declaration['name'] );
		$this->assertArrayHasKey( 'parameters', $declaration );

		// Verify additionalProperties is NOT present.
		$this->assertArrayNotHasKey( 'additionalProperties', $declaration['parameters'] );
		$this->assertEquals( 'object', $declaration['parameters']['type'] );
		$this->assertArrayHasKey( 'properties', $declaration['parameters'] );
	}

	/**
	 * Test that nested additionalProperties are stripped.
	 */
	public function test_sanitize_parameters_strips_nested_additional_properties() {
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

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'Test tool',
					'parameters'  => array(
						'type'                 => 'object',
						'properties'           => array(
							'data' => array(
								'type'                 => 'object',
								'properties'           => array(
									'items' => array(
										'type'  => 'array',
										'items' => array(
											'type' => 'object',
											'additionalProperties' => false,
										),
									),
								),
								'additionalProperties' => false,
							),
						),
						'additionalProperties' => false,
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

		// Verify no additionalProperties at any level.
		$this->assertArrayNotHasKey( 'additionalProperties', $declaration['parameters'] );
		$this->assertArrayNotHasKey( 'additionalProperties', $declaration['parameters']['properties']['data'] );
		$this->assertArrayNotHasKey( 'additionalProperties', $declaration['parameters']['properties']['data']['properties']['items']['items'] );
	}

	/**
	 * Test that type arrays (union types) are converted to single type.
	 */
	public function test_sanitize_parameters_converts_type_arrays() {
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

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'Test tool',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'color' => array(
								'type'        => array( 'string', 'array' ),
								'description' => 'Color value',
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

		// Verify type array is converted to single type (first element).
		$this->assertEquals( 'string', $declaration['parameters']['properties']['color']['type'] );
		$this->assertIsString( $declaration['parameters']['properties']['color']['type'] );
	}

	/**
	 * Test that valid parameters are preserved.
	 */
	public function test_sanitize_parameters_preserves_valid_fields() {
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

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'Test tool',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'count' => array(
								'type'        => 'integer',
								'description' => 'Count value',
								'minimum'     => 1,
								'maximum'     => 100,
								'default'     => 10,
							),
							'tags'  => array(
								'type'        => 'array',
								'description' => 'Tag list',
								'items'       => array(
									'type' => 'string',
								),
							),
						),
						'required'   => array( 'count' ),
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

		// Verify valid fields are preserved.
		$this->assertEquals( 'integer', $declaration['parameters']['properties']['count']['type'] );
		$this->assertEquals( 1, $declaration['parameters']['properties']['count']['minimum'] );
		$this->assertEquals( 100, $declaration['parameters']['properties']['count']['maximum'] );

		// Verify 'default' is REMOVED (not supported by Gemini).
		$this->assertArrayNotHasKey( 'default', $declaration['parameters']['properties']['count'] );

		$this->assertEquals( array( 'count' ), $declaration['parameters']['required'] );

		// Verify array items are preserved.
		$this->assertEquals( 'array', $declaration['parameters']['properties']['tags']['type'] );
		$this->assertArrayHasKey( 'items', $declaration['parameters']['properties']['tags'] );
		$this->assertEquals( 'string', $declaration['parameters']['properties']['tags']['items']['type'] );
	}

	/**
	 * Test that all unsupported keywords are stripped.
	 */
	public function test_sanitize_parameters_strips_all_unsupported_keywords() {
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

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_comprehensive',
					'description' => 'Test all unsupported keywords',
					'parameters'  => array(
						'type'                 => 'object',
						'properties'           => array(
							'field1' => array(
								'type'        => 'string',
								'description' => 'Field with default',
								'default'     => 'test',
							),
							'field2' => array(
								'type'        => 'string',
								'description' => 'Field with examples',
								'examples'    => array( 'example1', 'example2' ),
							),
							'field3' => array(
								'type'        => 'string',
								'description' => 'Field with const',
								'const'       => 'constant',
							),
							'field4' => array(
								'type'        => 'string',
								'description' => 'Field with format',
								'format'      => 'email',
							),
							'field5' => array(
								'type'        => 'string',
								'description' => 'Field with nullable',
								'nullable'    => true,
							),
							'field6' => array(
								'description' => 'Field with oneOf',
								'oneOf'       => array(
									array( 'type' => 'string' ),
									array( 'type' => 'number' ),
								),
							),
							'field7' => array(
								'description' => 'Field with anyOf',
								'anyOf'       => array(
									array( 'type' => 'string' ),
									array( 'type' => 'number' ),
								),
							),
							'field8' => array(
								'description' => 'Field with allOf',
								'allOf'       => array(
									array( 'type' => 'string' ),
									array( 'minLength' => 5 ),
								),
							),
						),
						'additionalProperties' => false,
						'$schema'              => 'http://json-schema.org/draft-07/schema#',
						'$id'                  => 'http://example.com/schema',
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
		$params      = $declaration['parameters'];

		// Verify all unsupported root-level keywords are removed.
		$this->assertArrayNotHasKey( 'additionalProperties', $params );
		$this->assertArrayNotHasKey( '$schema', $params );
		$this->assertArrayNotHasKey( '$id', $params );

		// Verify unsupported keywords are removed from properties.
		$this->assertArrayNotHasKey( 'default', $params['properties']['field1'] );
		$this->assertArrayNotHasKey( 'examples', $params['properties']['field2'] );
		$this->assertArrayNotHasKey( 'const', $params['properties']['field3'] );
		$this->assertArrayNotHasKey( 'format', $params['properties']['field4'] );
		$this->assertArrayNotHasKey( 'nullable', $params['properties']['field5'] );
		$this->assertArrayNotHasKey( 'oneOf', $params['properties']['field6'] );
		$this->assertArrayNotHasKey( 'anyOf', $params['properties']['field7'] );
		$this->assertArrayNotHasKey( 'allOf', $params['properties']['field8'] );

		// Verify valid fields (description) are preserved.
		$this->assertArrayHasKey( 'description', $params['properties']['field1'] );
		$this->assertArrayHasKey( 'description', $params['properties']['field2'] );
	}

	/**
	 * Test that property names matching unsupported keywords are preserved.
	 *
	 * This ensures we don't accidentally filter out legitimate parameter names
	 * that happen to match schema keywords like 'format', 'default', etc.
	 */
	public function test_sanitize_parameters_preserves_property_names_matching_keywords() {
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

		// Tool with parameters NAMED after unsupported keywords.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_property_names',
					'description' => 'Test that property names are preserved',
					'parameters'  => array(
						'type'                 => 'object',
						'properties'           => array(
							'format'   => array(
								'type'        => 'string',
								'description' => 'Output format (parameter name)',
								'default'     => 'json',  // This schema keyword SHOULD be removed.
							),
							'default'  => array(
								'type'        => 'boolean',
								'description' => 'Use default settings (parameter name)',
							),
							'examples' => array(
								'type'        => 'array',
								'description' => 'Example values (parameter name)',
								'items'       => array(
									'type' => 'string',
								),
							),
							'const'    => array(
								'type'        => 'string',
								'description' => 'Constant value (parameter name)',
							),
						),
						'additionalProperties' => false,  // This schema keyword SHOULD be removed.
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
		$params      = $declaration['parameters'];

		// CRITICAL: Verify property names matching keywords are PRESERVED.
		$this->assertArrayHasKey( 'format', $params['properties'], 'Property named "format" should be preserved' );
		$this->assertArrayHasKey( 'default', $params['properties'], 'Property named "default" should be preserved' );
		$this->assertArrayHasKey( 'examples', $params['properties'], 'Property named "examples" should be preserved' );
		$this->assertArrayHasKey( 'const', $params['properties'], 'Property named "const" should be preserved' );

		// Verify the properties have correct structure.
		$this->assertEquals( 'string', $params['properties']['format']['type'] );
		$this->assertEquals( 'boolean', $params['properties']['default']['type'] );
		$this->assertEquals( 'array', $params['properties']['examples']['type'] );
		$this->assertEquals( 'string', $params['properties']['const']['type'] );

		// Verify schema keywords INSIDE those properties are removed.
		$this->assertArrayNotHasKey( 'default', $params['properties']['format'], 'Schema keyword "default" should be removed from format property' );

		// Verify root-level schema keywords are removed.
		$this->assertArrayNotHasKey( 'additionalProperties', $params );
	}
}
