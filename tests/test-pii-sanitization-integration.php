<?php
/**
 * Integration test to verify PII sanitization works in agentic workflow.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that PII sanitization integrates properly with the agentic workflow.
 */
class WP_MCP_AI_PII_Sanitization_Integration_Test extends WP_UnitTestCase {

	/**
	 * Test that sanitized results can be properly JSON encoded for OpenAI.
	 */
	public function test_sanitized_results_json_encode_properly() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tools_to_test = array(
			'get_user_info',
			'get_site_summary',
			'search_gmail',
			'get_woo_recent_orders',
		);

		foreach ( $tools_to_test as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );

			// Skip tools that aren't available.
			if ( ! $tool || ! ( $tool instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) ) {
				continue;
			}

			// Test with various result types.
			$test_cases = array(
				'array'        => array( 'id' => 123, 'name' => 'test@example.com' ),
				'empty_array'  => array(),
				'string'       => 'Error message',
				'empty_string' => '',
			);

			foreach ( $test_cases as $case_name => $input ) {
				$sanitized = $tool->sanitize_for_llm( $input );

				// Verify it can be JSON encoded (what the REST API does).
				$json = is_string( $sanitized ) ? $sanitized : wp_json_encode( $sanitized );

				$this->assertNotFalse(
					$json,
					sprintf(
						'Tool %s should return JSON-encodable result for %s case',
						$tool_slug,
						$case_name
					)
				);

				// Verify valid JSON.
				if ( ! is_string( $sanitized ) ) {
					json_decode( $json );
					$this->assertSame(
						JSON_ERROR_NONE,
						json_last_error(),
						sprintf(
							'Tool %s should produce valid JSON for %s case',
							$tool_slug,
							$case_name
						)
					);
				}
			}
		}
	}

	/**
	 * Test that sanitization doesn't break when results have nested arrays.
	 */
	public function test_sanitized_nested_arrays() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Test WooCommerce orders which returns array of arrays.
		$tool = $registry->get_tool( 'get_woo_recent_orders' );
		if ( ! $tool ) {
			$this->markTestSkipped( 'WooCommerce orders tool not available' );
		}

		$nested_result = array(
			array(
				'id'            => 1,
				'billing_email' => 'customer1@example.com',
				'billing_name'  => 'Customer One',
				'total'         => 99.99,
			),
			array(
				'id'            => 2,
				'billing_email' => 'customer2@example.com',
				'billing_name'  => 'Customer Two',
				'total'         => 149.99,
			),
		);

		$sanitized = $tool->sanitize_for_llm( $nested_result );

		// Should still be an array.
		$this->assertIsArray( $sanitized );
		$this->assertCount( 2, $sanitized );

		// Each order should be sanitized.
		foreach ( $sanitized as $order ) {
			$this->assertIsArray( $order );
			$this->assertArrayNotHasKey( 'billing_email', $order );
			$this->assertArrayNotHasKey( 'billing_name', $order );
			$this->assertArrayHasKey( 'id', $order );
			$this->assertArrayHasKey( 'total', $order );
		}

		// Should JSON encode properly.
		$json = wp_json_encode( $sanitized );
		$this->assertNotFalse( $json );
		json_decode( $json );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
	}

	/**
	 * Test that empty results after sanitization are valid.
	 */
	public function test_empty_results_after_sanitization() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_woo_recent_orders' );
		if ( ! $tool ) {
			$this->markTestSkipped( 'WooCommerce orders tool not available' );
		}

		// Empty order list.
		$empty_orders = array();
		$sanitized    = $tool->sanitize_for_llm( $empty_orders );

		$this->assertIsArray( $sanitized );
		$this->assertEmpty( $sanitized );

		// Should encode to valid JSON.
		$json = wp_json_encode( $sanitized );
		$this->assertSame( '[]', $json );
	}

	/**
	 * Test that the OpenAI content field format is correct.
	 *
	 * This simulates what happens in the REST API when building tool messages.
	 */
	public function test_openai_tool_message_format() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_user_info' );
		$this->assertNotNull( $tool );

		// Simulate a tool result.
		$result = array(
			'ID'           => 123,
			'display_name' => 'Test User',
			'user_login'   => 'testuser',
			'user_email'   => 'test@example.com',
			'roles'        => array( 'subscriber' ),
			'summary'      => 'User: Test User',
		);

		// Sanitize for LLM.
		$sanitized = $tool->sanitize_for_llm( $result );

		// Build the tool message as the REST API does.
		$tool_message = array(
			'role'         => 'tool',
			'content'      => is_string( $sanitized ) ? $sanitized : wp_json_encode( $sanitized ),
			'tool_call_id' => 'call_123',
			'name'         => 'get_user_info',
		);

		// Verify the structure.
		$this->assertIsArray( $tool_message );
		$this->assertSame( 'tool', $tool_message['role'] );
		$this->assertIsString( $tool_message['content'] );
		$this->assertNotEmpty( $tool_message['content'] );
		$this->assertSame( 'call_123', $tool_message['tool_call_id'] );
		$this->assertSame( 'get_user_info', $tool_message['name'] );

		// Verify the content is valid JSON.
		$decoded = json_decode( $tool_message['content'], true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertIsArray( $decoded );

		// Verify PII was removed.
		$this->assertArrayNotHasKey( 'user_email', $decoded );

		// Verify essential data is present.
		$this->assertArrayHasKey( 'ID', $decoded );
		$this->assertArrayHasKey( 'display_name', $decoded );
	}
}
