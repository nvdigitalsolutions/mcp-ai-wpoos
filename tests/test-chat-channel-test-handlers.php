<?php
/**
 * Test Slack, Discord, Microsoft Teams, and Telegram chat channel test handler registration.
 *
 * Verifies that AJAX handlers and admin UI elements for testing incoming/outgoing
 * messages to groups and channels are properly registered.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Slack, Discord, Teams, and Telegram test handler registration.
 */
class Test_Chat_Channel_Test_Handlers extends WP_UnitTestCase {

	/**
	 * Clean up connections before and after each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		// Production only instantiates the admin class under is_admin(), so
		// its channel test AJAX handlers are never hooked under PHPUnit.
		// Load and instantiate it here to make the registrations observable.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) && file_exists( WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php';
		}

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) && ! has_action( 'wp_ajax_wp_mcp_ai_test_slack_live' ) ) {
			new WP_MCP_AI_Pro_Remote_Sites_Admin();
		}
	}

	/**
	 * Clean up connections after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	/**
	 * Test that the Slack test live AJAX action is registered.
	 */
	public function test_slack_test_live_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_slack_live' ),
			'The wp_ajax_wp_mcp_ai_test_slack_live action should be registered'
		);
	}

	/**
	 * Test that the Slack test auto-reply AJAX action is registered.
	 */
	public function test_slack_test_auto_reply_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_slack_auto_reply' ),
			'The wp_ajax_wp_mcp_ai_test_slack_auto_reply action should be registered'
		);
	}

	/**
	 * Test that the Discord test live AJAX action is registered.
	 */
	public function test_discord_test_live_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_discord_live' ),
			'The wp_ajax_wp_mcp_ai_test_discord_live action should be registered'
		);
	}

	/**
	 * Test that the Discord test auto-reply AJAX action is registered.
	 */
	public function test_discord_test_auto_reply_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_discord_auto_reply' ),
			'The wp_ajax_wp_mcp_ai_test_discord_auto_reply action should be registered'
		);
	}

	/**
	 * Test that the Teams test live AJAX action is registered.
	 */
	public function test_teams_test_live_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_teams_live' ),
			'The wp_ajax_wp_mcp_ai_test_teams_live action should be registered'
		);
	}

	/**
	 * Test that the Teams test auto-reply AJAX action is registered.
	 */
	public function test_teams_test_auto_reply_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_teams_auto_reply' ),
			'The wp_ajax_wp_mcp_ai_test_teams_auto_reply action should be registered'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_slack_live method.
	 */
	public function test_admin_class_has_slack_live_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_slack_live' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_slack_live method'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_slack_auto_reply method.
	 */
	public function test_admin_class_has_slack_auto_reply_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_slack_auto_reply' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_slack_auto_reply method'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_discord_live method.
	 */
	public function test_admin_class_has_discord_live_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_discord_live' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_discord_live method'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_discord_auto_reply method.
	 */
	public function test_admin_class_has_discord_auto_reply_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_discord_auto_reply' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_discord_auto_reply method'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_teams_live method.
	 */
	public function test_admin_class_has_teams_live_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_teams_live' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_teams_live method'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_teams_auto_reply method.
	 */
	public function test_admin_class_has_teams_auto_reply_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_teams_auto_reply' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_teams_auto_reply method'
		);
	}

	/**
	 * Test that the Telegram send group AJAX action is registered.
	 */
	public function test_telegram_send_group_ajax_action_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_test_telegram_send_group' ),
			'The wp_ajax_wp_mcp_ai_test_telegram_send_group action should be registered'
		);
	}

	/**
	 * Test that the admin class has the ajax_test_telegram_send_group method.
	 */
	public function test_admin_class_has_telegram_send_group_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin', 'ajax_test_telegram_send_group' ),
			'WP_MCP_AI_Pro_Remote_Sites_Admin should have ajax_test_telegram_send_group method'
		);
	}

	/**
	 * Test that a Slack connection can be saved and retrieved with assigned assistants.
	 */
	public function test_slack_connection_saves_with_assigned_assistants() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                  => 'Test Slack Bot',
			'url'                   => 'https://slack.com/api',
			'connection_type'       => 'slack',
			'auth_type'             => 'none',
			'enabled'               => true,
			'api_key'               => 'xoxb-test-slack-bot-token',
			'signing_secret'        => 'test_signing_secret',
			'workspace_id'          => 'T0123456789',
			'assigned_assistant_ids' => array( 1, 2 ),
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( 'Test Slack Bot', $saved['name'] );
		$this->assertEquals( 'slack', $saved['connection_type'] );
		$this->assertEquals( 'T0123456789', $saved['workspace_id'] );
		$this->assertIsArray( $saved['assigned_assistant_ids'] );
		$this->assertCount( 2, $saved['assigned_assistant_ids'] );
	}

	/**
	 * Test that a Discord connection can be saved and retrieved with assigned assistants.
	 */
	public function test_discord_connection_saves_with_assigned_assistants() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                  => 'Test Discord Bot',
			'url'                   => 'https://discord.com/api/v10',
			'connection_type'       => 'discord',
			'auth_type'             => 'none',
			'enabled'               => true,
			'api_key'               => 'test_discord_bot_token',
			'application_id'        => '123456789012345678',
			'guild_id'              => '987654321098765432',
			'public_key'            => 'test_public_key_ed25519',
			'assigned_assistant_ids' => array( 3 ),
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( 'Test Discord Bot', $saved['name'] );
		$this->assertEquals( 'discord', $saved['connection_type'] );
		$this->assertEquals( '123456789012345678', $saved['application_id'] );
		$this->assertEquals( '987654321098765432', $saved['guild_id'] );
		$this->assertIsArray( $saved['assigned_assistant_ids'] );
		$this->assertCount( 1, $saved['assigned_assistant_ids'] );
	}

	/**
	 * Test that a Microsoft Teams connection can be saved and retrieved with assigned assistants.
	 */
	public function test_teams_connection_saves_with_assigned_assistants() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                  => 'Test Teams Bot',
			'url'                   => 'https://smba.trafficmanager.net/apis',
			'connection_type'       => 'microsoft_teams',
			'auth_type'             => 'none',
			'enabled'               => true,
			'signing_secret'        => 'test_security_token',
			'token'                 => 'test_graph_access_token',
			'tenant_id'             => 'test-tenant-id-12345',
			'assigned_assistant_ids' => array( 5, 6, 7 ),
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertEquals( 'Test Teams Bot', $saved['name'] );
		$this->assertEquals( 'microsoft_teams', $saved['connection_type'] );
		$this->assertEquals( 'test-tenant-id-12345', $saved['tenant_id'] );
		$this->assertIsArray( $saved['assigned_assistant_ids'] );
		$this->assertCount( 3, $saved['assigned_assistant_ids'] );
	}

	/**
	 * Test that WP_MCP_AI_Pro_Remote_Site_Manager has a test_slack_connection() method.
	 */
	public function test_remote_site_manager_has_slack_connection_method() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
		$this->assertTrue(
			$reflection->hasMethod( 'test_slack_connection' ),
			'WP_MCP_AI_Pro_Remote_Site_Manager should have a test_slack_connection() method'
		);

		$method = $reflection->getMethod( 'test_slack_connection' );
		$this->assertTrue(
			$method->isProtected() || $method->isPublic(),
			'test_slack_connection() should be at least protected'
		);
	}

	/**
	 * Test that test_connection() dispatches Slack connections to test_slack_connection()
	 * rather than the generic WordPress REST API test (which would 404 on slack.com/api).
	 * Specifically, verify it returns a WP_Error (missing token) rather than a WordPress
	 * REST API result when the connection has no api_key configured.
	 */
	public function test_test_connection_routes_slack_to_dedicated_handler() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Slack No Token',
			'url'             => 'https://slack.com/api',
			'connection_type' => 'slack',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

		// Without a bot token the Slack-specific handler returns a WP_Error.
		// This proves the generic WordPress REST handler (which would try to call
		// slack.com/api/wp/v2/types and return a different error) is NOT used.
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'test_connection() for Slack with no token should return WP_Error from Slack handler'
		);
		$this->assertStringContainsString(
			'slack',
			strtolower( $result->get_error_code() ),
			'WP_Error code should identify the Slack handler'
		);
	}
}
