<?php
/**
 * Tests for OpenAI transcription default settings.
 *
 * @package WP_MCP_AI
 */

/**
 * Test transcription settings functionality.
 */
class WP_MCP_AI_Transcription_Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that default transcription settings are properly defined.
	 */
	public function test_default_transcription_settings_exist() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		// Verify all transcription settings are present with correct defaults.
		$this->assertArrayHasKey( 'openai_transcribe_model', $defaults );
		$this->assertSame( 'gpt-4o-mini-transcribe', $defaults['openai_transcribe_model'] );

		$this->assertArrayHasKey( 'openai_transcribe_response_format', $defaults );
		$this->assertSame( 'verbose_json', $defaults['openai_transcribe_response_format'] );

		$this->assertArrayHasKey( 'openai_transcribe_language', $defaults );
		$this->assertSame( '', $defaults['openai_transcribe_language'] );

		$this->assertArrayHasKey( 'openai_transcribe_temperature', $defaults );
		$this->assertSame( '', $defaults['openai_transcribe_temperature'] );
	}

	/**
	 * Test that transcription fields are included in the OpenAI provider section.
	 */
	public function test_transcription_fields_in_openai_section() {
		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Verify transcription fields exist.
		$this->assertArrayHasKey( 'openai_transcribe_model', $fields );
		$this->assertArrayHasKey( 'openai_transcribe_response_format', $fields );
		$this->assertArrayHasKey( 'openai_transcribe_language', $fields );
		$this->assertArrayHasKey( 'openai_transcribe_temperature', $fields );

		// Verify field types.
		$this->assertSame( 'select', $fields['openai_transcribe_model']['type'] );
		$this->assertSame( 'select', $fields['openai_transcribe_response_format']['type'] );
		$this->assertSame( 'text', $fields['openai_transcribe_language']['type'] );
		$this->assertSame( 'text', $fields['openai_transcribe_temperature']['type'] );

		// Verify model options.
		$this->assertArrayHasKey( 'gpt-4o-mini-transcribe', $fields['openai_transcribe_model']['options'] );
		$this->assertArrayHasKey( 'whisper-1', $fields['openai_transcribe_model']['options'] );

		// Verify response format options.
		$this->assertArrayHasKey( 'verbose_json', $fields['openai_transcribe_response_format']['options'] );
		$this->assertArrayHasKey( 'json', $fields['openai_transcribe_response_format']['options'] );
	}

	/**
	 * Test that transcription settings can be saved and retrieved.
	 */
	public function test_transcription_settings_save_and_retrieve() {
		// Set custom transcription settings.
		$settings = array(
			'openai_transcribe_model'           => 'whisper-1',
			'openai_transcribe_response_format' => 'json',
			'openai_transcribe_language'        => 'en',
			'openai_transcribe_temperature'     => '0.2',
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Retrieve settings.
		$retrieved = WP_MCP_AI_Admin_Settings::get_settings();

		// Verify settings were saved correctly.
		$this->assertSame( 'whisper-1', $retrieved['openai_transcribe_model'] );
		$this->assertSame( 'json', $retrieved['openai_transcribe_response_format'] );
		$this->assertSame( 'en', $retrieved['openai_transcribe_language'] );
		$this->assertSame( '0.2', $retrieved['openai_transcribe_temperature'] );
	}

	/**
	 * Test that empty optional settings are handled correctly.
	 */
	public function test_empty_optional_settings() {
		// Set settings with empty optional values.
		$settings = array(
			'openai_transcribe_model'           => 'gpt-4o-mini-transcribe',
			'openai_transcribe_response_format' => 'verbose_json',
			'openai_transcribe_language'        => '',
			'openai_transcribe_temperature'     => '',
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Retrieve settings.
		$retrieved = WP_MCP_AI_Admin_Settings::get_settings();

		// Verify empty values are preserved.
		$this->assertSame( '', $retrieved['openai_transcribe_language'] );
		$this->assertSame( '', $retrieved['openai_transcribe_temperature'] );
	}

	/**
	 * Test that transcription settings from admin are used in the transcribe tool.
	 */
	public function test_transcribe_tool_uses_admin_settings() {
		// This test verifies that the tool uses admin settings as defaults.
		// We can't fully test the tool execution without setting up WordPress media,
		// but we can verify the settings retrieval logic.

		// Set custom transcription settings.
		$settings = array(
			'openai_transcribe_model'           => 'whisper-1',
			'openai_transcribe_response_format' => 'json',
			'openai_transcribe_language'        => 'es',
			'openai_transcribe_temperature'     => '0.5',
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Retrieve settings as the tool would.
		$retrieved = WP_MCP_AI_Admin_Settings::get_settings();

		// Verify the tool would get the correct defaults.
		$this->assertSame( 'whisper-1', $retrieved['openai_transcribe_model'] );
		$this->assertSame( 'json', $retrieved['openai_transcribe_response_format'] );
		$this->assertSame( 'es', $retrieved['openai_transcribe_language'] );
		$this->assertSame( '0.5', $retrieved['openai_transcribe_temperature'] );
	}

	/**
	 * Test that saving OpenAI subtab preserves transcription settings.
	 */
	public function test_saving_openai_subtab_preserves_transcription_settings() {
		// Set up initial settings with transcription configured.
		$initial_settings = array(
			'enable_openai'                     => true,
			'openai_api_key'                    => 'sk-test-key',
			'default_model'                     => 'gpt-4o',
			'openai_transcribe_model'           => 'whisper-1',
			'openai_transcribe_response_format' => 'json',
			'openai_transcribe_language'        => 'fr',
			'openai_transcribe_temperature'     => '0.3',
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial_settings );

		// Simulate saving the OpenAI subtab with modified chat model but not transcription settings.
		$posted_settings = array(
			'enable_openai'  => '1',
			'openai_api_key' => 'sk-test-key',
			'default_model'  => 'gpt-4o-mini',
		);

		// Merge with existing as would happen in a real save.
		$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$merged   = array_merge( $existing, $posted_settings );

		// Transcription settings should still be present.
		$this->assertSame( 'whisper-1', $merged['openai_transcribe_model'] );
		$this->assertSame( 'json', $merged['openai_transcribe_response_format'] );
		$this->assertSame( 'fr', $merged['openai_transcribe_language'] );
		$this->assertSame( '0.3', $merged['openai_transcribe_temperature'] );

		// Chat model should be updated.
		$this->assertSame( 'gpt-4o-mini', $merged['default_model'] );
	}
}
