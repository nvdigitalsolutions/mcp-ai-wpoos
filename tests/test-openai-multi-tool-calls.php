<?php
/**
 * Tests for OpenAI multi-tool call handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test multi-tool call scenarios with the OpenAI client.
 */
class WP_MCP_AI_OpenAI_Multi_Tool_Calls_Test extends WP_UnitTestCase {

	/**
	 * Test that should_use_responses_api returns false when tool calls are present.
	 */
	public function test_should_use_responses_api_false_with_tool_calls() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'should_use_responses_api' );
		$method->setAccessible( true );

		// Test with assistant message containing tool_calls.
		$messages_with_tool_calls = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{}',
						),
					),
				),
			),
		);

		$result = $method->invoke( $client, $messages_with_tool_calls, array() );
		$this->assertFalse( $result, 'should_use_responses_api should return false when tool_calls are present' );
	}

	/**
	 * Test that should_use_responses_api returns false when tool messages are present.
	 */
	public function test_should_use_responses_api_false_with_tool_messages() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'should_use_responses_api' );
		$method->setAccessible( true );

		$messages_with_tool_role = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			),
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'get_weather',
							'arguments' => '{}',
						),
					),
				),
			),
			array(
				'role'         => 'tool',
				'tool_call_id' => 'call_123',
				'content'      => 'Result',
			),
		);

		$result = $method->invoke( $client, $messages_with_tool_role, array() );
		$this->assertFalse( $result, 'should_use_responses_api should return false when tool role messages are present' );
	}

	/**
	 * Test that should_use_responses_api returns true when attachments are present and no tool calls.
	 */
	public function test_should_use_responses_api_true_with_attachments_no_tools() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'should_use_responses_api' );
		$method->setAccessible( true );

		$messages_with_attachments = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			),
		);

		$options = array(
			'attachments' => array(
				array(
					'id'   => 'file-123',
					'type' => 'image',
				),
			),
		);

		$result = $method->invoke( $client, $messages_with_attachments, $options );
		$this->assertTrue( $result, 'should_use_responses_api should return true when attachments are present without tool calls' );
	}

	/**
	 * Test that multiple tool calls are correctly preserved in message structure.
	 */
	public function test_multiple_tool_calls_preserved_in_normalization() {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['openai_api_key'] = 'sk-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$client = new WP_MCP_AI_OpenAI_Client();

		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'normalise_messages_for_payload' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => array(),
				'tool_calls' => array(
					array(
						'id'       => 'call_1',
						'type'     => 'function',
						'function' => array(
							'name'      => 'tool_a',
							'arguments' => '{}',
						),
					),
					array(
						'id'       => 'call_2',
						'type'     => 'function',
						'function' => array(
							'name'      => 'tool_b',
							'arguments' => '{}',
						),
					),
				),
			),
		);

		$normalised = $method->invoke( $client, $messages );

		$this->assertCount( 1, $normalised );
		$this->assertArrayHasKey( 'tool_calls', $normalised[0], 'tool_calls should be preserved' );
		$this->assertCount( 2, $normalised[0]['tool_calls'], 'Both tool calls should be preserved' );
		$this->assertSame( 'call_1', $normalised[0]['tool_calls'][0]['id'] );
		$this->assertSame( 'call_2', $normalised[0]['tool_calls'][1]['id'] );
	}
}
