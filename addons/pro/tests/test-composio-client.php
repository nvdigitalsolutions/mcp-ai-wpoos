<?php
/**
 * Tests for the Composio REST API client.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-auth-handler.php';

/**
 * Test the Composio API client.
 */
class Test_Composio_Client extends WP_UnitTestCase {

	/**
	 * Last request URL captured by the pre_http_request mock.
	 *
	 * @var string
	 */
	private $last_url = '';

	/**
	 * Last request args captured by the pre_http_request mock.
	 *
	 * @var array
	 */
	private $last_args = array();

	/**
	 * Number of HTTP requests performed.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		$this->last_url      = '';
		$this->last_args     = array();
		$this->request_count = 0;

		parent::tearDown();
	}

	/**
	 * Register a mock that returns a canned response.
	 *
	 * @param int   $status HTTP status code.
	 * @param array $body   JSON body array.
	 * @param array $headers Extra headers.
	 * @return void
	 */
	private function mock_response( $status = 200, $body = array(), $headers = array() ) {
		$this->request_count = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $status, $body, $headers ) {
				$this->last_url  = $url;
				$this->last_args = $args;
				++$this->request_count;

				return array(
					'headers'  => array_merge( array( 'content-type' => 'application/json' ), $headers ),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
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
	 * Register a mock that simulates a transport-level failure.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 * @return void
	 */
	private function mock_transport_error( $code, $message ) {
		$this->request_count = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $code, $message ) {
				$this->last_url  = $url;
				$this->last_args = $args;
				++$this->request_count;

				return new WP_Error( $code, $message );
			},
			10,
			3
		);
	}

	/**
	 * Register a mock whose response is decided per request.
	 *
	 * @param callable $resolver Receives ( $args, $url ) and returns a raw
	 *                           wp_remote_request response array or WP_Error.
	 * @return void
	 */
	private function mock_dynamic_response( $resolver ) {
		$this->request_count = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $_url ) use ( $resolver ) {
				$this->last_url  = $_url;
				$this->last_args = $args;
				++$this->request_count;

				$response = call_user_func( $resolver, $args );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				return array_merge(
					array(
						'headers'  => array( 'content-type' => 'application/json' ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					),
					$response
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a canned HTTP response array for the dynamic mock.
	 *
	 * @param int   $status HTTP status code.
	 * @param array $body   JSON body array.
	 * @return array
	 */
	private function http_response( $status, $body ) {
		return array(
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Build a canned auth-config listing item.
	 *
	 * @param string $id       Auth config ID.
	 * @param string $type     Auth config type (default|custom).
	 * @param bool   $managed  Whether the config is Composio-managed.
	 * @param string $status   Config status.
	 * @return array
	 */
	private function auth_config_item( $id, $type = 'default', $managed = true, $status = 'ENABLED' ) {
		return array(
			'id'                  => $id,
			'type'                => $type,
			'is_composio_managed' => $managed,
			'status'              => $status,
			'auth_scheme'         => 'OAUTH2',
			'toolkit'             => array( 'slug' => 'gmail' ),
		);
	}

	/**
	 * Test that a missing API key fails fast without an HTTP request.
	 */
	public function test_missing_api_key_returns_error() {
		$client = new WP_MCP_AI_Composio_Client( '' );

		$result = $client->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_missing_api_key', $result->get_error_code() );
		$this->assertSame( 0, $this->request_count );
	}

	/**
	 * Test that requests carry the x-api-key header and build the pinned URL.
	 */
	public function test_request_sends_x_api_key_header() {
		$this->mock_response(
			200,
			array( 'enum' => 'GMAIL_SEND_EMAIL,SLACK_SEND_MESSAGE' )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test_123' );
		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['connected'] );
		$this->assertSame( 2, $result['tools_count'] );

		$this->assertStringContainsString( 'https://backend.composio.dev/api/v3.1/tools/enum', $this->last_url );
		$this->assertSame( 'ak_test_123', $this->last_args['headers']['x-api-key'] );
		$this->assertSame( 'GET', $this->last_args['method'] );
	}

	/**
	 * Test that HTTP errors are normalised into WP_Error with the upstream message.
	 */
	public function test_http_error_returns_wp_error() {
		$this->mock_response(
			401,
			array( 'message' => 'Invalid API key' )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_bad' );
		$result = $client->list_connected_accounts();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_http_401', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid API key', $result->get_error_message() );
	}

	/**
	 * Test that Composio's documented nested error body is surfaced.
	 */
	public function test_http_error_extracts_nested_error_body() {
		$this->mock_response(
			400,
			array(
				'error' => array(
					'message'       => 'No connected account found for this user and toolkit',
					'status'        => 400,
					'request_id'    => 'req_abc123',
					'suggested_fix' => 'Connect the user to the toolkit first',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->execute_tool( 'GMAIL_SEND_EMAIL', 'ca_1', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_http_400', $result->get_error_code() );
		$this->assertStringContainsString( 'No connected account found', $result->get_error_message() );
		$this->assertStringContainsString( 'Connect the user to the toolkit first', $result->get_error_message() );
	}

	/**
	 * Test that a bodyless 401 still gets an actionable dashboard hint.
	 */
	public function test_http_401_without_body_appends_dashboard_hint() {
		$this->mock_response( 401, array() );

		$client = new WP_MCP_AI_Composio_Client( 'ak_bad' );
		$result = $client->test_connection();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'HTTP 401', $result->get_error_message() );
		$this->assertStringContainsString( 'Settings → API Keys', $result->get_error_message() );
	}

	/**
	 * Test that transport failures carry a hosting-egress hint.
	 */
	public function test_transport_failure_appends_egress_hint() {
		$this->mock_transport_error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_request_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'cURL error 28', $result->get_error_message() );
		$this->assertStringContainsString( 'backend.composio.dev', $result->get_error_message() );
		$this->assertStringContainsString( 'outbound HTTPS', $result->get_error_message() );
	}

	/**
	 * Test that the API key is trimmed before being sent.
	 */
	public function test_api_key_is_trimmed() {
		$this->mock_response(
			200,
			array( 'enum' => 'GMAIL_SEND_EMAIL' )
		);

		$client = new WP_MCP_AI_Composio_Client( "  ak_trimmed_123\n" );
		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertSame( 'ak_trimmed_123', $this->last_args['headers']['x-api-key'] );
	}

	/**
	 * Test that a 429 response sets a cooldown and returns an error.
	 */
	public function test_rate_limit_sets_cooldown() {
		$this->mock_response(
			429,
			array( 'message' => 'Too many requests' ),
			array( 'retry-after' => '45' )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_test' );
		$result = $client->list_tools();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_rate_limited', $result->get_error_code() );

		// A second call must be blocked by the cooldown without another request.
		$before = $this->request_count;
		$again  = $client->list_tools();
		$this->assertWPError( $again );
		$this->assertSame( 'wp_mcp_ai_composio_rate_limited', $again->get_error_code() );
		$this->assertSame( $before, $this->request_count );
	}

	/**
	 * Test that GET responses are cached for the configured TTL.
	 */
	public function test_get_responses_are_cached() {
		$this->mock_response(
			200,
			array(
				array(
					'tool_slug' => 'GMAIL_SEND_EMAIL',
					'toolkit'   => 'gmail',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_cache' );
		$first  = $client->list_tools();
		$second = $client->list_tools();

		$this->assertNotWPError( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * Test webhook signature verification (hex + base64 encodings).
	 */
	public function test_verify_webhook_signature() {
		$body   = '{"event":"composio.trigger.message"}';
		$secret = 'whsec_test_secret';

		$hex = hash_hmac( 'sha256', $body, $secret, false );
		$b64 = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture for signature comparison.

		$this->assertTrue( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body, $hex, $secret ) );
		$this->assertTrue( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body, $b64, $secret ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body . 'x', $hex, $secret ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body, $hex, 'other_secret' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::verify_webhook_signature( '', $hex, $secret ) );
	}

	/**
	 * Test that tool slugs are validated.
	 */
	public function test_tool_slug_validation() {
		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );

		$result = $client->get_tool_schema( 'not-a-valid-slug' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_invalid_tool_slug', $result->get_error_code() );

		$result = $client->get_tool_schema( '' );
		$this->assertWPError( $result );

		$result = $client->execute_tool( 'GMAIL_SEND_EMAIL', '', array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_missing_account', $result->get_error_code() );
	}

	/**
	 * Test that POST requests encode the JSON body.
	 */
	public function test_post_request_encodes_body() {
		$this->mock_dynamic_response(
			function ( $args ) {
				if ( 'GET' === $args['method'] ) {
					return $this->http_response(
						200,
						array(
							'items' => array( $this->auth_config_item( 'ac_gmail_1' ) ),
						)
					);
				}

				return $this->http_response(
					200,
					array(
						'link_token'           => 'lt_1',
						'redirect_url'         => 'https://auth.composio.dev/xyz',
						'connected_account_id' => 'ca_1',
					)
				);
			}
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_link_body' );
		$result = $client->create_connect_link( 'gmail', 'wp-1', 'https://example.test/callback' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://auth.composio.dev/xyz', $result['redirect_url'] );
		$this->assertSame( 'lt_1', $result['link_token'] );

		$this->assertSame( 'POST', $this->last_args['method'] );
		$this->assertSame( 'application/json', $this->last_args['headers']['Content-Type'] );

		$sent = json_decode( $this->last_args['body'], true );
		$this->assertSame( 'ac_gmail_1', $sent['auth_config_id'] );
		$this->assertSame( 'wp-1', $sent['user_id'] );
		$this->assertSame( 'https://example.test/callback', $sent['callback_url'] );
		$this->assertArrayNotHasKey( 'toolkit', $sent );
		$this->assertArrayNotHasKey( 'redirect_url', $sent );
	}

	/**
	 * Test that a Composio-managed default auth config is preferred.
	 */
	public function test_create_connect_link_prefers_managed_default_config() {
		$this->mock_dynamic_response(
			function ( $args ) {
				if ( 'GET' === $args['method'] ) {
					return $this->http_response(
						200,
						array(
							'items' => array(
								$this->auth_config_item( 'ac_custom', 'custom', false ),
								$this->auth_config_item( 'ac_managed', 'default', true ),
								$this->auth_config_item( 'ac_other_managed', 'custom', true ),
							),
						)
					);
				}

				return $this->http_response( 200, array( 'redirect_url' => 'https://auth.composio.dev/flow' ) );
			}
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_link_pref' );
		$result = $client->create_connect_link( 'gmail', 'wp-1', 'https://example.test/callback' );

		$this->assertNotWPError( $result );

		$sent = json_decode( $this->last_args['body'], true );
		$this->assertSame( 'ac_managed', $sent['auth_config_id'] );
	}

	/**
	 * Test that a missing auth config produces a clear error.
	 */
	public function test_create_connect_link_fails_without_auth_config() {
		$this->mock_dynamic_response(
			function ( $args ) {
				if ( 'GET' === $args['method'] ) {
					return $this->http_response( 200, array( 'items' => array() ) );
				}

				return $this->http_response( 200, array( 'redirect_url' => 'https://auth.composio.dev/flow' ) );
			}
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_link_none' );
		$result = $client->create_connect_link( 'gmail', 'wp-1', 'https://example.test/callback' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_no_auth_config', $result->get_error_code() );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * Test that listing connected accounts caches a count for admin badges.
	 */
	public function test_connected_account_count_is_cached() {
		$this->mock_response(
			200,
			array(
				array(
					'id'      => 'ca_1',
					'toolkit' => 'gmail',
					'status'  => 'active',
				),
				array(
					'id'      => 'ca_2',
					'toolkit' => 'slack',
					'status'  => 'active',
				),
			)
		);

		$client   = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_count' );
		$accounts = $client->list_connected_accounts();

		$this->assertNotWPError( $accounts );
		$this->assertSame( 2, WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_count' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_other' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::get_cached_account_count( '' ) );
	}

	/**
	 * Test that the v3.1 { items: [...] } wrapper is unwrapped and the real
	 * account count is cached (not the number of wrapper keys).
	 */
	public function test_list_connected_accounts_unwraps_items() {
		$this->mock_response(
			200,
			array(
				'items'       => array(
					array(
						'id'      => 'ca_1',
						'alias'   => 'me@example.com',
						'toolkit' => array( 'slug' => 'gmail' ),
						'status'  => 'ACTIVE',
					),
					array(
						'id'      => 'ca_2',
						'alias'   => 'workspace',
						'toolkit' => array( 'slug' => 'slack' ),
						'status'  => 'ACTIVE',
					),
				),
				'total_items' => 2,
				'next_cursor' => '',
			),
		);

		$client   = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_wrapped' );
		$accounts = $client->list_connected_accounts();

		$this->assertNotWPError( $accounts );
		$this->assertCount( 2, $accounts );
		$this->assertSame( 'ca_1', $accounts[0]['id'] );
		$this->assertSame( 'gmail', $accounts[0]['toolkit']['slug'] );
		$this->assertSame( 2, WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_wrapped' ) );
	}

	/**
	 * Test that flat legacy filters are mapped onto the v3.1 array query
	 * parameters (toolkit_slugs / statuses / user_ids).
	 */
	public function test_list_connected_accounts_maps_filters() {
		$this->mock_response(
			200,
			array(
				'items' => array(),
			),
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_filters' );
		$client->list_connected_accounts(
			array(
				'toolkit' => 'gmail',
				'status'  => 'active',
				'user_id' => 'wp-1',
			)
		);

		$this->assertStringContainsString( 'toolkit_slugs=gmail', $this->last_url );
		$this->assertStringContainsString( 'statuses=active', $this->last_url );
		$this->assertStringContainsString( 'user_ids=wp-1', $this->last_url );
		// A filtered listing must not clobber the total badge count.
		$this->assertFalse( WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_filters' ) );
	}

	/**
	 * Test that clearing the accounts cache drops both the listing cache and
	 * the admin-badge count transient.
	 */
	public function test_clear_accounts_cache_flushes_listing_and_count() {
		$this->mock_response(
			200,
			array(
				array(
					'id'      => 'ca_1',
					'toolkit' => 'gmail',
					'status'  => 'active',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_clear' );
		$client->list_connected_accounts();

		$this->assertSame( 1, WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_clear' ) );

		$listing_key = WP_MCP_AI_Composio_Client::CACHE_PREFIX . md5( 'conn_clear|' . WP_MCP_AI_Composio_Client::DEFAULT_BASE_URL . '/api/' . WP_MCP_AI_Composio_Client::API_VERSION . '/connected_accounts' );
		$this->assertNotFalse( get_transient( $listing_key ) );

		WP_MCP_AI_Composio_Client::clear_accounts_cache( 'conn_clear' );

		$this->assertFalse( get_transient( $listing_key ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_clear' ) );
	}

	/**
	 * Test that clearing the accounts cache with an empty connection ID is a
	 * safe no-op.
	 */
	public function test_clear_accounts_cache_empty_id_is_noop() {
		$this->mock_response( 200, array() );

		WP_MCP_AI_Composio_Client::clear_accounts_cache( '' );

		$this->assertSame( 0, $this->request_count );
	}

	/**
	 * Test that tool execution sends the bound user identity alongside the
	 * connected account. Composio rejects the account without it.
	 */
	public function test_execute_tool_sends_bound_user_id() {
		$this->mock_response( 200, array( 'successful' => true ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_exec', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_SEND_EMAIL', 'ca_1', array( 'to' => 'a@b.c' ) );

		$this->assertNotWPError( $result );

		$body = json_decode( $this->last_args['body'], true );

		$this->assertSame( 'ca_1', $body['connected_account_id'] );
		$this->assertSame( 'nvoos-shared', $body['user_id'] );
	}

	/**
	 * Test that an explicit user identity overrides the bound one.
	 */
	public function test_execute_tool_user_id_argument_overrides_bound_identity() {
		$this->mock_response( 200, array( 'successful' => true ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_exec', 'nvoos-shared' );
		$client->execute_tool( 'GMAIL_SEND_EMAIL', 'ca_1', array(), 'wp-7' );

		$body = json_decode( $this->last_args['body'], true );

		$this->assertSame( 'wp-7', $body['user_id'] );
	}

	/**
	 * Test that no user_id key is sent when no identity is known, so the
	 * upstream contract is unchanged for identity-less clients.
	 */
	public function test_execute_tool_omits_user_id_when_unbound() {
		$this->mock_response( 200, array( 'successful' => true ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$client->execute_tool( 'GMAIL_SEND_EMAIL', 'ca_1', array() );

		$body = json_decode( $this->last_args['body'], true );

		$this->assertArrayNotHasKey( 'user_id', $body );
	}

	/**
	 * Test that from_connection() binds the identity resolved from the
	 * connection's identity mode.
	 */
	public function test_from_connection_binds_shared_identity() {
		$client = WP_MCP_AI_Composio_Client::from_connection(
			array(
				'id'                => 'conn_shared',
				'api_key'           => 'ak_test',
				'connection_type'   => 'composio',
				'default_user_mode' => 'admin_shared',
			)
		);

		$this->assertSame( WP_MCP_AI_Composio_Auth_Handler::SHARED_USER_PREFIX, $client->get_user_id() );
	}

	/**
	 * Test that a trigger upsert without a pinned account carries the bound
	 * identity so Composio can resolve the connection itself.
	 */
	public function test_upsert_trigger_adds_bound_user_id() {
		$this->mock_response( 200, array( 'trigger_id' => 'ti_1' ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_trigger', 'nvoos-shared' );
		$client->upsert_trigger( 'GMAIL_NEW_MESSAGE', array() );

		$body = json_decode( $this->last_args['body'], true );

		$this->assertSame( 'nvoos-shared', $body['user_id'] );
	}

	/**
	 * Test that a pinned connected account is not overridden by the bound
	 * identity on trigger upsert.
	 */
	public function test_upsert_trigger_keeps_pinned_account() {
		$this->mock_response( 200, array( 'trigger_id' => 'ti_1' ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_trigger', 'nvoos-shared' );
		$client->upsert_trigger( 'GMAIL_NEW_MESSAGE', array( 'connected_account_id' => 'ca_1' ) );

		$body = json_decode( $this->last_args['body'], true );

		$this->assertSame( 'ca_1', $body['connected_account_id'] );
		$this->assertArrayNotHasKey( 'user_id', $body );
	}

	/**
	 * Test that removing a connected account asks Composio to revoke the
	 * upstream provider credentials too.
	 */
	public function test_delete_connected_account_can_revoke_upstream() {
		$this->mock_response( 200, array( 'success' => true ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_delete' );
		$result = $client->delete_connected_account( 'ca_1', true );

		$this->assertNotWPError( $result );
		$this->assertSame( 'DELETE', $this->last_args['method'] );
		$this->assertStringContainsString( '/connected_accounts/ca_1', $this->last_url );
		$this->assertStringContainsString( 'revoke_on_delete=true', $this->last_url );
	}

	/**
	 * Test that a missing account ID is rejected before any HTTP request.
	 */
	public function test_delete_connected_account_requires_account_id() {
		$this->mock_response( 200, array() );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->delete_connected_account( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_missing_account', $result->get_error_code() );
		$this->assertSame( 0, $this->request_count );
	}
}
