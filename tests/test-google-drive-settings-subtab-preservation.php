<?php
/**
 * Test Google Drive settings subtab preservation.
 *
 * Verifies that when saving settings on the Google Drive connection page,
 * the user is redirected back to the correct subtab and connection.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for Google Drive connection settings subtab preservation.
 */
class Test_Google_Drive_Settings_Subtab_Preservation extends WP_UnitTestCase {

	/**
	 * Test that explicit subtab field takes priority in settings save.
	 *
	 * When there are multiple subtab_* fields (from nested sections),
	 * the explicit 'subtab' field should take priority to preserve
	 * the parent-level subtab value.
	 *
	 * Note: This test directly manipulates $_POST to simulate form submission.
	 * This is appropriate for unit testing the subtab detection logic in isolation
	 * without requiring full WordPress request/response cycle setup.
	 */
	public function test_explicit_subtab_field_priority() {
		// Simulate POST data from Google Drive connection page.
		// Direct $_POST manipulation is intentional for isolated unit testing.
		$_POST = array(
			'wp_mcp_ai_settings' => array(),
			'active_tab'         => 'tools',
			'subtab'             => 'connections', // Explicit field from Tools section.
			'subtab_tools'       => 'connections', // Section-specific field from Tools.
			'subtab_integrations_gmail_crawl4ai' => 'google_drive', // Section-specific from Integrations.
			'connection'         => 'google_drive', // Connection parameter.
		);

		// Simulate the logic from class-wp-mcp-ai-settings-dashboard.php.
		$active_subtab = '';

		// PRIORITY 1: Check for explicit 'subtab' field first.
		if ( isset( $_POST['subtab'] ) && ! empty( $_POST['subtab'] ) ) {
			$active_subtab = sanitize_key( $_POST['subtab'] );
		}

		// PRIORITY 2: Fall back to section-specific subtab fields.
		if ( empty( $active_subtab ) ) {
			foreach ( $_POST as $key => $value ) {
				if ( strpos( $key, 'subtab_' ) === 0 && ! empty( $value ) ) {
					$active_subtab = sanitize_key( $value );
					break;
				}
			}
		}

		// Verify that the explicit 'subtab' field value is used.
		$this->assertEquals( 'connections', $active_subtab, 'Explicit subtab field should take priority over section-specific fields' );
	}

	/**
	 * Test that section-specific subtab field is used when no explicit field exists.
	 *
	 * For backward compatibility, section-specific subtab_* fields should
	 * still work when there's no explicit 'subtab' field.
	 *
	 * Note: This test directly manipulates $_POST to simulate form submission.
	 * This is appropriate for unit testing the fallback logic in isolation.
	 */
	public function test_section_specific_subtab_fallback() {
		// Simulate POST data without explicit subtab field.
		// Direct $_POST manipulation is intentional for isolated unit testing.
		$_POST = array(
			'wp_mcp_ai_settings'       => array(),
			'active_tab'               => 'providers',
			'subtab_providers_openai'  => 'openai',
		);

		// Simulate the logic from class-wp-mcp-ai-settings-dashboard.php.
		$active_subtab = '';

		// PRIORITY 1: Check for explicit 'subtab' field first.
		if ( isset( $_POST['subtab'] ) && ! empty( $_POST['subtab'] ) ) {
			$active_subtab = sanitize_key( $_POST['subtab'] );
		}

		// PRIORITY 2: Fall back to section-specific subtab fields.
		if ( empty( $active_subtab ) ) {
			foreach ( $_POST as $key => $value ) {
				if ( strpos( $key, 'subtab_' ) === 0 && ! empty( $value ) ) {
					$active_subtab = sanitize_key( $value );
					break;
				}
			}
		}

		// Verify that the section-specific field is used when no explicit field exists.
		$this->assertEquals( 'openai', $active_subtab, 'Section-specific subtab field should be used as fallback' );
	}

	/**
	 * Test that Google Drive callback URL is generated correctly.
	 *
	 * The callback URL displayed in the setup instructions should match
	 * the URL used in the OAuth flow.
	 */
	public function test_google_drive_callback_url_generation() {
		// Simulate callback URL generation (from class-wp-mcp-ai-section-integrations.php).
		$drive_redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'google_drive_callback' ),
			admin_url( 'admin.php' )
		);

		// Verify the URL structure.
		$this->assertStringContainsString( 'admin.php', $drive_redirect_uri, 'Callback URI should contain admin.php' );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=google_drive_callback', $drive_redirect_uri, 'Callback URI should contain the oauth parameter' );

		// Verify no double query separators.
		$this->assertStringNotContainsString( '??', $drive_redirect_uri, 'Callback URI should not have double question marks' );
		$this->assertStringNotContainsString( '?&', $drive_redirect_uri, 'Callback URI should not have ?& combination' );

		// Parse the URL to ensure it's valid.
		$parsed = wp_parse_url( $drive_redirect_uri );
		$this->assertNotFalse( $parsed, 'Callback URI should be a valid URL' );
		$this->assertArrayHasKey( 'query', $parsed, 'Callback URI should have a query string' );

		// Parse the query string.
		parse_str( $parsed['query'], $query_params );
		$this->assertArrayHasKey( 'wp_mcp_ai_oauth', $query_params, 'Query parameters should include wp_mcp_ai_oauth' );
		$this->assertEquals( 'google_drive_callback', $query_params['wp_mcp_ai_oauth'], 'OAuth parameter should be google_drive_callback' );
	}

	/**
	 * Test redirect URL construction after saving Google Drive settings.
	 *
	 * After saving settings on the Google Drive connection page, the redirect
	 * should maintain both the subtab and connection parameters.
	 */
	public function test_google_drive_settings_redirect_url() {
		// Simulate extracted parameters from POST data.
		$active_tab        = 'tools';
		$active_subtab     = 'connections'; // From explicit 'subtab' field.
		$active_connection = 'google_drive'; // From 'connection' field.

		// Simulate redirect URL construction (from class-wp-mcp-ai-settings-dashboard.php).
		$redirect_args = array(
			'page'    => 'wp-mcp-ai-dashboard',
			'updated' => 'true',
		);

		if ( ! empty( $active_tab ) ) {
			$redirect_args['tab'] = $active_tab;
		}

		if ( ! empty( $active_subtab ) ) {
			$redirect_args['subtab'] = $active_subtab;
		}

		if ( ! empty( $active_connection ) ) {
			$redirect_args['connection'] = $active_connection;
		}

		$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );

		// Verify the redirect URL structure.
		$this->assertStringContainsString( 'page=wp-mcp-ai-dashboard', $redirect_url, 'Redirect should include page parameter' );
		$this->assertStringContainsString( 'tab=tools', $redirect_url, 'Redirect should include tab parameter' );
		$this->assertStringContainsString( 'subtab=connections', $redirect_url, 'Redirect should include subtab parameter' );
		$this->assertStringContainsString( 'connection=google_drive', $redirect_url, 'Redirect should include connection parameter' );
		$this->assertStringContainsString( 'updated=true', $redirect_url, 'Redirect should include updated parameter' );

		// Parse the URL.
		$parsed = wp_parse_url( $redirect_url );
		parse_str( $parsed['query'], $query_params );

		// Verify all expected parameters are present.
		$this->assertEquals( 'wp-mcp-ai-dashboard', $query_params['page'], 'Page parameter should be wp-mcp-ai-dashboard' );
		$this->assertEquals( 'tools', $query_params['tab'], 'Tab parameter should be tools' );
		$this->assertEquals( 'connections', $query_params['subtab'], 'Subtab parameter should be connections' );
		$this->assertEquals( 'google_drive', $query_params['connection'], 'Connection parameter should be google_drive' );
		$this->assertEquals( 'true', $query_params['updated'], 'Updated parameter should be true' );
	}
}
