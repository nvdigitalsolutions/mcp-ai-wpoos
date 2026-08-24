<?php
/**
 * Tests for the composio_* MCP tools.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-composio-tools.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-list-tools.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-get-tool-schema.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-list-connected-accounts.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-create-connect-link.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-execute-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-manage-triggers.php';

/**
 * Test the composio_* tools.
 */
class Test_Composio_Tools extends WP_UnitTestCase {

	/**
	 * Connection under test.
	 *
	 * @var array
	 */
	private $connection = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		$id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Composio Tools Test',
				'url'             => 'https://backend.composio.dev',
				'connection_type' => 'composio',
				'auth_type'       => 'none',
				'api_key'         => 'ak_test_123',
				'enabled'         => true,
			)
		);

		$this->assertNotWPError( $id );
		$this->connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );

		// Grant the admin capability to the anonymous test user.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that all six tools expose the expected slugs and capabilities.
	 */
	public function test_tool_registration_shape() {
		$map = array(
			'WP_MCP_AI_Tool_Composio_List_Tools'          => array( 'composio_list_tools', 'edit_posts' ),
			'WP_MCP_AI_Tool_Composio_Get_Tool_Schema'     => array( 'composio_get_tool_schema', 'edit_posts' ),
			'WP_MCP_AI_Tool_Composio_List_Connected_Accounts' => array( 'composio_list_connected_accounts', 'manage_options' ),
			'WP_MCP_AI_Tool_Composio_Create_Connect_Link' => array( 'composio_create_connect_link', 'manage_options' ),
			'WP_MCP_AI_Tool_Composio_Execute_Tool'        => array( 'composio_execute_tool', 'manage_options' ),
			'WP_MCP_AI_Tool_Composio_Manage_Triggers'     => array( 'composio_manage_triggers', 'manage_options' ),
		);

		foreach ( $map as $class_name => $expected ) {
			$tool = new $class_name();
			$this->assertSame( $expected[0], $tool->get_slug() );
			$this->assertSame( $expected[1], $tool->get_required_capability() );
			$this->assertIsArray( $tool->get_parameters_schema() );
		}
	}

	/**
	 * Test that list_tools returns a canonical envelope with mocked HTTP.
	 */
	public function test_list_tools_returns_canonical_envelope() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							array(
								'tool_slug'   => 'GMAIL_SEND_EMAIL',
								'toolkit'     => 'gmail',
								'name'        => 'Send Email',
								'description' => 'Sends an email',
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_List_Tools();
		$result = $tool->execute( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'GMAIL_SEND_EMAIL', $result['tools'][0]['slug'] );
	}

	/**
	 * Test that list_tools fails cleanly when no connection exists.
	 */
	public function test_tools_fail_without_connection() {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		$tool   = new WP_MCP_AI_Tool_Composio_List_Tools();
		$result = $tool->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_no_connection', $result->get_error_code() );
	}

	/**
	 * Test that manage_triggers rejects invalid actions.
	 */
	public function test_manage_triggers_rejects_invalid_action() {
		$tool   = new WP_MCP_AI_Tool_Composio_Manage_Triggers();
		$result = $tool->execute( array( 'action' => 'explode' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_action', $result->get_error_code() );
	}

	/**
	 * Test that execute_tool flags destructive slugs and passes account IDs through.
	 */
	public function test_execute_tool_destructive_classification() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'success' => true ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GMAIL_SEND_EMAIL',
				'connected_account_id' => 'ca_123',
				'arguments'            => array( 'to' => 'a@b.c' ),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['destructive'] );
		$this->assertSame( 'ca_123', $result['account_id'] );
	}

	/**
	 * Test that execute_tool requires a connected account when none is supplied
	 * and the listing is empty.
	 */
	public function test_execute_tool_requires_account_when_none_found() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array() ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_SEND_EMAIL' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_no_active_account', $result->get_error_code() );
	}

	/**
	 * Test that get_tool_schema validates missing params.
	 */
	public function test_get_tool_schema_requires_slug() {
		$tool   = new WP_MCP_AI_Tool_Composio_Get_Tool_Schema();
		$result = $tool->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test that list_connected_accounts unwraps the v3.1 { items: [...] }
	 * response and renders the nested toolkit slug instead of a placeholder.
	 */
	public function test_list_connected_accounts_handles_wrapped_response() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'items'       => array(
								array(
									'id'      => 'ca_1',
									'alias'   => 'me@example.com',
									'toolkit' => array( 'slug' => 'gmail' ),
									'status'  => 'ACTIVE',
								),
							),
							'total_items' => 1,
							'next_cursor' => '',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_List_Connected_Accounts();
		$result = $tool->execute( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'ca_1', $result['accounts'][0]['id'] );
		$this->assertSame( 'gmail', $result['accounts'][0]['toolkit'] );
		$this->assertSame( 'me@example.com', $result['accounts'][0]['alias'] );
		$this->assertSame( 'ACTIVE', $result['accounts'][0]['status'] );
	}

	/**
	 * Test that execute_tool auto-resolves an account from a wrapped listing.
	 */
	public function test_execute_tool_resolves_account_from_wrapped_listing() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				if ( 'GET' === $args['method'] ) {
					return array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'body'     => wp_json_encode(
							array(
								'items'       => array(
									array(
										'id'     => 'ca_gmail_1',
										'status' => 'ACTIVE',
									),
								),
								'total_items' => 1,
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'success' => true ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_SEND_EMAIL' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'ca_gmail_1', $result['account_id'] );
	}

	/**
	 * Capture the JSON body of the tool-execution POST while serving canned
	 * responses for the GET lookups that precede it.
	 *
	 * @param array $listing Connected-account listing items returned for GETs.
	 * @param array $captured Output. Receives the decoded POST body.
	 * @return void
	 */
	private function mock_execute_transport( array $listing, &$captured ) {
		$captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $listing, &$captured ) {
				if ( 'GET' === $args['method'] ) {
					// Single-account lookups return the account object itself;
					// listings return the v3.1 { items: [...] } wrapper.
					$is_single = (bool) preg_match( '#/connected_accounts/[^/?]+#', $url );
					$body      = $is_single
						? ( isset( $listing[0] ) ? $listing[0] : array() )
						: array(
							'items'       => $listing,
							'total_items' => count( $listing ),
						);

					return array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'body'     => wp_json_encode( $body ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				$captured = json_decode( $args['body'], true );

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'successful' => true ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Test that execute_tool sends the connection's shared identity with the
	 * connected account. Without it Composio answers "User ID is required with
	 * connected account".
	 */
	public function test_execute_tool_sends_shared_identity() {
		$captured = array();
		$this->mock_execute_transport( array(), $captured );

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GMAIL_FETCH_EMAILS',
				'connected_account_id' => 'ca_shared_1',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'ca_shared_1', $captured['connected_account_id'] );
		$this->assertSame( WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX, $captured['user_id'] );
		$this->assertSame( WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX, $result['composio_user_id'] );
	}

	/**
	 * Test that the identity stored on the account wins over the connection
	 * default, so accounts linked under another identity keep working.
	 */
	public function test_execute_tool_prefers_account_owner_identity() {
		$captured = array();
		$this->mock_execute_transport(
			array(
				array(
					'id'      => 'ca_owned_1',
					'status'  => 'ACTIVE',
					'user_id' => 'wp-9',
				),
			),
			$captured
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GMAIL_FETCH_EMAILS',
				'connected_account_id' => 'ca_owned_1',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'wp-9', $captured['user_id'] );
	}

	/**
	 * Test that auto-resolution prefers the active account belonging to the
	 * connection's identity over other active accounts.
	 */
	public function test_execute_tool_auto_resolve_prefers_matching_identity() {
		$captured = array();
		$this->mock_execute_transport(
			array(
				array(
					'id'      => 'ca_other',
					'status'  => 'ACTIVE',
					'user_id' => 'wp-4',
				),
				array(
					'id'      => 'ca_shared',
					'status'  => 'ACTIVE',
					'user_id' => WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX,
				),
			),
			$captured
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_FETCH_EMAILS' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'ca_shared', $result['account_id'] );
		$this->assertSame( WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX, $captured['user_id'] );
	}

	/**
	 * Test that auto-resolution skips accounts that are not active.
	 */
	public function test_execute_tool_auto_resolve_skips_inactive_accounts() {
		$captured = array();
		$this->mock_execute_transport(
			array(
				array(
					'id'      => 'ca_initializing',
					'status'  => 'INITIALIZING',
					'user_id' => WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX,
				),
				array(
					'id'      => 'ca_expired',
					'status'  => 'EXPIRED',
					'user_id' => WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX,
				),
				array(
					'id'      => 'ca_live',
					'status'  => 'ACTIVE',
					'user_id' => 'wp-2',
				),
			),
			$captured
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_FETCH_EMAILS' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'ca_live', $result['account_id'] );
		$this->assertSame( 'wp-2', $captured['user_id'] );
	}

	/**
	 * Test that the connected-account listing exposes the owning identity so
	 * assistants can diagnose identity-mode mismatches.
	 */
	public function test_list_connected_accounts_exposes_identity() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'items'       => array(
								array(
									'id'      => 'ca_1',
									'toolkit' => array( 'slug' => 'gmail' ),
									'status'  => 'ACTIVE',
									'user_id' => 'nvoos-shared',
								),
							),
							'total_items' => 1,
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_List_Connected_Accounts();
		$result = $tool->execute( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'nvoos-shared', $result['accounts'][0]['user_id'] );
	}
}
