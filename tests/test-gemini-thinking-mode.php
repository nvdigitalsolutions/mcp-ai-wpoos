<?php
/**
 * Tests for Gemini thinking mode (thinkingConfig) integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests that gemini_thinking_budget_tokens is correctly stored, defaulted,
 * and propagated through the REST validator into the options array so the
 * Gemini client can pick it up as thinking_budget_tokens.
 */
class WP_MCP_AI_Gemini_Thinking_Mode_Test extends WP_UnitTestCase {

	/**
	 * Reset to default settings before each test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	// -------------------------------------------------------------------------
	// Default settings
	// -------------------------------------------------------------------------

	/**
	 * Default value of gemini_thinking_budget_tokens must be 0 (disabled).
	 */
	public function test_default_thinking_budget_is_zero() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'gemini_thinking_budget_tokens', $defaults );
		$this->assertSame( 0, $defaults['gemini_thinking_budget_tokens'] );
	}

	// -------------------------------------------------------------------------
	// REST validator — sanitize_options()
	// -------------------------------------------------------------------------

	/**
	 * When thinking_budget_tokens is provided in request options it must be
	 * forwarded by sanitize_options().
	 */
	public function test_sanitize_options_passes_through_thinking_budget() {
		$validator       = new WP_MCP_AI_REST_Validator();
		$assistant_config = array( 'provider' => 'gemini' );

		$sanitized = $validator->sanitize_options(
			array(
				'provider'               => 'gemini',
				'thinking_budget_tokens' => 2048,
			),
			$assistant_config
		);

		$this->assertArrayHasKey( 'thinking_budget_tokens', $sanitized );
		$this->assertSame( 2048, $sanitized['thinking_budget_tokens'] );
	}

	/**
	 * A thinking_budget_tokens of 0 must be removed from options.
	 */
	public function test_sanitize_options_removes_zero_thinking_budget() {
		$validator       = new WP_MCP_AI_REST_Validator();
		$assistant_config = array( 'provider' => 'gemini' );

		$sanitized = $validator->sanitize_options(
			array(
				'provider'               => 'gemini',
				'thinking_budget_tokens' => 0,
			),
			$assistant_config
		);

		$this->assertArrayNotHasKey( 'thinking_budget_tokens', $sanitized );
	}

	/**
	 * thinking_budget_tokens must be capped at 24576.
	 */
	public function test_sanitize_options_caps_thinking_budget() {
		$validator       = new WP_MCP_AI_REST_Validator();
		$assistant_config = array( 'provider' => 'gemini' );

		$sanitized = $validator->sanitize_options(
			array(
				'provider'               => 'gemini',
				'thinking_budget_tokens' => 99999,
			),
			$assistant_config
		);

		$this->assertArrayHasKey( 'thinking_budget_tokens', $sanitized );
		$this->assertSame( 24576, $sanitized['thinking_budget_tokens'] );
	}

	/**
	 * When no thinking_budget_tokens is in the request but the global setting is
	 * > 0 and provider is Gemini, the global budget must be injected.
	 */
	public function test_sanitize_options_injects_global_budget_for_gemini() {
		// Set a non-zero global Gemini thinking budget.
		$settings                                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_thinking_budget_tokens']  = 4096;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$validator       = new WP_MCP_AI_REST_Validator();
		$assistant_config = array( 'provider' => 'gemini' );

		$sanitized = $validator->sanitize_options(
			array( 'provider' => 'gemini' ),
			$assistant_config
		);

		$this->assertArrayHasKey( 'thinking_budget_tokens', $sanitized );
		$this->assertSame( 4096, $sanitized['thinking_budget_tokens'] );
	}

	/**
	 * When no thinking_budget_tokens is in the request and the global setting is 0,
	 * thinking_budget_tokens must NOT be injected into options.
	 */
	public function test_sanitize_options_does_not_inject_zero_global_budget() {
		// Default settings have gemini_thinking_budget_tokens = 0.
		$validator       = new WP_MCP_AI_REST_Validator();
		$assistant_config = array( 'provider' => 'gemini' );

		$sanitized = $validator->sanitize_options(
			array( 'provider' => 'gemini' ),
			$assistant_config
		);

		$this->assertArrayNotHasKey( 'thinking_budget_tokens', $sanitized );
	}

	/**
	 * Global thinking budget must NOT be injected for non-Gemini providers.
	 */
	public function test_sanitize_options_does_not_inject_budget_for_openai() {
		$settings                                  = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gemini_thinking_budget_tokens'] = 2048;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$validator       = new WP_MCP_AI_REST_Validator();
		$assistant_config = array( 'provider' => 'openai' );

		$sanitized = $validator->sanitize_options(
			array( 'provider' => 'openai' ),
			$assistant_config
		);

		$this->assertArrayNotHasKey( 'thinking_budget_tokens', $sanitized );
	}

	// -------------------------------------------------------------------------
	// Settings sanitization
	// -------------------------------------------------------------------------

	/**
	 * sanitize_settings() must clamp gemini_thinking_budget_tokens to 0–24576.
	 */
	public function test_admin_settings_sanitizes_thinking_budget() {
		$admin    = new WP_MCP_AI_Admin_Settings();
		$method   = new ReflectionMethod( $admin, 'sanitize_settings' );
		$method->setAccessible( true );

		$input  = WP_MCP_AI_Admin_Settings::get_default_settings();
		$input['gemini_thinking_budget_tokens'] = 50000; // Exceeds maximum.

		$result = $method->invoke( $admin, $input );

		$this->assertArrayHasKey( 'gemini_thinking_budget_tokens', $result );
		$this->assertSame( 24576, $result['gemini_thinking_budget_tokens'] );
	}

	/**
	 * A thinking budget of 1024 must be stored as-is.
	 */
	public function test_admin_settings_stores_valid_thinking_budget() {
		$admin  = new WP_MCP_AI_Admin_Settings();
		$method = new ReflectionMethod( $admin, 'sanitize_settings' );
		$method->setAccessible( true );

		$input = WP_MCP_AI_Admin_Settings::get_default_settings();
		$input['gemini_thinking_budget_tokens'] = 1024;

		$result = $method->invoke( $admin, $input );

		$this->assertSame( 1024, $result['gemini_thinking_budget_tokens'] );
	}
}
