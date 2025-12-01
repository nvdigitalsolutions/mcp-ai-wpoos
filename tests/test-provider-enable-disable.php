<?php
/**
 * Tests for provider enable/disable functionality.
 */
class WP_MCP_AI_Provider_Enable_Disable_Test extends WP_UnitTestCase {

	/**
	 * Test that enable fields exist in provider section.
	 */
	public function test_provider_enable_fields_exist() {
		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Check that all provider enable fields exist.
		$this->assertArrayHasKey( 'enable_openai', $fields );
		$this->assertArrayHasKey( 'enable_anthropic', $fields );
		$this->assertArrayHasKey( 'enable_gemini', $fields );
		$this->assertArrayHasKey( 'enable_ollama', $fields );
		$this->assertArrayHasKey( 'enable_lm_studio', $fields );
	}

	/**
	 * Test that enable fields are checkboxes.
	 */
	public function test_provider_enable_fields_are_checkboxes() {
		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Check field types.
		$this->assertEquals( 'checkbox', $fields['enable_openai']['type'] );
		$this->assertEquals( 'checkbox', $fields['enable_anthropic']['type'] );
		$this->assertEquals( 'checkbox', $fields['enable_gemini']['type'] );
		$this->assertEquals( 'checkbox', $fields['enable_ollama']['type'] );
		$this->assertEquals( 'checkbox', $fields['enable_lm_studio']['type'] );
	}

	/**
	 * Test that enable fields default to true for backward compatibility.
	 */
	public function test_provider_enable_fields_default_true() {
		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Check defaults.
		$this->assertTrue( $fields['enable_openai']['default'] );
		$this->assertTrue( $fields['enable_anthropic']['default'] );
		$this->assertTrue( $fields['enable_gemini']['default'] );
		$this->assertTrue( $fields['enable_ollama']['default'] );
		$this->assertTrue( $fields['enable_lm_studio']['default'] );
	}

	/**
	 * Test that subtab groups include enable fields.
	 */
	public function test_subtab_groups_include_enable_fields() {
		$section    = new WP_MCP_AI_Section_Providers();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$groups = $method->invoke( $section );

		// Check that each provider subtab includes its enable field.
		$this->assertContains( 'enable_openai', $groups['openai']['fields'] );
		$this->assertContains( 'enable_anthropic', $groups['anthropic']['fields'] );
		$this->assertContains( 'enable_gemini', $groups['gemini']['fields'] );
		$this->assertContains( 'enable_ollama', $groups['ollama']['fields'] );
		$this->assertContains( 'enable_lm_studio', $groups['lm_studio']['fields'] );
	}

	/**
	 * Test that enable fields are first in their respective subtabs.
	 */
	public function test_enable_fields_are_first_in_subtabs() {
		$section    = new WP_MCP_AI_Section_Providers();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$groups = $method->invoke( $section );

		// Check that enable fields are the first field in each provider subtab.
		$this->assertEquals( 'enable_openai', $groups['openai']['fields'][0] );
		$this->assertEquals( 'enable_anthropic', $groups['anthropic']['fields'][0] );
		$this->assertEquals( 'enable_gemini', $groups['gemini']['fields'][0] );
		$this->assertEquals( 'enable_ollama', $groups['ollama']['fields'][0] );
		$this->assertEquals( 'enable_lm_studio', $groups['lm_studio']['fields'][0] );
	}

	/**
	 * Test that enable settings can be saved and retrieved.
	 */
	public function test_provider_enable_settings_save_and_retrieve() {
		// Save settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_openai'    => false,
				'enable_anthropic' => true,
				'enable_gemini'    => false,
				'enable_ollama'    => true,
				'enable_lm_studio' => false,
			)
		);

		// Retrieve settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Check values.
		$this->assertFalse( $settings['enable_openai'] );
		$this->assertTrue( $settings['enable_anthropic'] );
		$this->assertFalse( $settings['enable_gemini'] );
		$this->assertTrue( $settings['enable_ollama'] );
		$this->assertFalse( $settings['enable_lm_studio'] );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that section sanitization handles enable fields correctly.
	 */
	public function test_section_sanitizes_enable_fields() {
		$section = new WP_MCP_AI_Section_Providers();

		// Test with checkbox enabled.
		$input_enabled = array(
			'enable_openai' => '1',
		);
		$sanitized     = $section->sanitize( $input_enabled );
		$this->assertTrue( $sanitized['enable_openai'] );

		// Test with checkbox disabled (not present in input).
		$input_disabled = array();
		// Simulate form submission for openai subtab.
		$_POST['subtab'] = 'openai';
		$sanitized       = $section->sanitize( $input_disabled );
		$this->assertFalse( $sanitized['enable_openai'] );

		// Clean up.
		unset( $_POST['subtab'] );
	}
}
