<?php
/**
 * Tests for the composio_* MCP tools.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-account-health.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-composio-tools.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-list-tools.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-get-tool-schema.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-list-connected-accounts.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/composio/class-wp-mcp-ai-tool-composio-manage-accounts.php';
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
		if ( isset( $this->connection['id'] ) ) {
			WP_MCP_AI_Composio_Account_Health::forget_all( $this->connection['id'] );
		}
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_composio_probe_tool' );
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that all seven tools expose the expected slugs and capabilities.
	 */
	public function test_tool_registration_shape() {
		$map = array(
			'WP_MCP_AI_Tool_Composio_List_Tools'          => array( 'composio_list_tools', 'edit_posts' ),
			'WP_MCP_AI_Tool_Composio_Get_Tool_Schema'     => array( 'composio_get_tool_schema', 'edit_posts' ),
			'WP_MCP_AI_Tool_Composio_List_Connected_Accounts' => array( 'composio_list_connected_accounts', 'manage_options' ),
			'WP_MCP_AI_Tool_Composio_Manage_Accounts'     => array( 'composio_manage_accounts', 'manage_options' ),
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
		$result = $tool->execute( array( 'verify' => false ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'ca_1', $result['accounts'][0]['id'] );
		$this->assertSame( 'gmail', $result['accounts'][0]['toolkit'] );
		$this->assertSame( 'me@example.com', $result['accounts'][0]['alias'] );
		$this->assertSame( 'ACTIVE', $result['accounts'][0]['status'] );
		// Skipping verification must be stated plainly, not implied.
		$this->assertFalse( $result['verification_enabled'] );
		$this->assertFalse( $result['accounts'][0]['health']['verified'] );
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
		$result = $tool->execute( array( 'verify' => false ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'nvoos-shared', $result['accounts'][0]['user_id'] );
	}

	/**
	 * Register a URL-aware transport mock for Composio's route families.
	 *
	 * @param array $routes Keys: account, accounts, catalog, schema, execute.
	 * @return void
	 */
	private function mock_routes( array $routes ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $routes ) {
				$body = array();

				if ( false !== strpos( $url, '/tools/execute/' ) ) {
					$body = isset( $routes['execute'] ) ? $routes['execute'] : array( 'successful' => true );
				} elseif ( preg_match( '#/tools/[A-Z][A-Z0-9_]*#', $url ) ) {
					$body = isset( $routes['schema'] ) ? $routes['schema'] : array();
				} elseif ( false !== strpos( $url, '/toolkits' ) ) {
					$body = isset( $routes['toolkits'] ) ? $routes['toolkits'] : array( 'items' => array() );
				} elseif ( false !== strpos( $url, '/tools' ) ) {
					$body = isset( $routes['catalog'] ) ? $routes['catalog'] : array( 'items' => array() );
				} elseif ( preg_match( '#/connected_accounts/[^/?]+#', $url ) ) {
					$body = isset( $routes['account'] ) ? $routes['account'] : array();
				} elseif ( false !== strpos( $url, '/connected_accounts' ) ) {
					$body = isset( $routes['accounts'] ) ? array( 'items' => $routes['accounts'] ) : array( 'items' => array() );
				}

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
			},
			10,
			3
		);
	}

	/**
	 * Test that the catalog listing surfaces real slugs from the v3.1 wrapper
	 * and groups them by toolkit. Before the fix this returned a single blank
	 * entry, forcing callers to guess slugs.
	 */
	public function test_list_tools_returns_populated_grouped_catalog() {
		$this->mock_routes(
			array(
				'catalog' => array(
					'items'       => array(
						array(
							'slug'        => 'GMAIL_FETCH_EMAILS',
							'name'        => 'Fetch Emails',
							'description' => 'Fetch emails from a Gmail mailbox.',
							'toolkit'     => array( 'slug' => 'gmail' ),
						),
						array(
							'slug'        => 'SLACK_SEND_MESSAGE',
							'name'        => 'Send Message',
							'description' => 'Post a message to a Slack channel.',
							'toolkit'     => array( 'slug' => 'slack' ),
						),
					),
					'total_items' => 2,
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_List_Tools();
		$result = $tool->execute( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 'GMAIL_FETCH_EMAILS', $result['tools'][0]['slug'] );
		$this->assertSame( 'gmail', $result['tools'][0]['toolkit'] );
		$this->assertArrayHasKey( 'gmail', $result['toolkits'] );
		$this->assertArrayHasKey( 'slack', $result['toolkits'] );
		$this->assertContains( 'SLACK_SEND_MESSAGE', $result['toolkits']['slack'] );
	}

	/**
	 * Test that a natural-language query ranks the obvious match first.
	 */
	public function test_list_tools_ranks_natural_language_query() {
		$this->mock_routes(
			array(
				'catalog' => array(
					'items' => array(
						array(
							'slug'        => 'GMAIL_LIST_LABELS',
							'name'        => 'List Labels',
							'description' => 'Lists Gmail labels.',
							'toolkit'     => array( 'slug' => 'gmail' ),
						),
						array(
							'slug'        => 'GMAIL_SEND_EMAIL',
							'name'        => 'Send Email',
							'description' => 'Sends an email message.',
							'toolkit'     => array( 'slug' => 'gmail' ),
						),
					),
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_List_Tools();
		$result = $tool->execute( array( 'search' => 'send an email' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'GMAIL_SEND_EMAIL', $result['tools'][0]['slug'] );
	}

	/**
	 * Test that the connected-apps view says so plainly when nothing is
	 * connected, instead of returning a blank list.
	 */
	public function test_list_tools_connected_only_without_accounts() {
		$this->mock_routes( array( 'accounts' => array() ) );

		$tool   = new WP_MCP_AI_Tool_Composio_List_Tools();
		$result = $tool->execute( array( 'connected_only' => true ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 0, $result['count'] );
		$this->assertTrue( $result['connected_only'] );
		$this->assertStringContainsString( 'composio_create_connect_link', $result['message'] );
	}

	/**
	 * Test that a verified listing reports a revoked-but-ACTIVE credential as
	 * broken — the false positive this work exists to remove.
	 */
	public function test_list_connected_accounts_reports_revoked_token() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		$this->mock_routes(
			array(
				'accounts' => array(
					array(
						'id'      => 'ca_zombie',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'nvoos-shared',
					),
				),
				'execute'  => array(
					'successful' => false,
					'error'      => 'Auth refresh required: invalid_grant',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_List_Connected_Accounts();
		$result = $tool->execute( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'ACTIVE', $result['accounts'][0]['status'] );
		$this->assertFalse( $result['accounts'][0]['health']['verified'] );
		$this->assertTrue( $result['accounts'][0]['health']['needs_reconnect'] );
		$this->assertNotEmpty( $result['accounts'][0]['reconnect_url'] );
		$this->assertSame( 1, $result['summary']['needs_reconnect'] );
		$this->assertStringContainsString( 'need reconnecting', $result['message'] );
	}

	/**
	 * Test that a 401 during execution becomes an actionable, recoverable error
	 * carrying a reconnect URL rather than a raw upstream message.
	 */
	public function test_execute_tool_surfaces_reconnect_guidance_on_auth_failure() {
		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_dead',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'gmail' ),
				),
				'execute' => array(
					'successful' => false,
					'error'      => 'invalid_grant: Token has been expired or revoked.',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GMAIL_FETCH_EMAILS',
				'connected_account_id' => 'ca_dead',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_account_auth_required', $result->get_error_code() );
		$this->assertStringContainsString( 'revoked or expired', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertTrue( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'composio_connect_link', $data['reconnect_url'] );

		// The verdict is remembered, so auto-resolution stops picking this one.
		$record = WP_MCP_AI_Composio_Account_Health::get( $this->connection['id'], 'ca_dead' );
		$this->assertTrue( $record['needs_reconnect'] );
	}

	/**
	 * Test the failure mode observed in production: Composio answers
	 * `successful: true` because it delivered the call, and Google's 401 arrives
	 * proxied inside `data.message`. That was reported to the assistant as
	 * "Composio tool X executed", so the agentic loop could not tell the call
	 * had failed and retried for minutes.
	 */
	public function test_execute_tool_reports_reconnect_on_proxied_provider_401() {
		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_proxied',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'googlecalendar' ),
				),
				'execute' => array(
					'successful' => true,
					'data'       => array(
						'message' => 'HTTP 401: Request had invalid authentication credentials. Expected OAuth 2 access token.',
					),
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GOOGLECALENDAR_EVENTS_LIST',
				'connected_account_id' => 'ca_proxied',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_account_auth_required', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 401, $data['provider_status'] );
		$this->assertTrue( $data['needs_reconnect'] );
		$this->assertStringContainsString( 'invalid authentication credentials', $data['upstream_error'] );

		// A failed execution must not be recorded as a verification.
		$record = WP_MCP_AI_Composio_Account_Health::get( $this->connection['id'], 'ca_proxied' );
		$this->assertTrue( $record['needs_reconnect'] );
		$this->assertFalse( $record['verified'] );
	}

	/**
	 * Test that an account Composio does not know fails fast and names the live
	 * accounts, instead of sending a stale `ca_...` upstream and returning an
	 * opaque failure. Replaying a remembered account ID is the common cause.
	 */
	public function test_execute_tool_rejects_unknown_account_with_live_list() {
		$executed = false;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$executed ) {
				$status = 200;
				$body   = array();

				if ( false !== strpos( $url, '/tools/execute/' ) ) {
					$executed = true;
					$body     = array( 'successful' => true );
				} elseif ( preg_match( '#/connected_accounts/[^/?]+#', $url ) ) {
					$status = 404;
					$body   = array( 'error' => array( 'message' => 'Connected account not found.' ) );
				} elseif ( false !== strpos( $url, '/connected_accounts' ) ) {
					$body = array(
						'items' => array(
							array(
								'id'      => 'ca_live_1',
								'status'  => 'ACTIVE',
								'toolkit' => array( 'slug' => 'googlecalendar' ),
							),
						),
					);
				}

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Not Found',
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
				'tool_slug'            => 'GOOGLECALENDAR_EVENTS_LIST',
				'connected_account_id' => 'ca_F0HEJBssnCXL',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_unknown_account', $result->get_error_code() );
		$this->assertStringContainsString( 'ca_live_1', $result->get_error_message() );
		$this->assertContains( 'ca_live_1', $result->get_error_data()['available_accounts'] );
		$this->assertFalse( $executed, 'A dead account ID must not reach the execute endpoint.' );
	}

	/**
	 * Test that auto-resolution refuses to guess between indistinguishable
	 * accounts for a write-class action.
	 */
	public function test_execute_tool_refuses_ambiguous_destructive_resolution() {
		$this->mock_routes(
			array(
				'accounts' => array(
					array(
						'id'      => 'ca_one',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'wp-4',
					),
					array(
						'id'      => 'ca_two',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'wp-5',
					),
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_SEND_EMAIL' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_ambiguous_account', $result->get_error_code() );
		$this->assertCount( 2, $result->get_error_data()['candidates'] );
	}

	/**
	 * Test that a recently probe-verified account wins auto-resolution over an
	 * equally "ACTIVE" but unverified sibling.
	 */
	public function test_execute_tool_prefers_verified_account() {
		WP_MCP_AI_Composio_Account_Health::record(
			$this->connection['id'],
			'ca_good',
			array(
				'account_id'   => 'ca_good',
				'verified'     => true,
				'validated_at' => time(),
				'checked_at'   => time(),
			)
		);

		$this->mock_routes(
			array(
				'accounts' => array(
					array(
						'id'      => 'ca_unknown',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'wp-4',
					),
					array(
						'id'      => 'ca_good',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'wp-4',
					),
				),
				'execute'  => array( 'successful' => true ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_FETCH_EMAILS' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'ca_good', $result['account_id'] );
	}

	/**
	 * Test that a known-dead account is excluded from auto-resolution and the
	 * resulting error explains how to fix it.
	 */
	public function test_execute_tool_excludes_known_dead_account() {
		WP_MCP_AI_Composio_Account_Health::record(
			$this->connection['id'],
			'ca_dead',
			array(
				'account_id'      => 'ca_dead',
				'toolkit'         => 'gmail',
				'verified'        => false,
				'needs_reconnect' => true,
				'checked_at'      => time(),
			)
		);

		$this->mock_routes(
			array(
				'accounts' => array(
					array(
						'id'      => 'ca_dead',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'nvoos-shared',
					),
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute( array( 'tool_slug' => 'GMAIL_FETCH_EMAILS' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_account_auth_required', $result->get_error_code() );
		$this->assertContains( 'ca_dead', $result->get_error_data()['dead_accounts'] );
	}

	/**
	 * Test that a successful execution records a verification verdict, so health
	 * data improves with normal use rather than only when probed.
	 */
	public function test_successful_execution_records_verification() {
		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_working',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'gmail' ),
					'user_id' => 'nvoos-shared',
				),
				'execute' => array( 'successful' => true ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GMAIL_FETCH_EMAILS',
				'connected_account_id' => 'ca_working',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );

		$record = WP_MCP_AI_Composio_Account_Health::get( $this->connection['id'], 'ca_working' );
		$this->assertTrue( $record['verified'] );
		$this->assertSame( 'execution', $record['verification_method'] );
	}

	/**
	 * Test that manage_accounts rejects unknown actions and a prune without an
	 * explicit toolkit (so the blast radius is always stated).
	 */
	public function test_manage_accounts_guards_inputs() {
		$tool = new WP_MCP_AI_Tool_Composio_Manage_Accounts();

		$this->assertSame( 'invalid_action', $tool->execute( array( 'action' => 'nuke' ) )->get_error_code() );
		$this->assertSame( 'missing_params', $tool->execute( array( 'action' => 'prune' ) )->get_error_code() );
		$this->assertSame( 'missing_params', $tool->execute( array( 'action' => 'delete' ) )->get_error_code() );
		$this->assertSame( 'missing_params', $tool->execute( array( 'action' => 'validate' ) )->get_error_code() );
	}

	/**
	 * Test that validate reports a real verdict with a reconnect URL.
	 */
	public function test_manage_accounts_validate_reports_verdict() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_check',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'gmail' ),
				),
				'execute' => array(
					'successful' => false,
					'error'      => 'Auth refresh required',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Manage_Accounts();
		$result = $tool->execute(
			array(
				'action'               => 'validate',
				'connected_account_id' => 'ca_check',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['failing'] );
		$this->assertSame( 0, $result['verified'] );
		$this->assertFalse( $result['accounts'][0]['health']['verified'] );
		$this->assertNotEmpty( $result['accounts'][0]['reconnect_url'] );
		$this->assertStringContainsString( 'did NOT verify', $result['message'] );
	}

	/**
	 * Test that reconnect re-authorises the same account in place instead of
	 * minting a duplicate.
	 */
	public function test_manage_accounts_reconnect_is_in_place() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/refresh' ) ) {
					$body = array(
						'id'           => 'ca_existing',
						'status'       => 'INITIATED',
						'redirect_url' => 'https://backend.composio.dev/link/abc',
					);
				} else {
					$body = array(
						'id'      => 'ca_existing',
						'status'  => 'EXPIRED',
						'toolkit' => array( 'slug' => 'gmail' ),
					);
				}

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
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Manage_Accounts();
		$result = $tool->execute(
			array(
				'action'               => 'reconnect',
				'connected_account_id' => 'ca_existing',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['in_place'] );
		$this->assertFalse( $result['creates_new'] );
		$this->assertSame( 'ca_existing', $result['account_id'] );
		$this->assertTrue( $result['destructive'] );
	}

	/**
	 * Test that prune deletes only accounts that fail a fresh credential check.
	 */
	public function test_manage_accounts_prune_keeps_healthy_accounts() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		$deleted = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$deleted ) {
				if ( 'DELETE' === $args['method'] ) {
					preg_match( '#/connected_accounts/([^/?]+)#', $url, $m );
					$deleted[] = isset( $m[1] ) ? $m[1] : '';
					$body      = array( 'success' => true );
				} elseif ( false !== strpos( $url, '/tools/execute/' ) ) {
					$payload = json_decode( $args['body'], true );
					$body    = 'ca_dead' === $payload['connected_account_id']
						? array(
							'successful' => false,
							'error'      => 'invalid_grant',
						)
						: array( 'successful' => true );
				} else {
					$body = array(
						'items' => array(
							array(
								'id'      => 'ca_dead',
								'status'  => 'ACTIVE',
								'toolkit' => array( 'slug' => 'gmail' ),
							),
							array(
								'id'      => 'ca_alive',
								'status'  => 'ACTIVE',
								'toolkit' => array( 'slug' => 'gmail' ),
							),
						),
					);
				}

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
			},
			10,
			3
		);

		$tool   = new WP_MCP_AI_Tool_Composio_Manage_Accounts();
		$result = $tool->execute(
			array(
				'action'  => 'prune',
				'toolkit' => 'gmail',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'ca_dead' ), $result['deleted'] );
		$this->assertSame( array( 'ca_alive' ), $result['kept'] );
		$this->assertSame( array( 'ca_dead' ), $deleted );
	}

	/**
	 * Test that a connected-account nanoid passed as connection_id is named as
	 * the wrong ID kind instead of dead-ending on "connection not found".
	 */
	public function test_account_id_passed_as_connection_id_is_diagnosed() {
		$tool   = new WP_MCP_AI_Tool_Composio_Get_Tool_Schema();
		$result = $tool->execute(
			array(
				'tool_slug'     => 'GMAIL_LIST_MESSAGES',
				'connection_id' => 'ca_F0HEJBssnCXL',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_id_swapped', $result->get_error_code() );
		$this->assertStringContainsString( 'connected-account ID', $result->get_error_message() );
		$this->assertStringContainsString( 'omit connection_id', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 'ca_F0HEJBssnCXL', $data['supplied'] );
		$this->assertSame( 'connection_id', $data['expected_kind'] );
		$this->assertContains( $this->connection['id'], $data['available'] );
	}

	/**
	 * Test that the swap is caught on every tool that takes a connection_id,
	 * because the shared resolver owns the check.
	 */
	public function test_swapped_connection_id_is_caught_across_tools() {
		$cases = array(
			array( new WP_MCP_AI_Tool_Composio_List_Tools(), array() ),
			array( new WP_MCP_AI_Tool_Composio_List_Connected_Accounts(), array() ),
			array( new WP_MCP_AI_Tool_Composio_Create_Connect_Link(), array( 'toolkit' => 'gmail' ) ),
			array( new WP_MCP_AI_Tool_Composio_Execute_Tool(), array( 'tool_slug' => 'GMAIL_LIST_MESSAGES' ) ),
			array( new WP_MCP_AI_Tool_Composio_Manage_Triggers(), array( 'action' => 'list_active' ) ),
			array(
				new WP_MCP_AI_Tool_Composio_Manage_Accounts(),
				array(
					'action'  => 'validate',
					'toolkit' => 'gmail',
				),
			),
		);

		foreach ( $cases as $case ) {
			list( $tool, $args ) = $case;

			$args['connection_id'] = 'ca_F0HEJBssnCXL';
			$result                = $tool->execute( $args );

			$this->assertWPError( $result, $tool->get_slug() . ' should reject a ca_ connection_id' );
			$this->assertSame(
				'wp_mcp_ai_composio_id_swapped',
				$result->get_error_code(),
				$tool->get_slug() . ' should report the swapped ID kind'
			);
		}
	}

	/**
	 * Test the reverse swap: a connection ID passed as connected_account_id.
	 */
	public function test_connection_id_passed_as_account_id_is_diagnosed() {
		$tool   = new WP_MCP_AI_Tool_Composio_Execute_Tool();
		$result = $tool->execute(
			array(
				'tool_slug'            => 'GMAIL_LIST_MESSAGES',
				'connected_account_id' => $this->connection['id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_id_swapped', $result->get_error_code() );
		$this->assertStringContainsString( 'pass it as connection_id', strtolower( $result->get_error_message() ) );
		$this->assertSame( 'connected_account_id', $result->get_error_data()['expected_kind'] );
	}

	/**
	 * Test that an unknown-but-plausible connection ID lists the real ones, so a
	 * caller can retry without guessing again.
	 */
	public function test_unknown_connection_id_lists_available_ids() {
		$tool   = new WP_MCP_AI_Tool_Composio_List_Tools();
		$result = $tool->execute( array( 'connection_id' => 'conn_doesnotexist' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_invalid_connection', $result->get_error_code() );
		$this->assertStringContainsString( $this->connection['id'], $result->get_error_message() );
		$this->assertContains( $this->connection['id'], $result->get_error_data()['available'] );
	}

	/**
	 * Test the prefix helpers, including that detection is case-insensitive so a
	 * mixed-case nanoid is still recognised.
	 */
	public function test_id_kind_detection() {
		$this->assertTrue( WP_MCP_AI_Composio_Tools::looks_like_account_id( 'ca_F0HEJBssnCXL' ) );
		$this->assertTrue( WP_MCP_AI_Composio_Tools::looks_like_account_id( 'CA_F0HEJBssnCXL' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Tools::looks_like_account_id( 'conn_abc123' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Tools::looks_like_account_id( '' ) );

		$this->assertTrue( WP_MCP_AI_Composio_Tools::looks_like_connection_id( 'conn_abc123' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Tools::looks_like_connection_id( 'ca_F0HEJBssnCXL' ) );

		// A real account ID must pass validation untouched.
		$this->assertTrue( WP_MCP_AI_Composio_Tools::validate_account_id( 'ca_F0HEJBssnCXL' ) );
		$this->assertTrue( WP_MCP_AI_Composio_Tools::validate_account_id( '' ) );
	}
}
