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
}
