<?php
/**
 * Tests for the Model Rate Limits CCT.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		$this->assertContains( 'gpt-4.1', $model_names );
		$this->assertContains( 'gpt-4.1-mini', $model_names );
		$this->assertContains( 'gpt-4.1-nano', $model_names );
		$this->assertContains( 'gpt-4.1-turbo', $model_names );
		$this->assertContains( 'gpt-5.2', $model_names );
		$this->assertContains( 'gpt-5.3-codex', $model_names );
		$this->assertContains( 'gpt-5.1', $model_names );
		$this->assertContains( 'gpt-5', $model_names );
		$this->assertContains( 'gpt-5-mini', $model_names );
	}

	/**
	 * Test that Gemini models are included in defaults.
	 */
	public function test_default_models_include_gemini() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$gemini_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'gemini' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $gemini_models );

		// Check for specific models.
		$model_names = array_column( $gemini_models, 'model_name' );
		$this->assertContains( 'gemini-2.5-flash', $model_names );
		$this->assertContains( 'gemini-2.5-pro', $model_names );
		$this->assertContains( 'gemini-3-flash-preview', $model_names );
		$this->assertContains( 'gemini-3.5-flash', $model_names );
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
		$this->assertContains( 'claude-opus-4-6', $model_names );
		$this->assertContains( 'claude-sonnet-4-6', $model_names );
		$this->assertContains( 'claude-3-5-sonnet-20241022', $model_names );
	}

	/**
	 * Test that NVIDIA NIM models are included in defaults.
	 */
	public function test_default_models_include_nvidia() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$nvidia_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'nvidia' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $nvidia_models );

		// Check for specific models across different families.
		$model_names = array_column( $nvidia_models, 'model_name' );
		$this->assertContains( 'meta/llama-3.3-70b-instruct', $model_names );
		$this->assertContains( 'meta/llama-4-1-maverick-17b-128e-instruct', $model_names );
		$this->assertContains( 'nvidia/llama-3.1-nemotron-70b-instruct', $model_names );
		$this->assertContains( 'nvidia/nemotron-3-super-120b-a12b', $model_names );
		$this->assertContains( 'nvidia/nemotron-4-340b-instruct', $model_names );
		$this->assertContains( 'mistralai/mistral-large-2-instruct', $model_names );
		$this->assertContains( 'deepseek-ai/deepseek-r1', $model_names );
		$this->assertContains( 'qwen/qwen3-32b', $model_names );
		$this->assertContains( 'meta/llama-4-scout-17b-16e-instruct', $model_names );
		$this->assertContains( 'google/gemma-3-27b-it', $model_names );

		// Verify we have a substantial number of NVIDIA models.
		$this->assertGreaterThanOrEqual( 50, count( $nvidia_models ) );
	}

	/**
	 * Test that TPM limits are reasonable.
	 */
	public function test_default_models_have_valid_tpm_limits() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		// Providers whose catalog entries legitimately carry zero TPM limits
		// (local deployments or marketplaces without published rate limits),
		// plus image-generation models that do not bill per token.
		$zero_tpm_providers = array( 'embedded', 'lm_studio', 'ollama', 'webllm', 'kimi', 'openrouter', 'digitalocean', 'baseten' );

		foreach ( $default_models as $model ) {
			$this->assertArrayHasKey( 'tpm_limit', $model );
			$this->assertIsInt( $model['tpm_limit'] );
			if ( in_array( $model['provider'], $zero_tpm_providers, true ) || 0 === (int) $model['max_output_tokens'] ) {
				$this->assertGreaterThanOrEqual( 0, $model['tpm_limit'] );
			} else {
				$this->assertGreaterThan( 0, $model['tpm_limit'] );
			}
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
			// Image-generation models do not have a token context window.
			if ( 0 === (int) $model['max_output_tokens'] ) {
				$this->assertGreaterThanOrEqual( 0, $model['context_window'] );
			} else {
				$this->assertGreaterThan( 0, $model['context_window'] );
			}
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
				$this->assertIsNumeric( $model['cost_per_1k_input_tokens'] );
				$this->assertGreaterThanOrEqual( 0, $model['cost_per_1k_input_tokens'] );
			}

			if ( isset( $model['cost_per_1k_output_tokens'] ) ) {
				$this->assertIsNumeric( $model['cost_per_1k_output_tokens'] );
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
		// Current catalog value for the gpt-4o default tier.
		$this->assertSame( 450000, $gpt4o['tpm_limit'] );
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
				return 'gemini-3.1-pro' === $model['model_name'];
			}
		);

		$this->assertNotEmpty( $gemini_pro );

		$model = reset( $gemini_pro );
		// Gemini 3.1 Pro has a 2M context window.
		$this->assertGreaterThan( 1000000, $model['context_window'] );
	}

	/**
	 * Test that Ollama models are included.
	 */
	public function test_default_models_include_ollama() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$ollama_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'ollama' === $model['provider'];
			}
		);

		$this->assertNotEmpty( $ollama_models );

		// Check for specific models.
		$model_names = array_column( $ollama_models, 'model_name' );
		$this->assertContains( 'llama3', $model_names );
		$this->assertContains( 'mistral', $model_names );
		$this->assertContains( 'codellama', $model_names );
	}

	/**
	 * Test that Ollama models have no rate limits (local deployment).
	 */
	public function test_ollama_models_have_no_rate_limits() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$llama3 = array_filter(
			$default_models,
			function ( $model ) {
				return 'llama3' === $model['model_name'];
			}
		);

		$this->assertNotEmpty( $llama3 );

		$model = reset( $llama3 );
		// Local models should have 0 TPM (no API limits).
		$this->assertSame( 0, $model['tpm_limit'] );
		$this->assertSame( 0, $model['rpm_limit'] );
		$this->assertEquals( 0, $model['cost_per_1k_input_tokens'] );
		$this->assertEquals( 0, $model['cost_per_1k_output_tokens'] );
	}

	/**
	 * Test that GPT-5 models are included.
	 */
	public function test_default_models_include_gpt5() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$gpt5_models = array_filter(
			$default_models,
			function ( $model ) {
				return in_array( $model['model_name'], array( 'gpt-5', 'gpt-5-mini' ), true );
			}
		);

		$this->assertCount( 2, $gpt5_models );

		// Check GPT-5 has high TPM.
		$gpt5 = array_filter(
			$gpt5_models,
			function ( $model ) {
				return 'gpt-5' === $model['model_name'];
			}
		);

		$this->assertNotEmpty( $gpt5 );
		$model = reset( $gpt5 );
		$this->assertGreaterThanOrEqual( 400000, $model['tpm_limit'] );
	}

	/**
	 * Test that Gemini image models are included in defaults.
	 */
	public function test_default_models_include_gemini_image_models() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );

		$gemini_models = array_filter(
			$default_models,
			function ( $model ) {
				return 'gemini' === $model['provider'];
			}
		);

		$model_names = array_column( $gemini_models, 'model_name' );

		// Check that Gemini image models are included.
		$this->assertContains( 'gemini-2.5-flash-image', $model_names, 'Gemini 2.5 Flash Image should be in default models' );

		// Check that Gemini 3.1 Flash Image is included (latest).
		$this->assertContains( 'gemini-3.1-flash-image', $model_names, 'Gemini 3.1 Flash Image should be in default models' );

		// Check that Imagen 4 is included (alternative).
		$this->assertContains( 'imagen-4', $model_names, 'Imagen 4 should be in default models' );

		// Verify Gemini 2.5 Flash Image has correct configuration.
		$gemini_25_flash_image = array_values(
			array_filter(
				$default_models,
				function ( $model ) {
					return 'gemini-2.5-flash-image' === $model['model_name'];
				}
			)
		);

		$this->assertNotEmpty( $gemini_25_flash_image, 'Should find gemini-2.5-flash-image in defaults' );
		$model = reset( $gemini_25_flash_image );

		// Verify key properties.
		$this->assertSame( 'gemini', $model['provider'], 'Provider should be gemini' );
		$this->assertSame( 1000000, $model['tpm_limit'], 'TPM limit should be 1M' );
		$this->assertSame( 1000, $model['rpm_limit'], 'RPM limit should be 1000' );
		$this->assertFalse( $model['supports_streaming'], 'Image models should not support streaming' );
		$this->assertFalse( $model['supports_function_calling'], 'Image models should not support function calling' );
		$this->assertTrue( $model['supports_vision'], 'Image models should support vision' );
		$this->assertSame( 0.03, $model['cost_per_1k_output_tokens'], 'Output token cost should be $0.03 per 1K' );
	}

	/**
	 * Test GPT-4.1 models are included with correct specifications.
	 */
	public function test_gpt_41_models_included() {
		$default_models = WP_MCP_AI_Model_Rate_Limits_CCT::get_default_model_data();

		// Filter for GPT-4.1 models.
		$gpt_41_models = array_filter(
			$default_models,
			function ( $model ) {
				return isset( $model['model_name'] ) && strpos( $model['model_name'], 'gpt-4.1' ) === 0;
			}
		);

		// Should have exactly 4 GPT-4.1 variants.
		$this->assertCount( 4, $gpt_41_models, 'Should have 4 GPT-4.1 model variants' );

		$model_names = array_column( $gpt_41_models, 'model_name' );
		$this->assertContains( 'gpt-4.1', $model_names );
		$this->assertContains( 'gpt-4.1-mini', $model_names );
		$this->assertContains( 'gpt-4.1-nano', $model_names );
		$this->assertContains( 'gpt-4.1-turbo', $model_names );

		// Verify GPT-4.1 base model properties.
		$gpt_41 = array_values(
			array_filter(
				$gpt_41_models,
				function ( $model ) {
					return 'gpt-4.1' === $model['model_name'];
				}
			)
		)[0];

		$this->assertSame( 'openai', $gpt_41['provider'] );
		$this->assertSame( 80000, $gpt_41['tpm_limit'] );
		$this->assertSame( 800, $gpt_41['rpm_limit'] );
		$this->assertSame( 128000, $gpt_41['context_window'] );
		$this->assertTrue( $gpt_41['supports_vision'] );
		$this->assertTrue( $gpt_41['supports_function_calling'] );
		$this->assertSame( 0.002, $gpt_41['cost_per_1k_input_tokens'] );
	}

	/**
	 * Test that GPT-5.3 Codex is included in default model data.
	 */
	public function test_default_models_include_gpt_53_codex() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Model_Rate_Limits_CCT' );
		$method     = $reflection->getMethod( 'get_default_model_data' );
		$method->setAccessible( true );

		$default_models = $method->invoke( null );
		$model_names    = array_column( $default_models, 'model_name' );

		$this->assertContains( 'gpt-5.3-codex', $model_names, 'Default models should include gpt-5.3-codex' );

		// Verify codex entry details.
		$codex = array_values(
			array_filter(
				$default_models,
				function ( $m ) {
					return 'gpt-5.3-codex' === $m['model_name'];
				}
			)
		)[0];

		$this->assertSame( 'openai', $codex['provider'] );
		$this->assertSame( 922000, $codex['context_window'], 'Codex should have a 922K context window' );
		$this->assertSame( 128000, $codex['max_output_tokens'], 'Codex should have 128K max output tokens' );
		$this->assertFalse( $codex['supports_vision'], 'Codex is text-only' );
		$this->assertTrue( $codex['supports_function_calling'] );
	}
}
