<?php
/**
 * Tests for Gemini schema sanitization with type inference from structure.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Gemini_Client schema type inference.
 */
class WP_MCP_AI_Gemini_Schema_Type_Inference_Test extends WP_UnitTestCase {

	/**
	 * Test that properties with 'items' but no 'type' get 'array' type added.
	 */
	public function test_sanitize_infers_array_type_from_items() {
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

		// Tool with a property that has 'items' but no 'type'.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_array_inference',
					'description' => 'Test array type inference',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'tags' => array(
								'description' => 'Array of tags',
								'items'       => array(
									'type' => 'string',
								),
								// Note: No 'type' field - should be inferred as 'array'
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

		// Verify the 'tags' property now has 'type' set to 'array'.
		$this->assertArrayHasKey( 'type', $declaration['parameters']['properties']['tags'], 'Missing type field should be added automatically' );
		$this->assertEquals( 'array', $declaration['parameters']['properties']['tags']['type'], 'Type should be inferred as array when items is present' );

		// Verify 'items' is still present.
		$this->assertArrayHasKey( 'items', $declaration['parameters']['properties']['tags'] );
	}

	/**
	 * Test that properties with 'properties' but no 'type' get 'object' type added.
	 */
	public function test_sanitize_infers_object_type_from_properties() {
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

		// Tool with a property that has 'properties' but no 'type'.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_object_inference',
					'description' => 'Test object type inference',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'config' => array(
								'description' => 'Configuration object',
								'properties'  => array(
									'enabled' => array(
										'type'        => 'boolean',
										'description' => 'Whether enabled',
									),
								),
								// Note: No 'type' field - should be inferred as 'object'
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

		// Verify the 'config' property now has 'type' set to 'object'.
		$this->assertArrayHasKey( 'type', $declaration['parameters']['properties']['config'], 'Missing type field should be added automatically' );
		$this->assertEquals( 'object', $declaration['parameters']['properties']['config']['type'], 'Type should be inferred as object when properties is present' );

		// Verify 'properties' is still present.
		$this->assertArrayHasKey( 'properties', $declaration['parameters']['properties']['config'] );
	}

	/**
	 * Test that properties with neither 'items' nor 'properties' get 'string' type as default.
	 */
	public function test_sanitize_defaults_to_string_type() {
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

		// Tool with a property that has neither 'items' nor 'properties'.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_string_default',
					'description' => 'Test string type default',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'value' => array(
								'description' => 'A value field',
								// Note: No 'type', 'items', or 'properties' - should default to 'string'
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

		// Verify the 'value' property now has 'type' set to 'string'.
		$this->assertArrayHasKey( 'type', $declaration['parameters']['properties']['value'], 'Missing type field should be added automatically' );
		$this->assertEquals( 'string', $declaration['parameters']['properties']['value']['type'], 'Type should default to string' );
	}

	/**
	 * Test that nested properties in array items also get type inference.
	 */
	public function test_sanitize_handles_nested_type_inference() {
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

		// Tool with nested array containing object with properties missing types.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_nested_inference',
					'description' => 'Test nested type inference',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'filters' => array(
								'type'        => 'array',
								'description' => 'Array of filter objects',
								'items'       => array(
									// Note: items has 'properties' but no 'type'
									'properties' => array(
										'key'   => array(
											'type'        => 'string',
											'description' => 'Filter key',
										),
										'value' => array(
											'description' => 'Filter value - no type',
											// No type, items, or properties - should default to string
										),
										'values' => array(
											'description' => 'Multiple values',
											'items'       => array(
												'type' => 'string',
											),
											// Has items but no type - should infer array
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

		// Verify items object has type 'object' inferred.
		$items = $declaration['parameters']['properties']['filters']['items'];
		$this->assertArrayHasKey( 'type', $items, 'Items should have type field' );
		$this->assertEquals( 'object', $items['type'], 'Items should have type object when it has properties' );

		// Verify nested 'value' property has type 'string'.
		$this->assertArrayHasKey( 'type', $items['properties']['value'] );
		$this->assertEquals( 'string', $items['properties']['value']['type'] );

		// Verify nested 'values' property has type 'array'.
		$this->assertArrayHasKey( 'type', $items['properties']['values'] );
		$this->assertEquals( 'array', $items['properties']['values']['type'] );
	}

	/**
	 * Test that properties with explicit type are not modified.
	 */
	public function test_sanitize_preserves_explicit_types() {
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

		// Tool with properties that have explicit types.
		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_explicit_types',
					'description' => 'Test explicit type preservation',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'count'   => array(
								'type'        => 'integer',
								'description' => 'Count value',
							),
							'enabled' => array(
								'type'        => 'boolean',
								'description' => 'Boolean value',
							),
							'items'   => array(
								'type'        => 'array',
								'description' => 'Array value',
								'items'       => array(
									'type' => 'string',
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

		// Verify all explicit types are preserved.
		$this->assertEquals( 'integer', $declaration['parameters']['properties']['count']['type'] );
		$this->assertEquals( 'boolean', $declaration['parameters']['properties']['enabled']['type'] );
		$this->assertEquals( 'array', $declaration['parameters']['properties']['items']['type'] );
	}
}
