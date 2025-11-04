<?php
/**
 * Test WP_MCP_AI_Model_Selector class.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Model_Selector
 */
class Test_WP_MCP_AI_Model_Selector extends WP_UnitTestCase {

	/**
	 * Test that light tasks route to gpt-4o-mini.
	 */
	public function test_light_task_routes_to_mini() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the capital of France?',
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );

		$this->assertEquals( 'gpt-4o-mini', $model, 'Simple question should route to gpt-4o-mini' );
	}

	/**
	 * Test that complex keywords trigger gpt-4o.
	 */
	public function test_complex_keywords_route_to_gpt4o() {
		$complex_prompts = array(
			'Please provide a detailed analysis of the current economic situation.',
			'Write a comprehensive guide on machine learning.',
			'Analyze this data in-depth and provide insights.',
			'Create a sophisticated solution for this problem.',
			'I need a thorough research on this topic.',
		);

		foreach ( $complex_prompts as $prompt ) {
			$messages = array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);

			$model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );

			$this->assertEquals( 'gpt-4o', $model, "Complex prompt should route to gpt-4o: {$prompt}" );
		}
	}

	/**
	 * Test that long content triggers gpt-4o.
	 */
	public function test_long_content_routes_to_gpt4o() {
		// Generate a long message (over 4000 tokens = ~16000 chars).
		$long_text = str_repeat( 'This is a long document with lots of content. ', 400 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $long_text,
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );

		$this->assertEquals( 'gpt-4o', $model, 'Long content should route to gpt-4o' );
	}

	/**
	 * Test that explicit model preference is respected.
	 */
	public function test_explicit_model_respected() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$options = array(
			'model' => 'gpt-4',
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		$this->assertEquals( 'gpt-4', $model, 'Explicit model should be respected' );
	}

	/**
	 * Test that use_advanced_model flag triggers gpt-4o.
	 */
	public function test_advanced_flag_routes_to_gpt4o() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Simple question',
			),
		);

		$options = array(
			'use_advanced_model' => true,
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		$this->assertEquals( 'gpt-4o', $model, 'use_advanced_model flag should route to gpt-4o' );
	}

	/**
	 * Test that multiple tools trigger gpt-4o.
	 */
	public function test_multiple_tools_route_to_gpt4o() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Help me with this task',
			),
		);

		$options = array(
			'tools' => array(
				array( 'name' => 'tool1' ),
				array( 'name' => 'tool2' ),
				array( 'name' => 'tool3' ),
				array( 'name' => 'tool4' ),
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		$this->assertEquals( 'gpt-4o', $model, 'Multiple tools should route to gpt-4o' );
	}

	/**
	 * Test that structured output triggers gpt-4o.
	 */
	public function test_structured_output_routes_to_gpt4o() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Extract information',
			),
		);

		$options = array(
			'response_format' => array(
				'type' => 'json_object',
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		$this->assertEquals( 'gpt-4o', $model, 'Structured output should route to gpt-4o' );
	}

	/**
	 * Test that disable_auto_routing works.
	 */
	public function test_disable_auto_routing() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Please provide a detailed analysis.',
			),
		);

		$options = array(
			'disable_auto_routing' => true,
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		$this->assertEquals( 'gpt-4o-mini', $model, 'Disabled auto-routing should always return default light model' );
	}

	/**
	 * Test routing with conversation history.
	 */
	public function test_routing_with_conversation_history() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi there!',
			),
			array(
				'role'    => 'user',
				'content' => 'Analyze this complex situation in detail.',
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );

		$this->assertEquals( 'gpt-4o', $model, 'Should analyze latest user message for complexity' );
	}

	/**
	 * Test routing with multipart content.
	 */
	public function test_routing_with_multipart_content() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Please provide comprehensive analysis of this image.',
					),
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => 'https://example.com/image.jpg',
						),
					),
				),
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );

		$this->assertEquals( 'gpt-4o', $model, 'Complex keywords in multipart message should route to gpt-4o' );
	}
}
