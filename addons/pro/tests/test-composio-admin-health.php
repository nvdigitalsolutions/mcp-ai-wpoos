<?php
/**
 * Tests for the Composio Connected Apps health column and account actions.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-account-health.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';

/**
 * Test the Composio admin health surface.
 */
class Test_Composio_Admin_Health extends WP_UnitTestCase {

	/**
	 * Connection under test.
	 *
	 * @var array
	 */
	private $connection = array();

	/**
	 * Last captured redirect target.
	 *
	 * @var string
	 */
	private $redirect_target = '';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		$id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Composio Admin Test',
				'url'             => 'https://backend.composio.dev',
				'connection_type' => 'composio',
				'auth_type'       => 'none',
				'api_key'         => 'ak_test_admin',
				'enabled'         => true,
			)
		);

		$this->assertNotWPError( $id );
		$this->connection      = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->redirect_target = '';

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
		remove_all_filters( 'wp_redirect' );
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		unset( $_GET['page'], $_GET['edit'] );

		parent::tearDown();
	}

	/**
	 * Capture and block redirects so handlers abort via WPDieException.
	 *
	 * @param string $location Redirect target.
	 * @return false
	 */
	public function capture_redirect( $location ) {
		$this->redirect_target = $location;

		return false;
	}

	/**
	 * Invoke a protected method on the admin class.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke( $method, array $args = array() ) {
		$admin      = new WP_MCP_AI_Pro_Remote_Sites_Admin();
		$reflection = new ReflectionMethod( $admin, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $admin, $args );
	}

	/**
	 * Register a URL-aware transport mock for Composio's route families.
	 *
	 * @param array $routes Canned payloads keyed by route family: accounts,
	 *                      account, execute, refresh.
	 * @return void
	 */
	private function mock_routes( array $routes ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $routes ) {
				if ( false !== strpos( $url, '/refresh' ) ) {
					$body = isset( $routes['refresh'] ) ? $routes['refresh'] : array();
				} elseif ( false !== strpos( $url, '/tools/execute/' ) ) {
					$body = isset( $routes['execute'] ) ? $routes['execute'] : array( 'successful' => true );
				} elseif ( false !== strpos( $url, '/tools' ) ) {
					$body = array( 'items' => array() );
				} elseif ( preg_match( '#/connected_accounts/[^/?]+#', $url ) ) {
					$body = isset( $routes['account'] ) ? $routes['account'] : array();
				} else {
					$body = array( 'items' => isset( $routes['accounts'] ) ? $routes['accounts'] : array() );
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
	 * Test that an unchecked credential is reported as unchecked, never as
	 * healthy — the whole point of separating Health from Status.
	 */
	public function test_health_display_reports_never_checked() {
		$display = $this->invoke( 'get_composio_health_display', array( $this->connection['id'], 'ca_new' ) );

		$this->assertSame( 'Not checked', $display['label'] );
		$this->assertFalse( $display['needs_reconnect'] );
		$this->assertStringContainsString( 'never been tested', $display['detail'] );
	}

	/**
	 * Test the verified display, including how the credential was proven.
	 */
	public function test_health_display_reports_verified() {
		WP_MCP_AI_Composio_Account_Health::record(
			$this->connection['id'],
			'ca_ok',
			array(
				'verified'     => true,
				'probe_tool'   => 'GMAIL_GET_PROFILE',
				'validated_at' => time(),
				'checked_at'   => time(),
			)
		);

		$display = $this->invoke( 'get_composio_health_display', array( $this->connection['id'], 'ca_ok' ) );

		$this->assertSame( 'Verified', $display['label'] );
		$this->assertSame( '#00a32a', $display['color'] );
		$this->assertStringContainsString( 'GMAIL_GET_PROFILE', $display['detail'] );
		$this->assertFalse( $display['needs_reconnect'] );
	}

	/**
	 * Test that a dead credential is flagged red with the provider's reason.
	 */
	public function test_health_display_reports_needs_reconnect() {
		WP_MCP_AI_Composio_Account_Health::record(
			$this->connection['id'],
			'ca_dead',
			array(
				'verified'        => false,
				'needs_reconnect' => true,
				'last_error'      => 'invalid_grant: token revoked',
				'checked_at'      => time(),
			)
		);

		$display = $this->invoke( 'get_composio_health_display', array( $this->connection['id'], 'ca_dead' ) );

		$this->assertSame( 'Needs reconnect', $display['label'] );
		$this->assertSame( '#d63638', $display['color'] );
		$this->assertTrue( $display['needs_reconnect'] );
		$this->assertStringContainsString( 'invalid_grant', $display['detail'] );
	}

	/**
	 * Test that an unprobeable credential is "Unconfirmed", not "Verified" and
	 * not "Needs reconnect" — it is genuinely unknown.
	 */
	public function test_health_display_reports_unconfirmed() {
		WP_MCP_AI_Composio_Account_Health::record(
			$this->connection['id'],
			'ca_unknown',
			array(
				'verified'            => false,
				'needs_reconnect'     => false,
				'verification_method' => 'status_only',
				'last_error'          => 'No zero-argument read-only tool is available for this toolkit.',
				'checked_at'          => time(),
			)
		);

		$display = $this->invoke( 'get_composio_health_display', array( $this->connection['id'], 'ca_unknown' ) );

		$this->assertSame( 'Unconfirmed', $display['label'] );
		$this->assertSame( '#dba617', $display['color'] );
		$this->assertFalse( $display['needs_reconnect'] );
	}

	/**
	 * Test that the Connected Apps table renders the Health column, the
	 * per-row Verify/Reconnect actions and the stored-vs-verified explanation.
	 */
	public function test_connected_apps_table_renders_health_column() {
		$this->mock_routes(
			array(
				'accounts' => array(
					array(
						'id'      => 'ca_render',
						'alias'   => 'me@example.com',
						'status'  => 'ACTIVE',
						'toolkit' => array( 'slug' => 'gmail' ),
						'user_id' => 'nvoos-shared',
					),
				),
			)
		);

		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['edit'] = $this->connection['id'];

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( '>Health<', $output );
		$this->assertStringContainsString( 'ca_render', $output );
		$this->assertStringContainsString( 'Not checked', $output );
		$this->assertStringContainsString( 'composio_verify', $output );
		$this->assertStringContainsString( 'composio_reconnect', $output );
		$this->assertStringContainsString( 'Verify all', $output );
		// The distinction between stored status and verified health must be
		// stated on the page, not left for the operator to infer.
		$this->assertStringContainsString( 'lags a revoked token', $output );
	}

	/**
	 * Test that rendering the table performs no live probe. A probe costs one
	 * tool execution per account, so it must never fire on page load.
	 */
	public function test_rendering_does_not_probe() {
		$executions = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$executions ) {
				if ( false !== strpos( $url, '/tools/execute/' ) ) {
					++$executions;
				}

				$body = false !== strpos( $url, '/connected_accounts' )
					? array(
						'items' => array(
							array(
								'id'      => 'ca_render',
								'status'  => 'ACTIVE',
								'toolkit' => array( 'slug' => 'gmail' ),
							),
						),
					)
					: array( 'items' => array() );

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

		$_GET['page'] = 'wp-mcp-ai-remote-sites';
		$_GET['edit'] = $this->connection['id'];

		$admin = new WP_MCP_AI_Pro_Remote_Sites_Admin();

		ob_start();
		$admin->render_admin_page();
		ob_get_clean();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 0, $executions, 'Rendering the Connected Apps table must not execute any Composio tool.' );
	}

	/**
	 * Test that the Verify action probes every account, stores the verdicts and
	 * reports the tally on the redirect.
	 */
	public function test_verify_all_records_verdicts_and_reports_counts() {
		add_filter(
			'wp_mcp_ai_composio_probe_tool',
			static function () {
				return 'GMAIL_GET_PROFILE';
			}
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/tools/execute/' ) ) {
					$payload = json_decode( $args['body'], true );
					$body    = 'ca_bad' === $payload['connected_account_id']
						? array(
							'successful' => false,
							'error'      => 'Auth refresh required: invalid_grant',
						)
						: array( 'successful' => true );
				} elseif ( false !== strpos( $url, '/tools' ) ) {
					$body = array( 'items' => array() );
				} else {
					$body = array(
						'items' => array(
							array(
								'id'      => 'ca_good',
								'status'  => 'ACTIVE',
								'toolkit' => array( 'slug' => 'gmail' ),
							),
							array(
								'id'      => 'ca_bad',
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

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		try {
			$this->invoke( 'handle_composio_verify', array( $this->connection['id'], '' ) );
		} catch ( WPDieException $e ) {
			// Expected: the blocked redirect aborts the handler.
			unset( $e );
		}

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( 'composio_verified=1', $this->redirect_target );
		$this->assertStringContainsString( 'composio_ok=1', $this->redirect_target );
		$this->assertStringContainsString( 'composio_bad=1', $this->redirect_target );

		$good = WP_MCP_AI_Composio_Account_Health::get( $this->connection['id'], 'ca_good' );
		$bad  = WP_MCP_AI_Composio_Account_Health::get( $this->connection['id'], 'ca_bad' );

		$this->assertTrue( $good['verified'] );
		$this->assertTrue( $bad['needs_reconnect'] );
	}

	/**
	 * Test that Reconnect sends the operator to Composio's per-account
	 * re-authorisation URL, so no duplicate account is created.
	 */
	public function test_reconnect_redirects_to_in_place_url() {
		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_existing',
					'status'  => 'EXPIRED',
					'toolkit' => array( 'slug' => 'gmail' ),
				),
				'refresh' => array(
					'id'           => 'ca_existing',
					'status'       => 'INITIATED',
					'redirect_url' => 'https://backend.composio.dev/link/abc123',
				),
			)
		);

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		try {
			$this->invoke( 'handle_composio_reconnect', array( $this->connection['id'], 'ca_existing' ) );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'https://backend.composio.dev/link/abc123', $this->redirect_target );
	}

	/**
	 * Test that Reconnect drops the stale verdict, so a credential that is
	 * about to change is not trusted from the old probe.
	 */
	public function test_reconnect_clears_stale_verdict() {
		WP_MCP_AI_Composio_Account_Health::record(
			$this->connection['id'],
			'ca_existing',
			array(
				'verified'        => false,
				'needs_reconnect' => true,
				'checked_at'      => time(),
			)
		);

		$this->mock_routes(
			array(
				'account' => array(
					'id'      => 'ca_existing',
					'status'  => 'EXPIRED',
					'toolkit' => array( 'slug' => 'gmail' ),
				),
				'refresh' => array(
					'id'           => 'ca_existing',
					'redirect_url' => 'https://backend.composio.dev/link/abc123',
				),
			)
		);

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		try {
			$this->invoke( 'handle_composio_reconnect', array( $this->connection['id'], 'ca_existing' ) );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		remove_all_filters( 'pre_http_request' );

		$this->assertSame(
			array(),
			WP_MCP_AI_Composio_Account_Health::get( $this->connection['id'], 'ca_existing' )
		);
	}

	/**
	 * Test that Reconnect fails with a clear message when Composio returns no
	 * re-authorisation URL and the toolkit is unknown.
	 */
	public function test_reconnect_reports_failure_without_url() {
		$this->mock_routes(
			array(
				'account' => array( 'id' => 'ca_orphan' ),
				'refresh' => array(),
			)
		);

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		try {
			$this->invoke( 'handle_composio_reconnect', array( $this->connection['id'], 'ca_orphan' ) );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( 'composio_reconnected=0', $this->redirect_target );
		$this->assertStringContainsString( 'composio_error=', $this->redirect_target );
	}

	/**
	 * Test that account actions on a non-Composio connection are rejected.
	 */
	public function test_actions_reject_non_composio_connection() {
		$other = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Plain WP Site',
				'url'             => 'https://example.com',
				'connection_type' => 'wordpress',
				'auth_type'       => 'none',
				'enabled'         => true,
			)
		);

		$this->assertNotWPError( $other );

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );

		try {
			$this->invoke( 'handle_composio_verify', array( $other, 'ca_x' ) );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$this->assertStringContainsString( 'composio_verified=0', $this->redirect_target );
		$this->assertStringContainsString( 'composio_error=', $this->redirect_target );
	}
}
