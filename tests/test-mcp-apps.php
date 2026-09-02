<?php
/**
 * Tests for MCP Apps infrastructure.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 */

/**
 * Mock base tool interface for tests.
 */
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	interface WP_MCP_AI_Tool_Interface {
		public function get_slug();
		public function get_name();
		public function get_description();
		public function get_parameters_schema();
		public function execute( array $arguments, array $context );
	}
}

if ( ! interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) ) {
	interface WP_MCP_AI_Tool_Capability_Flags_Interface {
		public function get_capability_flags();
	}
}

if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	trait WP_MCP_AI_Tool_Chat_Response {}
}

/**
 * Tests for WP_MCP_AI_MCP_App_Client.
 */
class Test_MCP_App_Client extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
	}

	/**
	 * Test constructor with default config.
	 */
	public function test_constructor_defaults() {
		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp',
			)
		);

		$this->assertEquals( 'https://example.com/mcp', $client->get_server_url() );
	}

	/**
	 * Test constructor sanitizes URL.
	 */
	public function test_constructor_sanitizes_url() {
		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://example.com/mcp?foo=bar&baz=qux',
			)
		);

		$url = $client->get_server_url();
		$this->assertStringStartsWith( 'https://example.com/mcp', $url );
	}

	/**
	 * Test test_connection returns error for empty URL.
	 */
	public function test_test_connection_empty_url() {
		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => '',
			)
		);

		$result = $client->test_connection();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_missing_url', $result->get_error_code() );
	}

	/**
	 * Test test_connection returns error for invalid scheme.
	 */
	public function test_test_connection_invalid_scheme() {
		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'ftp://example.com/mcp',
			)
		);

		$result = $client->test_connection();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_mcp_app_invalid_scheme', $result->get_error_code() );
	}

	/**
	 * Test initialize returns error for unreachable server.
	 */
	public function test_initialize_unreachable_server() {
		$client = new WP_MCP_AI_MCP_App_Client(
			array(
				'server_url' => 'https://nonexistent-mcp-server-test.invalid/mcp',
				'timeout'    => 2,
			)
		);

		$result = $client->initialize();
		$this->assertWPError( $result );
	}

	/**
	 * Test protocol version constant.
	 */
	public function test_protocol_version() {
		$this->assertEquals( '2026-07-28', WP_MCP_AI_MCP_App_Client::PROTOCOL_VERSION );
	}

	/**
	 * Test max response size constant.
	 */
	public function test_max_response_size() {
		$this->assertEquals( 2097152, WP_MCP_AI_MCP_App_Client::MAX_RESPONSE_SIZE );
	}
}

/**
 * Tests for WP_MCP_AI_MCP_App_Registry.
 */
class Test_MCP_App_Registry extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Tool_Bridge' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-tool-bridge.php';
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
	 * Test singleton instance.
	 */
	public function test_singleton() {
		$instance1 = WP_MCP_AI_MCP_App_Registry::get_instance();
		$instance2 = WP_MCP_AI_MCP_App_Registry::get_instance();

		$this->assertSame( $instance1, $instance2 );
		$this->assertInstanceOf( 'WP_MCP_AI_MCP_App_Registry', $instance1 );
	}

	/**
	 * Test get_apps returns empty array for unconfigured assistant.
	 */
	public function test_get_apps_empty() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$apps     = $registry->get_apps( $this->assistant_id );

		$this->assertIsArray( $apps );
		$this->assertEmpty( $apps );
	}

	/**
	 * Test save_apps and get_apps roundtrip.
	 */
	public function test_save_and_get_apps() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$apps = array(
			array(
				'label'       => 'Test App',
				'server_url'  => 'https://example.com/mcp',
				'auth_type'   => 'bearer',
				'token'       => 'test-token-123',
				'header_name' => '',
				'enabled'     => true,
				'timeout'     => 30,
				'verify_ssl'  => true,
			),
		);

		$result = $registry->save_apps( $this->assistant_id, $apps );
		$this->assertTrue( $result );

		$saved_apps = $registry->get_apps( $this->assistant_id );
		$this->assertCount( 1, $saved_apps );
		$this->assertEquals( 'Test App', $saved_apps[0]['label'] );
		$this->assertEquals( 'https://example.com/mcp', $saved_apps[0]['server_url'] );
		$this->assertEquals( 'bearer', $saved_apps[0]['auth_type'] );
	}

	/**
	 * Test save_apps with empty array deletes meta.
	 */
	public function test_save_empty_apps_deletes_meta() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		// Save first.
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'To Remove',
					'server_url' => 'https://example.com/mcp',
				),
			)
		);

		$this->assertNotEmpty( $registry->get_apps( $this->assistant_id ) );

		// Save empty.
		$registry->save_apps( $this->assistant_id, array() );

		$this->assertEmpty( $registry->get_apps( $this->assistant_id ) );
	}

	/**
	 * Test max apps per assistant limit.
	 */
	public function test_max_apps_limit() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$apps = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$apps[] = array(
				'label'      => 'App ' . $i,
				'server_url' => 'https://example' . $i . '.com/mcp',
			);
		}

		$registry->save_apps( $this->assistant_id, $apps );

		$saved_apps = $registry->get_apps( $this->assistant_id );
		$this->assertLessThanOrEqual( 10, count( $saved_apps ) );
	}

	/**
	 * Test sanitize_app_config.
	 */
	public function test_sanitize_app_config() {
		$raw = array(
			'label'       => '<script>alert("xss")</script>My App',
			'server_url'  => 'https://example.com/mcp?foo=bar',
			'auth_type'   => 'bearer',
			'token'       => 'secret-token',
			'header_name' => '',
			'enabled'     => '1',
			'timeout'     => '45',
			'verify_ssl'  => '0',
		);

		$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $raw );

		$this->assertStringNotContainsString( '<script>', $sanitized['label'] );
		$this->assertEquals( 'bearer', $sanitized['auth_type'] );
		$this->assertTrue( $sanitized['enabled'] );
		$this->assertEquals( 45, $sanitized['timeout'] );
		$this->assertFalse( $sanitized['verify_ssl'] );
	}

	/**
	 * Test sanitize_app_config rejects invalid auth type.
	 */
	public function test_sanitize_invalid_auth_type() {
		$raw = array(
			'server_url' => 'https://example.com/mcp',
			'auth_type'  => 'invalid_type',
		);

		$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $raw );

		$this->assertEquals( 'none', $sanitized['auth_type'] );
	}

	/**
	 * Test sanitize_app_config clamps timeout.
	 */
	public function test_sanitize_timeout_clamp() {
		$raw_low = array(
			'server_url' => 'https://example.com/mcp',
			'timeout'    => 0,
		);

		$raw_high = array(
			'server_url' => 'https://example.com/mcp',
			'timeout'    => 999,
		);

		$sanitized_low  = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $raw_low );
		$sanitized_high = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( $raw_high );

		$this->assertGreaterThanOrEqual( 1, $sanitized_low['timeout'] );
		$this->assertLessThanOrEqual( 120, $sanitized_high['timeout'] );
	}

	/**
	 * Test get_apps with invalid assistant ID.
	 */
	public function test_get_apps_invalid_id() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();

		$this->assertEmpty( $registry->get_apps( 0 ) );
		$this->assertEmpty( $registry->get_apps( -1 ) );
	}

	/**
	 * Test save_apps with invalid assistant ID.
	 */
	public function test_save_apps_invalid_id() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$result   = $registry->save_apps( 0, array() );

		$this->assertFalse( $result );
	}

	/**
	 * Test sanitize_app_config with non-array input.
	 */
	public function test_sanitize_non_array() {
		$sanitized = WP_MCP_AI_MCP_App_Registry::sanitize_app_config( 'not an array' );
		$this->assertIsArray( $sanitized );
		$this->assertEmpty( $sanitized );
	}

	/**
	 * Test META_KEY constant.
	 */
	public function test_meta_key() {
		$this->assertEquals( '_wp_mcp_ai_mcp_apps', WP_MCP_AI_MCP_App_Registry::META_KEY );
	}
}

/**
 * Tests for WP_MCP_AI_MCP_App_Tool_Bridge.
 */
class Test_MCP_App_Tool_Bridge extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Tool_Bridge' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-tool-bridge.php';
		}
	}

	/**
	 * Test tool bridge creation with basic tool definition.
	 */
	public function test_create_bridge_basic() {
		$remote_tool = array(
			'name'        => 'get_weather',
			'description' => 'Get weather for a location',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'location' => array( 'type' => 'string' ),
				),
			),
		);

		$app_config = array(
			'server_url' => 'https://example.com/mcp',
			'auth_type'  => 'none',
		);

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $app_config, 'Weather App' );

		$this->assertStringContainsString( 'mcp_app_', $bridge->get_slug() );
		$this->assertStringContainsString( 'get_weather', $bridge->get_slug() );
		$this->assertStringContainsString( 'Weather App', $bridge->get_name() );
		$this->assertStringContainsString( 'Weather App', $bridge->get_description() );
		$this->assertEquals( 'get_weather', $bridge->get_remote_tool_name() );
	}

	/**
	 * Test tool bridge extracts UI resource URI from _meta.ui.resourceUri.
	 */
	public function test_ui_resource_uri_standard() {
		$remote_tool = array(
			'name'        => 'show_dashboard',
			'description' => 'Show the dashboard',
			'inputSchema' => array( 'type' => 'object' ),
			'_meta'       => array(
				'ui' => array(
					'resourceUri' => 'ui://weather-server/dashboard',
				),
			),
		);

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, array(), 'Dashboard' );

		$this->assertEquals( 'ui://weather-server/dashboard', $bridge->get_ui_resource_uri() );
	}

	/**
	 * Test tool bridge extracts UI resource URI from deprecated flat format.
	 */
	public function test_ui_resource_uri_deprecated_format() {
		$remote_tool = array(
			'name'        => 'show_dashboard',
			'description' => 'Show the dashboard',
			'inputSchema' => array( 'type' => 'object' ),
			'_meta'       => array(
				'ui/resourceUri' => 'ui://old-format/dashboard',
			),
		);

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, array(), 'Dashboard' );

		$this->assertEquals( 'ui://old-format/dashboard', $bridge->get_ui_resource_uri() );
	}

	/**
	 * Test tool bridge with no UI resource.
	 */
	public function test_no_ui_resource() {
		$remote_tool = array(
			'name'        => 'basic_tool',
			'description' => 'A basic tool',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, array(), 'Basic' );

		$this->assertEmpty( $bridge->get_ui_resource_uri() );
	}

	/**
	 * Test get_definition returns expected structure.
	 */
	public function test_get_definition() {
		$remote_tool = array(
			'name'        => 'test_tool',
			'description' => 'Test tool description',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$bridge    = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, array(), 'Test' );
		$definition = $bridge->get_definition();

		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertEquals( 'mcp_apps', $definition['toolkit'] );
		$this->assertEquals( 'medium', $definition['risk_level'] );
	}

	/**
	 * Test get_capability_flags.
	 */
	public function test_capability_flags() {
		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array( 'name' => 'test', 'inputSchema' => array( 'type' => 'object' ) ),
			array(),
			'Test'
		);

		$flags = $bridge->get_capability_flags();

		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test execute requires permissions.
	 */
	public function test_execute_requires_permissions() {
		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array( 'name' => 'test', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'server_url' => 'https://example.com/mcp' ),
			'Test'
		);

		// Execute as a subscriber (no edit_posts capability).
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $bridge->execute(
			array(),
			array( 'user_id' => $subscriber )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test get_parameters_schema returns remote schema.
	 */
	public function test_get_parameters_schema() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'query' => array( 'type' => 'string' ),
			),
			'required' => array( 'query' ),
		);

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array( 'name' => 'search', 'inputSchema' => $schema ),
			array(),
			'Search'
		);

		$this->assertEquals( $schema, $bridge->get_parameters_schema() );
	}

	/**
	 * Test get_app_config returns config.
	 */
	public function test_get_app_config() {
		$config = array(
			'server_url' => 'https://example.com/mcp',
			'auth_type'  => 'bearer',
			'token'      => 'secret',
		);

		$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge(
			array( 'name' => 'test', 'inputSchema' => array( 'type' => 'object' ) ),
			$config,
			'Test'
		);

		$this->assertEquals( $config, $bridge->get_app_config() );
	}
}

/**
 * Tests for the MCP App URL allowlist (R-S-14, F-AI-03).
 *
 * @group mcp-apps
 * @group security
 */
class Test_MCP_App_Allowlist extends WP_UnitTestCase {

	/**
	 * Load registry class.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
	}

	/**
	 * Reset filters between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_mcp_app_allowed_hosts' );
		parent::tearDown();
	}

	public function test_rejects_empty_url() {
		$result = WP_MCP_AI_MCP_App_Registry::is_url_allowed( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mcp_app_url_empty', $result->get_error_code() );
	}

	public function test_rejects_non_http_scheme() {
		foreach ( array( 'javascript:alert(1)', 'data:text/html,foo', 'file:///etc/passwd', 'ftp://example.com/' ) as $url ) {
			$result = WP_MCP_AI_MCP_App_Registry::is_url_allowed( $url );
			$this->assertWPError( $result, "Should reject $url" );
			$this->assertContains(
				$result->get_error_code(),
				array( 'wp_mcp_ai_mcp_app_url_scheme', 'wp_mcp_ai_mcp_app_url_malformed' ),
				"URL $url returned unexpected code " . $result->get_error_code()
			);
		}
	}

	public function test_rejects_malformed_url() {
		$result = WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'not-a-url' );
		$this->assertWPError( $result );
	}

	public function test_permissive_when_allowlist_empty() {
		// No filter, no constant: any well-formed http(s) URL is allowed.
		$this->assertTrue( WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://example.com/mcp' ) );
		$this->assertTrue( WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'http://localhost:3000/mcp' ) );
	}

	public function test_filter_allowlist_exact_match() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'mcp.example.com' );
			}
		);

		$this->assertTrue( WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/path' ) );
	}

	public function test_filter_allowlist_rejects_other_hosts() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'mcp.example.com' );
			}
		);

		$result = WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://evil.example.org/path' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mcp_app_url_not_allowed', $result->get_error_code() );
	}

	public function test_filter_allowlist_case_insensitive() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'MCP.Example.COM' );
			}
		);

		$this->assertTrue( WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/path' ) );
	}

	public function test_wildcard_matches_subdomain() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( '*.example.com' );
			}
		);

		$this->assertTrue( WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://api.example.com/mcp' ) );
		$this->assertTrue( WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://mcp.example.com/' ) );
	}

	public function test_wildcard_does_not_match_apex_or_other_domain() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( '*.example.com' );
			}
		);

		$apex = WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://example.com/' );
		$this->assertWPError( $apex, 'Wildcard *.example.com must not match apex example.com' );

		$other = WP_MCP_AI_MCP_App_Registry::is_url_allowed( 'https://api.example.org/' );
		$this->assertWPError( $other );
	}

	public function test_sanitize_app_config_drops_disallowed_url() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'mcp.allowed.example' );
			}
		);

		$cfg = WP_MCP_AI_MCP_App_Registry::sanitize_app_config(
			array(
				'label'      => 'Bad app',
				'server_url' => 'https://attacker.example/mcp',
			)
		);

		$this->assertSame( '', $cfg['server_url'], 'Disallowed URLs must be cleared at sanitize time' );
	}

	public function test_sanitize_app_config_keeps_allowed_url() {
		add_filter(
			'wp_mcp_ai_mcp_app_allowed_hosts',
			static function () {
				return array( 'mcp.allowed.example' );
			}
		);

		$cfg = WP_MCP_AI_MCP_App_Registry::sanitize_app_config(
			array(
				'label'      => 'Good app',
				'server_url' => 'https://mcp.allowed.example/path',
			)
		);

		$this->assertSame( 'https://mcp.allowed.example/path', $cfg['server_url'] );
	}
}
