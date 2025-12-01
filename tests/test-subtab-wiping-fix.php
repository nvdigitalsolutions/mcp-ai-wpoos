<?php
/**
 * Test that the fix for subtab data wiping works correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test the subtab sanitization fix.
 */
class WP_MCP_AI_Subtab_Wiping_Fix_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		unset( $_POST['subtab'] );
		unset( $_GET['subtab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that saving OpenAI subtab doesn't clear Anthropic settings.
	 *
	 * This is the main bug fix test.
	 */
	public function test_saving_openai_preserves_anthropic_settings() {
		// Set up initial state with both providers enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'     => true,
				'openai_api_key'    => 'sk-old-openai-key',
				'enable_anthropic'  => true,
				'anthropic_api_key' => 'sk-ant-old-key',
				'enable_gemini'     => true,
				'gemini_api_key'    => 'AIza-old-key',
			)
		);

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate saving OpenAI subtab with new API key.
		$_POST['subtab'] = 'openai';
		$input           = array(
			'enable_openai'  => '1', // Still enabled.
			'openai_api_key' => 'sk-new-openai-key', // New key.
			'default_model'  => 'gpt-4o',
		);

		// Sanitize (this goes through the NEW dashboard logic).
		$sanitized = $dashboard->sanitize_settings( $input, 'providers' );

		// Merge with existing settings (simulating actual save).
		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify OpenAI settings were updated.
		$this->assertTrue( $merged['enable_openai'], 'OpenAI should still be enabled' );
		$this->assertEquals( 'sk-new-openai-key', $merged['openai_api_key'], 'OpenAI key should be updated' );

		// CRITICAL: Verify Anthropic settings were NOT wiped!
		$this->assertTrue( $merged['enable_anthropic'], 'Anthropic should still be enabled after saving OpenAI' );
		$this->assertEquals( 'sk-ant-old-key', $merged['anthropic_api_key'], 'Anthropic key should be preserved' );

		// CRITICAL: Verify Gemini settings were NOT wiped!
		$this->assertTrue( $merged['enable_gemini'], 'Gemini should still be enabled after saving OpenAI' );
		$this->assertEquals( 'AIza-old-key', $merged['gemini_api_key'], 'Gemini key should be preserved' );
	}

	/**
	 * Test that disabling one provider doesn't affect others.
	 */
	public function test_disabling_openai_preserves_other_providers() {
		// Set up initial state with all providers enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'    => true,
				'enable_anthropic' => true,
				'enable_gemini'    => true,
				'enable_ollama'    => true,
				'enable_lm_studio' => true,
			)
		);

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Disable OpenAI.
		$_POST['subtab'] = 'openai';
		$input           = array(
			// enable_openai NOT in input = unchecked = disabled.
			'openai_api_key' => '',
		);

		$sanitized = $dashboard->sanitize_settings( $input, 'providers' );
		$existing  = get_option( 'wp_mcp_ai_settings', array() );
		$merged    = array_merge( $existing, $sanitized );

		// Verify OpenAI is disabled.
		$this->assertFalse( $merged['enable_openai'], 'OpenAI should be disabled' );

		// Verify all other providers are still enabled.
		$this->assertTrue( $merged['enable_anthropic'], 'Anthropic should still be enabled' );
		$this->assertTrue( $merged['enable_gemini'], 'Gemini should still be enabled' );
		$this->assertTrue( $merged['enable_ollama'], 'Ollama should still be enabled' );
		$this->assertTrue( $merged['enable_lm_studio'], 'LM Studio should still be enabled' );
	}

	/**
	 * Test that changing priority order doesn't affect provider enable states.
	 */
	public function test_changing_priority_preserves_enable_states() {
		// Set up initial state.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'          => true,
				'enable_anthropic'       => false,
				'enable_gemini'          => true,
				'provider_priority_list' => array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ),
			)
		);

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Change priority order.
		$_POST['subtab'] = 'priority';
		$input           = array(
			'provider_priority_list' => array( 'gemini', 'openai', 'ollama', 'anthropic', 'lm_studio' ),
		);

		$sanitized = $dashboard->sanitize_settings( $input, 'providers' );
		$existing  = get_option( 'wp_mcp_ai_settings', array() );
		$merged    = array_merge( $existing, $sanitized );

		// Verify priority changed.
		$this->assertEquals( 'gemini', $merged['provider_priority_list'][0] );

		// Verify enable states preserved.
		$this->assertTrue( $merged['enable_openai'], 'OpenAI enable state should be preserved' );
		$this->assertFalse( $merged['enable_anthropic'], 'Anthropic enable state should be preserved' );
		$this->assertTrue( $merged['enable_gemini'], 'Gemini enable state should be preserved' );
	}

	/**
	 * Test sequential saves of different subtabs.
	 */
	public function test_sequential_subtab_saves_all_preserve_data() {
		// Start with empty settings.
		update_option( 'wp_mcp_ai_settings', array() );

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Save OpenAI.
		$_POST['subtab'] = 'openai';
		$input1          = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-openai-123',
		);
		$sanitized1      = $dashboard->sanitize_settings( $input1, 'providers' );
		$existing1       = get_option( 'wp_mcp_ai_settings', array() );
		$merged1         = array_merge( $existing1, $sanitized1 );
		update_option( 'wp_mcp_ai_settings', $merged1 );

		// Save Anthropic.
		$_POST['subtab'] = 'anthropic';
		$input2          = array(
			'enable_anthropic'  => '1',
			'anthropic_api_key' => 'sk-ant-456',
		);
		$sanitized2      = $dashboard->sanitize_settings( $input2, 'providers' );
		$existing2       = get_option( 'wp_mcp_ai_settings', array() );
		$merged2         = array_merge( $existing2, $sanitized2 );
		update_option( 'wp_mcp_ai_settings', $merged2 );

		// Save Gemini.
		$_POST['subtab'] = 'gemini';
		$input3          = array(
			'enable_gemini'  => '1',
			'gemini_api_key' => 'AIza-789',
		);
		$sanitized3      = $dashboard->sanitize_settings( $input3, 'providers' );
		$existing3       = get_option( 'wp_mcp_ai_settings', array() );
		$merged3         = array_merge( $existing3, $sanitized3 );
		update_option( 'wp_mcp_ai_settings', $merged3 );

		// Verify all three are saved and enabled.
		$final = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertTrue( $final['enable_openai'], 'OpenAI should be enabled' );
		$this->assertEquals( 'sk-openai-123', $final['openai_api_key'] );
		$this->assertTrue( $final['enable_anthropic'], 'Anthropic should be enabled' );
		$this->assertEquals( 'sk-ant-456', $final['anthropic_api_key'] );
		$this->assertTrue( $final['enable_gemini'], 'Gemini should be enabled' );
		$this->assertEquals( 'AIza-789', $final['gemini_api_key'] );
	}
}
