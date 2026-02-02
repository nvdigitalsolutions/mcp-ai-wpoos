<?php
/**
 * Tests for textarea array handling in settings sections.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that textarea fields properly handle array values.
 */
class WP_MCP_AI_Textarea_Array_Handling_Test extends WP_UnitTestCase {

	/**
	 * Test that array values are converted to JSON strings when rendering textarea fields.
	 *
	 * This prevents the fatal error: "htmlspecialchars(): Argument #1 ($string) must be of type string, array given"
	 */
	public function test_textarea_renders_array_as_json() {
		// Set up a test setting with an array value (simulating what might be in the database).
		$test_array = array(
			array(
				'kty' => 'RSA',
				'use' => 'sig',
				'kid' => 'test-key-id',
				'n'   => 'test-n-value',
				'e'   => 'AQAB',
			),
		);

		// Store as array in settings.
		$settings                           = get_option( 'wp_mcp_ai_settings', array() );
		$settings['federation_jwks_keys']   = $test_array;
		$settings['federation_price_hints'] = array(
			'gpt-4' => array(
				'input'  => 0.03,
				'output' => 0.06,
			),
		);
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a section instance.
		require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-advanced.php';
		$section = new WP_MCP_AI_Section_Advanced();

		// Capture the output of rendering.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The output should NOT contain the fatal error message.
		$this->assertStringNotContainsString( 'Fatal error', $output );
		$this->assertStringNotContainsString( 'htmlspecialchars()', $output );
		$this->assertStringNotContainsString( 'Argument #1 ($string) must be of type string, array given', $output );

		// The output should contain the JSON representation of the array.
		$this->assertStringContainsString( 'federation_jwks_keys', $output );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that string values in textarea fields are rendered normally.
	 */
	public function test_textarea_renders_string_normally() {
		// Set up a test setting with a string value.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['federation_jwks_keys'] = '{"kty":"RSA","use":"sig"}';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a section instance.
		require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-advanced.php';
		$section = new WP_MCP_AI_Section_Advanced();

		// Capture the output of rendering.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The output should contain the string value.
		$this->assertStringContainsString( 'federation_jwks_keys', $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that empty values in textarea fields are rendered as empty strings.
	 */
	public function test_textarea_renders_empty_value() {
		// Set up a test setting with an empty value.
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings['federation_jwks_keys'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a section instance.
		require_once dirname( __DIR__ ) . '/includes/admin/sections/class-wp-mcp-ai-section-advanced.php';
		$section = new WP_MCP_AI_Section_Advanced();

		// Capture the output of rendering.
		ob_start();
		$section->render_wrapper();
		$output = ob_get_clean();

		// The output should contain the textarea field.
		$this->assertStringContainsString( 'federation_jwks_keys', $output );
		$this->assertStringNotContainsString( 'Fatal error', $output );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}
}
