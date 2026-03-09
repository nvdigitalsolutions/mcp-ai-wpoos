<?php
/**
 * Tests for LM Studio tool calling parity with OpenAI.
 *
 * @package WP_MCP_AI
 */

/**
 * Validates that WP_MCP_AI_LM_Studio_Client builds function-calling payloads
 * with the same schema sanitization and control flags as the OpenAI client.
 */
class WP_MCP_AI_LM_Studio_Tool_Parity_Test extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_LM_Studio_Client
	 */
	private $client;

	/**
	 * Set up a client instance with a fake endpoint for each test.
	 */
	public function set_up() {
		parent::set_up();
		remove_all_filters( 'pre_http_request' );

		$defaults                          = WP_MCP_AI_Admin_Settings::get_default_settings();
		$defaults['lm_studio_endpoint_url'] = 'http://127.0.0.1:1234';
		$defaults['lm_studio_model']        = 'llama-3-test';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $defaults );

		$this->client = new WP_MCP_AI_LM_Studio_Client();
	}

	/**
	 * Clean up HTTP filter after each test.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// sanitize_parameters_for_openai
	// -------------------------------------------------------------------------

	/**
	 * sanitize_parameters_for_openai must be accessible (protected, tested via reflection).
	 */
	public function test_sanitize_parameters_for_openai_method_exists() {
		$this->assertTrue( method_exists( $this->client, 'sanitize_parameters_for_openai' ) );
	}

	/**
	 * Root-level composition keywords must be stripped by sanitize_parameters_for_openai.
	 */
	public function test_sanitize_parameters_removes_root_composition_keywords() {
		$schema = array(
			'type'       => 'object',
			'oneOf'      => array( array( 'type' => 'string' ) ),
			'anyOf'      => array( array( 'type' => 'number' ) ),
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
		);

		$method = new ReflectionMethod( $this->client, 'sanitize_parameters_for_openai' );
		$method->setAccessible( true );
		$sanitized = $method->invoke( $this->client, $schema );

		$this->assertArrayNotHasKey( 'oneOf', $sanitized, 'oneOf should be removed at root level' );
		$this->assertArrayNotHasKey( 'anyOf', $sanitized, 'anyOf should be removed at root level' );
		$this->assertSame( 'object', $sanitized['type'] );
	}

	/**
	 * sanitize_parameters_for_openai must add type:object if missing at root.
	 */
	public function test_sanitize_parameters_adds_root_type_object() {
		$schema = array(
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
		);

		$method = new ReflectionMethod( $this->client, 'sanitize_parameters_for_openai' );
		$method->setAccessible( true );
		$sanitized = $method->invoke( $this->client, $schema );

		$this->assertSame( 'object', $sanitized['type'] );
	}

	/**
	 * Nested composition keywords must be preserved (they are valid inside properties).
	 */
	public function test_sanitize_parameters_preserves_nested_composition_keywords() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'value' => array(
					'anyOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'number' ),
					),
				),
			),
		);

		$method = new ReflectionMethod( $this->client, 'sanitize_parameters_for_openai' );
		$method->setAccessible( true );
		$sanitized = $method->invoke( $this->client, $schema );

		$this->assertArrayHasKey( 'anyOf', $sanitized['properties']['value'], 'anyOf in a nested property should be preserved' );
	}

	// -------------------------------------------------------------------------
	// normalise_tools_for_payload — schema sanitization via public surface
	// -------------------------------------------------------------------------

	/**
	 * normalise_tools_for_payload must call sanitize_parameters_for_openai so that
	 * root-level composition keywords are stripped from tool function parameters.
	 */
	public function test_normalise_tools_sanitizes_function_parameters() {
		$tools = array(
			array(
				'type'     => 'function',
				'name'     => 'test_tool',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'A test tool',
					'parameters'  => array(
						'type'       => 'object',
						'oneOf'      => array( array( 'type' => 'string' ) ),
						'properties' => array(
							'query' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);

		$method = new ReflectionMethod( $this->client, 'normalise_tools_for_payload' );
		$method->setAccessible( true );
		$normalised = $method->invoke( $this->client, $tools );

		$this->assertCount( 1, $normalised );
		$params = $normalised[0]['function']['parameters'];
		$this->assertArrayNotHasKey( 'oneOf', $params, 'Root-level oneOf must be removed from function parameters' );
	}

	// -------------------------------------------------------------------------
	// tool_choice and parallel_tool_calls in build_payload
	// -------------------------------------------------------------------------

	/**
	 * tool_choice => 'auto' must appear in the outgoing request body.
	 */
	public function test_build_payload_includes_tool_choice_auto() {
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'ok',
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$messages = array( array( 'role' => 'user', 'content' => 'Use a tool' ) );
		$options  = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'name'     => 'my_tool',
					'function' => array(
						'name'        => 'my_tool',
						'description' => 'Test',
						'parameters'  => array( 'type' => 'object', 'properties' => array() ),
					),
				),
			),
			'tool_choice' => 'auto',
		);

		$this->client->create_chat_completion( $messages, $options );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'tool_choice', $payload_sent );
		$this->assertSame( 'auto', $payload_sent['tool_choice'] );
	}

	/**
	 * tool_choice => 'none' must appear in the outgoing request body.
	 */
	public function test_build_payload_includes_tool_choice_none() {
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'ok',
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$messages = array( array( 'role' => 'user', 'content' => 'No tools' ) );
		$options  = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'name'     => 'my_tool',
					'function' => array(
						'name'        => 'my_tool',
						'description' => 'Test',
						'parameters'  => array( 'type' => 'object', 'properties' => array() ),
					),
				),
			),
			'tool_choice' => 'none',
		);

		$this->client->create_chat_completion( $messages, $options );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'tool_choice', $payload_sent );
		$this->assertSame( 'none', $payload_sent['tool_choice'] );
	}

	/**
	 * A specific function tool_choice must be structured correctly.
	 */
	public function test_build_payload_includes_specific_function_tool_choice() {
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'ok',
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$messages = array( array( 'role' => 'user', 'content' => 'Call specific tool' ) );
		$options  = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'name'     => 'specific_tool',
					'function' => array(
						'name'        => 'specific_tool',
						'description' => 'A specific tool',
						'parameters'  => array( 'type' => 'object', 'properties' => array() ),
					),
				),
			),
			'tool_choice' => array(
				'type'     => 'function',
				'function' => array( 'name' => 'specific_tool' ),
			),
		);

		$this->client->create_chat_completion( $messages, $options );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'tool_choice', $payload_sent );
		$this->assertSame( 'function', $payload_sent['tool_choice']['type'] );
		$this->assertSame( 'specific_tool', $payload_sent['tool_choice']['function']['name'] );
	}

	/**
	 * parallel_tool_calls => false must appear in the outgoing request body.
	 */
	public function test_build_payload_includes_parallel_tool_calls() {
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'ok',
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$messages = array( array( 'role' => 'user', 'content' => 'Parallel tools' ) );
		$options  = array(
			'tools'               => array(
				array(
					'type'     => 'function',
					'name'     => 'my_tool',
					'function' => array(
						'name'        => 'my_tool',
						'description' => 'Test',
						'parameters'  => array( 'type' => 'object', 'properties' => array() ),
					),
				),
			),
			'parallel_tool_calls' => false,
		);

		$this->client->create_chat_completion( $messages, $options );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayHasKey( 'parallel_tool_calls', $payload_sent );
		$this->assertFalse( $payload_sent['parallel_tool_calls'] );
	}

	/**
	 * tool_choice must not appear in the payload when no tools are provided.
	 */
	public function test_tool_choice_absent_when_no_tools() {
		$payload_sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$payload_sent ) {
				$payload_sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'role'    => 'assistant',
										'content' => 'ok',
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$messages = array( array( 'role' => 'user', 'content' => 'No tools here' ) );
		$this->client->create_chat_completion( $messages, array( 'tool_choice' => 'auto' ) );

		$this->assertNotNull( $payload_sent );
		$this->assertArrayNotHasKey( 'tool_choice', $payload_sent, 'tool_choice must be absent when tools array is empty' );
	}
}
