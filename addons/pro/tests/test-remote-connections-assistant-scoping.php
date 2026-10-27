<?php
/**
 * Assistant-level scoping enforcement for the remaining Remote Sites
 * connection families — Gmail, Google Calendar, FlowHub, and Printful.
 *
 * Each family must mirror the remote_wp_connection contract: an assistant
 * with a configured Remote Site Connections list may only resolve and
 * discover the connections enabled for it, and the site-wide settings
 * fallback is refused for restricted assistants.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Tests for assistant scoping across the Gmail, Calendar, FlowHub and
 * Printful connection families.
 */
class Test_Remote_Connections_Assistant_Scoping extends WP_UnitTestCase {

	/**
	 * Assistant post ID used as the execution-context assistant.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Prepare default settings and a scoped assistant for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Scoped Connections Assistant',
			)
		);
	}

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Restrict the test assistant to the given connection IDs.
	 *
	 * @param array $connection_ids Allowed connection IDs.
	 */
	private function restrict_assistant_to( array $connection_ids ) {
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', $connection_ids );
	}

	/**
	 * Create an administrator and set it as the current user.
	 *
	 * @return int Administrator user ID.
	 */
	private function login_as_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	/**
	 * An assistant may not use a Gmail connection that is not enabled for it.
	 */
	public function test_gmail_client_rejects_connection_not_enabled_for_assistant() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_inbox_a' => array(
					'connection_type' => 'gmail',
					'name'            => 'Inbox A',
					'enabled'         => true,
					'client_id'       => 'client-id-a',
					'client_secret'   => 'client-secret-a',
					'refresh_token'   => 'refresh-token-a',
				),
				'conn_inbox_b' => array(
					'connection_type' => 'gmail',
					'name'            => 'Inbox B',
					'enabled'         => true,
					'client_id'       => 'client-id-b',
					'client_secret'   => 'client-secret-b',
					'refresh_token'   => 'refresh-token-b',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_inbox_a' ) );

		$result = WP_MCP_AI_Pro_Gmail_Client::resolve_credentials(
			'conn_inbox_b',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * An assistant may use a Gmail connection that is enabled for it.
	 */
	public function test_gmail_client_allows_assistant_enabled_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_inbox_a' => array(
					'connection_type' => 'gmail',
					'name'            => 'Inbox A',
					'enabled'         => true,
					'client_id'       => 'client-id-a',
					'client_secret'   => 'client-secret-a',
					'refresh_token'   => 'refresh-token-a',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_inbox_a' ) );

		$credentials = WP_MCP_AI_Pro_Gmail_Client::resolve_credentials(
			'conn_inbox_a',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $credentials );
		$this->assertSame( 'connection', $credentials['source'] );
		$this->assertSame( 'client-id-a', $credentials['client_id'] );
	}

	/**
	 * A restricted assistant cannot use the site-wide Gmail settings fallback.
	 */
	public function test_gmail_client_blocks_settings_when_assistant_restricted() {
		$settings                            = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gmail_client_id']         = 'client-id';
		$settings['gmail_client_secret']     = 'client-secret';
		$settings['gmail_refresh_token']     = 'refresh-token';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$this->restrict_assistant_to( array( 'conn_inbox_a' ) );

		$result = WP_MCP_AI_Pro_Gmail_Client::resolve_credentials(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_settings_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The list_gmail_connections tool must only reveal the assistant's
	 * enabled connections and hide the settings fallback row.
	 */
	public function test_list_gmail_connections_filters_by_assistant() {
		$settings                            = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gmail_client_id']         = 'client-id';
		$settings['gmail_client_secret']     = 'client-secret';
		$settings['gmail_refresh_token']     = 'refresh-token';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_inbox_a' => array(
					'connection_type' => 'gmail',
					'name'            => 'Inbox A',
					'enabled'         => true,
				),
				'conn_inbox_b' => array(
					'connection_type' => 'gmail',
					'name'            => 'Inbox B',
					'enabled'         => true,
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_inbox_a' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Gmail_Connections();
		$result = $tool->execute(
			array(),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$rows = $result['data']['connections'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'conn_inbox_a', $rows[0]['id'] );

		$ids = wp_list_pluck( $rows, 'id' );
		$this->assertNotContains( 'conn_inbox_b', $ids, 'Connections outside the assistant list must be hidden.' );
		$this->assertNotContains( 'settings', $ids, 'The settings fallback must be hidden from restricted assistants.' );
	}

	/**
	 * The calendar resolver must refuse connections outside the assistant list.
	 */
	public function test_calendar_resolve_rejects_connection_not_enabled_for_assistant() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_cal_a' => array(
					'connection_type' => 'google_calendar',
					'name'            => 'Calendar A',
					'enabled'         => true,
					'client_id'       => 'client-id-a',
					'client_secret'   => 'client-secret-a',
					'refresh_token'   => 'refresh-token-a',
				),
				'conn_cal_b' => array(
					'connection_type' => 'google_calendar',
					'name'            => 'Calendar B',
					'enabled'         => true,
					'client_id'       => 'client-id-b',
					'client_secret'   => 'client-secret-b',
					'refresh_token'   => 'refresh-token-b',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_cal_a' ) );

		$result = WP_MCP_AI_Google_Calendar_Credentials::resolve(
			'conn_cal_b',
			array( 'assistant_id' => $this->assistant_id ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The calendar resolver must allow connections from the assistant list.
	 */
	public function test_calendar_resolve_allows_assistant_enabled_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_cal_a' => array(
					'connection_type' => 'google_calendar',
					'name'            => 'Calendar A',
					'enabled'         => true,
					'client_id'       => 'client-id-a',
					'client_secret'   => 'client-secret-a',
					'refresh_token'   => 'refresh-token-a',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_cal_a' ) );

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve(
			'conn_cal_a',
			array( 'assistant_id' => $this->assistant_id ),
			array()
		);

		$this->assertNotWPError( $credentials );
		$this->assertSame( 'connection', $credentials['source'] );
		$this->assertSame( 'conn_cal_a', $credentials['connection_id'] );
	}

	/**
	 * A restricted assistant cannot fall back to the site-wide calendar settings.
	 */
	public function test_calendar_resolve_blocks_settings_when_assistant_restricted() {
		$this->restrict_assistant_to( array( 'conn_cal_a' ) );

		$result = WP_MCP_AI_Google_Calendar_Credentials::resolve(
			'',
			array( 'assistant_id' => $this->assistant_id ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_settings_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The FlowHub helper must refuse connections outside the assistant list.
	 */
	public function test_flowhub_helper_rejects_connection_not_enabled_for_assistant() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_fh_a' => array(
					'connection_type' => 'flowhub',
					'name'            => 'FlowHub A',
					'enabled'         => true,
					'client_id'       => 'fh-client-a',
					'api_key'         => 'fh-key-a',
				),
				'conn_fh_b' => array(
					'connection_type' => 'flowhub',
					'name'            => 'FlowHub B',
					'enabled'         => true,
					'client_id'       => 'fh-client-b',
					'api_key'         => 'fh-key-b',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_fh_a' ) );

		$result = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection(
			'conn_fh_b',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_flowhub_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A restricted assistant cannot use the site-wide FlowHub toolkit settings.
	 */
	public function test_flowhub_helper_blocks_settings_when_assistant_restricted() {
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'client_id' => 'fh-settings-client',
				'api_key'   => 'fh-settings-key',
			)
		);

		$this->restrict_assistant_to( array( 'conn_fh_a' ) );

		$result = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_flowhub_settings_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A restricted assistant auto-resolves to the first allowed FlowHub
	 * connection instead of the first connection in the roster.
	 */
	public function test_flowhub_helper_auto_resolves_only_allowed_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_fh_a' => array(
					'connection_type' => 'flowhub',
					'name'            => 'FlowHub A',
					'enabled'         => true,
					'client_id'       => 'fh-client-a',
					'api_key'         => 'fh-key-a',
				),
				'conn_fh_b' => array(
					'connection_type' => 'flowhub',
					'name'            => 'FlowHub B',
					'enabled'         => true,
					'client_id'       => 'fh-client-b',
					'api_key'         => 'fh-key-b',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_fh_b' ) );

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $resolved );
		$this->assertSame( 'conn_fh_b', $resolved['connection_id'] );
		$this->assertSame( 'connection', $resolved['source'] );
	}

	/**
	 * The Printful gate must read the same assistant meta key the metabox
	 * writes — the historical key was a typo that made the gate a no-op.
	 */
	public function test_printful_gate_reads_assistant_meta_key() {
		$this->restrict_assistant_to( array( 'conn_printful_allowed' ) );

		$method = new ReflectionMethod( WP_MCP_AI_Pro_Tool_Printful::class, 'is_connection_enabled_for_assistant' );
		$method->setAccessible( true );

		$tool = new WP_MCP_AI_Pro_Tool_Printful();

		$this->assertTrue(
			$method->invoke( $tool, 'conn_printful_allowed', array( 'assistant_id' => $this->assistant_id ) ),
			'Connections enabled for the assistant must be allowed.'
		);
		$this->assertFalse(
			$method->invoke( $tool, 'conn_printful_other', array( 'assistant_id' => $this->assistant_id ) ),
			'Connections outside the assistant list must be denied.'
		);
	}

	/**
	 * The Classroom resolver must refuse connections outside the assistant list.
	 */
	public function test_classroom_resolve_rejects_connection_not_enabled_for_assistant() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_class_a' => array(
					'connection_type' => 'google_classroom',
					'name'            => 'Classroom A',
					'enabled'         => true,
					'client_id'       => 'client-id-a',
					'client_secret'   => 'client-secret-a',
					'refresh_token'   => 'refresh-token-a',
				),
				'conn_class_b' => array(
					'connection_type' => 'google_classroom',
					'name'            => 'Classroom B',
					'enabled'         => true,
					'client_id'       => 'client-id-b',
					'client_secret'   => 'client-secret-b',
					'refresh_token'   => 'refresh-token-b',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_class_a' ) );

		$result = WP_MCP_AI_Google_Classroom_Credentials::resolve(
			'conn_class_b',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_classroom_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The Classroom resolver must allow connections from the assistant list.
	 */
	public function test_classroom_resolve_allows_assistant_enabled_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_class_a' => array(
					'connection_type' => 'google_classroom',
					'name'            => 'Classroom A',
					'enabled'         => true,
					'client_id'       => 'client-id-a',
					'client_secret'   => 'client-secret-a',
					'refresh_token'   => 'refresh-token-a',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_class_a' ) );

		$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve(
			'conn_class_a',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $credentials );
		$this->assertSame( 'connection', $credentials['source'] );
		$this->assertSame( 'conn_class_a', $credentials['connection_id'] );
	}

	/**
	 * The PayHere tool must refuse connections outside the assistant list.
	 */
	public function test_payhere_tool_rejects_connection_not_enabled_for_assistant() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_payhere_a' => array(
					'connection_type' => 'payhere',
					'name'            => 'PayHere A',
					'enabled'         => true,
				),
				'conn_payhere_b' => array(
					'connection_type' => 'payhere',
					'name'            => 'PayHere B',
					'enabled'         => true,
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_payhere_a' ) );

		$tool   = new WP_MCP_AI_Tool_PayHere_Get_Payment();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_payhere_b',
				'order_id'      => 'ORDER-123',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_payhere_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The QuickBooks report tool must refuse connections outside the
	 * assistant list and block its settings fallback for restricted assistants.
	 */
	public function test_quickbooks_report_rejects_connection_not_enabled_for_assistant() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_qb_a' => array(
					'connection_type' => 'quickbooks',
					'name'            => 'QuickBooks A',
					'enabled'         => true,
					'client_id'       => 'qb-client-a',
					'client_secret'   => 'qb-secret-a',
					'company_id'      => '123',
				),
				'conn_qb_b' => array(
					'connection_type' => 'quickbooks',
					'name'            => 'QuickBooks B',
					'enabled'         => true,
					'client_id'       => 'qb-client-b',
					'client_secret'   => 'qb-secret-b',
					'company_id'      => '456',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_qb_a' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_qb_b',
				'report'        => 'ProfitAndLoss',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The QuickBooks report tool blocks the settings fallback for a
	 * restricted assistant.
	 */
	public function test_quickbooks_report_blocks_settings_when_assistant_restricted() {
		$admin_id = $this->login_as_admin();

		$this->restrict_assistant_to( array( 'conn_qb_a' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report();
		$result = $tool->execute(
			array( 'report' => 'ProfitAndLoss' ),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_settings_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The QuickBooks Desktop sync tool must refuse connections outside the
	 * assistant list.
	 */
	public function test_quickbooks_desktop_sync_rejects_connection_not_enabled_for_assistant() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_qbd_a' => array(
					'connection_type' => 'quickbooks_desktop',
					'name'            => 'QB Desktop A',
					'enabled'         => true,
					'url'             => 'https://relay-a.example.com',
				),
				'conn_qbd_b' => array(
					'connection_type' => 'quickbooks_desktop',
					'name'            => 'QB Desktop B',
					'enabled'         => true,
					'url'             => 'https://relay-b.example.com',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_qbd_a' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_qbd_b',
				'action'        => 'get_customers',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_qbd_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The iSAMS query tool must refuse connections outside the assistant
	 * list and block its settings fallback for restricted assistants.
	 */
	public function test_isams_query_rejects_connection_not_enabled_for_assistant() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_isams_a' => array(
					'connection_type' => 'isams',
					'name'            => 'iSAMS A',
					'enabled'         => true,
					'url'             => 'https://isams-a.example.com',
				),
				'conn_isams_b' => array(
					'connection_type' => 'isams',
					'name'            => 'iSAMS B',
					'enabled'         => true,
					'url'             => 'https://isams-b.example.com',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_isams_a' ) );

		$tool   = new WP_MCP_AI_Tool_ISAMS_Query();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_isams_b',
				'endpoint'      => 'students',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_isams_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The iSAMS query tool blocks the settings fallback for a restricted
	 * assistant.
	 */
	public function test_isams_query_blocks_settings_when_assistant_restricted() {
		$admin_id = $this->login_as_admin();

		$this->restrict_assistant_to( array( 'conn_isams_a' ) );

		$tool   = new WP_MCP_AI_Tool_ISAMS_Query();
		$result = $tool->execute(
			array( 'endpoint' => 'students' ),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_isams_settings_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The Upwork and LinkedIn clients must gate connections by the
	 * assistant's Remote Site Connections list.
	 */
	public function test_upwork_and_linkedin_clients_gate_by_assistant_list() {
		$this->restrict_assistant_to( array( 'conn_upwork_a', 'conn_linkedin_a' ) );

		$this->assertTrue(
			WP_MCP_AI_Upwork_Client::is_connection_enabled_for_assistant( 'conn_upwork_a', array( 'assistant_id' => $this->assistant_id ) )
		);
		$this->assertFalse(
			WP_MCP_AI_Upwork_Client::is_connection_enabled_for_assistant( 'conn_upwork_b', array( 'assistant_id' => $this->assistant_id ) )
		);
		$this->assertTrue(
			WP_MCP_AI_LinkedIn_Client::is_connection_enabled_for_assistant( 'conn_linkedin_a', array( 'assistant_id' => $this->assistant_id ) )
		);
		$this->assertFalse(
			WP_MCP_AI_LinkedIn_Client::is_connection_enabled_for_assistant( 'conn_linkedin_b', array( 'assistant_id' => $this->assistant_id ) )
		);

		// No assistant context — direct calls stay unrestricted.
		$this->assertTrue( WP_MCP_AI_Upwork_Client::is_connection_enabled_for_assistant( 'conn_upwork_b', array() ) );
		$this->assertTrue( WP_MCP_AI_LinkedIn_Client::is_connection_enabled_for_assistant( 'conn_linkedin_b', array() ) );
	}

	/**
	 * The Composio resolver must gate explicit connections and auto-resolve
	 * only inside the assistant's allowed list.
	 */
	public function test_composio_resolver_gates_by_assistant_list() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_composio_a' => array(
					'connection_type' => 'composio',
					'name'            => 'Composio A',
					'enabled'         => true,
				),
				'conn_composio_b' => array(
					'connection_type' => 'composio',
					'name'            => 'Composio B',
					'enabled'         => true,
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_composio_a' ) );

		$connection = null;
		$denied     = WP_MCP_AI_Composio_Tools::resolve_connection(
			array( 'connection_id' => 'conn_composio_b' ),
			$connection,
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $denied );
		$this->assertSame( 'wp_mcp_ai_composio_connection_not_enabled', $denied->get_error_code() );
		$this->assertSame( 403, $denied->get_error_data()['status'] );

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection(
			array(),
			$connection,
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $resolved );
		$this->assertSame( 'Composio A', $resolved['name'] );

		// Restricted to a non-Composio connection — nothing usable.
		$this->restrict_assistant_to( array( 'conn_other' ) );

		$connection = null;
		$none       = WP_MCP_AI_Composio_Tools::resolve_connection(
			array(),
			$connection,
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $none );
		$this->assertSame( 'wp_mcp_ai_composio_no_allowed_connection', $none->get_error_code() );
	}
}
