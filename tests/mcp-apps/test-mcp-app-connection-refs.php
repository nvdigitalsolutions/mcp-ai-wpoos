<?php
/**
 * Tests for MCP App connection references (Remote Sites integration).
 *
 * Covers:
 *  - sanitize_app_config() / save_apps() keeping reference entries.
 *  - resolve_apps() expanding references from the Remote Sites store.
 *  - Unresolvable references being skipped with a recorded status.
 *  - Status keys distinguishing references from inline entries.
 *  - The import-time reference validator disabling broken references.
 *
 * @package WP_MCP_AI
 * @since   1.1.85
 */

/**
 * MCP App connection reference tests.
 *
 * @since 1.1.85
 */
class Test_MCP_App_Connection_Refs extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Ref Assistant',
				'post_status' => 'publish',
			)
		);

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Seed a global MCP Server connection in the Remote Sites store.
	 *
	 * @return string Connection ID.
	 */
	protected function seed_remote_mcp_connection() {
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Central Elementor',
				'url'             => 'https://example.com/wp-json/mcp/elementor-mcp-server',
				'connection_type' => 'mcp_server',
				'auth_type'       => 'basic_auth',
				'username'        => 'mcp-agent',
				'password'        => 'central-secret',
				'enabled'         => true,
			)
		);

		return $result;
	}

	/**
	 * Entries that only carry a connection_ref survive sanitize_app_config().
	 */
	public function test_sanitize_keeps_reference_entries() {
		$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config(
			array(
				'label'          => 'Elementor',
				'connection_ref' => 'mcp_central',
				'enabled'        => true,
			)
		);

		$this->assertSame( 'mcp_central', $sanitized['connection_ref'] );
		$this->assertSame( 'Elementor', $sanitized['label'] );
	}

	/**
	 * Reference entries survive save_apps() without a server URL.
	 */
	public function test_save_apps_keeps_reference_entries() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$saved = $registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Elementor',
					'connection_ref' => 'mcp_central',
					'enabled'        => true,
				),
			)
		);

		$this->assertTrue( $saved );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertCount( 1, $apps );
		$this->assertSame( 'mcp_central', $apps[0]['connection_ref'] );
	}

	/**
	 * A reference expands into a runtime config with decrypted credentials.
	 */
	public function test_resolve_apps_expands_reference() {
		$connection_id = $this->seed_remote_mcp_connection();

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Elementor',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		$resolved = $registry->resolve_apps( $this->assistant_id );

		$this->assertCount( 1, $resolved );
		$this->assertSame( 'https://example.com/wp-json/mcp/elementor-mcp-server/', $resolved[0]['server_url'] );
		$this->assertSame( 'basic', $resolved[0]['auth_type'] );
		$this->assertSame( 'mcp-agent:central-secret', $resolved[0]['token'] );
		$this->assertSame( 'Elementor', $resolved[0]['label'] );
		$this->assertSame( $connection_id, $resolved[0]['connection_ref'] );

		// Resolved credentials are never written back to post meta.
		$stored = $registry->get_apps( $this->assistant_id );
		$this->assertSame( '', $stored[0]['token'] );
	}

	/**
	 * An unresolvable reference is skipped and recorded as an error status.
	 */
	public function test_resolve_apps_skips_missing_reference() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Ghost',
					'connection_ref' => 'does_not_exist',
					'enabled'        => true,
				),
				array(
					'label'      => 'Inline',
					'server_url' => 'https://example.org/mcp',
					'auth_type'  => 'none',
					'enabled'    => true,
				),
			)
		);

		$resolved = $registry->resolve_apps( $this->assistant_id );

		$this->assertCount( 1, $resolved );
		$this->assertSame( 'https://example.org/mcp', $resolved[0]['server_url'] );

		$statuses = $registry->get_app_status( $this->assistant_id );
		$this->assertNotEmpty( $statuses );

		$found_error = false;
		foreach ( $statuses as $snapshot ) {
			if ( 'error' === $snapshot['last_status'] ) {
				$found_error = true;
			}
		}
		$this->assertTrue( $found_error, 'Expected an error status snapshot for the missing reference.' );
	}

	/**
	 * Status keys distinguish references from inline entries.
	 */
	public function test_status_key_includes_reference() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$ref_key       = $registry->get_app_status_key(
			array(
				'connection_ref' => 'mcp_a',
				'server_url'     => '',
				'auth_type'      => 'none',
				'header_name'    => '',
			)
		);
		$other_ref_key = $registry->get_app_status_key(
			array(
				'connection_ref' => 'mcp_b',
				'server_url'     => '',
				'auth_type'      => 'none',
				'header_name'    => '',
			)
		);

		$this->assertNotSame( $ref_key, $other_ref_key );
	}

	/**
	 * The import validator disables references that cannot be resolved.
	 */
	public function test_import_validator_disables_missing_references() {
		$init_file = WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/mcp-apps-init.php';
		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) && file_exists( $init_file ) ) {
			require_once $init_file;
		}

		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) ) {
			$this->markTestSkipped( 'mcp-apps-init.php not loadable.' );
		}

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Ghost',
					'connection_ref' => 'does_not_exist',
					'enabled'        => true,
				),
			)
		);

		wp_mcp_ai_mcp_apps_validate_imported_refs( $this->assistant_id, array(), false );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertFalse( $apps[0]['enabled'] );
	}

	/**
	 * The import validator leaves resolvable references untouched.
	 */
	public function test_import_validator_keeps_valid_references() {
		$init_file = WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/mcp-apps-init.php';
		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) && file_exists( $init_file ) ) {
			require_once $init_file;
		}

		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) ) {
			$this->markTestSkipped( 'mcp-apps-init.php not loadable.' );
		}

		$connection_id = $this->seed_remote_mcp_connection();

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Elementor',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		wp_mcp_ai_mcp_apps_validate_imported_refs( $this->assistant_id, array(), false );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertTrue( $apps[0]['enabled'] );
	}

	/**
	 * Seed an Upwork connection in MCP mode in the Remote Sites store.
	 *
	 * @return string Connection ID.
	 */
	protected function seed_remote_upwork_mcp_connection() {
		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork MCP',
				'url'             => 'https://api.upwork.com/graphql',
				'connection_type' => 'upwork',
				'upwork_mode'     => 'mcp',
				'enabled'         => true,
			)
		);
	}

	/**
	 * A reference to an Upwork MCP connection resolves against the official
	 * gateway with the decrypted central OAuth blob.
	 */
	public function test_resolve_apps_expands_upwork_mcp_reference() {
		$connection_id = $this->seed_remote_upwork_mcp_connection();
		$this->assertNotWPError( $connection_id );

		WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$connection_id,
			array(
				'access_token'  => 'upwork-access',
				'refresh_token' => 'upwork-refresh',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'issued_at'     => time(),
			)
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Upwork',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		$resolved = $registry->resolve_apps( $this->assistant_id );

		$this->assertCount( 1, $resolved );
		$this->assertSame( 'https://mcp.upwork.com/mcp', $resolved[0]['server_url'] );
		$this->assertSame( 'oauth', $resolved[0]['auth_type'] );
		$this->assertSame( 'upwork-access', $resolved[0]['oauth_data']['access_token'] );
		$this->assertSame( $connection_id, $resolved[0]['connection_ref'] );

		// Resolved credentials are never written back to post meta.
		$stored = $registry->get_apps( $this->assistant_id );
		$this->assertSame( '', $stored[0]['server_url'] );
		$this->assertArrayNotHasKey( 'oauth_data', $stored[0] );
		$this->assertSame( '', $stored[0]['token'] );
	}

	/**
	 * Upwork connections outside MCP mode are not referenceable.
	 */
	public function test_resolve_apps_skips_non_mcp_upwork_reference() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Upwork API',
				'url'             => 'https://api.upwork.com/graphql',
				'connection_type' => 'upwork',
				'upwork_mode'     => 'api',
				'client_id'       => 'cid',
				'client_secret'   => 'csecret',
				'enabled'         => true,
			)
		);
		$this->assertNotWPError( $connection_id );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Upwork API',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		$resolved = $registry->resolve_apps( $this->assistant_id );

		$this->assertCount( 0, $resolved );

		$statuses    = $registry->get_app_status( $this->assistant_id );
		$found_error = false;
		foreach ( $statuses as $snapshot ) {
			if ( 'error' === $snapshot['last_status'] ) {
				$found_error = true;
			}
		}
		$this->assertTrue( $found_error, 'Expected an error status snapshot for the non-MCP Upwork reference.' );
	}

	/**
	 * The import validator keeps Upwork MCP references enabled.
	 */
	public function test_import_validator_keeps_upwork_mcp_references() {
		$init_file = WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/mcp-apps-init.php';
		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) && file_exists( $init_file ) ) {
			require_once $init_file;
		}

		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) ) {
			$this->markTestSkipped( 'mcp-apps-init.php not loadable.' );
		}

		$connection_id = $this->seed_remote_upwork_mcp_connection();
		$this->assertNotWPError( $connection_id );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'Upwork',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		wp_mcp_ai_mcp_apps_validate_imported_refs( $this->assistant_id, array(), false );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertTrue( $apps[0]['enabled'] );
	}

	/**
	 * Seed a FlowHub connection in MCP mode in the Remote Sites store.
	 *
	 * @return string Connection ID.
	 */
	protected function seed_remote_flowhub_mcp_connection() {
		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'FlowHub MCP',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'flowhub_mode'    => 'mcp',
				'enabled'         => true,
			)
		);
	}

	/**
	 * A reference to a FlowHub MCP connection resolves against the official
	 * gateway with the decrypted central OAuth blob.
	 */
	public function test_resolve_apps_expands_flowhub_mcp_reference() {
		$connection_id = $this->seed_remote_flowhub_mcp_connection();
		$this->assertNotWPError( $connection_id );

		WP_MCP_AI_Pro_Remote_Site_Manager::update_mcp_oauth(
			$connection_id,
			array(
				'access_token'  => 'flowhub-access',
				'refresh_token' => 'flowhub-refresh',
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'issued_at'     => time(),
			)
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'FlowHub',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		$resolved = $registry->resolve_apps( $this->assistant_id );

		$this->assertCount( 1, $resolved );
		$this->assertSame( 'https://mcp.flowhub.com', $resolved[0]['server_url'] );
		$this->assertSame( 'oauth', $resolved[0]['auth_type'] );
		$this->assertSame( 'flowhub-access', $resolved[0]['oauth_data']['access_token'] );
		$this->assertSame( $connection_id, $resolved[0]['connection_ref'] );

		// Resolved credentials are never written back to post meta.
		$stored = $registry->get_apps( $this->assistant_id );
		$this->assertSame( '', $stored[0]['server_url'] );
		$this->assertArrayNotHasKey( 'oauth_data', $stored[0] );
		$this->assertSame( '', $stored[0]['token'] );
	}

	/**
	 * FlowHub connections outside MCP mode are not referenceable.
	 */
	public function test_resolve_apps_skips_non_mcp_flowhub_reference() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'FlowHub API',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'flowhub_mode'    => 'api',
				'client_id'       => 'cid',
				'api_key'         => 'key',
				'enabled'         => true,
			)
		);
		$this->assertNotWPError( $connection_id );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'FlowHub API',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		$resolved = $registry->resolve_apps( $this->assistant_id );

		$this->assertCount( 0, $resolved );

		$statuses    = $registry->get_app_status( $this->assistant_id );
		$found_error = false;
		foreach ( $statuses as $snapshot ) {
			if ( 'error' === $snapshot['last_status'] ) {
				$found_error = true;
			}
		}
		$this->assertTrue( $found_error, 'Expected an error status snapshot for the non-MCP FlowHub reference.' );
	}

	/**
	 * The import validator keeps FlowHub MCP references enabled.
	 */
	public function test_import_validator_keeps_flowhub_mcp_references() {
		$init_file = WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/mcp-apps-init.php';
		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) && file_exists( $init_file ) ) {
			require_once $init_file;
		}

		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_validate_imported_refs' ) ) {
			$this->markTestSkipped( 'mcp-apps-init.php not loadable.' );
		}

		$connection_id = $this->seed_remote_flowhub_mcp_connection();
		$this->assertNotWPError( $connection_id );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'FlowHub',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		wp_mcp_ai_mcp_apps_validate_imported_refs( $this->assistant_id, array(), false );

		$apps = $registry->get_apps( $this->assistant_id );
		$this->assertTrue( $apps[0]['enabled'] );
	}

	// -------------------------------------------------------------------------
	// OAuth login flow — proxy inheritance for geo-blocked gateways
	// -------------------------------------------------------------------------

	/**
	 * Seed a FlowHub MCP-mode connection with an outbound proxy.
	 *
	 * @return string Connection ID.
	 */
	protected function seed_remote_flowhub_mcp_connection_with_proxy() {
		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'FlowHub MCP Proxied',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'flowhub_mode'    => 'mcp',
				'proxy_enabled'   => true,
				'proxy_url'       => 'proxy.example.com:8080',
				'proxy_username'  => 'proxyuser',
				'proxy_password'  => 'proxypass',
				'enabled'         => true,
			)
		);
	}

	/**
	 * Load the MCP Apps REST controller + OAuth client classes for flow tests.
	 *
	 * @return void
	 */
	protected function load_oauth_flow_classes() {
		$pro_dir = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : WP_MCP_AI_PATH . 'addons/pro/';
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			require_once $pro_dir . 'includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_REST_MCP_Apps_Controller' ) ) {
			require_once $pro_dir . 'includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php';
		}
	}

	/**
	 * Install a pre_http_request mock for the FlowHub MCP OAuth discovery + DCR
	 * flow (no live HTTP requests are made).
	 *
	 * @param array $captured Reference collecting the requested URLs.
	 * @return void
	 */
	protected function install_flowhub_oauth_mock( &$captured ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				unset( $pre, $args );
				$captured[] = $url;

				if ( false !== strpos( $url, '/.well-known/oauth-authorization-server' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'issuer'                 => 'https://mcp.flowhub.com',
								'authorization_endpoint' => 'https://mcp.flowhub.com/oauth/authorize',
								'token_endpoint'         => 'https://mcp.flowhub.com/oauth/token',
								'registration_endpoint'  => 'https://mcp.flowhub.com/oauth/register',
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( false !== strpos( $url, '/oauth/register' ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'client_id' => 'flowhub-dyn-client',
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
					'body'     => wp_json_encode( new stdClass() ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * The OAuth login flow for a FlowHub reference carries the connection's
	 * proxy into the flow state so the follow-up token exchange routes through
	 * it too (geo-blocked deployments would otherwise get HTTP 403).
	 */
	public function test_oauth_init_inherits_flowhub_connection_proxy() {
		$this->load_oauth_flow_classes();
		$captured      = array();
		$connection_id = $this->seed_remote_flowhub_mcp_connection_with_proxy();
		$this->assertNotWPError( $connection_id );

		$this->install_flowhub_oauth_mock( $captured );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/oauth/init' );
		$request->set_param( 'server_url', 'https://mcp.flowhub.com' );
		$request->set_param( 'connection_ref', $connection_id );

		$response = $controller->initiate_oauth( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['state'] );

		// The stored flow state must carry the resolved proxy credentials so
		// handle_oauth_callback()/complete_oauth() rebuild the OAuth client
		// with them on the token exchange.
		$flow_state = get_transient( WP_MCP_AI_REST_MCP_Apps_Controller::OAUTH_STATE_TRANSIENT . $data['state'] );
		$this->assertIsArray( $flow_state );
		$this->assertSame( 'proxy.example.com:8080', $flow_state['proxy_url'] );
		$this->assertSame( 'proxyuser:proxypass', $flow_state['proxy_auth'] );
		$this->assertSame( $connection_id, $flow_state['connection_ref'] );

		// Discovery and DCR requests were made (through the client seam the
		// proxy applies to at the cURL layer in production).
		$this->assertNotEmpty( $captured );
	}

	/**
	 * The OAuth client options resolver reads the proxy from the connection
	 * referenced by connection_ref.
	 */
	public function test_oauth_client_options_resolve_proxy_from_connection_ref() {
		$this->load_oauth_flow_classes();
		$connection_id = $this->seed_remote_flowhub_mcp_connection_with_proxy();
		$this->assertNotWPError( $connection_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$method     = new ReflectionMethod( $controller, 'get_oauth_client_options' );
		$method->setAccessible( true );

		$options = $method->invokeArgs( $controller, array( 'https://mcp.flowhub.com', $connection_id ) );

		$this->assertSame( 'proxy.example.com:8080', $options['proxy_url'] );
		$this->assertSame( 'proxyuser:proxypass', $options['proxy_auth'] );
		$this->assertSame( 30, $options['timeout'] );
		$this->assertTrue( $options['verify_ssl'] );
	}

	/**
	 * Without an explicit connection_ref the resolver still finds the proxy by
	 * matching the gateway URL against central FlowHub MCP connections.
	 */
	public function test_oauth_client_options_fallback_by_gateway_url() {
		$this->load_oauth_flow_classes();
		$connection_id = $this->seed_remote_flowhub_mcp_connection_with_proxy();
		$this->assertNotWPError( $connection_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$method     = new ReflectionMethod( $controller, 'get_oauth_client_options' );
		$method->setAccessible( true );

		$options = $method->invokeArgs( $controller, array( 'https://mcp.flowhub.com', '' ) );

		$this->assertSame( 'proxy.example.com:8080', $options['proxy_url'] );
		$this->assertSame( 'proxyuser:proxypass', $options['proxy_auth'] );
	}

	/**
	 * Servers that match no central connection get default options without a
	 * proxy.
	 */
	public function test_oauth_client_options_defaults_without_match() {
		$this->load_oauth_flow_classes();

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$method     = new ReflectionMethod( $controller, 'get_oauth_client_options' );
		$method->setAccessible( true );

		$options = $method->invokeArgs( $controller, array( 'https://unrelated.example.com/mcp', '' ) );

		$this->assertArrayNotHasKey( 'proxy_url', $options );
		$this->assertArrayNotHasKey( 'proxy_auth', $options );
		$this->assertSame( 30, $options['timeout'] );
		$this->assertTrue( $options['verify_ssl'] );
	}
}
