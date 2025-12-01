<?php
/**
 * Tests for Gemini tool parameter sanitization - scalar property value fix.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client scalar property value fix.
 *
 * This test verifies that the Gemini client properly handles malformed schemas
 * where property values are scalars (e.g., "string") instead of proper schema
 * objects (e.g., {"type": "string"}).
 */
class WP_MCP_AI_Gemini_Scalar_Property_Fix_Test extends WP_UnitTestCase {

	/**
	 * Test that scalar property values are converted to schema objects.
	 *
	 * This addresses the error:
	 * "Invalid value at 'tools[0].function_declarations[0].parameters.properties[X].value'
	 * (type.googleapis.com/google.ai.generativelanguage.v1beta.Schema), "string""
	 */
	public function test_scalar_property_values_converted_to_schema_objects() {
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

		// Tool with malformed properties where values are scalars instead of schema objects.
		// This simulates a bug where a tool definition incorrectly uses:
		// 'properties' => array( 'field1' => 'string' )
		// instead of:
		// 'properties' => array( 'field1' => array('type' => 'string') )
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_malformed_properties',
					'description' => 'Test tool with malformed property values',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'field1' => 'string',  // Malformed: should be array('type' => 'string').
							'field2' => 'number',  // Malformed: should be array('type' => 'number').
							'field3' => array(     // Properly formed.
								'type'        => 'boolean',
								'description' => 'Properly formed field',
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

		$function_declarations = $payload['tools'][0]['functionDeclarations'];
		$this->assertIsArray( $function_declarations );
		$this->assertCount( 1, $function_declarations );

		$declaration = $function_declarations[0];
		$params      = $declaration['parameters'];

		// Verify that scalar property values were converted to proper schema objects.
		$this->assertIsArray( $params['properties']['field1'], 'field1 should be an array (schema object), not a scalar' );
		$this->assertArrayHasKey( 'type', $params['properties']['field1'], 'field1 schema should have a type field' );
		$this->assertEquals( 'string', $params['properties']['field1']['type'], 'field1 type should be "string"' );

		$this->assertIsArray( $params['properties']['field2'], 'field2 should be an array (schema object), not a scalar' );
		$this->assertArrayHasKey( 'type', $params['properties']['field2'], 'field2 schema should have a type field' );
		$this->assertEquals( 'number', $params['properties']['field2']['type'], 'field2 type should be "number"' );

		// Verify that properly formed properties are preserved.
		$this->assertIsArray( $params['properties']['field3'] );
		$this->assertEquals( 'boolean', $params['properties']['field3']['type'] );
		$this->assertEquals( 'Properly formed field', $params['properties']['field3']['description'] );
	}

	/**
	 * Test nested properties with scalar values (deeper nesting).
	 *
	 * Addresses errors like:
	 * "Invalid value at 'tools[0].function_declarations[0].parameters.properties[5].value.items.properties[3].value'"
	 */
	public function test_nested_scalar_property_values_in_items() {
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

		// Tool with deeply nested malformed properties.
		// Simulates: parameters.properties.items.items.properties with scalar values.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_nested_malformed',
					'description' => 'Test tool with nested malformed properties',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'items_list' => array(
								'type'        => 'array',
								'description' => 'Array of items',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'name'   => 'string',  // Malformed: scalar instead of schema object.
										'value'  => 'number',  // Malformed: scalar instead of schema object.
										'active' => array(     // Properly formed.
											'type' => 'boolean',
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
		$items_list  = $declaration['parameters']['properties']['items_list'];
		$item_schema = $items_list['items'];
		$item_props  = $item_schema['properties'];

		// Verify nested scalar values were converted to schema objects.
		$this->assertIsArray( $item_props['name'], 'Nested name property should be an array (schema object)' );
		$this->assertArrayHasKey( 'type', $item_props['name'], 'Nested name schema should have a type field' );
		$this->assertEquals( 'string', $item_props['name']['type'] );

		$this->assertIsArray( $item_props['value'], 'Nested value property should be an array (schema object)' );
		$this->assertArrayHasKey( 'type', $item_props['value'], 'Nested value schema should have a type field' );
		$this->assertEquals( 'number', $item_props['value']['type'] );

		// Verify properly formed nested properties are preserved.
		$this->assertIsArray( $item_props['active'] );
		$this->assertEquals( 'boolean', $item_props['active']['type'] );
	}

	/**
	 * Test that non-string scalar values are handled gracefully.
	 */
	public function test_non_string_scalar_values_default_to_string_type() {
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

		// Tool with non-string scalar property values (numbers, booleans).
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_nonstring_scalars',
					'description' => 'Test tool with non-string scalar values',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'field1' => 123,    // Number as property value.
							'field2' => true,   // Boolean as property value.
							'field3' => null,   // Null as property value.
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

		$params = $payload['tools'][0]['functionDeclarations'][0]['parameters'];

		// Non-string scalars should be converted to string type schema objects.
		$this->assertIsArray( $params['properties']['field1'] );
		$this->assertEquals( 'string', $params['properties']['field1']['type'] );

		$this->assertIsArray( $params['properties']['field2'] );
		$this->assertEquals( 'string', $params['properties']['field2']['type'] );

		// Null values might be excluded or converted; just ensure no scalar remains.
		if ( isset( $params['properties']['field3'] ) ) {
			$this->assertIsArray( $params['properties']['field3'] );
		}
	}
}
