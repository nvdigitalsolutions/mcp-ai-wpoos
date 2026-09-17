<?php
/**
 * FlowHub Connection Helper Tests.
 *
 * @package WP_MCP_AI
 * @since 1.1.81
 */

/**
 * Test class for the FlowHub connection resolution helper.
 *
 * Covers the credential-source resolution contract shared by the base and
 * Pro FlowHub tools: explicit connection argument → toolkit settings →
 * configured sync connections → first enabled FlowHub connection.
 */
class Test_FlowHub_Connection_Helper extends WP_UnitTestCase {

	/**
	 * Load the helper under test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Connection_Helper' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-connection-helper.php';
		}

		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Clean up persisted connections and settings.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	/**
	 * Create and save an enabled FlowHub connection.
	 *
	 * @param array $overrides Optional overrides for the connection record.
	 * @return string Connection ID.
	 */
	protected function create_flowhub_connection( $overrides = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		$data = array_merge(
			array(
				'name'            => 'Test FlowHub',
				'url'             => 'https://api.flowhub.co',
				'connection_type' => 'flowhub',
				'auth_type'       => 'none',
				'client_id'       => 'test_client_id',
				'api_key'         => 'test_api_key',
				'enabled'         => true,
			),
			$overrides
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );

		$this->assertNotWPError( $connection_id, 'Connection should save cleanly.' );

		return $connection_id;
	}

	// ------------------------------------------------------------------ //
	// Resolution order
	// ------------------------------------------------------------------ //

	/**
	 * Nothing configured should return a missing-credentials error.
	 */
	public function test_resolve_connection_errors_when_nothing_configured() {
		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection();

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_flowhub_missing_credentials', $resolved->get_error_code() );
	}

	/**
	 * Toolkit settings credentials resolve to settings mode.
	 */
	public function test_resolve_connection_uses_settings_when_configured() {
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'client_id'   => 'settings_client',
				'api_key'     => 'settings_key',
				'location_id' => 'loc_1',
			)
		);

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection();

		$this->assertIsArray( $resolved );
		$this->assertSame( '', $resolved['connection_id'] );
		$this->assertSame( 'settings', $resolved['source'] );
		$this->assertSame( 'settings_client', $resolved['credentials']['client_id'] );
		$this->assertSame( 'settings_key', $resolved['credentials']['api_key'] );
		$this->assertSame( 'loc_1', $resolved['credentials']['location_id'] );
	}

	/**
	 * An explicit connection ID resolves to that connection with decrypted credentials.
	 */
	public function test_resolve_connection_uses_explicit_connection() {
		$connection_id = $this->create_flowhub_connection();
		$resolved      = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		$this->assertIsArray( $resolved );
		$this->assertSame( $connection_id, $resolved['connection_id'] );
		$this->assertSame( 'connection', $resolved['source'] );
		$this->assertSame( 'test_client_id', $resolved['credentials']['client_id'] );
		$this->assertSame( 'test_api_key', $resolved['credentials']['api_key'] );
	}

	/**
	 * With settings empty, the first enabled FlowHub connection resolves automatically.
	 */
	public function test_resolve_connection_auto_resolves_first_enabled_connection() {
		$connection_id = $this->create_flowhub_connection();
		$resolved      = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection();

		$this->assertIsArray( $resolved );
		$this->assertSame( $connection_id, $resolved['connection_id'] );
		$this->assertSame( 'connection', $resolved['source'] );
	}

	/**
	 * Configured sync connections win over the first-enabled fallback.
	 */
	public function test_resolve_connection_prefers_sync_connections() {
		$this->create_flowhub_connection(); // First enabled connection (not selected).
		$sync_connection = $this->create_flowhub_connection(
			array(
				'name'      => 'Sync FlowHub',
				'client_id' => 'sync_client_id',
			)
		);

		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array( 'sync_connections' => array( $sync_connection ) )
		);

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection();

		$this->assertIsArray( $resolved );
		$this->assertSame( $sync_connection, $resolved['connection_id'] );
		$this->assertSame( 'sync_connection', $resolved['source'] );
		$this->assertSame( 'sync_client_id', $resolved['credentials']['client_id'] );
	}

	/**
	 * Disabled connections never auto-resolve.
	 */
	public function test_resolve_connection_skips_disabled_connections() {
		$this->create_flowhub_connection( array( 'enabled' => false ) );

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection();

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_flowhub_missing_credentials', $resolved->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// Validation
	// ------------------------------------------------------------------ //

	/**
	 * An unknown connection ID surfaces the not-found error.
	 */
	public function test_resolve_connection_rejects_unknown_id() {
		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( 'conn_does_not_exist' );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_pro_connection_not_found', $resolved->get_error_code() );
	}

	/**
	 * A non-FlowHub connection is rejected with the wrong-type error.
	 */
	public function test_resolve_connection_rejects_wrong_connection_type() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Not FlowHub',
				'url'             => 'https://example.com',
				'connection_type' => 'wordpress',
				'auth_type'       => 'none',
				'enabled'         => true,
			)
		);

		$this->assertNotWPError( $connection_id );

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_pro_wrong_connection_type', $resolved->get_error_code() );
	}

	/**
	 * An explicitly supplied disabled connection is rejected.
	 */
	public function test_resolve_connection_rejects_disabled_explicit_connection() {
		$connection_id = $this->create_flowhub_connection( array( 'enabled' => false ) );

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_pro_connection_disabled', $resolved->get_error_code() );
	}

	/**
	 * A connection missing its credentials is rejected.
	 */
	public function test_resolve_connection_rejects_connection_without_credentials() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro Remote Site Manager not available' );
		}

		// save_connection() validates credentials, so seed the option directly
		// to simulate a legacy/corrupted connection record missing its keys.
		$connection_id = 'conn_nocreds_test';
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				$connection_id => array(
					'name'            => 'No Creds FlowHub',
					'url'             => 'https://api.flowhub.co',
					'connection_type' => 'flowhub',
					'auth_type'       => 'none',
					'client_id'       => '',
					'api_key'         => '',
					'enabled'         => true,
				),
			)
		);

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		$this->assertWPError( $resolved );
		$this->assertSame( 'wp_mcp_ai_flowhub_missing_credentials', $resolved->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// Proxy resolution
	// ------------------------------------------------------------------ //

	/**
	 * Connection proxy resolves with decrypted password auth.
	 */
	public function test_resolve_proxy_from_connection() {
		$connection_id = $this->create_flowhub_connection(
			array(
				'proxy_enabled'  => true,
				'proxy_url'      => 'http://proxy.test:3128',
				'proxy_username' => 'puser',
				'proxy_password' => 'ppass',
			)
		);

		$proxy = WP_MCP_AI_FlowHub_Connection_Helper::resolve_proxy( $connection_id );

		$this->assertSame( 'http://proxy.test:3128', $proxy['url'] );
		$this->assertSame( 'puser:ppass', $proxy['auth'] );
	}

	/**
	 * Proxy falls back to toolkit settings when the connection has none.
	 */
	public function test_resolve_proxy_falls_back_to_toolkit_settings() {
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'proxy_enabled'  => true,
				'proxy_url'      => 'http://settings-proxy:3128',
				'proxy_username' => 'suser',
				'proxy_password' => 'spass',
			)
		);

		$proxy = WP_MCP_AI_FlowHub_Connection_Helper::resolve_proxy();

		$this->assertSame( 'http://settings-proxy:3128', $proxy['url'] );
		$this->assertSame( 'suser:spass', $proxy['auth'] );
	}

	/**
	 * The connection proxy wins over the toolkit settings fallback.
	 */
	public function test_resolve_proxy_prefers_connection_over_settings() {
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'proxy_enabled' => true,
				'proxy_url'     => 'http://settings-proxy:3128',
			)
		);

		$connection_id = $this->create_flowhub_connection(
			array(
				'proxy_enabled' => true,
				'proxy_url'     => 'http://connection-proxy:8080',
			)
		);

		$proxy = WP_MCP_AI_FlowHub_Connection_Helper::resolve_proxy( $connection_id );

		$this->assertSame( 'http://connection-proxy:8080', $proxy['url'] );
	}

	/**
	 * Proxy is empty when neither the connection nor settings configure one.
	 */
	public function test_resolve_proxy_empty_when_unconfigured() {
		$proxy = WP_MCP_AI_FlowHub_Connection_Helper::resolve_proxy();

		$this->assertSame( '', $proxy['url'] );
		$this->assertSame( '', $proxy['auth'] );
	}

	/**
	 * The resolved connection array carries the connection's proxy.
	 */
	public function test_resolve_connection_includes_connection_proxy() {
		$connection_id = $this->create_flowhub_connection(
			array(
				'proxy_enabled' => true,
				'proxy_url'     => 'http://proxy.test:3128',
			)
		);

		$resolved = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		$this->assertIsArray( $resolved );
		$this->assertArrayHasKey( 'proxy', $resolved );
		$this->assertSame( 'http://proxy.test:3128', $resolved['proxy']['url'] );
	}

	// ------------------------------------------------------------------ //
	// is_configured()
	// ------------------------------------------------------------------ //

	/**
	 * Is configured: true when toolkit settings hold credentials.
	 */
	public function test_is_configured_true_with_settings() {
		update_option(
			'wp_mcp_ai_flowhub_toolkit_settings',
			array(
				'client_id' => 'settings_client',
				'api_key'   => 'settings_key',
			)
		);

		$this->assertTrue( WP_MCP_AI_FlowHub_Connection_Helper::is_configured() );
	}

	/**
	 * Is configured: true when a usable connection exists.
	 */
	public function test_is_configured_true_with_connection() {
		$this->create_flowhub_connection();

		$this->assertTrue( WP_MCP_AI_FlowHub_Connection_Helper::is_configured() );
	}

	/**
	 * Is configured: false when no credentials exist anywhere.
	 */
	public function test_is_configured_false_when_nothing_exists() {
		$this->assertFalse( WP_MCP_AI_FlowHub_Connection_Helper::is_configured() );
	}
}
