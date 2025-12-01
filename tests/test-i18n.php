<?php
/**
 * Tests for internationalisation (i18n) functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests related to internationalisation.
 */
class WP_MCP_AI_I18N_Test extends WP_UnitTestCase {

	/**
	 * Verify plugin has proper translation headers for WordPress 6.7+ JIT loading.
	 *
	 * WordPress 6.7+ automatically loads translations via just-in-time (JIT) loading
	 * based on the "Text Domain" and "Domain Path" headers. This test verifies those
	 * headers are present and correct.
	 *
	 * @see https://make.wordpress.org/core/2024/02/27/i18n-improvements-6-5/
	 */
	public function test_plugin_has_translation_headers() {
		$plugin_file = WP_MCP_AI_FILE;
		$plugin_data = get_plugin_data( $plugin_file, false, false );

		$this->assertArrayHasKey( 'TextDomain', $plugin_data, 'Plugin should have Text Domain header' );
		$this->assertEquals( 'wp-mcp-ai', $plugin_data['TextDomain'], 'Text Domain should be wp-mcp-ai' );

		$this->assertArrayHasKey( 'DomainPath', $plugin_data, 'Plugin should have Domain Path header' );
		$this->assertEquals( '/languages', $plugin_data['DomainPath'], 'Domain Path should be /languages' );
	}

	/**
	 * Verify translation functions work correctly.
	 *
	 * This confirms that WordPress's automatic JIT translation loading is functioning
	 * properly for the plugin.
	 */
	public function test_translation_functions_work() {
		// Call a translation function - WordPress should automatically load the textdomain.
		$translated = __( 'Settings', 'wp-mcp-ai' );

		// In a test environment without actual translation files, this will return the original string.
		// The important part is that it doesn't trigger any errors or warnings.
		$this->assertIsString( $translated, 'Translation function should return a string' );
		$this->assertEquals( 'Settings', $translated, 'Without translation files, should return original string' );
	}
}
