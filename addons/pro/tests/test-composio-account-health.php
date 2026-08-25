<?php
/**
 * Tests for the Composio connected-account health ledger and probe engine.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-account-health.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';

/**
 * Test the account-health engine.
 */
class Test_Composio_Account_Health extends WP_UnitTestCase {

	/**
	 * Tool slugs executed during the test.
	 *
	 * @var array
	 */
	private $executed = array();

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_composio_probe_tool' );
		$this->executed = array();

		parent::tearDown();
	}

	/**
	 * Register a URL-aware transport mock.
	 *
	 * @param array $routes Canned payloads keyed by route family: `account`
	 *                      (GET /connected_accounts/{id}), `catalog` (GET /tools),
	 *                      `schema` (GET /tools/{SLUG}), `execute`
	 *                      (POST /tools/execute/{SLUG}).
	 * @return void
	 */
	private function mock_routes( array $routes ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $routes ) {
				$body = array();

				if ( false !== strpos( $url, '/tools/execute/' ) ) {
					preg_match( '#/tools/execute/([A-Z0-9_]+)#', $url, $m );
					$this->executed[] = isset( $m[1] ) ? $m[1] : '';
					$body             = isset( $routes['execute'] ) ? $routes['execute'] : array( 'successful' => true );
				} elseif ( preg_match( '#/tools/[A-Z][A-Z0-9_]*#', $url ) ) {
					$body = isset( $routes['schema'] ) ? $routes['schema'] : array();
				} elseif ( false !== strpos( $url, '/tools' ) ) {
					$body = isset( $routes['catalog'] ) ? $routes['catalog'] : array( 'items' => array() );
				} elseif ( false !== strpos( $url, '/connected_accounts' ) ) {
					$body = isset( $routes['account'] ) ? $routes['account'] : array();
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
	 * Test that the ledger round-trips a record and forgets it on request.
	 */
	public function test_ledger_records_and_forgets() {
		$this->assertSame( array(), WP_MCP_AI_Composio_Account_Health::get( 'conn_led', 'ca_1' ) );

		WP_MCP_AI_Composio_Account_Health::record(
			'conn_led',
			'ca_1',
			array(
				'verified'   => true,
				'account_id' => 'ca_1',
			)
		);

		$record = WP_MCP_AI_Composio_Account_Health::get( 'conn_led', 'ca_1' );

		$this->assertTrue( $record['verified'] );
		$this->assertNotEmpty( $record['checked_at'] );

		WP_MCP_AI_Composio_Account_Health::forget( 'conn_led', 'ca_1' );

		$this->assertSame( array(), WP_MCP_AI_Composio_Account_Health::get( 'conn_led', 'ca_1' ) );
	}

	/**
	 * Test that a verdict older than the staleness window is flagged stale.
	 */
	public function test_staleness_window() {
		$this->assertTrue( WP_MCP_AI_Composio_Account_Health::is_stale( array() ) );
		$this->assertFalse( WP_MCP_AI_Composio_Account_Health::is_stale( array( 'checked_at' => time() ) ) );
		$this->assertTrue(
			WP_MCP_AI_Composio_Account_Health::is_stale(
				array( 'checked_at' => time() - ( WP_MCP_AI_Composio_Account_Health::STALE_AFTER + 60 ) )
			)
		);
	}

	/**
	 * Test that an expired account is reported as needing reconnection without
	 * spending a probe execution.
	 */
	public function test_probe_short_circuits_on_expired_status() {
		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_expired',
					'status'  => 'EXPIRED',
					'toolkit' => array( 'slug' => 'gmail' ),
					'state'   => array(
						'val' => array(
							'expired_at'        => '2026-08-01T00:00:00Z',
							'error_description' => 'Token has been expired or revoked.',
						),
					),
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_exp' );
		$record = WP_MCP_AI_Composio_Account_Health::probe( $client, 'conn_exp', 'ca_expired' );

		$this->assertNotWPError( $record );
		$this->assertFalse( $record['verified'] );
		$this->assertTrue( $record['needs_reconnect'] );
		$this->assertSame( 'EXPIRED', $record['status'] );
		$this->assertSame( '2026-08-01T00:00:00Z', $record['expires_at'] );
		$this->assertSame( 'status_only', $record['verification_method'] );
		// No tool was executed — a known-bad account must not burn quota.
		$this->assertSame( array(), $this->executed );
	}

	/**
	 * Test that a live probe success marks the account verified — the case a
	 * stored-status check cannot distinguish from a revoked token.
	 */
	public function test_probe_verifies_active_account_via_live_call() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_live',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'gmail' ),
					'user_id' => 'nvoos-shared',
				),
				'execute' => array(
					'successful' => true,
					'data'       => array( 'emailAddress' => 'me@example.com' ),
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_live' );
		$record = WP_MCP_AI_Composio_Account_Health::probe( $client, 'conn_live', 'ca_live' );

		$this->assertNotWPError( $record );
		$this->assertTrue( $record['verified'] );
		$this->assertSame( 'probe', $record['verification_method'] );
		$this->assertSame( 'GMAIL_GET_PROFILE', $record['probe_tool'] );
		$this->assertNotEmpty( $record['validated_at'] );
		$this->assertSame( array( 'GMAIL_GET_PROFILE' ), $this->executed );
	}

	/**
	 * Test the headline regression: Composio reports ACTIVE but the provider
	 * has revoked the token. The probe must report it as broken.
	 */
	public function test_probe_detects_revoked_token_behind_active_status() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_zombie',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'gmail' ),
				),
				'execute' => array(
					'successful' => false,
					'error'      => 'Auth refresh required: invalid_grant',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_zombie' );
		$record = WP_MCP_AI_Composio_Account_Health::probe( $client, 'conn_zombie', 'ca_zombie' );

		$this->assertNotWPError( $record );
		$this->assertSame( 'ACTIVE', $record['status'] );
		$this->assertFalse( $record['verified'] );
		$this->assertTrue( $record['needs_reconnect'] );
		$this->assertSame( 'probe', $record['verification_method'] );
		$this->assertStringContainsString( 'invalid_grant', $record['last_error'] );
	}

	/**
	 * Test that a non-auth probe failure is reported as inconclusive rather
	 * than as a dead credential.
	 */
	public function test_probe_marks_non_auth_failure_inconclusive() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_flaky',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'gmail' ),
				),
				'execute' => array(
					'successful' => false,
					'error'      => 'Upstream provider returned 503 Service Unavailable',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_flaky' );
		$record = WP_MCP_AI_Composio_Account_Health::probe( $client, 'conn_flaky', 'ca_flaky' );

		$this->assertNotWPError( $record );
		$this->assertFalse( $record['verified'] );
		$this->assertFalse( $record['needs_reconnect'] );
		$this->assertSame( 'probe_inconclusive', $record['verification_method'] );
	}

	/**
	 * Test that a toolkit with no safe probe degrades honestly to status_only
	 * instead of claiming the credential is verified.
	 */
	public function test_probe_degrades_when_no_safe_tool_exists() {
		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_odd',
					'status'  => 'ACTIVE',
					'toolkit' => array( 'slug' => 'obscureapp' ),
				),
				// Only write-class and argument-hungry tools exist.
				'catalog' => array(
					'items' => array(
						array(
							'slug'             => 'OBSCUREAPP_CREATE_THING',
							'toolkit'          => array( 'slug' => 'obscureapp' ),
							'input_parameters' => array( 'required' => array() ),
						),
						array(
							'slug'             => 'OBSCUREAPP_GET_THING',
							'toolkit'          => array( 'slug' => 'obscureapp' ),
							'input_parameters' => array( 'required' => array( 'thing_id' ) ),
						),
					),
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_odd' );
		$record = WP_MCP_AI_Composio_Account_Health::probe( $client, 'conn_odd', 'ca_odd' );

		$this->assertNotWPError( $record );
		$this->assertFalse( $record['verified'] );
		$this->assertSame( 'status_only', $record['verification_method'] );
		$this->assertSame( 'wp_mcp_ai_composio_probe_unavailable', $record['last_error_code'] );
		$this->assertSame( array(), $this->executed );
	}

	/**
	 * Test that discovery picks a zero-argument read-verb tool from the catalog.
	 */
	public function test_resolve_probe_tool_discovers_safe_candidate() {
		$this->mock_routes(
			array(
				'catalog' => array(
					'items' => array(
						array(
							'slug'             => 'SLACK_SEND_MESSAGE',
							'toolkit'          => array( 'slug' => 'slack' ),
							'input_parameters' => array( 'required' => array() ),
						),
						array(
							'slug'             => 'SLACK_LIST_ALL_USERS',
							'toolkit'          => array( 'slug' => 'slack' ),
							'input_parameters' => array( 'required' => array() ),
						),
					),
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_discover' );

		$this->assertSame(
			'SLACK_LIST_ALL_USERS',
			WP_MCP_AI_Composio_Account_Health::resolve_probe_tool( $client, 'conn_discover', 'slack' )
		);
	}

	/**
	 * Test that a pinned probe tool from another toolkit is rejected, so the
	 * filter cannot be used to execute an arbitrary action.
	 */
	public function test_pinned_probe_tool_must_belong_to_toolkit() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GITHUB_DELETE_A_REPOSITORY';
			}
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_pin' );

		$this->assertSame(
			'',
			WP_MCP_AI_Composio_Account_Health::resolve_probe_tool( $client, 'conn_pin', 'gmail' )
		);
	}

	/**
	 * Test the auth-error classifier against real Composio phrasing.
	 */
	public function test_auth_error_classification() {
		$auth = array(
			array( '', 'Auth refresh required. The OAuth token has expired.' ),
			array( '', 'invalid_grant' ),
			array( '', 'Token has been expired or revoked.' ),
			array( 'wp_mcp_ai_composio_http_401', 'HTTP 401: nope' ),
			array( 'wp_mcp_ai_composio_http_403', 'HTTP 403: nope' ),
			array( '', 'Request had insufficient authentication scopes.' ),
			// Google's phrasing, proxied by Composio into the tool result. The
			// status is not on the transport, so only the text can classify it.
			array( '', 'HTTP 401: Request had invalid authentication credentials.' ),
			array( 'wp_mcp_ai_composio_tool_failed', 'Composio tool GMAIL_FETCH_EMAILS failed: HTTP 403: forbidden' ),
		);

		foreach ( $auth as $case ) {
			$this->assertTrue(
				WP_MCP_AI_Composio_Account_Health::is_auth_error( $case[0], $case[1] ),
				'Expected auth classification for: ' . $case[1]
			);
		}

		$not_auth = array(
			array( 'wp_mcp_ai_composio_http_500', 'HTTP 500: upstream exploded' ),
			array( '', 'Message not found' ),
			array( '', 'Rate limit reached. Retry in 60 seconds.' ),
			array( '', 'HTTP 404: calendar not found' ),
		);

		foreach ( $not_auth as $case ) {
			$this->assertFalse(
				WP_MCP_AI_Composio_Account_Health::is_auth_error( $case[0], $case[1] ),
				'Did not expect auth classification for: ' . $case[1]
			);
		}
	}

	/**
	 * Test that the presenter reports "never checked" rather than implying a
	 * healthy account when no verdict exists.
	 */
	public function test_present_never_checked_is_not_verified() {
		$presented = WP_MCP_AI_Composio_Account_Health::present( array() );

		$this->assertFalse( $presented['verified'] );
		$this->assertSame( 'never_checked', $presented['verification_method'] );
		$this->assertTrue( $presented['stale'] );
	}

	/**
	 * Test that a reconnect hint names the app and carries a usable URL.
	 */
	public function test_reconnect_hint_is_actionable() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$hint = WP_MCP_AI_Composio_Account_Health::build_reconnect_hint( 'conn_hint', 'gmail', 'ca_dead', 'invalid_grant' );

		$this->assertStringContainsString( 'gmail', $hint['message'] );
		$this->assertStringContainsString( 'ca_dead', $hint['message'] );
		$this->assertStringContainsString( 'invalid_grant', $hint['message'] );
		$this->assertStringContainsString( 'oauth_handler=composio_connect_link', $hint['reconnect_url'] );
		$this->assertStringContainsString( 'toolkit=gmail', $hint['reconnect_url'] );
	}

	/**
	 * Test that an expired-account webhook writes a needs_reconnect verdict, so
	 * auto-resolution stops choosing the account immediately.
	 */
	public function test_webhook_expiry_writes_health_verdict() {
		WP_MCP_AI_Composio_Auth_Handler::mark_account_expired( 'conn_hook', 'ca_hook', 'gmail' );

		$record = WP_MCP_AI_Composio_Account_Health::get( 'conn_hook', 'ca_hook' );

		$this->assertTrue( $record['needs_reconnect'] );
		$this->assertSame( 'webhook', $record['verification_method'] );
		$this->assertSame( 'gmail', $record['toolkit'] );
		$this->assertTrue( WP_MCP_AI_Composio_Auth_Handler::is_account_expired( 'conn_hook', 'ca_hook' ) );
	}
}
