<?php
/**
 * Tests for Google Drive OAuth Handler
 *
 * @package WP_MCP_AI
 */

/**
 * Test Google Drive OAuth Handler.
 */
class Test_Google_Drive_OAuth_Handler extends WP_UnitTestCase {

	/**
	 * Test that the Google Drive OAuth handler class exists.
	 */
	public function test_google_drive_oauth_handler_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Google_Drive_OAuth_Handler' ) );
	}

	/**
	 * Test that OAuth constants are defined correctly.
	 */
	public function test_google_drive_oauth_constants() {
		$this->assertEquals( 
			'https://accounts.google.com/o/oauth2/v2/auth',
			WP_MCP_AI_Google_Drive_OAuth_Handler::GOOGLE_DRIVE_OAUTH_AUTHORIZE_ENDPOINT
		);
		$this->assertEquals(
			'https://oauth2.googleapis.com/token',
			WP_MCP_AI_Google_Drive_OAuth_Handler::GOOGLE_DRIVE_OAUTH_TOKEN_ENDPOINT
		);
		$this->assertEquals(
			'https://www.googleapis.com/drive/v3',
			WP_MCP_AI_Google_Drive_OAuth_Handler::GOOGLE_DRIVE_API_BASE
		);
	}

	/**
	 * Test that OAuth scopes are defined for Drive read access.
	 */
	public function test_google_drive_oauth_scopes() {
		$scopes = WP_MCP_AI_Google_Drive_OAuth_Handler::GOOGLE_DRIVE_OAUTH_SCOPES;
		$this->assertStringContainsString( 'drive.readonly', $scopes );
		$this->assertStringContainsString( 'drive.metadata.readonly', $scopes );
	}

	/**
	 * Test that the Google Drive OAuth handler can be instantiated.
	 */
	public function test_google_drive_oauth_handler_instantiation() {
		$handler = new WP_MCP_AI_Google_Drive_OAuth_Handler();
		$this->assertInstanceOf( 'WP_MCP_AI_Google_Drive_OAuth_Handler', $handler );
	}

	/**
	 * Test that OAuth redirect host is added to allowed hosts.
	 */
	public function test_google_drive_oauth_redirect_host() {
		$handler = new WP_MCP_AI_Google_Drive_OAuth_Handler();
		$allowed_hosts = array( 'example.com' );
		$filtered_hosts = $handler->allow_google_drive_oauth_redirect_host( $allowed_hosts );

		$this->assertContains( 'accounts.google.com', $filtered_hosts );
		$this->assertContains( 'example.com', $filtered_hosts );
	}
}
