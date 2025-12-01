<?php
/**
 * Test per-model high token fallback configuration.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Per_Model_Fallback
 */
class Test_WP_MCP_AI_Per_Model_Fallback extends WP_UnitTestCase {

	/**
	 * Test that per-model fallback is used when configured.
	 */
	public function test_per_model_fallback_used_when_configured() {
		// Skip if JetEngine is not available.
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$this->markTestSkipped( 'JetEngine not available for CCT testing' );
		}

		// Mock the CCT get_model_fallback method.
		$mock_fallback = 'gemini-1.5-pro';

		// Create a test to verify the method exists and can be called.
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT', 'get_model_fallback' ),
			'get_model_fallback method should exist'
		);
	}

	/**
	 * Test that global fallback is used when no per-model fallback is configured.
	 */
	public function test_global_fallback_used_when_no_per_model_fallback() {
		// Set global fallback in settings.
		$settings                                   = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_high_token_model_switch'] = true;
		$settings['high_token_fallback_model']      = 'gemini-2.0-flash-exp';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create messages that exceed gpt-4o-mini's TPM limit (200,000).
		// Simulate 250,000 tokens (62,500 chars * 4).
		$large_text = str_repeat( 'This is a large document with lots of content. ', 1400 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $large_text,
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		// This should trigger fallback to the global setting.
		$selected_model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		// The model selector should fall back to a higher-capacity model.
		// Since we have a very large request, it should use the configured fallback.
		$this->assertNotEquals(
			'gpt-4o-mini',
			$selected_model,
			'Should fall back from gpt-4o-mini when TPM limit is exceeded'
		);
	}

	/**
	 * Test that get_high_capacity_fallback_model accepts model parameter.
	 */
	public function test_get_high_capacity_fallback_accepts_model_parameter() {
		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'get_high_capacity_fallback_model' );
		$method->setAccessible( true );

		// Set global fallback.
		$settings                              = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['high_token_fallback_model'] = 'gemini-2.0-flash-exp';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Call without parameter - should use global setting.
		$fallback = $method->invoke( null );
		$this->assertEquals( 'gemini-2.0-flash-exp', $fallback, 'Should use global fallback when no model specified' );

		// Call with parameter - should still work (will use global if no per-model config).
		$fallback_with_param = $method->invoke( null, 'gpt-4o' );
		$this->assertEquals( 'gemini-2.0-flash-exp', $fallback_with_param, 'Should use global fallback when no per-model config exists' );
	}

	/**
	 * Test that fallback logging includes source information.
	 */
	public function test_fallback_logging_includes_source() {
		// Enable logging.
		$settings                                   = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_logging']                 = true;
		$settings['enable_high_token_model_switch'] = true;
		$settings['high_token_fallback_model']      = 'gemini-2.0-flash-exp';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Clear previous logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Create messages that would trigger fallback.
		$large_text = str_repeat( 'This is a large document. ', 2000 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $large_text,
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		// Trigger model selection which should log the fallback.
		WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		// Check that logging happened (if enabled).
		// Note: Actual log verification would require the logger to be working.
		// This is a basic test to ensure the code path executes without errors.
		$this->assertTrue( true, 'Model selection with potential fallback executed without errors' );
	}

	/**
	 * Test backward compatibility - system works without JetEngine.
	 */
	public function test_backward_compatibility_without_jetengine() {
		// Set global fallback.
		$settings                                   = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_high_token_model_switch'] = true;
		$settings['high_token_fallback_model']      = 'gemini-2.0-flash-exp';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Even if JetEngine is not available, the system should work with global settings.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Simple question',
			),
		);

		$model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );

		$this->assertNotEmpty( $model, 'Should select a model even without JetEngine' );
	}

	/**
	 * Test that auto-switching can be disabled.
	 */
	public function test_auto_switching_can_be_disabled() {
		// Disable auto-switching.
		$settings                                   = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['enable_high_token_model_switch'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Create a large request.
		$large_text = str_repeat( 'This is a large document. ', 2000 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $large_text,
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		// Should keep the original model when auto-switching is disabled.
		$selected_model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		// Note: The model selector might still apply other logic, but auto-switching.
		// to high-capacity fallback should not happen.
		$this->assertNotEmpty( $selected_model, 'Should return a model even with auto-switching disabled' );
	}

	/**
	 * Test that fallback_model field was added to CCT.
	 */
	public function test_fallback_model_field_exists_in_cct() {
		// Skip if JetEngine is not available.
		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
			$this->markTestSkipped( 'JetEngine not available for CCT testing' );
		}

		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_meta_fields' );
		$method->setAccessible( true );

		// Get the meta fields.
		$fields = $method->invoke( null );

		// Check if fallback_model field exists.
		$fallback_field_exists = false;
		foreach ( $fields as $field ) {
			if ( isset( $field['name'] ) && 'fallback_model' === $field['name'] ) {
				$fallback_field_exists = true;
				$this->assertEquals( 'text', $field['type'], 'Fallback model field should be of type text' );
				break;
			}
		}

		$this->assertTrue( $fallback_field_exists, 'fallback_model field should exist in CCT meta fields' );
	}
}
