<?php
/**
 * Test that League OAuth2 Client doesn't send approval_prompt parameter.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests to ensure League OAuth2 Client integration doesn't send conflicting OAuth parameters.
 */
class Test_League_OAuth2_No_Approval_Prompt extends WP_UnitTestCase {

	/**
	 * Test that League OAuth2 Client doesn't include approval_prompt in authorization URL.
	 */
	public function test_league_oauth_no_approval_prompt() {
		if ( ! class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
			$this->markTestSkipped( 'League OAuth2 Client is not available' );
		}

		$provider = new \League\OAuth2\Client\Provider\GenericProvider(
			array(
				'clientId'                => 'test-client-id',
				'clientSecret'            => 'test-client-secret',
				'redirectUri'             => 'https://example.com/callback',
				'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
				'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
				'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
				'scopes'                  => 'https://www.googleapis.com/auth/gmail.readonly',
			)
		);

		// Get authorization URL with prompt=consent.
		$authorize_url = $provider->getAuthorizationUrl(
			array(
				'state'                  => 'test-state-12345',
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
			)
		);

		// Parse the URL to check parameters.
		$parsed = wp_parse_url( $authorize_url );
		$this->assertNotFalse( $parsed, 'Authorization URL should be valid' );
		$this->assertArrayHasKey( 'query', $parsed, 'Authorization URL should have query parameters' );

		parse_str( $parsed['query'], $query_params );

		// Verify prompt parameter is present.
		$this->assertArrayHasKey( 'prompt', $query_params, 'Authorization URL should have prompt parameter' );
		$this->assertEquals( 'consent', $query_params['prompt'], 'prompt parameter should be "consent"' );

		// Verify approval_prompt parameter is NOT present.
		$this->assertArrayNotHasKey( 'approval_prompt', $query_params, 'Authorization URL should NOT have deprecated approval_prompt parameter' );

		// Verify other expected parameters are present.
		$this->assertArrayHasKey( 'client_id', $query_params, 'Authorization URL should have client_id parameter' );
		$this->assertArrayHasKey( 'redirect_uri', $query_params, 'Authorization URL should have redirect_uri parameter' );
		$this->assertArrayHasKey( 'state', $query_params, 'Authorization URL should have state parameter' );
		$this->assertArrayHasKey( 'scope', $query_params, 'Authorization URL should have scope parameter' );
		$this->assertArrayHasKey( 'response_type', $query_params, 'Authorization URL should have response_type parameter' );
		$this->assertArrayHasKey( 'access_type', $query_params, 'Authorization URL should have access_type parameter' );
		$this->assertArrayHasKey( 'include_granted_scopes', $query_params, 'Authorization URL should have include_granted_scopes parameter' );

		// Verify parameter values.
		$this->assertEquals( 'code', $query_params['response_type'], 'response_type should be "code"' );
		$this->assertEquals( 'offline', $query_params['access_type'], 'access_type should be "offline"' );
		$this->assertEquals( 'true', $query_params['include_granted_scopes'], 'include_granted_scopes should be "true"' );
	}

	/**
	 * Test that Google Drive OAuth URL generation doesn't include approval_prompt.
	 */
	public function test_google_drive_oauth_url_no_approval_prompt() {
		if ( ! class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
			$this->markTestSkipped( 'League OAuth2 Client is not available' );
		}

		// Simulate the Google Drive OAuth URL generation from OAuth Manager.
		$provider = new \League\OAuth2\Client\Provider\GenericProvider(
			array(
				'clientId'                => 'test-client-id',
				'clientSecret'            => 'test-client-secret',
				'redirectUri'             => admin_url( 'admin.php?wp_mcp_ai_oauth=google_drive_callback' ),
				'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
				'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
				'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
				'scopes'                  => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.metadata.readonly',
			)
		);

		$authorize_url = $provider->getAuthorizationUrl(
			array(
				'state'                  => 'test-state-67890',
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
			)
		);

		$parsed = wp_parse_url( $authorize_url );
		parse_str( $parsed['query'], $query_params );

		// The critical test: approval_prompt should NOT be present.
		$this->assertArrayNotHasKey(
			'approval_prompt',
			$query_params,
			'Google Drive OAuth URL should NOT contain deprecated approval_prompt parameter that conflicts with prompt'
		);

		// Verify prompt is present instead.
		$this->assertArrayHasKey( 'prompt', $query_params, 'Google Drive OAuth URL should have prompt parameter' );
		$this->assertEquals( 'consent', $query_params['prompt'], 'prompt parameter should be "consent"' );
	}
}
