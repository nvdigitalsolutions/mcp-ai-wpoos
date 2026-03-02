<?php
/**
 * Test Slack, Discord, Microsoft Teams, and Telegram chat channel test handler registration.
 *
 * Verifies that AJAX handlers and admin UI elements for testing incoming/outgoing
 * messages to groups and channels are properly registered.
 *
 * @package WP_MCP_AI_Pro
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
}
