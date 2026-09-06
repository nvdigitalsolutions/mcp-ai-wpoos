<?php
/**
 * Test NV oOS Addons Admin Page AJAX Handler
 *
 * Verifies the addon registry, status resolution, and the
 * wp_mcp_ai_install_addon AJAX endpoint:
 * - Nonce verification.
 * - install_plugins capability gate.
 * - Allowlist lookup (unknown slugs are rejected).
 * - External components are not installable.
 * - Installed-but-inactive addons are activated.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for the Addons page AJAX handler.
 */
class Test_Addons_Page_Ajax extends WP_Ajax_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Addons page instance.
	 *
	 * @var WP_MCP_AI_Addons_Page
	 */
	private $page;

	/**
	 * Dummy plugin dir created for the activate test.
	 *
	 * @var string
	 */
	private $dummy_plugin_dir = '';

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the page class (its constructor registers hooks only).
		$file = WP_MCP_AI_PATH . 'addons/pro/includes/admin/class-wp-mcp-ai-addons-page.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		if ( class_exists( 'WP_MCP_AI_Addons_Page' ) ) {
			$this->page = new WP_MCP_AI_Addons_Page();
		}
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Remove any dummy plugin created during the activate test.
		if ( '' !== $this->dummy_plugin_dir ) {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( array( 'nvoos-algorave/nvoos-algorave.php' ) );
			}
			$this->remove_dummy_plugin_dir();
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( true );
			}
			$this->dummy_plugin_dir = '';
		}

		parent::tearDown();
	}

	/**
	 * Dispatch the install AJAX handler and return the decoded response.
	 *
	 * @param string $nonce  Nonce value.
	 * @param string $addon  Addon slug.
	 * @param bool   $is_admin Whether the current user is an admin.
	 * @return array Decoded JSON response.
	 */
	private function dispatch( $nonce, $addon, $is_admin = true ) {
		if ( $is_admin ) {
			wp_set_current_user( $this->admin_id );
		} else {
			wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		}

		$_POST['nonce'] = $nonce;
		$_POST['addon'] = $addon;

		$this->_last_response = '';

		try {
			$this->_handleAjax( 'wp_mcp_ai_install_addon' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected — wp_send_json_* exits.
		} catch ( WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected.
		}

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Create a minimal dummy plugin at WP_PLUGIN_DIR/nvoos-algorave/.
	 */
	private function create_dummy_plugin() {
		$this->dummy_plugin_dir = WP_PLUGIN_DIR . '/nvoos-algorave';
		wp_mkdir_p( $this->dummy_plugin_dir );

		$plugin_content = "<?php\n/**\n * Plugin Name: NV oOS Algorave Addon (Test)\n * Version: 0.0.1\n */\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture in the test plugins dir.
		file_put_contents( $this->dummy_plugin_dir . '/nvoos-algorave.php', $plugin_content );

		wp_clean_plugins_cache( true );
	}

	/**
	 * Delete the dummy plugin directory.
	 */
	private function remove_dummy_plugin_dir() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture cleanup; @ suppresses errors on missing files.
		@unlink( $this->dummy_plugin_dir . '/nvoos-algorave.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture cleanup.
		@rmdir( $this->dummy_plugin_dir );
	}

	/**
	 * Test that the page class exists and the registry is populated.
	 */
	public function test_class_exists_and_registry_populated() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Addons_Page' ), 'WP_MCP_AI_Addons_Page class should exist' );
		$this->assertNotNull( $this->page, 'Addons page should be instantiable' );

		$definitions = $this->page->get_addon_definitions();
		$this->assertIsArray( $definitions );
		$this->assertArrayHasKey( 'canvas', $definitions, 'Registry should contain canvas' );
		$this->assertArrayHasKey( 'algorave', $definitions, 'Registry should contain algorave' );
		$this->assertArrayHasKey( 'media-worker', $definitions, 'Registry should contain external components' );
	}

	/**
	 * Test that every registry entry has the required metadata.
	 */
	public function test_registry_definitions_shape() {
		$definitions = $this->page->get_addon_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$this->assertNotEmpty( $definition['name'], "Addon $slug should have a name" );
			$this->assertNotEmpty( $definition['description'], "Addon $slug should have a description" );

			if ( ! empty( $definition['external'] ) ) {
				continue;
			}

			$this->assertNotEmpty( $definition['plugin_file'], "Addon $slug should declare a plugin file" );
			$this->assertNotEmpty( $definition['zip_pattern'], "Addon $slug should declare a zip pattern" );
		}
	}

	/**
	 * Test that get_addon_status returns the expected shape.
	 */
	public function test_get_addon_status_shape() {
		$definitions = $this->page->get_addon_definitions();
		$status      = $this->page->get_addon_status( $definitions['canvas'] );

		$this->assertArrayHasKey( 'active', $status );
		$this->assertArrayHasKey( 'installed', $status );
		$this->assertArrayHasKey( 'zip_path', $status );
		$this->assertArrayHasKey( 'version', $status );
		$this->assertIsBool( $status['active'] );
		$this->assertIsString( $status['zip_path'] );

		// In the test environment canvas is not an active plugin.
		$this->assertFalse( $status['active'] );

		// If a ZIP was found it must live inside the plugin's build/ directory.
		if ( '' !== $status['zip_path'] ) {
			$this->assertStringEndsWith( '.zip', $status['zip_path'] );
			$this->assertStringContainsString( WP_MCP_AI_PATH . 'build' . DIRECTORY_SEPARATOR, $status['zip_path'] );
		}
	}

	/**
	 * Test that the handler rejects a bad nonce.
	 */
	public function test_ajax_rejects_bad_nonce() {
		$response = $this->dispatch( 'invalid-nonce', 'canvas' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test that the handler rejects users without install_plugins.
	 */
	public function test_ajax_rejects_subscriber() {
		$response = $this->dispatch( wp_create_nonce( 'wp_mcp_ai_install_addon' ), 'canvas', false );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test that the handler rejects slugs outside the registry allowlist.
	 */
	public function test_ajax_rejects_unknown_addon() {
		$response = $this->dispatch( wp_create_nonce( 'wp_mcp_ai_install_addon' ), 'not-a-real-addon' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test that external components cannot be installed from this page.
	 */
	public function test_ajax_rejects_external_component() {
		$response = $this->dispatch( wp_create_nonce( 'wp_mcp_ai_install_addon' ), 'media-worker' );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Test that an installed-but-inactive addon is activated.
	 */
	public function test_ajax_activates_installed_addon() {
		$this->create_dummy_plugin();

		$this->assertFalse(
			is_plugin_active( 'nvoos-algorave/nvoos-algorave.php' ),
			'Dummy plugin should start inactive'
		);

		$response = $this->dispatch( wp_create_nonce( 'wp_mcp_ai_install_addon' ), 'algorave' );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'], 'Activation should succeed: ' . wp_json_encode( $response ) );
		$this->assertTrue(
			is_plugin_active( 'nvoos-algorave/nvoos-algorave.php' ),
			'Addon should be active after the AJAX call'
		);
	}
}
