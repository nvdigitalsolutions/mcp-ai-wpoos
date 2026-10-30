<?php
/**
 * Assistant-level scoping enforcement for the Google Drive tool family.
 *
 * Mirrors the remote_wp_connection contract: an assistant with a configured
 * Remote Site Connections list (the assistant editor metabox) may only
 * resolve, auto-resolve, and discover the connections enabled for it, and a
 * connection-scoped folder acts as a hard limit for search_drive and
 * get_drive_file.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Tests for Google Drive assistant scoping.
 */
class Test_Google_Drive_Assistant_Scoping extends WP_UnitTestCase {

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
				'post_title'  => 'Scoped Drive Assistant',
			)
		);
	}

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );

		// Restore a clean settings surface so settings-based credentials written
		// by these tests cannot leak into later suites in the same process,
		// where they would hijack the settings fallback of unrelated tools.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

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
	 * An assistant may not use a Drive connection that is not enabled for it.
	 */
	public function test_resolve_credentials_rejects_connection_not_enabled_for_assistant() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo'  => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
				'conn_sales' => array(
					'connection_type' => 'google_drive',
					'name'            => 'scm-sales',
					'enabled'         => true,
					'folder_id'       => 'folder-complete',
					'client_id'       => 'client-id-sales',
					'client_secret'   => 'client-secret-sales',
					'refresh_token'   => 'refresh-token-sales',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		$result = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials(
			'conn_sales',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_connection_not_enabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'scm-sales', $result->get_error_message() );
	}

	/**
	 * An assistant may use a Drive connection that is enabled for it.
	 */
	public function test_resolve_credentials_allows_assistant_enabled_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo'  => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
				'conn_sales' => array(
					'connection_type' => 'google_drive',
					'name'            => 'scm-sales',
					'enabled'         => true,
					'client_id'       => 'client-id-sales',
					'client_secret'   => 'client-secret-sales',
					'refresh_token'   => 'refresh-token-sales',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		$credentials = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials(
			'conn_demo',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $credentials );
		$this->assertSame( 'conn_demo', $credentials['connection_id'] );
		$this->assertSame( 'client-id-demo', $credentials['client_id'] );
	}

	/**
	 * Auto-resolution for a restricted assistant must only consider its
	 * enabled connections, never the rest of the roster.
	 */
	public function test_resolve_credentials_auto_resolves_only_allowed_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo'  => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
				'conn_sales' => array(
					'connection_type' => 'google_drive',
					'name'            => 'scm-sales',
					'enabled'         => true,
					'client_id'       => 'client-id-sales',
					'client_secret'   => 'client-secret-sales',
					'refresh_token'   => 'refresh-token-sales',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_sales' ) );

		$credentials = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $credentials );
		$this->assertSame( 'connection', $credentials['source'] );
		$this->assertSame( 'conn_sales', $credentials['connection_id'] );
	}

	/**
	 * A restricted assistant cannot use the site-wide settings fallback.
	 */
	public function test_resolve_credentials_blocks_settings_when_assistant_restricted() {
		$settings                               = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_drive_client_id']     = 'client-id';
		$settings['google_drive_client_secret'] = 'client-secret';
		$settings['google_drive_refresh_token'] = 'refresh-token';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		$result = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_settings_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A restricted assistant granted the "settings" row may use the site-wide
	 * Drive settings fallback.
	 */
	public function test_resolve_credentials_allows_settings_fallback_when_granted() {
		$settings                               = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_drive_client_id']     = 'client-id';
		$settings['google_drive_client_secret'] = 'client-secret';
		$settings['google_drive_refresh_token'] = 'refresh-token';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$this->restrict_assistant_to( array( 'settings' ) );

		$credentials = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertNotWPError( $credentials );
		$this->assertSame( 'settings', $credentials['source'] );
		$this->assertSame( 'client-id', $credentials['client_id'] );
	}

	/**
	 * A restricted assistant with no allowed Drive connection gets a targeted
	 * error instead of the generic "credentials not configured" message.
	 */
	public function test_resolve_credentials_reports_no_allowed_connection() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_other' => array(
					'connection_type' => 'gmail',
					'name'            => 'Inbox',
					'enabled'         => true,
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_other' ) );

		$result = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials(
			'',
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_no_allowed_connection', $result->get_error_code() );
	}

	/**
	 * The list_drive_connections tool must only reveal the assistant's
	 * enabled connections and must hide the settings fallback row.
	 */
	public function test_list_drive_connections_filters_by_assistant() {
		$settings                               = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_drive_client_id']     = 'client-id';
		$settings['google_drive_client_secret'] = 'client-secret';
		$settings['google_drive_refresh_token'] = 'refresh-token';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo'  => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
				),
				'conn_sales' => array(
					'connection_type' => 'google_drive',
					'name'            => 'scm-sales',
					'enabled'         => true,
					'folder_id'       => 'folder-complete',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Drive_Connections();
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
		$this->assertSame( 'conn_demo', $rows[0]['id'] );
		$this->assertSame( 'folder-demo', $rows[0]['folder_id'] );

		$ids = wp_list_pluck( $rows, 'id' );
		$this->assertNotContains( 'conn_sales', $ids, 'Connections outside the assistant list must be hidden.' );
		$this->assertNotContains( 'settings', $ids, 'The settings fallback must be hidden from restricted assistants.' );
	}

	/**
	 * The search_drive tool must reject a folder_id argument outside the
	 * connection's scoped folder before making any HTTP request.
	 */
	public function test_search_drive_rejects_folder_override_outside_connection_scope() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo' => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$http_calls ) {
				++$http_calls;
				return $preempt;
			},
			10
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Drive();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_demo',
				'query'         => 'report',
				'folder_id'     => 'folder-complete',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_folder_not_allowed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 0, $http_calls, 'The scope rejection must happen before any HTTP request.' );
	}

	/**
	 * The get_drive_file tool must refuse files outside the connection's
	 * scoped folder.
	 */
	public function test_get_drive_file_denies_file_outside_scoped_folder() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo' => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'access_token' => 'mock-token' ) ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/drive/v3/files/file-1' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'          => 'file-1',
								'name'        => 'Full-drive-secret.pdf',
								'mimeType'    => 'application/pdf',
								'parents'     => array( 'folder-complete' ),
								'size'        => '1024',
								'createdTime' => '2026-01-01T00:00:00.000Z',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/drive/v3/files/folder-complete' ) ) {
					// The walk verifies the parent chain: this parent is orphaned
					// (not inside the scoped tree), so the file is out of scope.
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'       => 'folder-complete',
								'name'     => 'Complete Folder',
								'mimeType' => 'application/vnd.google-apps.folder',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				return false;
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Drive_File();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_demo',
				'file_id'       => 'file-1',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_file_outside_scope', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The get_drive_file tool must allow files inside the connection's
	 * scoped folder.
	 */
	public function test_get_drive_file_allows_file_inside_scoped_folder() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo' => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
			)
		);

		$this->restrict_assistant_to( array( 'conn_demo' ) );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'access_token' => 'mock-token' ) ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/drive/v3/files/file-2' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'           => 'file-2',
								'name'         => 'Scoped-report.pdf',
								'mimeType'     => 'application/pdf',
								'parents'      => array( 'folder-demo' ),
								'size'         => '2048',
								'createdTime'  => '2026-01-01T00:00:00.000Z',
								'modifiedTime' => '2026-02-01T00:00:00.000Z',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				return false;
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Drive_File();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_demo',
				'file_id'       => 'file-2',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'file-2', $result['data']['id'] );
	}

	/**
	 * The folder-tree walk must handle the trivial cases and verify deeper
	 * chains through the mocked parent lookups.
	 */
	public function test_client_folder_tree_walk_basics() {
		// The scoped folder itself is always in scope.
		$this->assertTrue(
			WP_MCP_AI_Pro_Google_Drive_Client::is_file_within_folder_tree(
				array(),
				'folder-demo',
				'folder-demo',
				'mock-token',
				30
			)
		);

		// A direct child of the scoped folder is in scope (from the given metadata).
		$this->assertTrue(
			WP_MCP_AI_Pro_Google_Drive_Client::is_file_within_folder_tree(
				array( 'parents' => array( 'folder-demo' ) ),
				'file-1',
				'folder-demo',
				'mock-token',
				30
			)
		);

		// An orphan file (no parents) cannot be verified — fail closed.
		$this->assertFalse(
			WP_MCP_AI_Pro_Google_Drive_Client::is_file_within_folder_tree(
				array(),
				'file-1',
				'folder-demo',
				'mock-token',
				30
			)
		);

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/drive/v3/files/other-folder' ) ) {
					// Orphaned parent — the chain ends outside the scoped tree.
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'id' => 'other-folder' ) ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/drive/v3/files/child-folder' ) ) {
					// The intermediate parent lives inside the scoped folder.
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'      => 'child-folder',
								'parents' => array( 'folder-demo' ),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				return false;
			},
			10,
			3
		);

		// A chain ending in an orphaned parent is out of scope.
		$this->assertFalse(
			WP_MCP_AI_Pro_Google_Drive_Client::is_file_within_folder_tree(
				array( 'parents' => array( 'other-folder' ) ),
				'file-1',
				'folder-demo',
				'mock-token',
				30
			)
		);

		// A deeper chain that reaches the scoped folder is in scope.
		$this->assertTrue(
			WP_MCP_AI_Pro_Google_Drive_Client::is_file_within_folder_tree(
				array( 'parents' => array( 'child-folder' ) ),
				'file-1',
				'folder-demo',
				'mock-token',
				30
			)
		);
	}

	/**
	 * The base-plugin search_drive enforces the same connection folder scope.
	 */
	public function test_base_search_drive_rejects_folder_override_outside_scope() {
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_demo' => array(
					'connection_type' => 'google_drive',
					'name'            => 'SCM Demo Directory',
					'enabled'         => true,
					'folder_id'       => 'folder-demo',
					'client_id'       => 'client-id-demo',
					'client_secret'   => 'client-secret-demo',
					'refresh_token'   => 'refresh-token-demo',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Search_Drive();
		$result = $tool->execute(
			array(
				'connection_id' => 'conn_demo',
				'query'         => 'report',
				'folder_id'     => 'folder-complete',
			),
			array(
				'user_id'      => $admin_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_folder_not_allowed', $result->get_error_code() );
	}
}
