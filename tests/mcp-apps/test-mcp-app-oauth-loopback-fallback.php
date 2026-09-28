<?php
/**
 * Tests for the MCP App OAuth loopback fallback flow.
 *
 * Covers providers (e.g. Upwork) that reject non-localhost redirect URIs:
 * dynamic client registration error surfacing, the manual loopback fallback
 * in initiate_oauth(), the token-endpoint form-encoded retry, and the
 * oauth/complete paste-back endpoint.
 *
 * No live HTTP requests are made.
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 */

/**
 * Tests for the MCP App OAuth loopback fallback flow.
 */
class Test_MCP_App_OAuth_Loopback_Fallback extends WP_UnitTestCase {

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_REST_MCP_Apps_Controller' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Allowlist the test host so the SSRF URL guard passes without DNS.
		add_filter(
			'wp_mcp_ai_http_allowed_host',
			function ( $hosts, $host, $url ) {
				unset( $host, $url );
				$hosts[] = 'example.com';
				return $hosts;
			},
			10,
			3
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_http_allowed_host' );
		parent::tearDown();
	}

	/**
	 * Install a pre_http_request mock serving OAuth metadata and a DCR
	 * endpoint that rejects non-loopback redirect URIs once, then accepts
	 * the loopback registration.
	 *
	 * @param array $captured Reference for captured request args.
	 * @return void
	 */
	protected function install_loopback_dcr_mock( &$captured ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				unset( $pre );
				$captured[] = array(
					'url'  => $url,
					'args' => $args,
				);

				// OAuth metadata discovery.
				if ( false !== strpos( $url, '/.well-known/oauth-authorization-server' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'authorization_endpoint' => 'https://example.com/oauth/authorize',
								'token_endpoint'         => 'https://example.com/oauth/token',
								'registration_endpoint'  => 'https://example.com/oauth/register',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				// Dynamic client registration.
				if ( false !== strpos( $url, '/oauth/register' ) ) {
					$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
					$uris    = isset( $payload['redirect_uris'] ) ? $payload['redirect_uris'] : array();
					$first   = isset( $uris[0] ) ? $uris[0] : '';

					if ( 1 !== preg_match( '#^http://localhost:\d+/callback$#', $first ) ) {
						return array(
							'headers'  => array(),
							'body'     => wp_json_encode(
								array(
									'error'             => 'invalid_redirect_uri',
									'error_description' => 'One or more redirect URIs are invalid',
								)
							),
							'response' => array(
								'code'    => 400,
								'message' => 'Bad Request',
							),
						);
					}

					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'client_id'     => 'upwork-client-123',
								'redirect_uris' => $uris,
								'grant_types'   => array( 'authorization_code', 'refresh_token' ),
								'token_endpoint_auth_method' => 'none',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * The register_client() method surfaces the provider's OAuth error code
	 * so callers can detect the invalid_redirect_uri rejection.
	 */
	public function test_register_client_surfaces_oauth_error_code() {
		$captured = array();
		$this->install_loopback_dcr_mock( $captured );

		$client = new WP_MCP_AI_MCP_App_OAuth_Client( 'https://example.com/mcp' );
		$result = $client->register_client();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mcp_app_oauth_registration_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'invalid_redirect_uri', $data['oauth_error'] );
	}

	/**
	 * The initiate_oauth() method retries DCR with a loopback redirect URI
	 * when the provider rejects the site callback, and flags the flow as
	 * manual.
	 */
	public function test_initiate_oauth_falls_back_to_loopback_flow() {
		$captured = array();
		$this->install_loopback_dcr_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/oauth/init' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->initiate_oauth( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();

		$this->assertSame( 'manual_loopback', $data['redirect_mode'] );
		$this->assertSame( 'upwork-client-123', $data['client_id'] );
		$this->assertStringContainsString( 'redirect_uri=http://localhost:', $data['authorization_url'] );

		// The flow state must carry the loopback URI so the callback exchange
		// can restore it.
		$flow_state = get_transient( WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TRANSIENT . $data['state'] );
		$this->assertIsArray( $flow_state );
		$this->assertSame( 'manual_loopback', $flow_state['redirect_mode'] );
		$this->assertMatchesRegularExpression( '#^http://localhost:\d+/callback$#', $flow_state['redirect_uri'] );

		// Exactly two registrations: the site callback first (rejected), then
		// the loopback URI (accepted).
		$registrations = array_values(
			array_filter(
				$captured,
				function ( $call ) {
					return false !== strpos( $call['url'], '/oauth/register' );
				}
			)
		);
		$this->assertCount( 2, $registrations );
		$first_body = json_decode( $registrations[0]['args']['body'], true );
		$last_body  = json_decode( $registrations[1]['args']['body'], true );
		$this->assertSame( 1, preg_match( '#^http://localhost:\d+/callback$#', $last_body['redirect_uris'][0] ) );
		$this->assertNotSame( 1, preg_match( '#^http://localhost:#', $first_body['redirect_uris'][0] ) );
	}

	/**
	 * The oauth/complete endpoint rejects callback URLs that are not the
	 * loopback callback.
	 */
	public function test_complete_oauth_rejects_non_loopback_url() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/oauth/complete' );
		$request->set_param( 'state', 'teststate' );
		$request->set_param( 'callback_url', 'https://evil.example/cb?code=abc&state=teststate' );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->complete_oauth( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_mcp_app_oauth_bad_callback', $response->get_error_code() );
	}

	/**
	 * The oauth/complete endpoint exchanges the pasted loopback callback URL,
	 * retrying the token request form-encoded after a 415, and persists the
	 * tokens.
	 */
	public function test_complete_oauth_completes_manual_flow() {
		$state = 'manualstate123';
		set_transient(
			WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TRANSIENT . $state,
			array(
				'server_url'    => 'https://example.com/mcp',
				'assistant_id'  => $this->assistant_id,
				'client_id'     => 'upwork-client-123',
				'redirect_uri'  => 'http://localhost:5123/callback',
				'redirect_mode' => 'manual_loopback',
				'code_verifier' => 'verifier123',
				'created_at'    => time(),
			),
			WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TTL
		);

		$captured = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				unset( $pre );
				$captured[] = array(
					'url'  => $url,
					'args' => $args,
				);

				if ( false !== strpos( $url, '/.well-known/oauth-authorization-server' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'authorization_endpoint' => 'https://example.com/oauth/authorize',
								'token_endpoint'         => 'https://example.com/oauth/token',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/oauth/token' ) ) {
					$content_type = isset( $args['headers']['Content-Type'] ) ? $args['headers']['Content-Type'] : '';
					if ( 'application/json' === $content_type ) {
						// Upwork rejects JSON token requests.
						return array(
							'headers'  => array(),
							'body'     => '',
							'response' => array(
								'code'    => 415,
								'message' => 'Unsupported Media Type',
							),
						);
					}

					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'access_token'  => 'tok-abc',
								'refresh_token' => 'ref-abc',
								'token_type'    => 'Bearer',
								'expires_in'    => 3600,
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/oauth/complete' );
		$request->set_param( 'state', $state );
		$request->set_param( 'callback_url', 'http://localhost:5123/callback?code=authcode&state=' . $state );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->complete_oauth( $request );

		$this->assertNotWPError( $response );
		$this->assertTrue( $response->get_data()['success'] );

		// The transient must be consumed.
		$this->assertFalse( get_transient( WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TRANSIENT . $state ) );

		// The token request must have been retried form-encoded after the 415.
		$token_calls = array_values(
			array_filter(
				$captured,
				function ( $call ) {
					return false !== strpos( $call['url'], '/oauth/token' );
				}
			)
		);
		$this->assertCount( 2, $token_calls );
		$this->assertStringContainsString( 'application/x-www-form-urlencoded', $token_calls[1]['args']['headers']['Content-Type'] );
		$form_body = is_array( $token_calls[1]['args']['body'] )
			? http_build_query( $token_calls[1]['args']['body'], '', '&' )
			: $token_calls[1]['args']['body'];
		$this->assertStringContainsString( 'code_verifier=verifier123', $form_body );
		$this->assertStringContainsString( 'redirect_uri=' . rawurlencode( 'http://localhost:5123/callback' ), $form_body );

		// Tokens must be persisted onto the assistant's MCP Apps config.
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $this->assistant_id );
		$this->assertNotEmpty( $apps );
		$app = $apps[0];
		$this->assertSame( 'oauth', $app['auth_type'] );
		$this->assertSame( 'tok-abc', $app['oauth_data']['access_token'] );
		$this->assertSame( 'ref-abc', $app['oauth_data']['refresh_token'] );
	}
}
