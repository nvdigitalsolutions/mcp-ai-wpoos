<?php
/**
 * Tests for TPM limit validation and model fallback logic.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test TPM limit validation functionality.
 */
class Test_TPM_Limit_Validation extends WP_UnitTestCase {

	/**
	 * Test that validate_tpm_limit returns true when within limits.
	 */
	public function test_validate_tpm_limit_within_limits() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
		);

		$model             = 'gpt-4o-mini';
		$max_output_tokens = 1000;

		$result = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

		$this->assertTrue( $result, 'Validation should pass for small request within TPM limits.' );
	}

	/**
	 * Test that validate_tpm_limit returns WP_Error when exceeding limits.
	 */
	public function test_validate_tpm_limit_exceeds_limits() {
		// Create a very large message that will exceed gpt-4o-mini's 200k TPM limit.
		$large_content = str_repeat( 'This is a test message with lots of content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model             = 'gpt-4o-mini';
		$max_output_tokens = 16000;

		$result = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

		$this->assertInstanceOf( 'WP_Error', $result, 'Validation should return WP_Error when exceeding TPM limits.' );
		$this->assertEquals( 'wp_mcp_ai_tpm_limit_exceeded', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertArrayHasKey( 'tpm_limit', $error_data );
		$this->assertArrayHasKey( 'requested_tokens', $error_data );
		$this->assertArrayHasKey( 'suggested_models', $error_data );
		$this->assertGreaterThan( $error_data['tpm_limit'], $error_data['requested_tokens'] );
	}

	/**
	 * Test that validate_tpm_limit skips validation for models without TPM limits.
	 */
	public function test_validate_tpm_limit_skips_for_local_models() {
		$large_content = str_repeat( 'Large content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model             = 'llama3'; // Local model with no TPM limit.
		$max_output_tokens = 16000;

		$result = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

		$this->assertTrue( $result, 'Validation should pass for local models without TPM limits.' );
	}

	/**
	 * Test that get_higher_limit_models suggests appropriate fallbacks for OpenAI models.
	 */
	public function test_get_higher_limit_models_openai() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Token_Budget_Manager' );
		$method     = $reflection->getMethod( 'get_higher_limit_models' );
		$method->setAccessible( true );

		$current_model   = 'gpt-4o-mini';
		$required_tokens = 250000; // Exceeds gpt-4o-mini's 200k limit but within gpt-4.1-mini's 400k.

		$suggested = $method->invoke( null, $current_model, $required_tokens );

		$this->assertIsArray( $suggested );
		$this->assertNotEmpty( $suggested );
		$this->assertContains( 'gpt-4.1-mini', $suggested, 'Should suggest gpt-4.1-mini for 250k tokens.' );
	}

	/**
	 * Test that get_higher_limit_models suggests Gemini for very large requests.
	 */
	public function test_get_higher_limit_models_suggests_gemini() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Token_Budget_Manager' );
		$method     = $reflection->getMethod( 'get_higher_limit_models' );
		$method->setAccessible( true );

		$current_model   = 'gpt-4o-mini';
		$required_tokens = 600000; // Very large, exceeds most OpenAI models.

		$suggested = $method->invoke( null, $current_model, $required_tokens );

		$this->assertIsArray( $suggested );
		$this->assertNotEmpty( $suggested );
		$this->assertTrue(
			in_array( 'gemini-1.5-flash', $suggested, true ) || in_array( 'gemini-2.0-flash', $suggested, true ),
			'Should suggest Gemini for very large requests (> 200k tokens).'
		);
	}

	/**
	 * Test model selector fallback for TPM-constrained models.
	 */
	public function test_model_selector_fallback_for_tpm_exceeded() {
		// Create a large request that would exceed gpt-4o-mini's TPM limit.
		$large_content = str_repeat( 'This is test content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		$selected_model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		// Should fallback to a model with higher limits.
		$this->assertNotEquals( 'gpt-4o-mini', $selected_model, 'Should fallback from gpt-4o-mini for large requests.' );
		$this->assertContains(
			$selected_model,
			array( 'gpt-4o', 'gemini-2.0-flash', 'gemini-2.0-flash-exp', 'gemini-1.5-flash' ),
			'Should fallback to a higher-capacity model.'
		);
	}

	/**
	 * Test model selector does not fallback when within limits.
	 */
	public function test_model_selector_no_fallback_within_limits() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		$selected_model = WP_MCP_AI_Model_Selector::select_model( $messages, $options );

		$this->assertEquals( 'gpt-4o-mini', $selected_model, 'Should keep gpt-4o-mini for small requests.' );
	}

	/**
	 * Test that check_tpm_and_suggest_fallback returns original model when within limits.
	 */
	public function test_check_tpm_and_suggest_fallback_within_limits() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'check_tpm_and_suggest_fallback' );
		$method->setAccessible( true );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, this is a small message.',
			),
		);

		$model   = 'gpt-4o-mini';
		$options = array();

		$result = $method->invoke( null, $messages, $model, $options );

		$this->assertEquals( $model, $result, 'Should return original model when within TPM limits.' );
	}

	/**
	 * Test that check_tpm_and_suggest_fallback suggests fallback when exceeding limits.
	 */
	public function test_check_tpm_and_suggest_fallback_exceeds_limits() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'check_tpm_and_suggest_fallback' );
		$method->setAccessible( true );

		$large_content = str_repeat( 'Large test content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model   = 'gpt-4o-mini';
		$options = array();

		$result = $method->invoke( null, $messages, $model, $options );

		$this->assertNotEquals( $model, $result, 'Should suggest fallback model when exceeding TPM limits.' );
		$this->assertContains(
			$result,
			array( 'gpt-4o', 'gemini-2.0-flash', 'gemini-2.0-flash-exp', 'gemini-1.5-flash' ),
			'Should suggest a higher-capacity model.'
		);
	}

	/**
	 * Test that TPM validation is integrated into Enhanced OpenAI Client.
	 */
	public function test_enhanced_openai_client_tpm_validation() {
		$large_content = str_repeat( 'This is large content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$options = array(
			'model'      => 'gpt-4o-mini',
			'max_tokens' => 16000,
		);

		$client = new WP_MCP_AI_Enhanced_OpenAI_Client();
		$result = $client->create_chat_completion( $messages, $options );

		// Should return WP_Error due to TPM limit exceeded.
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error when TPM limit exceeded.' );
		$this->assertEquals( 'wp_mcp_ai_tpm_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Test TPM limit retrieval for various models.
	 */
	public function test_get_model_tpm_limit() {
		// Test OpenAI models.
		$gpt4o_mini_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'gpt-4o-mini' );
		$this->assertEquals( 200000, $gpt4o_mini_limit, 'gpt-4o-mini should have 200k TPM limit.' );

		$gpt4o_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'gpt-4o' );
		$this->assertEquals( 30000, $gpt4o_limit, 'gpt-4o should have 30k TPM limit (Tier 1).' );

		// Test Gemini models.
		$gemini_flash_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'gemini-1.5-flash' );
		$this->assertEquals( 1000000, $gemini_flash_limit, 'gemini-1.5-flash should have 1M TPM limit.' );

		// Test local models (no TPM limit).
		$llama3_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'llama3' );
		$this->assertNull( $llama3_limit, 'llama3 should have no TPM limit (local model).' );
	}

	/**
	 * Test error message includes helpful suggestions.
	 */
	public function test_tpm_error_includes_suggestions() {
		$large_content = str_repeat( 'Content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model             = 'gpt-4o-mini';
		$max_output_tokens = 16000;

		$result = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

		$this->assertInstanceOf( 'WP_Error', $result );

		$error_data = $result->get_error_data();
		$this->assertArrayHasKey( 'suggested_models', $error_data );
		$this->assertIsArray( $error_data['suggested_models'] );
		$this->assertNotEmpty( $error_data['suggested_models'], 'Should provide suggested alternative models.' );

		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'Request too large', $error_message );
		$this->assertStringContainsString( 'Limit:', $error_message );
		$this->assertStringContainsString( 'Requested:', $error_message );
	}

	/**
	 * Test that configured high-capacity fallback model is used when enabled.
	 */
	public function test_high_capacity_fallback_model_is_used() {
		// Set custom high-capacity fallback model in settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_high_token_model_switch' => true,
				'high_token_fallback_model'      => 'gemini-2.0-flash-exp',
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'check_tpm_and_suggest_fallback' );
		$method->setAccessible( true );

		// Create a large request that exceeds gpt-4o-mini's 200k TPM limit.
		$large_content = str_repeat( 'Large test content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model   = 'gpt-4o-mini';
		$options = array();

		$result = $method->invoke( null, $messages, $model, $options );

		// Should use the configured high-capacity fallback model.
		$this->assertEquals(
			'gemini-2.0-flash-exp',
			$result,
			'Should use configured high-capacity fallback model (gemini-2.0-flash-exp) for large requests.'
		);
	}

	/**
	 * Test that high-capacity fallback uses default when setting is disabled.
	 */
	public function test_high_capacity_fallback_disabled() {
		// Disable high-capacity fallback in settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_high_token_model_switch' => false,
				'high_token_fallback_model'      => 'gemini-2.0-flash-exp',
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'check_tpm_and_suggest_fallback' );
		$method->setAccessible( true );

		// Create a large request that exceeds gpt-4o's 30k TPM limit.
		$large_content = str_repeat( 'Very large test content. ', 10000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model   = 'gpt-4o';
		$options = array();

		$result = $method->invoke( null, $messages, $model, $options );

		// Should use the fallback logic (not the configured high-capacity model).
		// For gpt-4o with > 30k tokens, it should fallback to get_high_capacity_fallback_model().
		$this->assertContains(
			$result,
			array( 'gemini-2.0-flash-exp', 'gemini-2.0-flash', 'gemini-1.5-flash' ),
			'Should use default fallback logic when high-capacity model switch is disabled.'
		);
	}

	/**
	 * Test get_high_capacity_fallback_model method returns configured model.
	 */
	public function test_get_high_capacity_fallback_model() {
		// Set custom high-capacity fallback model.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'high_token_fallback_model' => 'gemini-2.0-flash-exp',
			)
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'get_high_capacity_fallback_model' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertEquals(
			'gemini-2.0-flash-exp',
			$result,
			'Should return configured high-capacity fallback model.'
		);
	}

	/**
	 * Test get_high_capacity_fallback_model returns default when not configured.
	 */
	public function test_get_high_capacity_fallback_model_default() {
		// Clear settings to test default behavior.
		delete_option( 'wp_mcp_ai_settings' );

		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Selector' );
		$method     = $reflection->getMethod( 'get_high_capacity_fallback_model' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertEquals(
			'gemini-2.0-flash-exp',
			$result,
			'Should return default high-capacity fallback model (gemini-2.0-flash-exp).'
		);
	}
}
