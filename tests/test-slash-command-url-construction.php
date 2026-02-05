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
	 * Test that endpoint URLs are constructed with proper pattern.
	 *
	 * This test validates the fix for PR #3580 where the URL
	 * was incorrectly generated as:
	 * /wp-json/mcp-ai/v1//mcp-ai/v1/slash-command/list
	 * instead of:
	 * /wp-json/mcp-ai/v1/slash-command/list
	 *
	 * The fix provides complete endpoint URLs (like chat.js does) rather than
	 * requiring JavaScript concatenation.
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
		
		// Check that specific endpoint URLs are provided.
		$this->assertArrayHasKey( 'slashCommandEndpoint', $data, 'mcpAiData should have slashCommandEndpoint key' );
		$this->assertArrayHasKey( 'slashCommandListEndpoint', $data, 'mcpAiData should have slashCommandListEndpoint key' );

		// Also check backward compatibility with restUrl.
		$this->assertArrayHasKey( 'restUrl', $data, 'mcpAiData should have restUrl key for backward compatibility' );

		// Test slashCommandEndpoint.
		$slash_command_endpoint = $data['slashCommandEndpoint'];
		$this->assertStringEndsWith( '/slash-command', $slash_command_endpoint, 'slashCommandEndpoint should end with /slash-command' );
		
		// Count occurrences of 'mcp-ai/v1' - should be exactly 1.
		$count = substr_count( $slash_command_endpoint, 'mcp-ai/v1' );
		$this->assertEquals( 1, $count, 'slashCommandEndpoint should contain mcp-ai/v1 exactly once, not duplicated' );

		// Verify no double slashes (except in protocol).
		$url_without_protocol = preg_replace( '#^https?://#', '', $slash_command_endpoint );
		$this->assertStringNotContainsString( '//', $url_without_protocol, 'slashCommandEndpoint should not contain double slashes in path' );

		// Test slashCommandListEndpoint.
		$slash_command_list_endpoint = $data['slashCommandListEndpoint'];
		$this->assertStringEndsWith( '/slash-command/list', $slash_command_list_endpoint, 'slashCommandListEndpoint should end with /slash-command/list' );

		// Count occurrences of 'mcp-ai/v1' - should be exactly 1.
		$count = substr_count( $slash_command_list_endpoint, 'mcp-ai/v1' );
		$this->assertEquals( 1, $count, 'slashCommandListEndpoint should contain mcp-ai/v1 exactly once, not duplicated' );

		// Verify no double slashes (except in protocol).
		$url_without_protocol = preg_replace( '#^https?://#', '', $slash_command_list_endpoint );
		$this->assertStringNotContainsString( '//', $url_without_protocol, 'slashCommandListEndpoint should not contain double slashes in path' );

		// Test backward compatibility with restUrl.
		$rest_url = $data['restUrl'];
		$this->assertStringEndsWith( '/', $rest_url, 'restUrl should have a trailing slash' );
		$this->assertStringContainsString( '/mcp-ai/v1/', $rest_url, 'restUrl should contain /mcp-ai/v1/' );
		
		$count = substr_count( $rest_url, 'mcp-ai/v1' );
		$this->assertEquals( 1, $count, 'restUrl should contain mcp-ai/v1 exactly once, not duplicated' );
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
