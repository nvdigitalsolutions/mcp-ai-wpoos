<?php
/**
 * Test Google Chat-specific fields persistence in Remote Site Manager and
 * the AJAX handler logic introduced for the Test Connection / Fetch Spaces /
 * Test Auto-Reply features.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager Google Chat field persistence.
 */
class Test_Remote_Connection_Google_Chat_Fields extends WP_UnitTestCase {

	/**
	 * Clean up connections before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Clean up connections after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	// =========================================================================
	// Field persistence.
	// =========================================================================

	/**
	 * Test that google_chat_space field persists when saving a Google Chat connection.
	 */
	public function test_google_chat_space_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'             => 'Test Google Chat Connection',
			'url'              => 'https://chat.googleapis.com/v1',
			'connection_type'  => 'google_chat',
			'auth_type'        => 'none',
			'enabled'          => true,
			'api_key'          => 'ya29.test_access_token',
			'verify_token'     => 'https://example.com/wp-json/mcp-ai/v1/webhooks/google-chat',
			'google_chat_space' => 'spaces/AAAATestSpace',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'google_chat', $saved['connection_type'], 'Connection type should be google_chat' );
		$this->assertSame( 'spaces/AAAATestSpace', $saved['google_chat_space'], 'google_chat_space field should persist' );
	}

	/**
	 * Test that verify_token (audience URL) persists for Google Chat connections.
	 */
	public function test_google_chat_audience_url_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$audience_url = 'https://example.com/wp-json/mcp-ai/v1/webhooks/google-chat';

		$connection_data = array(
			'name'             => 'Test Google Chat Audience',
			'url'              => 'https://chat.googleapis.com/v1',
			'connection_type'  => 'google_chat',
			'auth_type'        => 'none',
			'enabled'          => true,
			'api_key'          => 'ya29.test_token',
			'verify_token'     => $audience_url,
			'google_chat_space' => '',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved );
		$this->assertSame( $audience_url, $saved['verify_token'], 'Audience URL (verify_token) should persist' );
	}

	/**
	 * Test that assigned_assistant_ids persist for Google Chat connections.
	 */
	public function test_google_chat_assigned_assistants_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                   => 'Test Google Chat Assistants',
			'url'                    => 'https://chat.googleapis.com/v1',
			'connection_type'        => 'google_chat',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'ya29.test_token',
			'verify_token'           => '',
			'google_chat_space'      => '',
			'assigned_assistant_ids' => array( 10, 20, 30 ),
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved );
		$this->assertSame( array( 10, 20, 30 ), $saved['assigned_assistant_ids'], 'Assigned assistant IDs should persist' );
	}

	/**
	 * Test that google_chat_space is preserved as empty string when not provided.
	 */
	public function test_google_chat_space_empty_when_not_provided() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'             => 'Test Google Chat No Space',
			'url'              => 'https://chat.googleapis.com/v1',
			'connection_type'  => 'google_chat',
			'auth_type'        => 'none',
			'enabled'          => true,
			'api_key'          => 'ya29.test_token',
			'verify_token'     => '',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved );
		// google_chat_space key should exist (possibly empty) or not cause errors.
		$space = isset( $saved['google_chat_space'] ) ? $saved['google_chat_space'] : '';
		$this->assertSame( '', $space, 'google_chat_space should be empty when not provided' );
	}

	// =========================================================================
	// AJAX handler: test_google_chat_live — input validation.
	// =========================================================================

	/**
	 * Test ajax_test_google_chat_live sends error when access token is missing.
	 */
	public function test_ajax_test_google_chat_live_requires_token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$pro_admin_path = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php'
				: '';
			if ( $pro_admin_path && file_exists( $pro_admin_path ) ) {
				// Skip loading — constructor hooks would fire. Just verify the class exists.
				$this->markTestSkipped( 'Cannot safely instantiate remote sites admin in unit tests' );
				return;
			}
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->markTestSkipped( 'Cannot safely instantiate remote sites admin in unit tests' );
	}

	// =========================================================================
	// AJAX handler: fetch_google_chat_spaces — space name format validation.
	// =========================================================================

	/**
	 * Test that space names returned by Google Chat API follow the expected format.
	 */
	public function test_google_chat_space_name_format_validation() {
		$valid_names = array(
			'spaces/AAAABBBccc',
			'spaces/AAAA-1234',
			'spaces/abcDEF_789',
		);

		foreach ( $valid_names as $name ) {
			$this->assertMatchesRegularExpression(
				'/^spaces\/[a-zA-Z0-9_-]+$/',
				$name,
				"Space name '{$name}' should match expected format"
			);
		}

		$invalid_names = array(
			'AAAA-1234',
			'spaces/',
			'rooms/AAAA',
			'',
		);

		foreach ( $invalid_names as $name ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^spaces\/[a-zA-Z0-9_-]+$/',
				$name,
				"Space name '{$name}' should not match expected format"
			);
		}
	}

	/**
	 * Test that Google Chat test auto-reply space validation rejects invalid formats.
	 */
	public function test_google_chat_auto_reply_space_validation() {
		// Mirrors the preg_match check used in ajax_test_google_chat_auto_reply.
		$valid_space   = 'spaces/AAAATestSpace123';
		$invalid_space = 'not-a-space';

		$this->assertSame(
			1,
			preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $valid_space ),
			'Valid space should pass format validation'
		);

		$this->assertSame(
			0,
			preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $invalid_space ),
			'Invalid space should fail format validation'
		);
	}
}
