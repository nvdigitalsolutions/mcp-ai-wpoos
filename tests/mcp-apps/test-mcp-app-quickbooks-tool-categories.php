<?php
/**
 * Tests for the QuickBooks MCP tool-category switches.
 *
 * Covers the WordPress-side mirror of the upstream Intuit reference server's
 * QUICKBOOKS_DISABLE_WRITE / QUICKBOOKS_DISABLE_UPDATE / QUICKBOOKS_DISABLE_DELETE
 * environment flags:
 *
 *  - Persisting the switches on a `quickbooks_mcp` connection.
 *  - Resolving them into `disabled_tool_prefixes` on the app config.
 *  - Whitelisting the honoured prefixes.
 *  - Filtering discovered tools (fresh and cached) and the registry slugs.
 *  - Blocking a suppressed verb at bridge execution time.
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 */

/**
 * QuickBooks MCP tool-category switch tests.
 *
 * @since 1.2.22
 */
class Test_MCP_App_QuickBooks_Tool_Categories extends WP_UnitTestCase {

	/**
	 * Assistant post ID.
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
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Tool_Bridge' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-tool-bridge.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'QuickBooks Assistant',
				'post_status' => 'publish',
			)
		);

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Seed a stored QuickBooks MCP connection.
	 *
	 * @param array $overrides Connection field overrides.
	 * @return string Connection ID.
	 */
	protected function seed_quickbooks_connection( array $overrides = array() ) {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array_merge(
				array(
					'name'            => 'QBO Books',
					'url'             => 'https://qbo-mcp-' . wp_generate_password( 8, false ) . '.example.com/mcp',
					'connection_type' => 'quickbooks_mcp',
					'auth_type'       => 'none',
					'enabled'         => true,
				),
				$overrides
			)
		);

		$this->assertNotWPError( $connection_id );

		return $connection_id;
	}

	/**
	 * Install a pre_http_request mock returning a fixed tools/list payload.
	 *
	 * @param array $tool_names Remote tool names to advertise.
	 * @return void
	 */
	protected function mock_tools_list( array $tool_names ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $tool_names ) {
				unset( $pre, $url );

				$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';

				$result = array( 'serverInfo' => array( 'name' => 'QuickBooks Online MCP' ) );

				if ( 'tools/list' === $method ) {
					$tools = array();
					foreach ( $tool_names as $name ) {
						$tools[] = array(
							'name'        => $name,
							'description' => 'Remote QuickBooks tool.',
							'inputSchema' => array( 'type' => 'object' ),
						);
					}
					$result = array( 'tools' => $tools );
				} elseif ( 'tools/call' === $method ) {
					$result = array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Remote call executed.',
							),
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => $result,
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
	}

	/**
	 * Prefixes resolve from the connection switches, in category order.
	 */
	public function test_prefixes_resolve_from_connection_switches() {
		$prefixes = WP_MCP_AI_Pro_Remote_Site_Manager::get_quickbooks_mcp_disabled_prefixes(
			array(
				'connection_type'           => 'quickbooks_mcp',
				'quickbooks_disable_write'  => true,
				'quickbooks_disable_update' => false,
				'quickbooks_disable_delete' => true,
			)
		);

		$this->assertSame( array( 'create_', 'delete_' ), $prefixes );
	}

	/**
	 * Prefixes resolve from an app config that already carries them.
	 */
	public function test_prefixes_resolve_from_app_config() {
		$prefixes = WP_MCP_AI_Pro_Remote_Site_Manager::get_quickbooks_mcp_disabled_prefixes(
			array( 'disabled_tool_prefixes' => array( 'update_' ) )
		);

		$this->assertSame( array( 'update_' ), $prefixes );
	}

	/**
	 * Only the three documented prefixes are honoured.
	 */
	public function test_prefix_whitelist_rejects_unknown_values() {
		$prefixes = WP_MCP_AI_Pro_Remote_Site_Manager::get_quickbooks_mcp_disabled_prefixes(
			array( 'disabled_tool_prefixes' => array( 'get_', 'create_', 'drop_', '../', '' ) )
		);

		$this->assertSame( array( 'create_' ), $prefixes );
	}

	/**
	 * No switches means no suppressed prefixes.
	 */
	public function test_no_switches_means_no_prefixes() {
		$this->assertSame(
			array(),
			WP_MCP_AI_Pro_Remote_Site_Manager::get_quickbooks_mcp_disabled_prefixes(
				array( 'connection_type' => 'quickbooks_mcp' )
			)
		);
	}

	/**
	 * Suppressed verbs are filtered out; read verbs survive and are re-indexed.
	 */
	public function test_filter_drops_suppressed_verbs_and_keeps_reads() {
		$tools = array(
			array( 'name' => 'create_invoice' ),
			array( 'name' => 'update_invoice' ),
			array( 'name' => 'delete_invoice' ),
			array( 'name' => 'get_invoice' ),
			array( 'name' => 'search_invoices' ),
			array( 'name' => 'read_report' ),
		);

		$filtered = WP_MCP_AI_Pro_Remote_Site_Manager::filter_quickbooks_mcp_tool_definitions(
			$tools,
			array(
				'quickbooks_disable_write'  => true,
				'quickbooks_disable_update' => true,
				'quickbooks_disable_delete' => true,
			)
		);

		$this->assertSame(
			array( 'get_invoice', 'search_invoices', 'read_report' ),
			wp_list_pluck( $filtered, 'name' )
		);
		$this->assertSame( array( 0, 1, 2 ), array_keys( $filtered ) );
	}

	/**
	 * Filters are case-insensitive and leave the list untouched when unset.
	 */
	public function test_filter_is_noop_without_switches() {
		$tools = array(
			array( 'name' => 'CREATE_Invoice' ),
			array( 'name' => 'get_invoice' ),
		);

		$this->assertSame(
			$tools,
			WP_MCP_AI_Pro_Remote_Site_Manager::filter_quickbooks_mcp_tool_definitions( $tools, array() )
		);

		$this->assertSame(
			array( array( 'name' => 'get_invoice' ) ),
			WP_MCP_AI_Pro_Remote_Site_Manager::filter_quickbooks_mcp_tool_definitions(
				$tools,
				array( 'disabled_tool_prefixes' => array( 'create_' ) )
			)
		);
	}

	/**
	 * The app config builder carries the resolved prefixes.
	 */
	public function test_build_config_carries_disabled_prefixes() {
		$config = WP_MCP_AI_Pro_Remote_Site_Manager::build_quickbooks_mcp_app_config(
			array(
				'url'                      => 'https://example.com/mcp',
				'auth_type'                => 'none',
				'quickbooks_disable_write' => true,
			)
		);

		$this->assertSame( array( 'create_' ), $config['disabled_tool_prefixes'] );
	}

	/**
	 * The switches round-trip through the connection store as booleans.
	 */
	public function test_save_connection_persists_category_switches() {
		$connection_id = $this->seed_quickbooks_connection(
			array(
				'quickbooks_disable_write'  => '1',
				'quickbooks_disable_delete' => 1,
			)
		);

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertTrue( $connection['quickbooks_disable_write'] );
		$this->assertFalse( $connection['quickbooks_disable_update'] );
		$this->assertTrue( $connection['quickbooks_disable_delete'] );
	}

	/**
	 * Discovery drops suppressed verbs and records the effective tool count.
	 */
	public function test_discovery_hides_suppressed_verbs() {
		$connection_id = $this->seed_quickbooks_connection(
			array(
				'quickbooks_disable_write'  => true,
				'quickbooks_disable_delete' => true,
			)
		);

		$this->mock_tools_list( array( 'create_invoice', 'delete_invoice', 'get_invoice', 'search_invoices' ) );

		$tools = WP_MCP_AI_Pro_Remote_Site_Manager::discover_mcp_server_tools( $connection_id, true );

		$this->assertNotWPError( $tools );
		$this->assertSame( array( 'get_invoice', 'search_invoices' ), wp_list_pluck( $tools, 'name' ) );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertSame( 2, $connection['mcp_tool_count'] );
	}

	/**
	 * Cached discovery results are filtered on read, so flipping a switch takes
	 * effect without waiting out the transient TTL.
	 */
	public function test_cached_discovery_is_filtered_on_read() {
		$connection_id = $this->seed_quickbooks_connection();

		$this->mock_tools_list( array( 'create_invoice', 'get_invoice' ) );

		// Prime the raw cache with no switches on.
		$initial = WP_MCP_AI_Pro_Remote_Site_Manager::discover_mcp_server_tools( $connection_id, true );
		$this->assertSame( array( 'create_invoice', 'get_invoice' ), wp_list_pluck( $initial, 'name' ) );

		// Flip the switch on the stored connection.
		$connection                             = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$connection['quickbooks_disable_write'] = true;
		$connections                            = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$connections[ $connection_id ]          = $connection;
		update_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME, $connections, false );

		$cached = WP_MCP_AI_Pro_Remote_Site_Manager::discover_mcp_server_tools( $connection_id, false );

		$this->assertNotWPError( $cached );
		$this->assertSame( array( 'get_invoice' ), wp_list_pluck( $cached, 'name' ) );
	}

	/**
	 * Registry slugs exclude suppressed verbs for a connection reference.
	 */
	public function test_registry_slugs_exclude_suppressed_verbs() {
		$connection_id = $this->seed_quickbooks_connection( array( 'quickbooks_disable_write' => true ) );

		$this->mock_tools_list( array( 'create_invoice', 'get_invoice' ) );

		WP_MCP_AI_MCP_App_Registry::get_instance()->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'          => 'QuickBooks',
					'connection_ref' => $connection_id,
					'enabled'        => true,
				),
			)
		);

		$slugs = WP_MCP_AI_MCP_App_Registry::get_instance()->get_remote_tool_slugs( $this->assistant_id );
		$flat  = implode( "\n", $slugs );

		$this->assertStringContainsString( '_get_invoice', $flat );
		$this->assertStringNotContainsString( '_create_invoice', $flat );
	}

	/**
	 * A suppressed verb is rejected at execution time before any network call.
	 */
	public function test_bridge_blocks_suppressed_verb_before_network() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array(
				'name'        => 'create_invoice',
				'inputSchema' => array( 'type' => 'object' ),
			),
			array(
				'server_url'             => 'https://example.com/mcp',
				'auth_type'              => 'none',
				'disabled_tool_prefixes' => array( 'create_' ),
			),
			'QuickBooks'
		);

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$http_calls ) {
				++$http_calls;
				return new WP_Error( 'unexpected_http', 'HTTP must not be reached.' );
			},
			10,
			0
		);

		$result = $bridge->execute( array(), array( 'user_id' => get_current_user_id() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mcp_app_tool_category_disabled', $result->get_error_code() );
		$this->assertSame( 0, $http_calls );
	}

	/**
	 * Read verbs still execute when the write categories are switched off.
	 */
	public function test_bridge_allows_read_verb_when_writes_disabled() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->mock_tools_list( array( 'create_invoice', 'get_invoice' ) );

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array(
				'name'        => 'get_invoice',
				'inputSchema' => array( 'type' => 'object' ),
			),
			array(
				'server_url'             => 'https://example.com/mcp',
				'auth_type'              => 'none',
				'disabled_tool_prefixes' => array( 'create_', 'update_', 'delete_' ),
			),
			'QuickBooks'
		);

		$result = $bridge->execute( array(), array( 'user_id' => get_current_user_id() ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Remote call executed.', $result['message'] );
	}
}
