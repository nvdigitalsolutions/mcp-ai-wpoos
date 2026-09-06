<?php
/**
 * Google Drive Read Tools
 *
 * Tests for the Drive tool family: get_drive_file, list_drive_connections,
 * the shared Drive client's helpers, and the search_drive ergonomics
 * parameters (fields trimming, ids_only, has_more, size formatting).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-google-drive-client.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-drive-file.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-drive-connections.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-search-drive.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Tests for the Google Drive read tool family.
 */
class WP_MCP_AI_Drive_Read_Tools_Test extends WP_UnitTestCase {

	/**
	 * Prepare default settings for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Configure settings-based Drive credentials.
	 */
	private function configure_settings_credentials() {
		$settings                               = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_drive_client_id']     = 'client-id';
		$settings['google_drive_client_secret'] = 'client-secret';
		$settings['google_drive_refresh_token'] = 'refresh-token';
		$settings['google_drive_user_email']    = 'me';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Create an administrator and set it as the current user.
	 */
	private function login_as_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	/**
	 * The client should label Drive MIME types correctly.
	 */
	public function test_client_type_labels() {
		$this->assertSame( 'folder', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'application/vnd.google-apps.folder' ) );
		$this->assertSame( 'document', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'application/vnd.google-apps.document' ) );
		$this->assertSame( 'spreadsheet', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'application/vnd.google-apps.spreadsheet' ) );
		$this->assertSame( 'presentation', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'application/vnd.google-apps.presentation' ) );
		$this->assertSame( 'pdf', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'application/pdf' ) );
		$this->assertSame( 'image', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'image/png' ) );
		$this->assertSame( 'file', WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( 'application/octet-stream' ) );
	}

	/**
	 * The client should only treat the Docs family as text-exportable.
	 */
	public function test_client_exportable_detection() {
		$this->assertTrue( WP_MCP_AI_Pro_Google_Drive_Client::is_text_exportable( 'application/vnd.google-apps.document' ) );
		$this->assertTrue( WP_MCP_AI_Pro_Google_Drive_Client::is_text_exportable( 'application/vnd.google-apps.spreadsheet' ) );
		$this->assertFalse( WP_MCP_AI_Pro_Google_Drive_Client::is_text_exportable( 'application/vnd.google-apps.drawing' ) );
		$this->assertFalse( WP_MCP_AI_Pro_Google_Drive_Client::is_text_exportable( 'application/pdf' ) );
	}

	/**
	 * The client should format byte counts for humans.
	 */
	public function test_client_format_size() {
		$this->assertSame( '512 B', WP_MCP_AI_Pro_Google_Drive_Client::format_size( 512 ) );
		$this->assertSame( '1.0 KB', WP_MCP_AI_Pro_Google_Drive_Client::format_size( 1024 ) );
		$this->assertSame( '1.5 MB', WP_MCP_AI_Pro_Google_Drive_Client::format_size( 1572864 ) );
		$this->assertSame( '2.0 GB', WP_MCP_AI_Pro_Google_Drive_Client::format_size( 2147483648 ) );
	}

	/**
	 * Reading requires an appropriate capability.
	 */
	public function test_get_drive_file_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Drive_File();
		$result = $tool->execute(
			array( 'file_id' => 'file-1' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_forbidden', $result->get_error_code() );
	}

	/**
	 * Reading requires configured credentials.
	 */
	public function test_get_drive_file_requires_credentials() {
		$admin_id = $this->login_as_admin();

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Drive_File();
		$result = $tool->execute(
			array( 'file_id' => 'file-1' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_drive_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Google Docs files should be exported to plain text inside the envelope.
	 */
	public function test_get_drive_file_exports_google_doc() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

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

				// Order matters: the export URL also contains the metadata fragment.
				if ( false !== strpos( $url, '/files/file-doc/export' ) ) {
					return array(
						'headers'  => array(),
						'body'     => "Quarterly report.\nRevenue is up 12%.",
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/files/file-doc' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'           => 'file-doc',
								'name'         => 'Quarterly Report',
								'mimeType'     => 'application/vnd.google-apps.document',
								'size'         => '1024',
								'createdTime'  => '2026-01-01T00:00:00.000Z',
								'modifiedTime' => '2026-02-01T00:00:00.000Z',
								'parents'      => array( 'folder-1' ),
								'shared'       => false,
								'description'  => 'A report.',
								'webViewLink'  => 'https://docs.google.com/document/d/file-doc/edit',
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
				'file_id'   => 'file-doc',
				'max_chars' => 4000,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$data = $result['data'];
		$this->assertSame( 'file-doc', $data['id'] );
		$this->assertSame( 'Quarterly Report', $data['name'] );
		$this->assertSame( 'document', $data['type_label'] );
		$this->assertFalse( $data['is_folder'] );
		$this->assertTrue( $data['exportable'] );
		$this->assertSame( "Quarterly report.\nRevenue is up 12%.", $data['body'] );
		$this->assertFalse( $data['truncated'] );
		$this->assertSame( '1.0 KB', $data['size_formatted'] );
		$this->assertSame( array( 'folder-1' ), $data['parents'] );
	}

	/**
	 * Long exports must be truncated at the character cap.
	 */
	public function test_get_drive_file_truncates_text() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$long_text = str_repeat( 'word ', 500 );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $long_text ) {
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

				if ( false !== strpos( $url, '/files/file-long/export' ) ) {
					return array(
						'headers'  => array(),
						'body'     => $long_text,
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/files/file-long' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'       => 'file-long',
								'name'     => 'Long Doc',
								'mimeType' => 'application/vnd.google-apps.document',
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
				'file_id'   => 'file-long',
				'max_chars' => 100,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['data']['truncated'] );
		$this->assertLessThanOrEqual( 100, $result['data']['body_chars'] );
	}

	/**
	 * Folders should return their direct children.
	 */
	public function test_get_drive_file_returns_folder_children() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

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

				if ( false !== strpos( $url, '/drive/v3/files?' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'files' => array(
									array(
										'id'       => 'child-1',
										'name'     => 'Invoice.pdf',
										'mimeType' => 'application/pdf',
										'size'     => '2048',
									),
									array(
										'id'       => 'child-2',
										'name'     => 'Notes',
										'mimeType' => 'application/vnd.google-apps.document',
									),
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/files/folder-1' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'       => 'folder-1',
								'name'     => 'Invoices',
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
				'file_id'      => 'folder-1',
				'max_children' => 50,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['data']['is_folder'] );
		$this->assertSame( 'folder', $result['data']['type_label'] );
		$this->assertCount( 2, $result['data']['children'] );
		$this->assertSame( 2, $result['data']['children_count'] );
		$this->assertFalse( $result['data']['children_truncated'] );
		$this->assertSame( 'Invoice.pdf', $result['data']['children'][0]['name'] );
		$this->assertSame( 'pdf', $result['data']['children'][0]['type_label'] );
		$this->assertSame( '2.0 KB', $result['data']['children'][0]['size_formatted'] );
	}

	/**
	 * Binary files should return metadata plus a note, not content.
	 */
	public function test_get_drive_file_binary_returns_note() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

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

				if ( false !== strpos( $url, '/files/file-pdf' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'       => 'file-pdf',
								'name'     => 'Scan.pdf',
								'mimeType' => 'application/pdf',
								'size'     => '524288',
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
			array( 'file_id' => 'file-pdf' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['data']['exportable'] );
		$this->assertSame( '', $result['data']['body'] );
		$this->assertNotEmpty( $result['data']['note'] );
		$this->assertSame( 'pdf', $result['data']['type_label'] );
	}

	/**
	 * search_drive should report has_more and include formatted sizes and type labels.
	 */
	public function test_search_drive_has_more_and_size_formatting() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

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

				if ( false !== strpos( $url, '/drive/v3/files?' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'files'         => array(
									array(
										'id'           => 'file-a',
										'name'         => 'Budget',
										'mimeType'     => 'application/vnd.google-apps.spreadsheet',
										'createdTime'  => '2026-01-01T00:00:00.000Z',
										'modifiedTime' => '2026-02-01T00:00:00.000Z',
										'size'         => '4096',
										'webViewLink'  => 'https://docs.google.com/spreadsheets/d/file-a/edit',
										'shared'       => false,
										'description'  => '',
									),
								),
								'nextPageToken' => 'next-token',
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

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Drive();
		$result = $tool->execute(
			array( 'query' => 'budget' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['has_more'] );
		$this->assertSame( 'next-token', $result['next_page_token'] );

		$row = $result['files'][0];
		$this->assertSame( 'spreadsheet', $row['type_label'] );
		$this->assertSame( '4.0 KB', $row['size_formatted'] );
		$this->assertArrayNotHasKey( 'owners', $row, 'owners should be trimmed from search results.' );
		$this->assertArrayNotHasKey( 'permissions', $row, 'permissions should be trimmed from search results.' );
	}

	/**
	 * search_drive ids_only should return bare id/name rows.
	 */
	public function test_search_drive_ids_only() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

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

				if ( false !== strpos( $url, '/drive/v3/files?' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'files' => array(
									array(
										'id'       => 'file-a',
										'name'     => 'Budget',
										'mimeType' => 'application/vnd.google-apps.spreadsheet',
									),
								),
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

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Drive();
		$result = $tool->execute(
			array(
				'query'    => 'budget',
				'ids_only' => true,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame(
			array( 'id', 'name' ),
			array_keys( $result['files'][0] ),
			'ids_only rows should carry only id and name.'
		);
	}

	/**
	 * list_drive_connections must redact credentials and exclude non-Drive types.
	 */
	public function test_list_drive_connections_redacts_secrets() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_drive' => array(
					'connection_type' => 'google_drive',
					'name'            => 'Company Drive',
					'enabled'         => true,
					'user_email'      => 'drive@example.com',
					'folder_id'       => 'folder-123',
					'client_id'       => 'cid',
					'client_secret'   => 'DRIVE_SECRET',
					'refresh_token'   => 'DRIVE_REFRESH',
				),
				'conn_gmail' => array(
					'connection_type' => 'gmail',
					'name'            => 'Some Inbox',
					'enabled'         => true,
					'user_email'      => 'mail@example.com',
				),
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_List_Drive_Connections();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$rows = $result['data']['connections'];

		// Drive connection + settings fallback = 2 rows; Gmail excluded.
		$this->assertCount( 2, $rows );

		$drive_row = $rows[0];
		$this->assertSame( 'conn_drive', $drive_row['id'] );
		$this->assertSame( 'Company Drive', $drive_row['name'] );
		$this->assertTrue( $drive_row['enabled'] );
		$this->assertSame( 'drive@example.com', $drive_row['user_email'] );
		$this->assertSame( 'folder-123', $drive_row['folder_id'] );

		$serialized = wp_json_encode( $rows );
		$this->assertStringNotContainsString( 'DRIVE_SECRET', $serialized );
		$this->assertStringNotContainsString( 'DRIVE_REFRESH', $serialized );
		$this->assertStringNotContainsString( 'client_secret', $serialized );
		$this->assertStringNotContainsString( 'refresh_token', $serialized );

		$ids = wp_list_pluck( $rows, 'id' );
		$this->assertContains( 'settings', $ids, 'Settings-based fallback should be listed.' );
	}

	/**
	 * Argument-less Drive tools must encode empty properties as `{}`.
	 *
	 * Strict providers (DeepSeek) reject schemas whose `properties` key is a
	 * JSON array: "Invalid schema for function 'x': [] is not of type 'object'".
	 */
	public function test_list_drive_connections_schema_encodes_empty_properties_as_object() {
		$tool   = new WP_MCP_AI_Pro_Tool_List_Drive_Connections();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertSame(
			'{}',
			wp_json_encode( $schema['properties'] ),
			'list_drive_connections properties must encode as an empty object, not [].'
		);
	}
}
