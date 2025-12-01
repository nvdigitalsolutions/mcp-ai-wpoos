<?php
/**
 * Tests for GitHub Client
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test GitHub API client functionality.
 */
class Test_Github_Client extends WP_UnitTestCase {
	/**
	 * Test that GitHub client class exists.
	 */
	public function test_github_client_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Github_Client' ) );
	}

	/**
	 * Test that GitHub client can be instantiated.
	 */
	public function test_github_client_instantiation() {
		$client = new WP_MCP_AI_Github_Client( 'test_token' );
		$this->assertInstanceOf( 'WP_MCP_AI_Github_Client', $client );
	}

	/**
	 * Test that client returns error when no token is provided.
	 */
	public function test_client_requires_token() {
		$client = new WP_MCP_AI_Github_Client( '' );
		$result = $client->get_user();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_github_no_token', $result->get_error_code() );
	}

	/**
	 * Test that client loads token from settings if not provided.
	 */
	public function test_client_loads_token_from_settings() {
		// Set token in settings.
		update_option(
			'wp_mcp_ai_settings',
			array( 'github_access_token' => 'test_token_from_settings' )
		);

		$client = new WP_MCP_AI_Github_Client();

		// Use reflection to check private property.
		$reflection = new ReflectionClass( $client );
		$property   = $reflection->getProperty( 'access_token' );
		$property->setAccessible( true );

		$this->assertEquals( 'test_token_from_settings', $property->getValue( $client ) );
	}
}
