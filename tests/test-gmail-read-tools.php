<?php
/**
 * Gmail Read/Modify Tools
 *
 * Tests for the Gmail tool family: get_gmail_message, get_gmail_thread,
 * list_gmail_connections, modify_gmail_message, the shared Gmail client's
 * body-decoding helpers, and the search_gmail ergonomics parameters
 * (ids_only, snippet_length, thread_group, has_more).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-gmail-client.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-search-gmail.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-gmail-message.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-get-gmail-thread.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-list-gmail-connections.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/google-workspace/class-wp-mcp-ai-pro-tool-modify-gmail-message.php';
require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Tests for the Gmail read/modify tool family.
 */
class WP_MCP_AI_Gmail_Read_Tools_Test extends WP_UnitTestCase {

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
	 * Configure settings-based Gmail credentials.
	 */
	private function configure_settings_credentials() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['gmail_client_id']     = 'client-id';
		$settings['gmail_client_secret'] = 'client-secret';
		$settings['gmail_refresh_token'] = 'refresh-token';
		$settings['gmail_user_email']    = 'me';
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
	 * Base64url-encode text the way Gmail encodes part bodies.
	 *
	 * @param string $text Text to encode.
	 * @return string Base64url encoded text.
	 */
	private function b64url( $text ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding fixture data for Gmail API stubs.
		return rtrim( strtr( base64_encode( $text ), '+/', '-_' ), '=' );
	}

	/**
	 * Register an HTTP stub keyed by URL fragment.
	 *
	 * @param array $responders Map of URL fragment => JSON payload.
	 */
	private function stub_http( $responders ) {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $responders ) {
				foreach ( $responders as $fragment => $payload ) {
					if ( false !== strpos( $url, $fragment ) ) {
						return array(
							'headers'  => array(),
							'body'     => wp_json_encode( $payload ),
							'response' => array(
								'code'    => 200,
								'message' => 'OK',
							),
						);
					}
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'error' => 'unexpected URL: ' . $url ) ),
					'response' => array(
						'code'    => 500,
						'message' => 'ERR',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a full-format message payload fixture.
	 *
	 * @param string $id               Message ID.
	 * @param string $thread_id        Thread ID.
	 * @param int    $internal_date_ms Internal date in milliseconds.
	 * @param array  $parts            Optional payload parts.
	 * @return array Message payload fixture.
	 */
	private function message_payload( $id, $thread_id, $internal_date_ms, $parts = array() ) {
		return array(
			'id'           => $id,
			'threadId'     => $thread_id,
			'labelIds'     => array( 'INBOX' ),
			'internalDate' => (string) $internal_date_ms,
			'snippet'      => 'Snippet for ' . $id,
			'payload'      => array(
				'mimeType' => 'multipart/alternative',
				'headers'  => array(
					array(
						'name'  => 'Subject',
						'value' => 'Subject ' . $id,
					),
					array(
						'name'  => 'From',
						'value' => 'sender@example.com',
					),
					array(
						'name'  => 'To',
						'value' => 'me@example.com',
					),
					array(
						'name'  => 'Date',
						'value' => 'Mon, 01 Jan 2026 10:00:00 +0000',
					),
				),
				'parts'    => $parts,
			),
		);
	}

	/**
	 * Build a text/plain part fixture.
	 *
	 * @param string $text          Plain text content.
	 * @param array  $extra_headers Optional extra headers.
	 * @return array MIME part fixture.
	 */
	private function plain_part( $text, $extra_headers = array() ) {
		return array(
			'mimeType' => 'text/plain',
			'headers'  => $extra_headers,
			'body'     => array(
				'data' => $this->b64url( $text ),
			),
		);
	}

	/**
	 * Build a text/html part fixture.
	 *
	 * @param string $html HTML content.
	 * @return array MIME part fixture.
	 */
	private function html_part( $html ) {
		return array(
			'mimeType' => 'text/html',
			'headers'  => array(),
			'body'     => array(
				'data' => $this->b64url( $html ),
			),
		);
	}

	/**
	 * The client should prefer the text/plain part for plain format.
	 */
	public function test_client_extract_body_prefers_plain_part() {
		$payload = $this->message_payload(
			'msg-1',
			'thread-1',
			1700000000000,
			array(
				$this->plain_part( "Hello from the plain part.\nSecond line." ),
				$this->html_part( '<html><body><p>Hello from HTML.</p></body></html>' ),
			)
		);

		$body = WP_MCP_AI_Pro_Gmail_Client::extract_body( $payload, 'plain' );

		$this->assertSame( "Hello from the plain part.\nSecond line.", $body );
	}

	/**
	 * The client should fall back to the HTML part (stripped) when no plain part exists.
	 */
	public function test_client_extract_body_falls_back_to_html_stripped() {
		$payload = $this->message_payload(
			'msg-1',
			'thread-1',
			1700000000000,
			array(
				$this->html_part( '<html><body><p>Only HTML.</p><p>Two paragraphs.</p></body></html>' ),
			)
		);

		$body = WP_MCP_AI_Pro_Gmail_Client::extract_body( $payload, 'plain' );

		$this->assertStringContainsString( 'Only HTML.', $body );
		$this->assertStringContainsString( 'Two paragraphs.', $body );
		$this->assertStringNotContainsString( '<p>', $body );
		$this->assertStringContainsString( "\n", $body, 'Block boundaries should become line breaks.' );
	}

	/**
	 * Quoted-printable encoded parts must be decoded.
	 */
	public function test_client_decode_part_body_quoted_printable() {
		$qp = "Line one.\r\n=41=42=43";

		$part = array(
			'mimeType' => 'text/plain',
			'headers'  => array(
				array(
					'name'  => 'Content-Transfer-Encoding',
					'value' => 'quoted-printable',
				),
			),
			'body'     => array(
				'data' => $this->b64url( $qp ),
			),
		);

		$decoded = WP_MCP_AI_Pro_Gmail_Client::decode_part_body( $part );

		$this->assertStringContainsString( 'ABC', $decoded );
		$this->assertStringNotContainsString( '=41', $decoded );
	}

	/**
	 * HTML format output must be sanitised: scripts and images removed.
	 */
	public function test_get_gmail_message_html_format_is_sanitised() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$payload = $this->message_payload(
			'msg-html',
			'thread-1',
			1700000000000,
			array(
				$this->html_part( '<html><body><p>Hello <strong>world</strong>.</p><a href="https://example.com">Link</a><script>alert(1)</script><img src="https://track.example.com/x.png"></body></html>' ),
			)
		);

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token'         => array( 'access_token' => 'mock-token' ),
				'gmail/v1/users/me/messages/msg-html' => $payload,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Message();
		$result = $tool->execute(
			array(
				'message_id' => 'msg-html',
				'format'     => 'html',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'html', $result['data']['body_format'] );
		$this->assertStringContainsString( '<p>Hello <strong>world</strong>.</p>', $result['data']['body'] );
		$this->assertStringContainsString( '<a href="https://example.com">Link</a>', $result['data']['body'] );
		$this->assertStringNotContainsString( '<script', $result['data']['body'] );
		$this->assertStringNotContainsString( '<img', $result['data']['body'] );
	}

	/**
	 * Plain format returns the decoded plain body inside the canonical envelope.
	 */
	public function test_get_gmail_message_returns_plain_body() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$payload = $this->message_payload(
			'msg-1',
			'thread-1',
			1700000000000,
			array(
				$this->plain_part( "Invoice attached.\nTotal: $120.00" ),
				$this->html_part( '<p>Invoice attached.</p>' ),
				array(
					'partId'   => '2',
					'mimeType' => 'application/pdf',
					'filename' => 'invoice.pdf',
					'body'     => array(
						'data' => '',
					),
				),
			)
		);

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token'      => array( 'access_token' => 'mock-token' ),
				'gmail/v1/users/me/messages/msg-1' => $payload,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Message();
		$result = $tool->execute(
			array(
				'message_id' => 'msg-1',
				'max_chars'  => 4000,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$data = $result['data'];
		$this->assertSame( 'msg-1', $data['id'] );
		$this->assertSame( 'thread-1', $data['thread_id'] );
		$this->assertSame( 1700000000, $data['timestamp'] );
		$this->assertSame( "Invoice attached.\nTotal: $120.00", $data['body'] );
		$this->assertFalse( $data['truncated'] );
		$this->assertTrue( $data['has_attachments'] );
		$this->assertSame( array( 'invoice.pdf' ), $data['attachment_names'] );
	}

	/**
	 * Long bodies must be truncated at the character cap with the truncated flag.
	 */
	public function test_get_gmail_message_truncates_long_bodies() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$long_text = str_repeat( 'word ', 500 );

		$payload = $this->message_payload(
			'msg-long',
			'thread-1',
			1700000000000,
			array( $this->plain_part( $long_text ) )
		);

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token'         => array( 'access_token' => 'mock-token' ),
				'gmail/v1/users/me/messages/msg-long' => $payload,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Message();
		$result = $tool->execute(
			array(
				'message_id' => 'msg-long',
				'max_chars'  => 100,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['data']['truncated'] );
		$this->assertLessThanOrEqual( 100, $result['data']['body_chars'] );
	}

	/**
	 * The include_headers flag should expose the full header list.
	 */
	public function test_get_gmail_message_include_headers() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$payload = $this->message_payload(
			'msg-h',
			'thread-1',
			1700000000000,
			array( $this->plain_part( 'Body.' ) )
		);

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token'      => array( 'access_token' => 'mock-token' ),
				'gmail/v1/users/me/messages/msg-h' => $payload,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Message();
		$result = $tool->execute(
			array(
				'message_id'      => 'msg-h',
				'include_headers' => true,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['data']['headers'] );

		$names = wp_list_pluck( $result['data']['headers'], 'name' );
		$this->assertContains( 'Subject', $names, 'Header list should include the Subject header.' );
	}

	/**
	 * Reading requires an appropriate capability.
	 */
	public function test_get_gmail_message_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Message();
		$result = $tool->execute(
			array( 'message_id' => 'msg-1' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_forbidden', $result->get_error_code() );
	}

	/**
	 * Reading requires configured credentials.
	 */
	public function test_get_gmail_message_requires_credentials() {
		$admin_id = $this->login_as_admin();

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Message();
		$result = $tool->execute(
			array( 'message_id' => 'msg-1' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Thread reads should be sorted newest-first and respect max_messages.
	 */
	public function test_get_gmail_thread_sorts_newest_first_and_caps() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$thread_payload = array(
			'id'        => 'thread-1',
			'historyId' => '123',
			'messages'  => array(
				$this->message_payload( 'old', 'thread-1', 1700000000000, array( $this->plain_part( 'Oldest.' ) ) ),
				$this->message_payload( 'new', 'thread-1', 1700007200000, array( $this->plain_part( 'Newest.' ) ) ),
				$this->message_payload( 'mid', 'thread-1', 1700003600000, array( $this->plain_part( 'Middle.' ) ) ),
			),
		);

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token'        => array( 'access_token' => 'mock-token' ),
				'gmail/v1/users/me/threads/thread-1' => $thread_payload,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Gmail_Thread();
		$result = $tool->execute(
			array(
				'thread_id'    => 'thread-1',
				'max_messages' => 2,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$data = $result['data'];
		$this->assertSame( 'thread-1', $data['thread_id'] );
		$this->assertSame( 3, $data['message_count'] );
		$this->assertSame( 2, $data['returned_count'] );
		$this->assertSame( 'new', $data['messages'][0]['id'] );
		$this->assertSame( 'mid', $data['messages'][1]['id'] );
		$this->assertSame( 'Newest.', $data['messages'][0]['body'] );
	}

	/**
	 * The list_gmail_connections tool must redact credentials and exclude non-Gmail types.
	 */
	public function test_list_gmail_connections_redacts_secrets() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				'conn_store' => array(
					'connection_type' => 'gmail',
					'name'            => 'Store Inbox',
					'enabled'         => true,
					'user_email'      => 'store@example.com',
					'client_id'       => 'cid',
					'client_secret'   => 'TOP_SECRET',
					'refresh_token'   => 'REFRESH_SECRET',
				),
				'conn_site'  => array(
					'connection_type' => 'wordpress',
					'name'            => 'Some WP Site',
					'enabled'         => true,
					'user_email'      => 'wp@example.com',
				),
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_List_Gmail_Connections();
		$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$rows = $result['data']['connections'];

		// Gmail connection + settings fallback = 2 rows; WordPress excluded.
		$this->assertCount( 2, $rows );

		$gmail_row = $rows[0];
		$this->assertSame( 'conn_store', $gmail_row['id'] );
		$this->assertSame( 'Store Inbox', $gmail_row['name'] );
		$this->assertTrue( $gmail_row['enabled'] );
		$this->assertSame( 'store@example.com', $gmail_row['user_email'] );

		$serialized = wp_json_encode( $rows );
		$this->assertStringNotContainsString( 'TOP_SECRET', $serialized );
		$this->assertStringNotContainsString( 'REFRESH_SECRET', $serialized );
		$this->assertStringNotContainsString( 'client_secret', $serialized );
		$this->assertStringNotContainsString( 'refresh_token', $serialized );

		$ids = wp_list_pluck( $rows, 'id' );
		$this->assertContains( 'settings', $ids, 'Settings-based fallback should be listed.' );
	}

	/**
	 * The modify_gmail_message tool requires an appropriate capability.
	 */
	public function test_modify_gmail_message_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Pro_Tool_Modify_Gmail_Message();
		$result = $tool->execute(
			array(
				'message_id' => 'msg-1',
				'mark_read'  => true,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_forbidden', $result->get_error_code() );
	}

	/**
	 * The modify_gmail_message tool must reject empty operation sets.
	 */
	public function test_modify_gmail_message_rejects_noop() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$tool   = new WP_MCP_AI_Pro_Tool_Modify_Gmail_Message();
		$result = $tool->execute(
			array( 'message_id' => 'msg-1' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gmail_modify_nothing_to_do', $result->get_error_code() );
	}

	/**
	 * Convenience flags must map onto Gmail label semantics in the modify request.
	 */
	public function test_modify_gmail_message_maps_labels() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$posted_body = null;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$posted_body ) {
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

				if ( false !== strpos( $url, 'gmail/v1/users/me/messages/msg-1/modify' ) ) {
					$posted_body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;

					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'id'       => 'msg-1',
								'threadId' => 'thread-1',
								'labelIds' => array( 'Label_42' ),
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

		$tool   = new WP_MCP_AI_Pro_Tool_Modify_Gmail_Message();
		$result = $tool->execute(
			array(
				'message_id'    => 'msg-1',
				'add_label_ids' => array( 'Label_42' ),
				'mark_read'     => true,
				'archive'       => true,
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$this->assertIsArray( $posted_body, 'Modify request should carry a JSON body.' );
		$this->assertSame( array( 'Label_42' ), $posted_body['addLabelIds'] );
		$this->assertSame( array( 'UNREAD', 'INBOX' ), $posted_body['removeLabelIds'] );

		$this->assertSame( 'msg-1', $result['data']['id'] );
		$this->assertSame( 'thread-1', $result['data']['thread_id'] );
		$this->assertSame( array( 'UNREAD', 'INBOX' ), $result['data']['applied']['remove_label_ids'] );
	}

	/**
	 * The modify_gmail_message tool must be flagged state-changing so the destructive gate applies.
	 */
	public function test_modify_gmail_message_flags_are_state_changing() {
		$tool  = new WP_MCP_AI_Pro_Tool_Modify_Gmail_Message();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'write', $flags );
	}

	/**
	 * The search_gmail ids_only mode should skip per-message detail requests entirely.
	 */
	public function test_search_gmail_ids_only_skips_detail_fetches() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$request_count = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$request_count ) {
				$request_count++;

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

				if ( false !== strpos( $url, 'users/me/messages?' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'messages'           => array(
									array(
										'id'       => 'abc123',
										'threadId' => 'thread-1',
									),
									array(
										'id'       => 'def456',
										'threadId' => 'thread-2',
									),
								),
								'resultSizeEstimate' => 2,
								'nextPageToken'      => 'next-token',
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

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array(
				'query'    => 'from:x@example.com',
				'ids_only' => true,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertCount( 2, $result['messages'] );
		$this->assertSame( 2, $request_count, 'ids_only should trigger exactly the token and list requests.' );
		$this->assertSame(
			array( 'id', 'thread_id' ),
			array_keys( $result['messages'][0] ),
			'ids_only rows should carry only id and thread_id.'
		);
		$this->assertTrue( $result['has_more'] );
	}

	/**
	 * The search_gmail tool should sort results newest-first by internalDate.
	 */
	public function test_search_gmail_sorts_newest_first() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token' => array( 'access_token' => 'mock-token' ),
				'users/me/messages?'          => array(
					'messages'           => array(
						array(
							'id'       => 'old',
							'threadId' => 't-old',
						),
						array(
							'id'       => 'new',
							'threadId' => 't-new',
						),
					),
					'resultSizeEstimate' => 2,
				),
				'users/me/messages/old'       => $this->message_payload( 'old', 't-old', 1700000000000, array( $this->plain_part( 'Old.' ) ) ),
				'users/me/messages/new'       => $this->message_payload( 'new', 't-new', 1700007200000, array( $this->plain_part( 'New.' ) ) ),
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array( 'query' => 'in:inbox' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'new', $result['messages'][0]['id'] );
		$this->assertSame( 'old', $result['messages'][1]['id'] );
	}

	/**
	 * The search_gmail thread_group mode should collapse rows per thread with a message count.
	 */
	public function test_search_gmail_thread_group_collapses_threads() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token' => array( 'access_token' => 'mock-token' ),
				'users/me/messages?'          => array(
					'messages'           => array(
						array(
							'id'       => 'a1',
							'threadId' => 't1',
						),
						array(
							'id'       => 'a2',
							'threadId' => 't1',
						),
						array(
							'id'       => 'b1',
							'threadId' => 't2',
						),
					),
					'resultSizeEstimate' => 3,
				),
				'users/me/messages/a1'        => $this->message_payload( 'a1', 't1', 1700000000000, array( $this->plain_part( 'A1.' ) ) ),
				'users/me/messages/a2'        => $this->message_payload( 'a2', 't1', 1700007200000, array( $this->plain_part( 'A2.' ) ) ),
				'users/me/messages/b1'        => $this->message_payload( 'b1', 't2', 1700003600000, array( $this->plain_part( 'B1.' ) ) ),
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array(
				'query'        => 'in:inbox',
				'thread_group' => true,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertCount( 2, $result['messages'], 'Two threads should collapse into two rows.' );

		$first = $result['messages'][0];
		$this->assertSame( 't1', $first['thread_id'] );
		$this->assertSame( 'a2', $first['id'], 'The newest message should represent the thread.' );
		$this->assertSame( 2, $first['message_count'] );

		$this->assertSame( 't2', $result['messages'][1]['thread_id'] );
		$this->assertSame( 1, $result['messages'][1]['message_count'] );
	}

	/**
	 * A snippet_length of 0 should omit snippets entirely.
	 */
	public function test_search_gmail_snippet_length_zero_omits_snippet() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token' => array( 'access_token' => 'mock-token' ),
				'users/me/messages?'          => array(
					'messages'           => array(
						array(
							'id'       => 'msg-a',
							'threadId' => 't-a',
						),
					),
					'resultSizeEstimate' => 1,
				),
				'users/me/messages/msg-a'     => $this->message_payload( 'msg-a', 't-a', 1700000000000, array( $this->plain_part( 'Body.' ) ) ),
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array(
				'query'          => 'in:inbox',
				'snippet_length' => 0,
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayNotHasKey( 'snippet', $result['messages'][0], 'snippet should be omitted when snippet_length is 0.' );
	}

	/**
	 * Search rows should expose attachment metadata.
	 */
	public function test_search_gmail_detects_attachments() {
		$this->configure_settings_credentials();
		$admin_id = $this->login_as_admin();

		$payload                       = $this->message_payload( 'att', 't-att', 1700000000000, array( $this->plain_part( 'See attached.' ) ) );
		$payload['payload']['parts'][] = array(
			'partId'   => '2',
			'mimeType' => 'application/pdf',
			'filename' => 'invoice.pdf',
			'body'     => array(
				'data' => '',
			),
		);

		$this->stub_http(
			array(
				'oauth2.googleapis.com/token' => array( 'access_token' => 'mock-token' ),
				'users/me/messages?'          => array(
					'messages'           => array(
						array(
							'id'       => 'att',
							'threadId' => 't-att',
						),
					),
					'resultSizeEstimate' => 1,
				),
				'users/me/messages/att'       => $payload,
			)
		);

		$tool   = new WP_MCP_AI_Pro_Tool_Search_Gmail();
		$result = $tool->execute(
			array( 'query' => 'in:inbox' ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['messages'][0]['has_attachments'] );
		$this->assertSame( array( 'invoice.pdf' ), $result['messages'][0]['attachment_names'] );
	}

	/**
	 * Argument-less Gmail tools must encode empty properties as `{}`.
	 *
	 * Strict providers (DeepSeek) reject schemas whose `properties` key is a
	 * JSON array: "Invalid schema for function 'x': [] is not of type 'object'".
	 */
	public function test_list_gmail_connections_schema_encodes_empty_properties_as_object() {
		$tool   = new WP_MCP_AI_Pro_Tool_List_Gmail_Connections();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertSame(
			'{}',
			wp_json_encode( $schema['properties'] ),
			'list_gmail_connections properties must encode as an empty object, not [].'
		);
	}
}
