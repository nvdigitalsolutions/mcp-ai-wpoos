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
		// Public clients (e.g. Upwork) require the client ID in the exchange.
		$this->assertStringContainsString( 'client_id=upwork-client-123', $form_body );

		// Tokens must be persisted onto the assistant's MCP Apps config.
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $this->assistant_id );
		$this->assertNotEmpty( $apps );
		$app = $apps[0];
		$this->assertSame( 'oauth', $app['auth_type'] );
		$this->assertSame( 'tok-abc', $app['oauth_data']['access_token'] );
		$this->assertSame( 'ref-abc', $app['oauth_data']['refresh_token'] );
		// The dynamic client ID must persist so auto-refresh can identify itself.
		$this->assertSame( 'upwork-client-123', $app['oauth_data']['client_id'] );
	}

	/**
	 * The registry restores the dynamic client ID onto the attached OAuth
	 * client so automatic refresh can identify itself with providers like
	 * Upwork.
	 */
	public function test_registry_restores_client_id_for_auto_refresh() {
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$client   = $registry->create_client(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'oauth',
				'oauth_data' => array(
					'access_token'  => 'tok',
					'refresh_token' => 'ref',
					'client_id'     => 'upwork-client-123',
				),
			)
		);

		$this->assertInstanceOf( 'WP_MCP_AI_MCP_App_Client', $client );
		$oauth = $client->get_oauth_client();
		$this->assertInstanceOf( 'WP_MCP_AI_MCP_App_OAuth_Client', $oauth );
		$this->assertSame( 'upwork-client-123', $oauth->get_client_id() );
	}

	/**
	 * Save an OAuth app config the way finalize_oauth_flow() does.
	 *
	 * @return void
	 */
	protected function save_oauth_app_fixture() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'example.com',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'oauth',
					'enabled'    => true,
					'timeout'    => 30,
					'verify_ssl' => true,
					'oauth_data' => array(
						'access_token'  => 'tok-abc',
						'refresh_token' => 'ref-abc',
						'token_type'    => 'Bearer',
						'expires_in'    => 3600,
						'scope'         => '',
						'issued_at'     => time(),
						'client_id'     => 'upwork-client-123',
					),
				),
			)
		);
	}

	/**
	 * Install a pre_http_request mock that answers the JSON-RPC handshake
	 * (server/discover + tools/list) and captures outgoing request headers.
	 *
	 * @param array $captured Reference for captured outgoing requests.
	 * @return void
	 */
	protected function install_mcp_server_mock( &$captured ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				unset( $pre );
				if ( false === strpos( $url, 'https://example.com/mcp' ) ) {
					return false;
				}

				$captured[] = array(
					'url'  => $url,
					'args' => $args,
				);

				$decoded = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $decoded ) && isset( $decoded['method'] ) ? $decoded['method'] : '';

				if ( 'server/discover' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => isset( $decoded['id'] ) ? $decoded['id'] : 1,
								'result'  => array(
									'serverInfo'   => array(
										'name'    => 'upwork',
										'version' => '1.0',
									),
									'capabilities' => array( 'tools' => array() ),
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( 'tools/list' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => isset( $decoded['id'] ) ? $decoded['id'] : 1,
								'result'  => array(
									'tools' => array(
										array(
											'name'        => 'upwork_search',
											'description' => 'Search Upwork',
											'inputSchema' => array( 'type' => 'object' ),
										),
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
	 * Test Connection must resolve the stored OAuth token for a saved OAuth
	 * app even though the metabox never sends oauth_data back to the server
	 * (credentials are masked and stored server-side only).
	 */
	public function test_test_connection_resolves_stored_oauth_token() {
		$this->save_oauth_app_fixture();

		$captured = array();
		$this->install_mcp_server_mock( $captured );

		// Metabox payload: auth_type oauth, no token, no oauth_data.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/test' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'auth_type', 'oauth' );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->test_connection( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( isset( $data['success'] ) && $data['success'] );

		// The handshake must have carried the stored OAuth access token.
		$this->assertNotEmpty( $captured );
		$auth_header = '';
		foreach ( $captured as $call ) {
			if ( ! empty( $call['args']['headers'] ) ) {
				foreach ( $call['args']['headers'] as $name => $value ) {
					if ( 'authorization' === strtolower( (string) $name ) ) {
						$auth_header = (string) $value;
						break 2;
					}
				}
			}
		}
		$this->assertSame( 'Bearer tok-abc', $auth_header );
	}

	/**
	 * Discover Tools must resolve the stored OAuth token the same way Test
	 * Connection does.
	 */
	public function test_discover_tools_resolves_stored_oauth_token() {
		$this->save_oauth_app_fixture();

		$captured = array();
		$this->install_mcp_server_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/discover' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'auth_type', 'oauth' );
		$request->set_param( 'refresh', true );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->discover_tools( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( isset( $data['success'] ) && $data['success'] );
		$this->assertSame( 1, $data['tool_count'] );
		$this->assertSame( 'upwork_search', $data['tools'][0]['name'] );

		$auth_header = '';
		foreach ( $captured as $call ) {
			if ( ! empty( $call['args']['headers'] ) ) {
				foreach ( $call['args']['headers'] as $name => $value ) {
					if ( 'authorization' === strtolower( (string) $name ) ) {
						$auth_header = (string) $value;
						break 2;
					}
				}
			}
		}
		$this->assertSame( 'Bearer tok-abc', $auth_header );
	}

	/**
	 * Save an OAuth app whose access token is already expired, so the next
	 * request must refresh it.
	 *
	 * @return void
	 */
	protected function save_expired_oauth_app_fixture() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'example.com',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'oauth',
					'enabled'    => true,
					'timeout'    => 30,
					'verify_ssl' => true,
					'oauth_data' => array(
						'access_token'  => 'tok-old',
						'refresh_token' => 'ref-old',
						'token_type'    => 'Bearer',
						'expires_in'    => 3600,
						'scope'         => 'jobs.read',
						'issued_at'     => time() - 7200,
						'client_id'     => 'upwork-client-123',
					),
				),
			)
		);
	}

	/**
	 * Install a mock that serves OAuth metadata + token refresh and the MCP
	 * JSON-RPC handshake, capturing every outgoing request.
	 *
	 * @param array $captured Reference for captured requests.
	 * @return void
	 */
	protected function install_oauth_refresh_and_mcp_mock( &$captured ) {
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
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				// Token endpoint: refresh grants only.
				if ( false !== strpos( $url, '/oauth/token' ) ) {
					$body = isset( $args['body'] ) ? $args['body'] : '';
					if ( is_string( $body ) ) {
						$decoded = json_decode( $body, true );
						if ( is_array( $decoded ) ) {
							$body = $decoded;
						}
					}
					$grant = is_array( $body ) && isset( $body['grant_type'] ) ? $body['grant_type'] : '';

					if ( 'refresh_token' !== $grant ) {
						return array(
							'headers'  => array(),
							'body'     => wp_json_encode(
								array(
									'error'             => 'unsupported_grant_type',
									'error_description' => 'Only refresh_token grants are mocked.',
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
								'access_token'  => 'tok-new',
								'refresh_token' => 'ref-new',
								'token_type'    => 'Bearer',
								'expires_in'    => 3600,
								'scope'         => 'jobs.read',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				// MCP JSON-RPC endpoints.
				if ( false !== strpos( $url, 'https://example.com/mcp' ) ) {
					$decoded = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
					$method  = is_array( $decoded ) && isset( $decoded['method'] ) ? $decoded['method'] : '';

					if ( 'server/discover' === $method ) {
						return array(
							'headers'  => array(),
							'body'     => wp_json_encode(
								array(
									'jsonrpc' => '2.0',
									'id'      => isset( $decoded['id'] ) ? $decoded['id'] : 1,
									'result'  => array(
										'serverInfo'   => array(
											'name'    => 'upwork',
											'version' => '1.0',
										),
										'capabilities' => array( 'tools' => array() ),
									),
								)
							),
							'response' => array(
								'code'    => 200,
								'message' => 'OK',
							),
						);
					}

					if ( 'tools/list' === $method ) {
						return array(
							'headers'  => array(),
							'body'     => wp_json_encode(
								array(
									'jsonrpc' => '2.0',
									'id'      => isset( $decoded['id'] ) ? $decoded['id'] : 1,
									'result'  => array( 'tools' => array() ),
								)
							),
							'response' => array(
								'code'    => 200,
								'message' => 'OK',
							),
						);
					}
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
	 * Extract the Authorization header from the first captured request that
	 * carries one.
	 *
	 * @param array $captured Captured outgoing requests.
	 * @return string Authorization header value, or empty string.
	 */
	protected function find_auth_header( array $captured ) {
		foreach ( $captured as $call ) {
			if ( empty( $call['args']['headers'] ) ) {
				continue;
			}
			foreach ( $call['args']['headers'] as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					return (string) $value;
				}
			}
		}

		return '';
	}

	/**
	 * An automatic refresh during Test Connection must persist the rotated
	 * tokens onto the assistant's stored app config.
	 */
	public function test_oauth_refresh_persists_during_test_connection() {
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		$this->save_expired_oauth_app_fixture();

		$captured = array();
		$this->install_oauth_refresh_and_mcp_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/test' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'auth_type', 'oauth' );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->test_connection( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( isset( $data['success'] ) && $data['success'] );

		// The refresh request must have carried the stored public client ID.
		$refresh_request = null;
		foreach ( $captured as $call ) {
			if ( false !== strpos( $call['url'], '/oauth/token' ) ) {
				$refresh_request = $call;
				break;
			}
		}
		$this->assertNotNull( $refresh_request );
		$refresh_body = json_decode( $refresh_request['args']['body'], true );
		$this->assertSame( 'refresh_token', $refresh_body['grant_type'] );
		$this->assertSame( 'upwork-client-123', $refresh_body['client_id'] );
		$this->assertSame( 'ref-old', $refresh_body['refresh_token'] );

		// The handshake after the refresh must use the NEW access token.
		$handshake_header = '';
		foreach ( $captured as $call ) {
			if ( false !== strpos( $call['url'], 'https://example.com/mcp' ) ) {
				$handshake_header = $this->find_auth_header( array( $call ) );
				if ( '' !== $handshake_header ) {
					break;
				}
			}
		}
		$this->assertSame( 'Bearer tok-new', $handshake_header );

		// The rotated credentials must be persisted to the stored app config.
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$stored   = $registry->get_stored_oauth_data( $this->assistant_id, 'https://example.com/mcp' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'tok-new', $stored['access_token'] );
		$this->assertSame( 'ref-new', $stored['refresh_token'] );
		$this->assertSame( 'jobs.read', $stored['scope'] );
		$this->assertSame( 'upwork-client-123', $stored['client_id'] );
	}

	/**
	 * Update_app_oauth_data() merges refreshed tokens while preserving the
	 * dynamic client ID and a previously stored scope.
	 */
	public function test_update_app_oauth_data_preserves_client_id_and_scope() {
		$this->save_expired_oauth_app_fixture();

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$changed  = $registry->update_app_oauth_data(
			$this->assistant_id,
			'https://example.com/mcp',
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => '', // Providers may omit scope on refresh.
				'issued_at'     => time(),
			)
		);

		$this->assertTrue( $changed );

		$stored = $registry->get_stored_oauth_data( $this->assistant_id, 'https://example.com/mcp' );
		$this->assertSame( 'tok-new', $stored['access_token'] );
		$this->assertSame( 'ref-new', $stored['refresh_token'] );
		// The stored scope survives a refresh response that omits it.
		$this->assertSame( 'jobs.read', $stored['scope'] );
		// The dynamic client ID must never be dropped.
		$this->assertSame( 'upwork-client-123', $stored['client_id'] );

		// Re-applying identical data is a no-op.
		$again = $registry->update_app_oauth_data(
			$this->assistant_id,
			'https://example.com/mcp',
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => '',
				'issued_at'     => $stored['issued_at'],
			)
		);
		$this->assertFalse( $again );
	}

	/**
	 * Update_app_oauth_data() never writes credentials back to a centrally
	 * managed reference entry.
	 */
	public function test_update_app_oauth_data_skips_reference_entries() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'example.com',
					'server_url'     => 'https://example.com/mcp',
					'auth_type'      => 'oauth',
					'connection_ref' => 'conn_abc',
					'enabled'        => true,
					'oauth_data'     => array(),
				),
			)
		);

		$changed = $registry->update_app_oauth_data(
			$this->assistant_id,
			'https://example.com/mcp',
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'expires_in'    => 3600,
				'issued_at'     => time(),
			)
		);

		$this->assertFalse( $changed );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertEmpty( $apps[0]['oauth_data'] );
	}

	/**
	 * The tool bridge re-hydrates the stored OAuth credentials before
	 * execution, so a refresh persisted by an earlier call is not lost to
	 * the registration-time config snapshot.
	 */
	public function test_bridge_rehydrates_stored_oauth_data_before_execution() {
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Tool_Bridge' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-tool-bridge.php';
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Stored app carries the rotated credentials.
		$this->save_oauth_app_fixture();
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->update_app_oauth_data(
			$this->assistant_id,
			'https://example.com/mcp',
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => '',
				'issued_at'     => time(),
			)
		);

		// Bridge holds a stale, pre-refresh snapshot (still a valid token,
		// so no refresh should occur - the header must come from re-hydration).
		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array(
				'name'        => 'upwork_search',
				'description' => 'Search Upwork',
				'inputSchema' => array( 'type' => 'object' ),
			),
			array(
				'label'      => 'example.com',
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'oauth',
				'enabled'    => true,
				'oauth_data' => array(
					'access_token'  => 'tok-old',
					'refresh_token' => 'ref-old',
					'token_type'    => 'Bearer',
					'expires_in'    => 3600,
					'scope'         => '',
					'issued_at'     => time(),
					'client_id'     => 'upwork-client-123',
				),
			),
			'example.com',
			$this->assistant_id
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

				$decoded = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $decoded ) && isset( $decoded['method'] ) ? $decoded['method'] : '';

				if ( 'initialize' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => isset( $decoded['id'] ) ? $decoded['id'] : 1,
								'result'  => array(
									'protocolVersion' => '2025-03-26',
									'serverInfo'      => array(
										'name'    => 'upwork',
										'version' => '1.0',
									),
									'capabilities'    => array( 'tools' => new stdClass() ),
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( 'tools/call' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => isset( $decoded['id'] ) ? $decoded['id'] : 1,
								'result'  => array(
									'content' => array(
										array(
											'type' => 'text',
											'text' => '2 results',
										),
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

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => new stdClass(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$result = $bridge->execute( array( 'query' => 'wp developer' ), array( 'user_id' => $user_id ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( isset( $result['success'] ) && $result['success'] );

		// The initialize request must carry the re-hydrated access token.
		$initialize_header = '';
		foreach ( $captured as $call ) {
			if ( false !== strpos( $call['url'], 'https://example.com/mcp' ) ) {
				$initialize_header = $this->find_auth_header( array( $call ) );
				if ( '' !== $initialize_header ) {
					break;
				}
			}
		}
		$this->assertSame( 'Bearer tok-new', $initialize_header );
	}

	/**
	 * Inline MCP App credentials must be encrypted at rest: raw post meta
	 * never carries the plaintext token, while get_apps() decrypts for
	 * consumers.
	 */
	public function test_app_secrets_encrypted_at_rest() {
		$this->save_oauth_app_fixture();

		$raw = get_post_meta( $this->assistant_id, WP_MCP_AI_MCP_App_Registry::META_KEY, true );
		$this->assertIsArray( $raw );

		$raw_access  = $raw[0]['oauth_data']['access_token'];
		$raw_refresh = $raw[0]['oauth_data']['refresh_token'];

		// The plaintext tokens must never appear in storage.
		$this->assertNotSame( 'tok-abc', $raw_access );
		$this->assertFalse( strpos( (string) $raw_access, 'tok-abc' ) );
		$this->assertNotSame( 'ref-abc', $raw_refresh );
		$this->assertFalse( strpos( (string) $raw_refresh, 'ref-abc' ) );

		// Values must be recognized as encrypted by the shared detector.
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_value_encrypted( $raw_access ) );
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_value_encrypted( $raw_refresh ) );

		// Consumers read decrypted values transparently.
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $this->assistant_id );
		$this->assertSame( 'tok-abc', $apps[0]['oauth_data']['access_token'] );
		$this->assertSame( 'ref-abc', $apps[0]['oauth_data']['refresh_token'] );
	}

	/**
	 * The bearer/basic/header token field is encrypted at rest too.
	 */
	public function test_token_field_encrypted_at_rest() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'elementor',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'bearer',
					'token'      => 'secret-token',
					'enabled'    => true,
				),
			)
		);

		$raw = get_post_meta( $this->assistant_id, WP_MCP_AI_MCP_App_Registry::META_KEY, true );
		$this->assertNotSame( 'secret-token', $raw[0]['token'] );
		$this->assertFalse( strpos( (string) $raw[0]['token'], 'secret-token' ) );
		$this->assertTrue( WP_MCP_AI_Pro_Remote_Site_Manager::is_value_encrypted( $raw[0]['token'] ) );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertSame( 'secret-token', $apps[0]['token'] );
	}

	/**
	 * Legacy plaintext rows (written before encryption shipped) must keep
	 * working: decrypt-on-read is idempotent for plaintext.
	 */
	public function test_get_apps_decrypts_legacy_plaintext_rows() {
		update_post_meta(
			$this->assistant_id,
			WP_MCP_AI_MCP_App_Registry::META_KEY,
			array(
				array(
					'label'      => 'legacy',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'bearer',
					'token'      => 'legacy-plain-token',
					'enabled'    => true,
				),
			)
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $this->assistant_id );

		$this->assertSame( 'legacy-plain-token', $apps[0]['token'] );
	}

	/**
	 * Ensure the Remote Site Manager is loaded and its store is clean.
	 *
	 * @return void
	 */
	protected function reset_remote_sites_store() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Save a central OAuth mcp_server connection with expired tokens.
	 *
	 * @return string Connection ID.
	 */
	protected function save_central_oauth_connection() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork MCP',
				'url'             => 'https://example.com/mcp',
				'connection_type' => 'mcp_server',
				'auth_type'       => 'oauth',
				'enabled'         => true,
				'mcp_oauth'       => wp_json_encode(
					array(
						'access_token'  => 'tok-old',
						'refresh_token' => 'ref-old',
						'token_type'    => 'Bearer',
						'expires_in'    => 3600,
						'scope'         => 'jobs.read',
						'issued_at'     => time() - 7200,
						'client_id'     => 'upwork-client-123',
					)
				),
			)
		);

		$this->assertNotWPError( $connection_id );

		return $connection_id;
	}

	/**
	 * The registry routes reference-entry refresh persistence to the central
	 * Remote Sites store and never writes credentials back to post meta.
	 */
	public function test_update_app_oauth_data_routes_reference_to_remote_sites() {
		$this->reset_remote_sites_store();

		$connection_id = $this->save_central_oauth_connection();

		// The assistant holds a reference entry only (no inline credentials).
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'upwork',
					'connection_ref' => $connection_id,
					'auth_type'      => 'oauth',
					'enabled'        => true,
				),
			)
		);

		$updated = $registry->update_app_oauth_data(
			$this->assistant_id,
			'https://example.com/mcp',
			array(
				'access_token'  => 'tok-new',
				'refresh_token' => 'ref-new',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'scope'         => '',
				'issued_at'     => time(),
			),
			$connection_id
		);

		$this->assertTrue( $updated );

		// The central store carries the rotated tokens, still encrypted.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertStringNotContainsString( 'tok-new', (string) $connection['mcp_oauth'] );
		$decoded = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );
		$this->assertSame( 'tok-new', $decoded['access_token'] );
		$this->assertSame( 'ref-new', $decoded['refresh_token'] );
		$this->assertSame( 'jobs.read', $decoded['scope'] );
		$this->assertSame( 'upwork-client-123', $decoded['client_id'] );

		// Assistant post meta carries no credentials.
		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertEmpty( $apps[0]['oauth_data'] );
		$this->assertEmpty( $apps[0]['token'] );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * An automatic refresh on a client built from a central reference
	 * connection persists the rotated tokens back to the encrypted Remote
	 * Sites store.
	 */
	public function test_oauth_refresh_persists_to_remote_sites_store() {
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		$this->reset_remote_sites_store();

		$connection_id = $this->save_central_oauth_connection();
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Runtime config as the registry builds it for a reference app.
		$config                   = WP_MCP_AI_Pro_Remote_Site_Manager::build_mcp_app_config_from_connection( $connection );
		$config['connection_ref'] = $connection_id;

		$captured = array();
		$this->install_oauth_refresh_and_mcp_mock( $captured );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$client   = $registry->create_client( $config );
		$result   = $client->discover();

		$this->assertNotWPError( $result );

		// The refresh request carried the client ID from the central blob.
		$refresh_body = null;
		foreach ( $captured as $call ) {
			if ( false !== strpos( $call['url'], '/oauth/token' ) ) {
				$refresh_body = json_decode( $call['args']['body'], true );
				break;
			}
		}
		$this->assertNotNull( $refresh_body );
		$this->assertSame( 'refresh_token', $refresh_body['grant_type'] );
		$this->assertSame( 'upwork-client-123', $refresh_body['client_id'] );

		// The central store now holds the rotated tokens (encrypted at rest).
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertStringNotContainsString( 'tok-new', (string) $connection['mcp_oauth'] );
		$decoded = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );
		$this->assertSame( 'tok-new', $decoded['access_token'] );
		$this->assertSame( 'ref-new', $decoded['refresh_token'] );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Completing the loopback login from the assistant editor persists the
	 * tokens centrally AND the reference entry onto the assistant, so the
	 * just-added row survives the post-login page reload.
	 */
	public function test_complete_oauth_persists_upwork_reference_entry() {
		$this->reset_remote_sites_store();

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork MCP',
				'url'             => 'https://api.upwork.com/graphql',
				'connection_type' => 'upwork',
				'upwork_mode'     => 'mcp',
				'enabled'         => true,
			)
		);
		$this->assertNotWPError( $connection_id );

		$state = 'upworkrefstate';
		set_transient(
			WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TRANSIENT . $state,
			array(
				'server_url'     => 'https://example.com/mcp',
				'assistant_id'   => $this->assistant_id,
				'connection_ref' => $connection_id,
				'client_id'      => 'upwork-client-123',
				'redirect_uri'   => 'http://localhost:5123/callback',
				'redirect_mode'  => 'manual_loopback',
				'code_verifier'  => 'verifier123',
				'created_at'     => time(),
			),
			WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TTL
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $pre, $args );

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

		// Tokens persist centrally, encrypted at rest.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertStringNotContainsString( 'tok-abc', (string) $connection['mcp_oauth'] );
		$decoded = json_decode( WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['mcp_oauth'] ), true );
		$this->assertSame( 'tok-abc', $decoded['access_token'] );
		$this->assertSame( 'ref-abc', $decoded['refresh_token'] );
		$this->assertSame( 'upwork-client-123', $decoded['client_id'] );

		// The assistant now carries the reference entry — no credentials.
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $this->assistant_id );
		$this->assertCount( 1, $apps );
		$this->assertSame( $connection_id, $apps[0]['connection_ref'] );
		$this->assertSame( 'Upwork MCP', $apps[0]['label'] );
		$this->assertTrue( $apps[0]['enabled'] );
		$this->assertEmpty( $apps[0]['token'] );

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}
}
