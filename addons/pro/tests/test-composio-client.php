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
	 * Test that a FastAPI-style validation `detail` array names the rejected
	 * field instead of degrading to the generic placeholder.
	 */
	public function test_http_error_extracts_validation_detail_array() {
		$this->mock_response(
			400,
			array(
				'detail' => array(
					array(
						'loc'  => array( 'body', 'arguments' ),
						'msg'  => 'value is not a valid dict',
						'type' => 'type_error.dict',
					),
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_http_400', $result->get_error_code() );
		$this->assertStringContainsString( 'body.arguments', $result->get_error_message() );
		$this->assertStringContainsString( 'value is not a valid dict', $result->get_error_message() );
		$this->assertStringNotContainsString( 'Composio API returned an error.', $result->get_error_message() );
	}

	/**
	 * Test that a string `detail` is still surfaced verbatim.
	 */
	public function test_http_error_extracts_string_detail() {
		$this->mock_response( 400, array( 'detail' => 'Validation error while processing request' ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Validation error while processing request', $result->get_error_message() );
	}

	/**
	 * Test that the upstream error body is retained on the WP_Error so an opaque
	 * rejection can be diagnosed without reproducing the request.
	 */
	public function test_http_error_retains_upstream_payload() {
		$body = array(
			'detail' => array(
				array(
					'loc' => array( 'body', 'arguments' ),
					'msg' => 'value is not a valid dict',
				),
			),
		);

		$this->mock_response( 400, $body );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertWPError( $result );

		$data = $result->get_error_data();

		$this->assertSame( 400, $data['status'] );
		$this->assertSame( $body, $data['upstream'] );
	}

	/**
	 * Test that an oversized upstream body is truncated rather than retained in
	 * full, since the error data reaches the rolling log buffers.
	 */
	public function test_http_error_truncates_oversized_upstream_payload() {
		$this->mock_response(
			500,
			array( 'blob' => str_repeat( 'x', WP_MCP_AI_Composio_Client::MAX_UPSTREAM_ERROR_BYTES * 2 ) )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertWPError( $result );

		$upstream = $result->get_error_data()['upstream'];

		$this->assertIsString( $upstream );
		$this->assertLessThanOrEqual(
			WP_MCP_AI_Composio_Client::MAX_UPSTREAM_ERROR_BYTES + 3,
			strlen( $upstream )
		);
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
		// The nested v3.1 toolkit object is flattened to its slug so every
		// consumer reads the same shape.
		$this->assertSame( 'gmail', $accounts[0]['toolkit'] );
		$this->assertSame( 'slack', $accounts[1]['toolkit'] );
		$this->assertSame( 2, WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_wrapped' ) );
	}

	/**
	 * Test that a connected account's credential expiry and failure reason are
	 * lifted out of the nested state.val block, where v3.1 buries them.
	 */
	public function test_normalize_account_extracts_expiry_and_reason() {
		$account = WP_MCP_AI_Composio_Client::normalize_account(
			array(
				'id'          => 'ca_dead',
				'status'      => 'expired',
				'toolkit'     => array( 'slug' => 'GMAIL' ),
				'is_disabled' => true,
				'auth_config' => array( 'auth_scheme' => 'OAUTH2' ),
				'state'       => array(
					'val' => array(
						'expired_at'        => '2026-08-01T00:00:00Z',
						'error_description' => 'Token has been expired or revoked.',
					),
				),
			)
		);

		$this->assertSame( 'EXPIRED', $account['status'] );
		$this->assertSame( 'gmail', $account['toolkit'] );
		$this->assertSame( '2026-08-01T00:00:00Z', $account['expires_at'] );
		$this->assertSame( 'Token has been expired or revoked.', $account['status_reason'] );
		$this->assertTrue( $account['disabled'] );
		$this->assertSame( 'OAUTH2', $account['auth_scheme'] );
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
		// Composio's status enum is SCREAMING_SNAKE — a lowercased filter matches
		// nothing upstream.
		$this->assertStringContainsString( 'statuses=ACTIVE', $this->last_url );
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
	 * Test that clearing the accounts cache also drops filtered listings.
	 *
	 * A filtered listing hashes to a different key than the unfiltered URL, so
	 * reconstructing the unfiltered key alone left the listing that account
	 * resolution actually reads stale for the full TTL after a connect.
	 */
	public function test_clear_accounts_cache_flushes_filtered_listings() {
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

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_filtered_clear' );

		$client->list_connected_accounts( array( 'toolkit' => 'gmail' ) );
		$this->assertSame( 1, $this->request_count );

		// Second read is served from cache.
		$client->list_connected_accounts( array( 'toolkit' => 'gmail' ) );
		$this->assertSame( 1, $this->request_count );

		WP_MCP_AI_Composio_Client::clear_accounts_cache( 'conn_filtered_clear' );

		// After invalidation the filtered listing must be re-fetched.
		$client->list_connected_accounts( array( 'toolkit' => 'gmail' ) );
		$this->assertSame( 2, $this->request_count );
	}

	/**
	 * Test that flush_cache() actually invalidates cached GET responses.
	 */
	public function test_flush_cache_invalidates_cached_gets() {
		$this->mock_response( 200, array( array( 'tool_slug' => 'GMAIL_SEND_EMAIL' ) ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_flush' );

		$client->list_tools();
		$client->list_tools();
		$this->assertSame( 1, $this->request_count );

		$client->flush_cache();

		$client->list_tools();
		$this->assertSame( 2, $this->request_count );
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
	 * Test that a zero-argument call sends `arguments` as a JSON object.
	 *
	 * An empty PHP array encodes as `[]`, which Composio rejects with a
	 * validation error where its schema requires an object. Every health probe
	 * is a zero-argument call, so this shape decided whether a credential could
	 * be verified at all.
	 */
	public function test_execute_tool_sends_empty_arguments_as_object() {
		$this->mock_response( 200, array( 'successful' => true ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_exec', 'nvoos-shared' );
		$client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		// Asserted against the raw body: json_decode() maps both `{}` and `[]`
		// onto an empty PHP array, which would hide the bug entirely.
		$this->assertStringContainsString( '"arguments":{}', $this->last_args['body'] );
		$this->assertStringNotContainsString( '"arguments":[]', $this->last_args['body'] );
	}

	/**
	 * Test that supplied arguments are still sent unchanged.
	 */
	public function test_execute_tool_preserves_supplied_arguments() {
		$this->mock_response( 200, array( 'successful' => true ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_exec', 'nvoos-shared' );
		$client->execute_tool( 'GMAIL_SEND_EMAIL', 'ca_1', array( 'to' => 'a@b.c' ) );

		$body = json_decode( $this->last_args['body'], true );

		$this->assertSame( array( 'to' => 'a@b.c' ), $body['arguments'] );
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
	 * Test that the /tools pagination envelope is unwrapped into a list of
	 * addressable tool records. Returning the envelope raw made callers see the
	 * `items` key itself as a single, slug-less "tool".
	 */
	public function test_list_tools_unwraps_pagination_envelope() {
		$this->mock_response(
			200,
			array(
				'items'        => array(
					array(
						'slug'             => 'GMAIL_FETCH_EMAILS',
						'name'             => 'Fetch Emails',
						'description'      => 'Fetches emails from Gmail.',
						'toolkit'          => array(
							'slug' => 'gmail',
							'name' => 'Gmail',
						),
						'input_parameters' => array( 'required' => array( 'query' ) ),
					),
				),
				'next_cursor'  => 'eyJwYWdlIjoyfQ==',
				'total_items'  => 137,
				'total_pages'  => 3,
				'current_page' => 1,
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_tools' );
		$result = $client->list_tools( array( 'toolkit' => 'gmail' ) );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'GMAIL_FETCH_EMAILS', $result['items'][0]['slug'] );
		$this->assertSame( 'gmail', $result['items'][0]['toolkit'] );
		$this->assertSame( 'Gmail', $result['items'][0]['toolkit_name'] );
		$this->assertSame( array( 'query' ), $result['items'][0]['required_inputs'] );
		$this->assertSame( 137, $result['total_items'] );
		$this->assertSame( 'eyJwYWdlIjoyfQ==', $result['next_cursor'] );
	}

	/**
	 * Test that catalog filters are mapped onto the parameter names the endpoint
	 * actually accepts: toolkit_slug (singular) and query (not the deprecated
	 * search, and never the unsupported page).
	 */
	public function test_list_tools_maps_documented_query_params() {
		$this->mock_response( 200, array( 'items' => array() ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_params' );
		$client->list_tools(
			array(
				'toolkit' => 'gmail',
				'search'  => 'send an email',
				'limit'   => 10,
			)
		);

		$this->assertStringContainsString( 'toolkit_slug=gmail', $this->last_url );
		$this->assertStringNotContainsString( 'toolkit_slugs=', $this->last_url );
		$this->assertStringContainsString( 'query=send', $this->last_url );
		$this->assertStringNotContainsString( 'page=', $this->last_url );
		$this->assertStringContainsString( 'limit=10', $this->last_url );
	}

	/**
	 * Test that a legacy flat catalog array is still accepted.
	 */
	public function test_list_tools_accepts_legacy_flat_array() {
		$this->mock_response(
			200,
			array(
				array(
					'tool_slug'   => 'GMAIL_SEND_EMAIL',
					'toolkit'     => 'gmail',
					'name'        => 'Send Email',
					'description' => 'Sends an email',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_legacy' );
		$result = $client->list_tools();

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'GMAIL_SEND_EMAIL', $result['items'][0]['slug'] );
		$this->assertSame( 'gmail', $result['items'][0]['toolkit'] );
	}

	/**
	 * Test that Composio's in-band execution failure (HTTP 200 with
	 * successful:false) becomes a WP_Error instead of being reported as a
	 * successful execution.
	 */
	public function test_execute_tool_converts_in_band_failure_to_error() {
		$this->mock_response(
			200,
			array(
				'successful' => false,
				'error'      => 'Request failed with status 500',
				'data'       => null,
				'log_id'     => 'log_abc',
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_fail', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_tool_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'status 500', $result->get_error_message() );
		$this->assertSame( 'log_abc', $result->get_error_data()['log_id'] );
	}

	/**
	 * Test that an in-band auth failure is classified distinctly so callers can
	 * offer a reconnect rather than a generic retry.
	 */
	public function test_execute_tool_classifies_in_band_auth_failure() {
		$this->mock_response(
			200,
			array(
				'successful' => false,
				'error'      => 'Auth refresh required: invalid_grant (token has been expired or revoked)',
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_auth', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_dead', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_account_auth_required', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['auth_failure'] );
	}

	/**
	 * Test that a payload without a `successful` key is untouched, so older
	 * response shapes keep working.
	 */
	public function test_execute_tool_ignores_missing_successful_key() {
		$this->mock_response( 200, array( 'data' => array( 'messages' => array() ) ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_ok', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertNotWPError( $result );
	}

	/**
	 * Test the failure mode that made the agentic loop thrash: Composio reports
	 * `successful: true` because it *delivered* the call, and the provider's 401
	 * arrives proxied inside `data.message`. Reported as a success, the caller
	 * could not tell the call had failed.
	 */
	public function test_execute_tool_detects_provider_status_proxied_into_data() {
		$this->mock_response(
			200,
			array(
				'successful' => true,
				'data'       => array(
					'message' => 'HTTP 401: Request had invalid authentication credentials. Expected OAuth 2 access token, login cookie or other valid authentication credential.',
				),
				'log_id'     => 'log_401',
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_proxied', 'nvoos-shared' );
		$result = $client->execute_tool( 'GOOGLECALENDAR_EVENTS_LIST', 'ca_stale', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_account_auth_required', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 401, $data['provider_status'] );
		$this->assertTrue( $data['auth_failure'] );
		$this->assertSame( 'log_401', $data['log_id'] );
		$this->assertStringContainsString( 'invalid authentication credentials', $data['upstream_error'] );
	}

	/**
	 * Test that a non-auth provider status is a plain tool failure, so a 500 is
	 * not mistaken for a dead credential.
	 */
	public function test_execute_tool_detects_proxied_server_error() {
		$this->mock_response(
			200,
			array(
				'successful' => true,
				'data'       => array( 'error' => 'HTTP/1.1 500 Internal Server Error' ),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_5xx', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_tool_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['provider_status'] );
		$this->assertFalse( $result->get_error_data()['auth_failure'] );
	}

	/**
	 * Test that an in-band failure whose only message sits under `data` is
	 * reported with that message instead of the generic "no error message"
	 * fallback, which used to strip the auth classification with it.
	 */
	public function test_execute_tool_reads_error_nested_under_data() {
		$this->mock_response(
			200,
			array(
				'successful' => false,
				'error'      => null,
				'data'       => array( 'error' => 'Auth refresh required: token has been expired or revoked' ),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_nested', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_dead', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_account_auth_required', $result->get_error_code() );
		$this->assertStringContainsString( 'expired or revoked', $result->get_error_message() );
		$this->assertArrayNotHasKey( 'provider_status', $result->get_error_data() );
	}

	/**
	 * Test the false-positive guard: a *successful* read whose content merely
	 * mentions an HTTP status must stay a success. Scanning the whole payload
	 * instead of the error fields would fail an email fetch because of what the
	 * email said.
	 */
	public function test_execute_tool_ignores_http_status_inside_tool_content() {
		$this->mock_response(
			200,
			array(
				'successful' => true,
				'data'       => array(
					'messages' => array(
						array(
							'subject' => 'HTTP 401 errors on staging',
							'body'    => 'HTTP 500: the deploy is throwing again, can you look?',
						),
					),
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_content', 'nvoos-shared' );
		$result = $client->execute_tool( 'GMAIL_FETCH_EMAILS', 'ca_1', array() );

		$this->assertNotWPError( $result );
	}

	/**
	 * Test the status reader in isolation, including the anchoring that keeps
	 * prose out of the failure path.
	 */
	public function test_detect_proxied_http_status_is_anchored() {
		$this->assertSame( 401, WP_MCP_AI_Composio_Client::detect_proxied_http_status( 'HTTP 401: nope' ) );
		$this->assertSame( 429, WP_MCP_AI_Composio_Client::detect_proxied_http_status( '  http 429 slow down' ) );
		$this->assertSame( 503, WP_MCP_AI_Composio_Client::detect_proxied_http_status( 'HTTP/1.0 503 unavailable' ) );
		$this->assertSame( 0, WP_MCP_AI_Composio_Client::detect_proxied_http_status( 'Retried after an HTTP 404 from the CDN' ) );
		$this->assertSame( 0, WP_MCP_AI_Composio_Client::detect_proxied_http_status( 'HTTP 200: all good' ) );
		$this->assertSame( 0, WP_MCP_AI_Composio_Client::detect_proxied_http_status( 'Fetched 3 messages' ) );
		$this->assertSame( 0, WP_MCP_AI_Composio_Client::detect_proxied_http_status( array( 'not' => 'a string' ) ) );
	}

	/**
	 * Test that reconnecting targets the existing account's own refresh route,
	 * so no duplicate account is minted.
	 */
	public function test_reconnect_connected_account_targets_existing_account() {
		$this->mock_response(
			200,
			array(
				'id'           => 'ca_existing',
				'status'       => 'INITIATED',
				'redirect_url' => 'https://backend.composio.dev/link/abc',
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_reconnect' );
		$result = $client->reconnect_connected_account( 'ca_existing' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'ca_existing', $result['id'] );
		$this->assertStringContainsString( '/connected_accounts/ca_existing/refresh', $this->last_url );
		$this->assertSame( 'POST', $this->last_args['method'] );
	}

	/**
	 * Test that reconnect validates its input.
	 */
	public function test_reconnect_connected_account_requires_id() {
		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->reconnect_connected_account( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_missing_account', $result->get_error_code() );
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
