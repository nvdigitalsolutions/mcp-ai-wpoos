<?php
/**
 * Tests for Google Chat Space Tools.
 *
 * Validates the tool metadata, parameter schemas, input validation, and
 * space-specific enhancements (pagination, threading, membership management).
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Google Chat Space tools.
 */
class Test_Google_Chat_Space_Tools extends WP_UnitTestCase {

	/**
	 * Load a Google Chat tool class.
	 *
	 * @param string $class_name PHP class name.
	 * @param string $file_name  File name within ChatChannels directory.
	 */
	private function load_tool( $class_name, $file_name ) {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! class_exists( $class_name ) ) {
			$path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/' . $file_name;

			if ( ! file_exists( $path ) ) {
				$this->markTestSkipped( $class_name . ' file not found at ' . $path );
			}

			// Interface file required.
			$interface_path = WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			if ( file_exists( $interface_path ) ) {
				require_once $interface_path;
			}

			require_once $path;
		}

		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( $class_name . ' could not be loaded' );
		}
	}

	// =========================================================================
	// Get Google Chat Spaces – pagination parameters.
	// =========================================================================

	/**
	 * Test get_google_chat_spaces schema includes page_size and page_token parameters.
	 */
	public function test_get_google_chat_spaces_schema_has_pagination_params() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces', 'class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'page_size', $schema['properties'], 'Schema must include page_size' );
		$this->assertArrayHasKey( 'page_token', $schema['properties'], 'Schema must include page_token' );
		$this->assertSame( 'integer', $schema['properties']['page_size']['type'] );
		$this->assertSame( 'string', $schema['properties']['page_token']['type'] );
	}

	/**
	 * Test get_google_chat_spaces slug and name.
	 */
	public function test_get_google_chat_spaces_metadata() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces', 'class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php' );

		$tool = new WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces();

		$this->assertSame( 'get_google_chat_spaces', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test get_google_chat_spaces returns error when no credentials are provided.
	 */
	public function test_get_google_chat_spaces_requires_access_token() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces', 'class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces();
		$result = $tool->execute( array(), array( 'user_id' => $admin ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_access_token', $result->get_error_code() );
	}

	/**
	 * Test get_google_chat_spaces schema includes service_account_key and access_token parameters.
	 */
	public function test_get_google_chat_spaces_schema_has_auth_params() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces', 'class-wp-mcp-ai-pro-tool-get-google-chat-spaces.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'service_account_key', $schema['properties'], 'Schema must include service_account_key' );
		$this->assertArrayHasKey( 'access_token', $schema['properties'], 'Schema must include access_token for backwards compat' );
	}

	// =========================================================================
	// Send Google Chat Message – thread support parameters.
	// =========================================================================

	/**
	 * Test send_google_chat_message schema includes thread_key and thread_name parameters.
	 */
	public function test_send_google_chat_message_schema_has_thread_params() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message', 'class-wp-mcp-ai-pro-tool-send-google-chat-message.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'thread_key', $schema['properties'], 'Schema must include thread_key' );
		$this->assertArrayHasKey( 'thread_name', $schema['properties'], 'Schema must include thread_name' );
		$this->assertSame( 'string', $schema['properties']['thread_key']['type'] );
		$this->assertSame( 'string', $schema['properties']['thread_name']['type'] );
	}

	/**
	 * Test send_google_chat_message returns error when space format is invalid.
	 */
	public function test_send_google_chat_message_rejects_invalid_space_format() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message', 'class-wp-mcp-ai-pro-tool-send-google-chat-message.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message();
		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'space'        => 'invalid-format',
				'text'         => 'Hello',
			),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_space', $result->get_error_code() );
	}

	/**
	 * Test send_google_chat_message slug and name.
	 */
	public function test_send_google_chat_message_metadata() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message', 'class-wp-mcp-ai-pro-tool-send-google-chat-message.php' );

		$tool = new WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message();

		$this->assertSame( 'send_google_chat_message', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
	}

	// =========================================================================
	// Get Google Chat Messages – pagination, ordering, filtering.
	// =========================================================================

	/**
	 * Test get_google_chat_messages schema includes pagination and ordering params.
	 */
	public function test_get_google_chat_messages_schema_has_advanced_params() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages', 'class-wp-mcp-ai-pro-tool-get-google-chat-messages.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'page_token', $schema['properties'], 'Schema must include page_token' );
		$this->assertArrayHasKey( 'order_by', $schema['properties'], 'Schema must include order_by' );
		$this->assertArrayHasKey( 'filter', $schema['properties'], 'Schema must include filter' );
		$this->assertContains( 'createTime asc', $schema['properties']['order_by']['enum'] );
		$this->assertContains( 'createTime desc', $schema['properties']['order_by']['enum'] );
	}

	// =========================================================================
	// List Google Chat Space Members tool.
	// =========================================================================

	/**
	 * Test list_google_chat_space_members tool metadata.
	 */
	public function test_list_google_chat_space_members_metadata() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members', 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php' );

		$tool = new WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members();

		$this->assertSame( 'list_google_chat_space_members', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test list_google_chat_space_members schema structure.
	 */
	public function test_list_google_chat_space_members_schema() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members', 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members();
		$schema = $tool->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'service_account_key', $schema['properties'] );
		$this->assertArrayHasKey( 'access_token', $schema['properties'] );
		$this->assertArrayHasKey( 'space', $schema['properties'] );
		$this->assertArrayHasKey( 'page_size', $schema['properties'] );
		$this->assertArrayHasKey( 'page_token', $schema['properties'] );
		$this->assertArrayHasKey( 'filter', $schema['properties'] );
		$this->assertContains( 'space', $schema['required'] );
	}

	/**
	 * Test list_google_chat_space_members rejects invalid space format.
	 */
	public function test_list_google_chat_space_members_rejects_invalid_space() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members', 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members();
		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'space'        => 'not-a-valid-space',
			),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_space', $result->get_error_code() );
	}

	/**
	 * Test list_google_chat_space_members requires access token.
	 */
	public function test_list_google_chat_space_members_requires_access_token() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members', 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members();
		$result = $tool->execute(
			array(
				'access_token' => '',
				'space'        => 'spaces/AAABBB',
			),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_access_token', $result->get_error_code() );
	}

	/**
	 * Test list_google_chat_space_members capability flags include read-only.
	 */
	public function test_list_google_chat_space_members_is_read_only() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members', 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php' );

		$tool  = new WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	// =========================================================================
	// Add Google Chat Space Member tool.
	// =========================================================================

	/**
	 * Test add_google_chat_space_member tool metadata.
	 */
	public function test_add_google_chat_space_member_metadata() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' );

		$tool = new WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member();

		$this->assertSame( 'add_google_chat_space_member', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test add_google_chat_space_member schema has role enum.
	 */
	public function test_add_google_chat_space_member_schema_has_role() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'role', $schema['properties'] );
		$this->assertContains( 'ROLE_MEMBER', $schema['properties']['role']['enum'] );
		$this->assertContains( 'ROLE_MANAGER', $schema['properties']['role']['enum'] );
		$this->assertSame( 'ROLE_MEMBER', $schema['properties']['role']['default'] );
	}

	/**
	 * Test add_google_chat_space_member rejects invalid member_name format.
	 */
	public function test_add_google_chat_space_member_rejects_invalid_member_name() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member();
		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'space'        => 'spaces/AAABBB',
				'member_name'  => 'invalid-member-format',
			),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_member_name', $result->get_error_code() );
	}

	/**
	 * Test add_google_chat_space_member rejects invalid space format.
	 */
	public function test_add_google_chat_space_member_rejects_invalid_space() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member();
		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'space'        => 'bad-space',
				'member_name'  => 'users/123456',
			),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_space', $result->get_error_code() );
	}

	/**
	 * Test add_google_chat_space_member accepts valid users/ and groups/ member names.
	 */
	public function test_add_google_chat_space_member_accepts_valid_member_formats() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool = new WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member();

		// Patch wp_remote_post to avoid actual HTTP calls.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array( 'name' => 'spaces/AAA/members/123' ) ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'space'        => 'spaces/AAABBB',
				'member_name'  => 'users/123456',
			),
			array( 'user_id' => $admin )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Valid member_name should not return WP_Error for HTTP errors' );
	}

	/**
	 * Test add_google_chat_space_member infers BOT type for groups/ member names.
	 */
	public function test_add_google_chat_space_member_infers_bot_type_for_groups() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$captured_body = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array( 'name' => 'spaces/AAA/members/group123' ) ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool = new WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member();
		$tool->execute(
			array(
				'access_token' => 'fake_token',
				'space'        => 'spaces/AAABBB',
				'member_name'  => 'groups/group123',
			),
			array( 'user_id' => $admin )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'BOT', $captured_body['member']['type'] ?? null, 'groups/ member names should get BOT type' );
	}

	// =========================================================================
	// Remove Google Chat Space Member tool.
	// =========================================================================

	/**
	 * Test remove_google_chat_space_member tool metadata.
	 */
	public function test_remove_google_chat_space_member_metadata() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php' );

		$tool = new WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member();

		$this->assertSame( 'remove_google_chat_space_member', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test remove_google_chat_space_member schema requires membership param.
	 */
	public function test_remove_google_chat_space_member_schema() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php' );

		$tool   = new WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'membership', $schema['properties'] );
		$this->assertArrayHasKey( 'service_account_key', $schema['properties'] );
		$this->assertArrayHasKey( 'access_token', $schema['properties'] );
		$this->assertContains( 'membership', $schema['required'] );
	}

	/**
	 * Test remove_google_chat_space_member rejects invalid membership format.
	 */
	public function test_remove_google_chat_space_member_rejects_invalid_membership() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tool   = new WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member();
		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'membership'   => 'bad/format/here',
			),
			array( 'user_id' => $admin )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_membership', $result->get_error_code() );
	}

	/**
	 * Test remove_google_chat_space_member accepts valid resource name.
	 */
	public function test_remove_google_chat_space_member_accepts_valid_membership() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool = new WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array() ),
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = $tool->execute(
			array(
				'access_token' => 'fake_token',
				'membership'   => 'spaces/AAABBB/members/123456',
			),
			array( 'user_id' => $admin )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test remove_google_chat_space_member capability flags include write.
	 */
	public function test_remove_google_chat_space_member_is_write_capable() {
		$this->load_tool( 'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php' );

		$tool  = new WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'write', $flags );
		$this->assertContains( 'pro', $flags );
	}

	// =========================================================================
	// Capability checks.
	// =========================================================================

	/**
	 * Test all new Google Chat tools deny non-admin users.
	 */
	public function test_google_chat_space_tools_deny_non_admin_users() {
		$tools = array(
			array( 'WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members', 'class-wp-mcp-ai-pro-tool-list-google-chat-space-members.php' ),
			array( 'WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-add-google-chat-space-member.php' ),
			array( 'WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member', 'class-wp-mcp-ai-pro-tool-remove-google-chat-space-member.php' ),
		);

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		foreach ( $tools as $tool_config ) {
			list( $class_name, $file_name ) = $tool_config;
			$this->load_tool( $class_name, $file_name );

			$tool   = new $class_name();
			$result = $tool->execute(
				array( 'access_token' => 'fake', 'space' => 'spaces/AAA' ),
				array( 'user_id' => $subscriber )
			);

			$this->assertInstanceOf( WP_Error::class, $result, $class_name . ' should deny non-admin users' );
			$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
		}
	}
}
