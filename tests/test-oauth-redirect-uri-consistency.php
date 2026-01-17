<?php
/**
 * Test OAuth redirect URI consistency.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests to ensure OAuth redirect URIs are generated consistently.
 */
class Test_OAuth_Redirect_URI_Consistency extends WP_UnitTestCase {

	/**
	 * Test that Gmail redirect URI is generated consistently.
	 */
	public function test_gmail_redirect_uri_consistency() {
		// Simulate what the code does.
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			admin_url( 'admin.php' )
		);

		// Verify the URL structure.
		$this->assertStringContainsString( 'admin.php', $redirect_uri, 'Redirect URI should contain admin.php' );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=gmail_callback', $redirect_uri, 'Redirect URI should contain the oauth parameter' );

		// Verify no double query separators.
		$this->assertStringNotContainsString( '??', $redirect_uri, 'Redirect URI should not have double question marks' );
		$this->assertStringNotContainsString( '?&', $redirect_uri, 'Redirect URI should not have ?& combination' );

		// Parse the URL to ensure it's valid.
		$parsed = wp_parse_url( $redirect_uri );
		$this->assertNotFalse( $parsed, 'Redirect URI should be a valid URL' );
		$this->assertArrayHasKey( 'query', $parsed, 'Redirect URI should have a query string' );

		// Parse the query string.
		parse_str( $parsed['query'], $query_params );
		$this->assertArrayHasKey( 'wp_mcp_ai_oauth', $query_params, 'Query parameters should include wp_mcp_ai_oauth' );
		$this->assertEquals( 'gmail_callback', $query_params['wp_mcp_ai_oauth'], 'OAuth parameter should be gmail_callback' );
	}

	/**
	 * Test that Google Drive redirect URI is generated consistently.
	 */
	public function test_google_drive_redirect_uri_consistency() {
		// Simulate what the code does.
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'google_drive_callback' ),
			admin_url( 'admin.php' )
		);

		// Verify the URL structure.
		$this->assertStringContainsString( 'admin.php', $redirect_uri, 'Redirect URI should contain admin.php' );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=google_drive_callback', $redirect_uri, 'Redirect URI should contain the oauth parameter' );

		// Verify no double query separators.
		$this->assertStringNotContainsString( '??', $redirect_uri, 'Redirect URI should not have double question marks' );
		$this->assertStringNotContainsString( '?&', $redirect_uri, 'Redirect URI should not have ?& combination' );

		// Parse the URL to ensure it's valid.
		$parsed = wp_parse_url( $redirect_uri );
		$this->assertNotFalse( $parsed, 'Redirect URI should be a valid URL' );
		$this->assertArrayHasKey( 'query', $parsed, 'Redirect URI should have a query string' );

		// Parse the query string.
		parse_str( $parsed['query'], $query_params );
		$this->assertArrayHasKey( 'wp_mcp_ai_oauth', $query_params, 'Query parameters should include wp_mcp_ai_oauth' );
		$this->assertEquals( 'google_drive_callback', $query_params['wp_mcp_ai_oauth'], 'OAuth parameter should be google_drive_callback' );
	}

	/**
	 * Test that redirect URI is properly encoded when used in OAuth URL.
	 */
	public function test_redirect_uri_encoding_in_oauth_url() {
		// Build redirect URI.
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			admin_url( 'admin.php' )
		);

		// Build OAuth authorization URL (simulating what OAuth manager does).
		$params = array(
			'client_id'     => 'test-client-id',
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/gmail.readonly',
			'state'         => 'test-state',
		);

		$authorize_url = add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );

		// Verify the OAuth URL structure.
		$this->assertStringContainsString( 'accounts.google.com', $authorize_url, 'OAuth URL should contain Google OAuth domain' );
		$this->assertStringContainsString( 'client_id=', $authorize_url, 'OAuth URL should contain client_id parameter' );
		$this->assertStringContainsString( 'redirect_uri=', $authorize_url, 'OAuth URL should contain redirect_uri parameter' );

		// Parse the OAuth URL.
		$parsed = wp_parse_url( $authorize_url );
		$this->assertNotFalse( $parsed, 'OAuth URL should be valid' );

		// Parse the query parameters.
		parse_str( $parsed['query'], $oauth_params );

		// Verify the redirect_uri parameter is present and properly encoded/decoded.
		$this->assertArrayHasKey( 'redirect_uri', $oauth_params, 'OAuth URL should have redirect_uri parameter' );

		// The redirect_uri value should match what we originally created.
		$this->assertEquals( $redirect_uri, $oauth_params['redirect_uri'], 'Redirect URI should be properly encoded and decodable' );

		// Verify the decoded redirect_uri contains the expected parameter.
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=gmail_callback', $oauth_params['redirect_uri'], 'Decoded redirect_uri should contain the callback parameter' );
	}

	/**
	 * Test that old-style and new-style URL generation produce the same result.
	 */
	public function test_old_vs_new_url_generation() {
		// Old style (passing query string directly).
		$old_style = admin_url( 'admin.php?wp_mcp_ai_oauth=gmail_callback' );

		// New style (using add_query_arg).
		$new_style = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			admin_url( 'admin.php' )
		);

		// Both should produce functionally equivalent URLs.
		$this->assertEquals( $old_style, $new_style, 'Old and new URL generation methods should produce the same result' );
	}
}
