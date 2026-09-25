<?php
/**
 * Tests for WP_MCP_AI_MCP_App_Registry connection enhancements.
 *
 * Covers:
 *  - basic auth acceptance in sanitize_app_config().
 *  - Connection status persistence, write-skipping, and pruning.
 *  - Security Center setting + filter allowlist merge.
 *  - discover_tools() sessionful fallback.
 *
 * @package WP_MCP_AI
 * @since   1.9.1
 */

/**
 * Tests for WP_MCP_AI_MCP_App_Registry connection enhancements.
 */
class Test_MCP_App_Registry_Connection_Enhancements extends WP_UnitTestCase {

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Snapshot of the settings option taken before an allowlist test mutates it.
	 *
	 * @var array|false|null
	 */
	protected $settings_option_before = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'update_post_metadata' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_mcp_app_allowed_hosts' );

		if ( null !== $this->settings_option_before ) {
			if ( false === $this->settings_option_before ) {
				delete_option( 'wp_mcp_ai_settings' );
			} else {
				update_option( 'wp_mcp_ai_settings', $this->settings_option_before );
			}
			$this->settings_option_before = null;
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		parent::tearDown();
	}

	/**
	 * Store the MCP App Allowed Hosts setting and reset the settings cache.
	 *
	 * @param string $hosts Newline-separated host entries.
	 * @return void
	 */
	protected function set_allowed_hosts_setting( $hosts ) {
		if ( null === $this->settings_option_before ) {
			$this->settings_option_before = get_option( 'wp_mcp_ai_settings', array() );
		}

		$settings                          = is_array( $this->settings_option_before ) ? $this->settings_option_before : array();
		$settings['mcp_app_allowed_hosts'] = $hosts;
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Test sanitize_app_config accepts the basic auth type.
	 */
	public function test_sanitize_accepts_basic_auth() {
		$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'basic',
				'token'      => 'user:pass',
			)
		);

		$this->assertEquals( 'basic', $sanitized['auth_type'] );
		$this->assertEquals( 'user:pass', $sanitized['token'] );
	}

	/**
	 * Test record_app_status / get_app_status roundtrip.
	 */
	public function test_record_and_get_app_status() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$config   = array(
			'server_url'  => 'https://example.com/mcp',
			'auth_type'   => 'bearer',
			'header_name' => '',
		);

		$this->assertTrue(
			$registry->record_app_status(
				$this->assistant_id,
				$config,
				array(
					'last_status' => 'ok',
					'tool_count'  => 3,
					'protocol'    => '2025-11-25',
				)
			)
		);

		$statuses = $registry->get_app_status( $this->assistant_id );
		$key      = $registry->get_app_status_key( $config );

		$this->assertArrayHasKey( $key, $statuses );
		$this->assertEquals( 'ok', $statuses[ $key ]['last_status'] );
		$this->assertEquals( 3, $statuses[ $key ]['tool_count'] );
		$this->assertEquals( '2025-11-25', $statuses[ $key ]['protocol'] );
		$this->assertGreaterThan( 0, $statuses[ $key ]['checked_at'] );
	}

	/**
	 * Test record_app_status skips the meta write when nothing changed.
	 */
	public function test_record_app_status_skips_unchanged_write() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$config   = array(
			'server_url' => 'https://example.com/mcp',
			'auth_type'  => 'bearer',
		);

		$writes = 0;
		add_filter(
			'update_post_metadata',
			function ( $check, $object_id, $meta_key, $meta_value, $prev_value ) use ( &$writes ) {
				unset( $meta_value, $prev_value );
				if ( WP_MCP_AI_MCP_App_Registry::STATUS_META_KEY === $meta_key ) {
					$writes++;
				}
				return $check;
			},
			10,
			5
		);

		$status = array(
			'last_status' => 'ok',
			'tool_count'  => 1,
		);

		$registry->record_app_status( $this->assistant_id, $config, $status );
		$registry->record_app_status( $this->assistant_id, $config, $status );

		$this->assertSame( 1, $writes, 'An identical status snapshot must not rewrite post meta.' );

		// A changed snapshot must write again.
		$status['tool_count'] = 2;
		$registry->record_app_status( $this->assistant_id, $config, $status );

		$this->assertSame( 2, $writes );
	}

	/**
	 * Test save_apps prunes status entries for removed apps.
	 */
	public function test_save_apps_prunes_removed_statuses() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$removed = array(
			'server_url' => 'https://removed.example.com/mcp',
			'auth_type'  => 'none',
		);
		$kept    = array(
			'server_url' => 'https://kept.example.com/mcp',
			'auth_type'  => 'none',
		);

		$registry->record_app_status(
			$this->assistant_id,
			$removed,
			array(
				'last_status' => 'ok',
				'tool_count'  => 5,
			)
		);

		$registry->save_apps( $this->assistant_id, array( $kept ) );

		$statuses = $registry->get_app_status( $this->assistant_id );

		$this->assertArrayNotHasKey( $registry->get_app_status_key( $removed ), $statuses );
		$this->assertArrayNotHasKey( $registry->get_app_status_key( $kept ), $statuses, 'No status was ever recorded for the kept app.' );
	}

	/**
	 * Test the Security Center setting feeds the allowlist and blocks other hosts.
	 */
	public function test_allowlist_reads_security_center_setting() {
		$this->set_allowed_hosts_setting( "mcp.example.com\n*.corp.example.com" );

		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/mcp' )
		);
		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://api.corp.example.com/mcp' ),
			'A *. wildcard entry must match single subdomains.'
		);

		$blocked = WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://evil.example.net/mcp' );
		$this->assertWPError( $blocked );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_url_not_allowed', $blocked->get_error_code() );
	}

	/**
	 * Test the filter output and the saved setting are merged.
	 */
	public function test_allowlist_merges_filter_and_setting() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'filter.example.com' );
			}
		);

		$this->set_allowed_hosts_setting( "mcp.example.com\n" );

		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://filter.example.com/mcp' ),
			'Hosts from the filter must remain allowed.'
		);
		$this->assertTrue(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/mcp' ),
			'Hosts from the saved setting must be allowed.'
		);
		$this->assertWPError(
			WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://other.example.net/mcp' )
		);
	}

	/**
	 * Test discover_tools falls back to initialize for sessionful servers.
	 */
	public function test_discover_tools_sessionful_fallback() {
		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requests ) {
				unset( $pre, $url );
				$payload    = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method     = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';
				$requests[] = $method;

				if ( 'server/discover' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'error'   => array(
									'code'    => -32601,
									'message' => 'Method not found',
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( 'initialize' === $method ) {
					return array(
						'headers'  => array( 'mcp-session-id' => 'sess-registry' ),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'result'  => array(
									'protocolVersion' => '2025-11-25',
									'serverInfo'      => array( 'name' => 'Elementor MCP' ),
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

				if ( 'tools/list' === $method ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode(
							array(
								'jsonrpc' => '2.0',
								'id'      => 1,
								'result'  => array(
									'tools' => array(
										array( 'name' => 'read_page' ),
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

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$tools    = $registry->discover_tools(
			array(
				'server_url' => 'https://example.com/mcp',
				'auth_type'  => 'none',
			),
			true
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $tools );
		$this->assertCount( 1, $tools );
		$this->assertEquals( 'read_page', $tools[0]['name'] );
		$this->assertContains( 'server/discover', $requests );
		$this->assertContains( 'initialize', $requests );
		$this->assertContains( 'tools/list', $requests );
	}
}
