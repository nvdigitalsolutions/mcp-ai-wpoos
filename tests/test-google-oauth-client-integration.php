<?php
/**
 * Test Google OAuth Client integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests to ensure Google OAuth Client library integration works correctly.
 */
class Test_Google_OAuth_Client_Integration extends WP_UnitTestCase {

	/**
	 * Test that Google_Client class is available.
	 */
	public function test_google_client_class_exists() {
		$this->assertTrue( class_exists( 'Google_Client' ), 'Google_Client class should be available' );
	}

	/**
	 * Test that Google_Client can generate authorization URLs correctly.
	 */
	public function test_google_client_auth_url_generation() {
		if ( ! class_exists( 'Google_Client' ) ) {
			$this->markTestSkipped( 'Google_Client is not available' );
		}

		$client = new Google_Client();
		$client->setClientId( 'test-client-id' );
		$client->setClientSecret( 'test-client-secret' );

		// Set redirect URI using WordPress admin_url and add_query_arg.
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			admin_url( 'admin.php' )
		);
		$client->setRedirectUri( $redirect_uri );

		$client->addScope( 'https://www.googleapis.com/auth/gmail.readonly' );
		$client->setAccessType( 'offline' );
		$client->setIncludeGrantedScopes( true );
		$client->setPrompt( 'consent' );
		$client->setState( 'test-state-12345' );

		$auth_url = $client->createAuthUrl();

		// Verify the authorization URL structure.
		$this->assertStringContainsString( 'accounts.google.com', $auth_url, 'Auth URL should contain Google OAuth domain' );
		$this->assertStringContainsString( 'client_id=', $auth_url, 'Auth URL should contain client_id parameter' );
		$this->assertStringContainsString( 'redirect_uri=', $auth_url, 'Auth URL should contain redirect_uri parameter' );
		$this->assertStringContainsString( 'state=test-state-12345', $auth_url, 'Auth URL should contain state parameter' );
		$this->assertStringContainsString( 'scope=', $auth_url, 'Auth URL should contain scope parameter' );

		// Parse the URL and verify redirect_uri is properly encoded.
		$parsed = wp_parse_url( $auth_url );
		$this->assertNotFalse( $parsed, 'Auth URL should be a valid URL' );
		$this->assertArrayHasKey( 'query', $parsed, 'Auth URL should have a query string' );

		parse_str( $parsed['query'], $query_params );
		$this->assertArrayHasKey( 'redirect_uri', $query_params, 'Query parameters should include redirect_uri' );

		// The redirect_uri should contain the callback parameter.
		$decoded_redirect_uri = urldecode( $query_params['redirect_uri'] );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=gmail_callback', $decoded_redirect_uri, 'Decoded redirect_uri should contain the callback parameter' );
	}

	/**
	 * Test that redirect URI is consistently generated.
	 */
	public function test_redirect_uri_consistency() {
		// Test both Google_Client and manual generation.
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			admin_url( 'admin.php' )
		);

		// Manual URL generation (fallback method).
		$manual_params = array(
			'client_id'              => 'test-client-id',
			'redirect_uri'           => $redirect_uri,
			'response_type'          => 'code',
			'scope'                  => 'https://www.googleapis.com/auth/gmail.readonly',
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => 'test-state-12345',
		);
		$manual_url    = add_query_arg( $manual_params, 'https://accounts.google.com/o/oauth2/v2/auth' );

		// Parse manual URL.
		$manual_parsed = wp_parse_url( $manual_url );
		parse_str( $manual_parsed['query'], $manual_query_params );

		// Both should have the same redirect_uri value.
		$this->assertArrayHasKey( 'redirect_uri', $manual_query_params, 'Manual URL should have redirect_uri parameter' );
		$this->assertEquals( $redirect_uri, $manual_query_params['redirect_uri'], 'Redirect URI should match' );

		// If Google_Client is available, compare with its output.
		if ( class_exists( 'Google_Client' ) ) {
			$client = new Google_Client();
			$client->setClientId( 'test-client-id' );
			$client->setClientSecret( 'test-client-secret' );
			$client->setRedirectUri( $redirect_uri );
			$client->addScope( 'https://www.googleapis.com/auth/gmail.readonly' );
			$client->setAccessType( 'offline' );
			$client->setIncludeGrantedScopes( true );
			$client->setPrompt( 'consent' );
			$client->setState( 'test-state-12345' );

			$google_url    = $client->createAuthUrl();
			$google_parsed = wp_parse_url( $google_url );
			parse_str( $google_parsed['query'], $google_query_params );

			$this->assertArrayHasKey( 'redirect_uri', $google_query_params, 'Google Client URL should have redirect_uri parameter' );

			// The redirect URIs should be functionally equivalent (Google_Client may encode differently but decode to same value).
			$this->assertEquals(
				urldecode( $manual_query_params['redirect_uri'] ),
				urldecode( $google_query_params['redirect_uri'] ),
				'Redirect URIs should decode to the same value'
			);
		}
	}

	/**
	 * Test that redirect URI preserves all parameters.
	 */
	public function test_redirect_uri_preserves_parameters() {
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			admin_url( 'admin.php' )
		);

		// Parse the redirect URI.
		$parsed = wp_parse_url( $redirect_uri );
		$this->assertArrayHasKey( 'query', $parsed, 'Redirect URI should have a query string' );

		parse_str( $parsed['query'], $query_params );
		$this->assertArrayHasKey( 'wp_mcp_ai_oauth', $query_params, 'Redirect URI should preserve the callback parameter' );
		$this->assertEquals( 'gmail_callback', $query_params['wp_mcp_ai_oauth'], 'Callback parameter should have correct value' );
	}

	/**
	 * Test that OAuth manager can build redirect URIs correctly.
	 */
	public function test_oauth_manager_redirect_uri() {
		// Simulate what the OAuth manager does.
		$base_url     = admin_url( 'admin.php' );
		$redirect_uri = add_query_arg(
			array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
			$base_url
		);

		// Verify it's a valid URL.
		$this->assertNotEmpty( $redirect_uri, 'Redirect URI should not be empty' );

		$parsed = wp_parse_url( $redirect_uri );
		$this->assertNotFalse( $parsed, 'Redirect URI should be a valid URL' );
		$this->assertArrayHasKey( 'scheme', $parsed, 'Redirect URI should have a scheme' );
		$this->assertArrayHasKey( 'host', $parsed, 'Redirect URI should have a host' );
		$this->assertArrayHasKey( 'path', $parsed, 'Redirect URI should have a path' );
		$this->assertArrayHasKey( 'query', $parsed, 'Redirect URI should have a query string' );

		// Verify it contains the expected components.
		$this->assertStringContainsString( 'admin.php', $redirect_uri, 'Redirect URI should contain admin.php' );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=gmail_callback', $redirect_uri, 'Redirect URI should contain the callback parameter' );
	}
}
