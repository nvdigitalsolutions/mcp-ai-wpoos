<?php
/**
 * Test to reproduce the providers subtab checkbox saving issue.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test provider subtab checkbox handling.
 */
class WP_MCP_AI_Providers_Subtab_Checkbox_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		// Start with clean settings.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up.
		unset( $_POST['subtab'] );
		unset( $_GET['subtab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that saving OpenAI subtab with enable_openai checked works.
	 */
	public function test_openai_subtab_enable_checkbox_checked() {
		$section = new WP_MCP_AI_Section_Providers();

		// Simulate OpenAI subtab form submission with checkbox CHECKED.
		$_POST['subtab'] = 'openai';
		$input           = array(
			'enable_openai'  => '1', // Checkbox is checked.
			'openai_api_key' => 'sk-test-123',
			'default_model'  => 'gpt-4o',
		);

		$sanitized = $section->sanitize( $input );

		// Verify checkbox is true.
		$this->assertArrayHasKey( 'enable_openai', $sanitized );
		$this->assertTrue( $sanitized['enable_openai'], 'enable_openai should be true when checkbox is checked' );
	}

	/**
	 * Test that saving OpenAI subtab with enable_openai UNchecked works.
	 */
	public function test_openai_subtab_enable_checkbox_unchecked() {
		$section = new WP_MCP_AI_Section_Providers();

		// Simulate OpenAI subtab form submission with checkbox UNCHECKED.
		$_POST['subtab'] = 'openai';
		$input           = array(
			// enable_openai is NOT in the input (unchecked checkboxes don't send data).
			'openai_api_key' => 'sk-test-123',
			'default_model'  => 'gpt-4o',
		);

		$sanitized = $section->sanitize( $input );

		// Verify checkbox is false.
		$this->assertArrayHasKey( 'enable_openai', $sanitized );
		$this->assertFalse( $sanitized['enable_openai'], 'enable_openai should be false when checkbox is unchecked' );
	}

	/**
	 * Test that saving Anthropic subtab doesn't affect OpenAI checkbox.
	 */
	public function test_anthropic_subtab_doesnt_affect_openai() {
		// First, save OpenAI settings with checkbox enabled.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'     => true,
				'openai_api_key'    => 'sk-test-123',
				'enable_anthropic'  => false,
				'anthropic_api_key' => '',
			)
		);

		$section = new WP_MCP_AI_Section_Providers();

		// Now save Anthropic subtab.
		$_POST['subtab'] = 'anthropic';
		$input           = array(
			'enable_anthropic'  => '1', // Enable Anthropic.
			'anthropic_api_key' => 'sk-ant-test-456',
			'anthropic_model'   => 'claude-3-5-sonnet-20241022',
		);

		$sanitized = $section->sanitize( $input );

		// Verify Anthropic fields are in sanitized output.
		$this->assertArrayHasKey( 'enable_anthropic', $sanitized );
		$this->assertTrue( $sanitized['enable_anthropic'] );

		// Verify OpenAI fields are NOT in sanitized output (should preserve existing values).
		$this->assertArrayNotHasKey( 'enable_openai', $sanitized, 'Saving Anthropic should not include OpenAI fields' );
		$this->assertArrayNotHasKey( 'openai_api_key', $sanitized, 'Saving Anthropic should not include OpenAI fields' );

		// Verify that when merged with existing settings, OpenAI stays enabled.
		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );
		$this->assertTrue( $merged['enable_openai'], 'OpenAI should still be enabled after saving Anthropic' );
	}

	/**
	 * Test that saving Priority Order subtab doesn't affect provider checkboxes.
	 */
	public function test_priority_order_doesnt_affect_provider_checkboxes() {
		// Set up initial state with mixed enable settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'          => true,
				'enable_anthropic'       => false,
				'enable_gemini'          => true,
				'enable_ollama'          => true,
				'enable_lm_studio'       => false,
				'provider_priority_list' => array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ),
			)
		);

		$section = new WP_MCP_AI_Section_Providers();

		// Save Priority Order subtab.
		$_POST['subtab'] = 'priority';
		$input           = array(
			'provider_priority_list' => array( 'gemini', 'openai', 'ollama', 'anthropic', 'lm_studio' ),
		);

		$sanitized = $section->sanitize( $input );

		// Verify priority list is in output.
		$this->assertArrayHasKey( 'provider_priority_list', $sanitized );

		// Verify provider enable fields are NOT in output.
		$this->assertArrayNotHasKey( 'enable_openai', $sanitized, 'Priority tab should not include enable_openai' );
		$this->assertArrayNotHasKey( 'enable_anthropic', $sanitized, 'Priority tab should not include enable_anthropic' );
		$this->assertArrayNotHasKey( 'enable_gemini', $sanitized, 'Priority tab should not include enable_gemini' );
		$this->assertArrayNotHasKey( 'enable_ollama', $sanitized, 'Priority tab should not include enable_ollama' );
		$this->assertArrayNotHasKey( 'enable_lm_studio', $sanitized, 'Priority tab should not include enable_lm_studio' );

		// Verify that when merged with existing settings, enable flags are preserved.
		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );
		$this->assertTrue( $merged['enable_openai'], 'OpenAI should still be enabled' );
		$this->assertFalse( $merged['enable_anthropic'], 'Anthropic should still be disabled' );
		$this->assertTrue( $merged['enable_gemini'], 'Gemini should still be enabled' );
		$this->assertTrue( $merged['enable_ollama'], 'Ollama should still be enabled' );
		$this->assertFalse( $merged['enable_lm_studio'], 'LM Studio should still be disabled' );
	}

	/**
	 * Test the complete save flow through Settings Dashboard.
	 */
	public function test_complete_save_flow_through_dashboard() {
		// Set up initial state.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'  => true,
				'openai_api_key' => 'sk-old-key',
				'enable_ollama'  => true,
				'ollama_model'   => 'llama3',
			)
		);

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Simulate saving Ollama subtab.
		$_POST['subtab'] = 'ollama';
		$input           = array(
			'enable_ollama'       => '1', // Keep enabled.
			'ollama_endpoint_url' => 'http://localhost:11434',
			'ollama_model'        => 'mistral',
		);

		// Use dashboard's sanitize method (which processes all sections for the tab).
		$sanitized = $dashboard->sanitize_settings( $input, 'providers' );

		// Verify Ollama fields are present.
		$this->assertArrayHasKey( 'enable_ollama', $sanitized );
		$this->assertTrue( $sanitized['enable_ollama'] );
		$this->assertArrayHasKey( 'ollama_model', $sanitized );
		$this->assertEquals( 'mistral', $sanitized['ollama_model'] );

		// Verify OpenAI fields are NOT in sanitized output.
		$this->assertArrayNotHasKey( 'enable_openai', $sanitized, 'Saving Ollama should not include OpenAI fields' );

		// Merge with existing settings (simulating the actual save flow).
		$existing = get_option( 'wp_mcp_ai_settings', array() );
		$merged   = array_merge( $existing, $sanitized );

		// Verify OpenAI settings are preserved.
		$this->assertTrue( $merged['enable_openai'], 'OpenAI should still be enabled after saving Ollama' );
		$this->assertEquals( 'sk-old-key', $merged['openai_api_key'], 'OpenAI API key should be preserved' );

		// Verify Ollama settings are updated.
		$this->assertTrue( $merged['enable_ollama'] );
		$this->assertEquals( 'mistral', $merged['ollama_model'] );
	}

	/**
	 * Test that GET subtab doesn't interfere with POST subtab.
	 */
	public function test_get_subtab_doesnt_interfere_with_post() {
		$section = new WP_MCP_AI_Section_Providers();

		// User is viewing Gemini subtab (GET).
		$_GET['subtab'] = 'gemini';

		// But form submits for OpenAI subtab (POST - from hidden field).
		$_POST['subtab'] = 'openai';
		$input           = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-test-123',
		);

		$sanitized = $section->sanitize( $input );

		// Should sanitize OpenAI fields (from POST), not Gemini fields.
		$this->assertArrayHasKey( 'enable_openai', $sanitized );
		$this->assertArrayNotHasKey( 'enable_gemini', $sanitized );
	}
}
