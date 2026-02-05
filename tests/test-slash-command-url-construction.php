<?php
/**
 * Tests for Slash Command URL Construction
 *
 * Validates that the slash command REST API URL is properly constructed
 * using the correct pattern to prevent double path segments.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Slash Command URL Construction.
 */
class Test_Slash_Command_URL_Construction extends WP_UnitTestCase {

	/**
	 * Test that restUrl is constructed with proper pattern.
	 *
	 * This test validates the fix for PR #3580 where the URL
	 * was incorrectly generated as:
	 * /wp-json/mcp-ai/v1//mcp-ai/v1/slash-command/list
	 * instead of:
	 * /wp-json/mcp-ai/v1/slash-command/list
	 */
	public function test_rest_url_uses_constant() {
		// Initialize slash commands.
		do_action( 'init' );

		// Get localized data.
		global $wp_scripts;
		$script_data = $wp_scripts->get_data( 'mcp-ai-slash-commands', 'data' );

		// Verify mcpAiData is localized.
		$this->assertStringContainsString( 'mcpAiData', $script_data, 'Script should have mcpAiData localized' );

		// Extract the JSON data.
		preg_match( '/var mcpAiData = ({.*?});/', $script_data, $matches );
		$this->assertNotEmpty( $matches, 'Should find mcpAiData JSON' );

		$data = json_decode( $matches[1], true );
		$this->assertIsArray( $data, 'mcpAiData should be valid JSON' );
		$this->assertArrayHasKey( 'restUrl', $data, 'mcpAiData should have restUrl key' );

		$rest_url = $data['restUrl'];

		// Verify URL ends with trailing slash.
		$this->assertStringEndsWith( '/', $rest_url, 'restUrl should have a trailing slash' );

		// Verify URL contains /mcp-ai/v1/ exactly once.
		$this->assertStringContainsString( '/mcp-ai/v1/', $rest_url, 'restUrl should contain /mcp-ai/v1/' );

		// Count occurrences of 'mcp-ai/v1' - should be exactly 1.
		$count = substr_count( $rest_url, 'mcp-ai/v1' );
		$this->assertEquals( 1, $count, 'restUrl should contain mcp-ai/v1 exactly once, not duplicated' );

		// Verify no double slashes (except in protocol).
		$url_without_protocol = preg_replace( '#^https?://#', '', $rest_url );
		$this->assertStringNotContainsString( '//', $url_without_protocol, 'restUrl should not contain double slashes in path' );

		// Simulate JavaScript concatenation.
		$endpoint_url = $rest_url . 'slash-command/list';

		// Verify final URL format is correct.
		$this->assertStringEndsWith( '/mcp-ai/v1/slash-command/list', $endpoint_url, 'Final endpoint URL should be correctly formed' );

		// Verify the endpoint doesn't have duplicate namespace.
		$namespace_count = substr_count( $endpoint_url, 'mcp-ai/v1' );
		$this->assertEquals( 1, $namespace_count, 'Final endpoint URL should contain namespace exactly once' );
	}

	/**
	 * Test that slash command list endpoint is accessible.
	 */
	public function test_slash_command_list_endpoint_format() {
		// Get REST server.
		$rest_server = rest_get_server();
		$routes      = $rest_server->get_routes();

		// Verify the route exists with correct format.
		$this->assertArrayHasKey( '/mcp-ai/v1/slash-command/list', $routes, 'Slash command list route should exist' );

		// Verify route doesn't have duplicate namespace.
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'slash-command' ) !== false ) {
				$namespace_count = substr_count( $route, 'mcp-ai/v1' );
				$this->assertEquals( 1, $namespace_count, "Route $route should contain namespace exactly once" );
			}
		}
	}
}
