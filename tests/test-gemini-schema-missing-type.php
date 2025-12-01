<?php
/**
 * Tests for Gemini schema sanitization with missing type fields.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client schema enhancement.
 */
class WP_MCP_AI_Gemini_Schema_Missing_Type_Test extends WP_UnitTestCase {

	/**
	 * Test that properties missing 'type' field get 'string' type added automatically.
	 */
	public function test_sanitize_adds_missing_type_field() {
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

		// Tool with a property missing 'type' field (like search-content's value parameter).
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'Test tool with missing type',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'normal_field' => array(
								'type'        => 'string',
								'description' => 'Field with type',
							),
							'value'        => array(
								'description' => 'Field without type - should get string added',
							),
						),
						'required'   => array( 'value' ),
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

		// Verify the 'value' property now has a 'type' field.
		$this->assertArrayHasKey( 'type', $declaration['parameters']['properties']['value'], 'Missing type field should be added automatically' );
		$this->assertEquals( 'string', $declaration['parameters']['properties']['value']['type'], 'Default type should be string' );

		// Verify normal fields are preserved.
		$this->assertEquals( 'string', $declaration['parameters']['properties']['normal_field']['type'] );

		// Verify required array is preserved.
		$this->assertEquals( array( 'value' ), $declaration['parameters']['required'] );
	}

	/**
	 * Test that enum values are not recursively processed.
	 */
	public function test_sanitize_preserves_enum_values() {
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
					'name'        => 'test_enum',
					'description' => 'Test enum handling',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'status' => array(
								'type'        => 'string',
								'enum'        => array( 'active', 'inactive', 'pending' ),
								'description' => 'Status value',
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

		// Verify enum is preserved as array of values.
		$this->assertArrayHasKey( 'enum', $declaration['parameters']['properties']['status'] );
		$this->assertIsArray( $declaration['parameters']['properties']['status']['enum'] );
		$this->assertEquals( array( 'active', 'inactive', 'pending' ), $declaration['parameters']['properties']['status']['enum'] );
	}

	/**
	 * Test that nested properties with missing types are handled correctly.
	 */
	public function test_sanitize_handles_nested_missing_types() {
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

		// Tool with nested array items that have properties with missing types.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_nested',
					'description' => 'Test nested properties',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'filters' => array(
								'type'        => 'array',
								'description' => 'Array of filter objects',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'key'   => array(
											'type'        => 'string',
											'description' => 'Filter key',
										),
										'value' => array(
											'description' => 'Filter value - missing type',
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

		// Verify nested 'value' property has type added.
		$nested_props = $declaration['parameters']['properties']['filters']['items']['properties'];
		$this->assertArrayHasKey( 'type', $nested_props['value'], 'Nested property should have type added' );
		$this->assertEquals( 'string', $nested_props['value']['type'], 'Nested property should default to string' );
	}
}
