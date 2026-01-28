<?php
/**
 * Tests for Remote Sites OAuth Redirect URI construction
 *
 * @package WP_MCP_AI
 */

/**
 * Test Remote Sites OAuth redirect URI construction.
 */
class Test_Remote_Sites_OAuth_Redirect_URI extends WP_UnitTestCase {
	/**
	 * Test Gmail OAuth redirect URI construction.
	 */
	public function test_gmail_oauth_redirect_uri_construction() {
		// Simulate the redirect URI construction as it would be done in the Remote Sites admin.
		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'gmail_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Parse the URL to verify all components are present.
		$parsed = wp_parse_url( $redirect_uri );
		$this->assertIsArray( $parsed, 'URL should be parsable' );

		// Check that the path is correct.
		$this->assertStringContainsString( '/wp-admin/admin.php', $parsed['path'], 'Path should contain /wp-admin/admin.php' );

		// Parse query string.
		parse_str( $parsed['query'], $query_params );

		// Verify required parameters are present.
		$this->assertArrayHasKey( 'page', $query_params, 'URL should have page parameter' );
		$this->assertArrayHasKey( 'oauth_handler', $query_params, 'URL should have oauth_handler parameter' );

		// Verify parameter values.
		$this->assertEquals( 'wp-mcp-ai-remote-sites', $query_params['page'], 'Page parameter should be wp-mcp-ai-remote-sites' );
		$this->assertEquals( 'gmail_oauth_callback', $query_params['oauth_handler'], 'OAuth handler parameter should be gmail_oauth_callback' );

		// Verify the full URL is properly constructed.
		$this->assertStringContainsString( 'page=wp-mcp-ai-remote-sites', $redirect_uri, 'URL should contain page parameter' );
		$this->assertStringContainsString( 'oauth_handler=gmail_oauth_callback', $redirect_uri, 'URL should contain oauth_handler parameter' );
	}

	/**
	 * Test Google Drive OAuth redirect URI construction.
	 */
	public function test_google_drive_oauth_redirect_uri_construction() {
		// Simulate the redirect URI construction as it would be done in the Remote Sites admin.
		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'google_drive_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Parse the URL to verify all components are present.
		$parsed = wp_parse_url( $redirect_uri );
		$this->assertIsArray( $parsed, 'URL should be parsable' );

		// Check that the path is correct.
		$this->assertStringContainsString( '/wp-admin/admin.php', $parsed['path'], 'Path should contain /wp-admin/admin.php' );

		// Parse query string.
		parse_str( $parsed['query'], $query_params );

		// Verify required parameters are present.
		$this->assertArrayHasKey( 'page', $query_params, 'URL should have page parameter' );
		$this->assertArrayHasKey( 'oauth_handler', $query_params, 'URL should have oauth_handler parameter' );

		// Verify parameter values.
		$this->assertEquals( 'wp-mcp-ai-remote-sites', $query_params['page'], 'Page parameter should be wp-mcp-ai-remote-sites' );
		$this->assertEquals( 'google_drive_oauth_callback', $query_params['oauth_handler'], 'OAuth handler parameter should be google_drive_oauth_callback' );

		// Verify the full URL is properly constructed.
		$this->assertStringContainsString( 'page=wp-mcp-ai-remote-sites', $redirect_uri, 'URL should contain page parameter' );
		$this->assertStringContainsString( 'oauth_handler=google_drive_oauth_callback', $redirect_uri, 'URL should contain oauth_handler parameter' );
	}

	/**
	 * Test that add_query_arg produces consistent URLs.
	 */
	public function test_redirect_uri_consistency() {
		// Construct the same URL multiple times to ensure consistency.
		$uri1 = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'gmail_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		$uri2 = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'gmail_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		$this->assertEquals( $uri1, $uri2, 'Multiple calls should produce identical URLs' );
	}

	/**
	 * Test that esc_url doesn't strip the oauth_handler parameter.
	 */
	public function test_esc_url_preserves_parameters() {
		// Construct a redirect URI.
		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'gmail_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Apply esc_url as would be done in the template.
		$escaped_uri = esc_url( $redirect_uri );

		// Verify both parameters are still present after escaping.
		$this->assertStringContainsString( 'page=wp-mcp-ai-remote-sites', $escaped_uri, 'esc_url should preserve page parameter' );
		$this->assertStringContainsString( 'oauth_handler=gmail_oauth_callback', $escaped_uri, 'esc_url should preserve oauth_handler parameter' );

		// Parse and verify.
		$parsed = wp_parse_url( $escaped_uri );
		parse_str( $parsed['query'], $query_params );

		$this->assertArrayHasKey( 'oauth_handler', $query_params, 'oauth_handler should be present after esc_url' );
		$this->assertEquals( 'gmail_oauth_callback', $query_params['oauth_handler'], 'oauth_handler value should be preserved' );
	}

	/**
	 * Test comparison between old and new URL construction methods.
	 */
	public function test_old_vs_new_url_construction() {
		// Old method (potentially problematic).
		$old_method_uri = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=gmail_oauth_callback' );

		// New method (correct).
		$new_method_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'gmail_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Both should have the oauth_handler parameter.
		$this->assertStringContainsString( 'oauth_handler=gmail_oauth_callback', $old_method_uri, 'Old method should contain oauth_handler' );
		$this->assertStringContainsString( 'oauth_handler=gmail_oauth_callback', $new_method_uri, 'New method should contain oauth_handler' );

		// Parse both URLs.
		$old_parsed = wp_parse_url( $old_method_uri );
		$new_parsed = wp_parse_url( $new_method_uri );

		parse_str( $old_parsed['query'], $old_params );
		parse_str( $new_parsed['query'], $new_params );

		// Both should have the oauth_handler parameter.
		$this->assertArrayHasKey( 'oauth_handler', $old_params, 'Old method should have oauth_handler parameter' );
		$this->assertArrayHasKey( 'oauth_handler', $new_params, 'New method should have oauth_handler parameter' );

		// Values should match.
		$this->assertEquals( $old_params['oauth_handler'], $new_params['oauth_handler'], 'oauth_handler values should match' );
	}
}
