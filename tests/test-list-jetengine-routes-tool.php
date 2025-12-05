<?php
/**
 * Test for list_jetengine_rest_routes tool message field fix.
 *
 * Verifies that the tool includes a 'message' field in its response
 * so the backend's extract_text_from_tool_results() function can
 * display the routes when the LLM returns no content.
 *
 * @package WP_MCP_AI
 */

// Mock JetEngine if not loaded.
if ( ! class_exists( 'Jet_Engine' ) ) {
	class Jet_Engine {
	}
}

if ( ! function_exists( 'jet_engine' ) ) {
	function jet_engine() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new Jet_Engine();
		}
		return $instance;
	}
}

/**
 * Test class for list_jetengine_rest_routes tool.
 */
class WP_MCP_AI_List_JetEngine_Routes_Tool_Test extends WP_UnitTestCase {

	/**
	 * Test that the tool includes a message field in its response.
	 */
	public function test_tool_includes_message_field() {
		// Create admin user for permissions.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Get the tool instance.
		$tool = new WP_MCP_AI_Tool_List_JetEngine_Routes();

		// Execute the tool.
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		// Verify the result is an array (not WP_Error).
		$this->assertIsArray( $result, 'Tool should return an array' );

		// CRITICAL: Verify the message field exists.
		// This is required for extract_text_from_tool_results() to work.
		$this->assertArrayHasKey( 'message', $result, 'Tool result must include message field for display' );
		$this->assertIsString( $result['message'], 'Message field should be a string' );
		$this->assertNotEmpty( $result['message'], 'Message field should not be empty' );

		// Verify the message contains expected text.
		$this->assertStringContainsString( 'JetEngine', $result['message'], 'Message should mention JetEngine' );
		$this->assertStringContainsString( 'REST', $result['message'], 'Message should mention REST' );

		// Verify other expected fields still exist.
		$this->assertArrayHasKey( 'namespace', $result, 'Tool result should include namespace' );
		$this->assertArrayHasKey( 'routes', $result, 'Tool result should include routes array' );
		$this->assertIsArray( $result['routes'], 'Routes should be an array' );
	}

	/**
	 * Test that the message field contains formatted route information.
	 */
	public function test_message_contains_formatted_routes() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_List_JetEngine_Routes();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$message = $result['message'];

		// Verify the message includes route details (method + path).
		$this->assertStringContainsString( 'GET', $message, 'Message should include HTTP methods' );
		$this->assertStringContainsString( '/search-posts/', $message, 'Message should include route paths' );

		// Verify numbered list format.
		$this->assertStringContainsString( '1.', $message, 'Message should include numbered list' );

		// Verify descriptions are included.
		$this->assertStringContainsString( 'Searches published posts', $message, 'Message should include route descriptions' );
	}

	/**
	 * Test filtering routes by path parameter.
	 */
	public function test_route_filtering_in_message() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_List_JetEngine_Routes();

		// Filter to only search routes.
		$result = $tool->execute(
			array( 'route' => 'search' ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );

		$message = $result['message'];

		// Should include search-posts route.
		$this->assertStringContainsString( 'search-posts', $message );

		// Should NOT include add-item route (doesn't match filter).
		$this->assertStringNotContainsString( 'add-item', $message );
	}

	/**
	 * Test that non-admin users get permission error.
	 */
	public function test_requires_admin_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_List_JetEngine_Routes();
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Non-admin users should get WP_Error' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}
}
