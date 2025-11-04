<?php
/**
 * Tests for the Model Rate Limits CCT.
 */
class WP_MCP_AI_Model_Rate_Limits_CCT_Test extends WP_UnitTestCase {

	/**
	 * Test CCT slug retrieval.
	 */
	public function test_get_slug() {
		$slug = WP_MCP_AI_Model_Rate_Limits_CCT::get_slug();
		$this->assertSame( 'ai_model_rate_limits', $slug );
	}

	/**
	 * Test default model data structure.
	 */
	public function test_default_model_data_structure() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$this->assertIsArray( $default_models );
		$this->assertNotEmpty( $default_models );

		// Check first model has required fields.
		$first_model = $default_models[0];
		$this->assertArrayHasKey( 'model_name', $first_model );
		$this->assertArrayHasKey( 'provider', $first_model );
		$this->assertArrayHasKey( 'tpm_limit', $first_model );
		$this->assertArrayHasKey( 'context_window', $first_model );
	}

	/**
	 * Test that OpenAI models are included in defaults.
	 */
	public function test_default_models_include_openai() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$openai_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'openai' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $openai_models );

		// Check for specific models.
		$model_names = array_column( $openai_models, 'model_name' );
		$this->assertContains( 'gpt-4o', $model_names );
		$this->assertContains( 'gpt-4o-mini', $model_names );
		$this->assertContains( 'o1-preview', $model_names );
	}

	/**
	 * Test that Google models are included in defaults.
	 */
	public function test_default_models_include_google() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$google_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'google' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $google_models );

		// Check for specific models.
		$model_names = array_column( $google_models, 'model_name' );
		$this->assertContains( 'gemini-1.5-pro', $model_names );
		$this->assertContains( 'gemini-1.5-flash', $model_names );
		$this->assertContains( 'gemini-2.0-flash', $model_names );
	}

	/**
	 * Test that Anthropic models are included in defaults.
	 */
	public function test_default_models_include_anthropic() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$anthropic_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'anthropic' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $anthropic_models );

		// Check for specific models.
		$model_names = array_column( $anthropic_models, 'model_name' );
		$this->assertContains( 'claude-3.5-sonnet', $model_names );
		$this->assertContains( 'claude-3-opus', $model_names );
		$this->assertContains( 'claude-3-haiku', $model_names );
	}

	/**
	 * Test that TPM limits are reasonable.
	 */
	public function test_default_models_have_valid_tpm_limits() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		foreach ( $default_models as $model ) {
			$this->assertArrayHasKey( 'tpm_limit', $model );
			$this->assertIsInt( $model['tpm_limit'] );
			$this->assertGreaterThan( 0, $model['tpm_limit'] );
			$this->assertLessThanOrEqual( 10000000, $model['tpm_limit'] );
		}
	}

	/**
	 * Test that context windows are reasonable.
	 */
	public function test_default_models_have_valid_context_windows() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		foreach ( $default_models as $model ) {
			$this->assertArrayHasKey( 'context_window', $model );
			$this->assertIsInt( $model['context_window'] );
			$this->assertGreaterThan( 0, $model['context_window'] );
			// Max context should be less than 3 million tokens.
			$this->assertLessThanOrEqual( 3000000, $model['context_window'] );
		}
	}

	/**
	 * Test token budget manager integration for TPM limits.
	 */
	public function test_token_budget_manager_tpm_integration() {
		// This test checks that the Token Budget Manager can retrieve TPM limits.
		$tpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( 'gpt-4o' );

		// Should return null if CCT not populated, or a number if it is.
		$this->assertTrue( is_null( $tpm_limit ) || is_int( $tpm_limit ) );

		if ( is_int( $tpm_limit ) ) {
			$this->assertGreaterThan( 0, $tpm_limit );
		}
	}

	/**
	 * Test token budget manager integration for RPM limits.
	 */
	public function test_token_budget_manager_rpm_integration() {
		// This test checks that the Token Budget Manager can retrieve RPM limits.
		$rpm_limit = WP_MCP_AI_Token_Budget_Manager::get_model_rpm_limit( 'gpt-4o' );

		// Should return null if CCT not populated, or a number if it is.
		$this->assertTrue( is_null( $rpm_limit ) || is_int( $rpm_limit ) );

		if ( is_int( $rpm_limit ) ) {
			$this->assertGreaterThan( 0, $rpm_limit );
		}
	}

	/**
	 * Test token budget manager integration for context window.
	 */
	public function test_token_budget_manager_context_window_integration() {
		// This test checks that the Token Budget Manager still returns context windows.
		$context_window = WP_MCP_AI_Token_Budget_Manager::get_model_limit( 'gpt-4o' );

		$this->assertIsInt( $context_window );
		$this->assertGreaterThan( 0, $context_window );
	}

	/**
	 * Test that all default models have required capability flags.
	 */
	public function test_default_models_have_capability_flags() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		foreach ( $default_models as $model ) {
			$this->assertArrayHasKey( 'supports_streaming', $model );
			$this->assertArrayHasKey( 'supports_function_calling', $model );
			$this->assertArrayHasKey( 'supports_vision', $model );
			$this->assertIsBool( $model['supports_streaming'] );
			$this->assertIsBool( $model['supports_function_calling'] );
			$this->assertIsBool( $model['supports_vision'] );
		}
	}

	/**
	 * Test that pricing data is included.
	 */
	public function test_default_models_have_pricing() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		foreach ( $default_models as $model ) {
			if ( isset( $model['cost_per_1k_input_tokens'] ) ) {
				$this->assertIsFloat( $model['cost_per_1k_input_tokens'] );
				$this->assertGreaterThanOrEqual( 0, $model['cost_per_1k_input_tokens'] );
			}

			if ( isset( $model['cost_per_1k_output_tokens'] ) ) {
				$this->assertIsFloat( $model['cost_per_1k_output_tokens'] );
				$this->assertGreaterThanOrEqual( 0, $model['cost_per_1k_output_tokens'] );
			}
		}
	}

	/**
	 * Test that gpt-4o has correct TPM limit from research.
	 */
	public function test_gpt4o_has_correct_tpm_limit() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$gpt4o_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'gpt-4o' === $model['model_name'] && 'openai' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $gpt4o_models );

		$gpt4o = reset( $gpt4o_models );
		// According to the problem statement, the default tier has 30,000 TPM.
		$this->assertSame( 30000, $gpt4o['tpm_limit'] );
	}

	/**
	 * Test that Gemini models have large context windows.
	 */
	public function test_gemini_has_large_context_windows() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$gemini_pro = array_filter(
			$default_models,
			function ( $model ) {
				return 'gemini-1.5-pro' === $model['model_name'];
			}
		);

		$this->assertNotEmpty( $gemini_pro );

		$model = reset( $gemini_pro );
		// Gemini 1.5 Pro has 2M context window.
		$this->assertGreaterThan( 1000000, $model['context_window'] );
	}
}
