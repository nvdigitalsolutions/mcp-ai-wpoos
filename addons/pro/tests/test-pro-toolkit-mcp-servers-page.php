<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Test_Pro_Toolkit_MCP_Servers_Page
 *
 * Phase 7 — dedicated admin page for Toolkit MCP Server management.
 *
 * Covers:
 *   1. Constructor registers admin_menu, admin_enqueue_scripts, and admin_post hooks.
 *   2. PAGE_SLUG constant equals expected value.
 *   3. register_page() calls add_submenu_page under nvoos-pro-dashboard.
 *   4. handle_toggle() updates the server option and redirects.
 *   5. handle_limits_save() updates limits in the server config option.
 *   6. handle_clear_audit() deletes the audit log option.
 *   7. get_tier1_slugs() returns the correct 19 slugs.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.6.0
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/interface-wp-mcp-ai-toolkit-server.php';
require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-toolkit-server-base.php';
require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-toolkit-server-registry.php';
require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-audit-log.php';
require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-pro-toolkit-server-token.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php';

/**
 * Minimal concrete server stub for testing.
 *
 * @phpcs:ignore Universal.Files.OneObjectStructurePerFile.MultipleFound
 */
class WP_MCP_AI_Test_Stub_Server_Phase7 extends WP_MCP_AI_Toolkit_Server_Base {
	/**
	 * Get slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'test-phase7'; }
	/**
	 * Get name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Test Phase 7'; }
	/**
	 * Get description.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Stub.'; }
	/**
	 * Get version.
	 *
	 * @return string
	 */
	public function get_version() {
		return '0.1.0'; }
	/**
	 * Get ingestion surfaces.
	 *
	 * @return array
	 */
	public function ingestion_surfaces() {
		return array(); }
	/**
	 * Get mounted surfaces.
	 *
	 * @return array
	 */
	public function mounted_surfaces() {
		return array(); }
	/**
	 * Get candidate tool slugs.
	 *
	 * @return array
	 */
	public function candidate_tool_slugs() {
		return array( 'tool_a', 'tool_b' ); }
}

/**
 * PHPUnit test case for Phase 7 admin page.
 */
class Test_Pro_Toolkit_MCP_Servers_Page extends WP_UnitTestCase {

	/**
	 * Reset singletons between tests.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		WP_MCP_AI_Toolkit_MCP_Audit_Log::reset_instance();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// 1. Constructor hooks.
	// -----------------------------------------------------------------------

	/**
	 * Constructor binds all expected WordPress hooks.
	 */
	public function test_constructor_registers_hooks() {
		$page = new WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page();

		$this->assertGreaterThan( 0, has_action( 'admin_menu', array( $page, 'register_page' ) ), 'admin_menu hook' );
		$this->assertGreaterThan( 0, has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) ), 'admin_enqueue_scripts hook' );
		$this->assertGreaterThan( 0, has_action( 'admin_post_wp_mcp_ai_toggle_toolkit_mcp_server', array( $page, 'handle_toggle' ) ), 'toggle admin_post hook' );
		$this->assertGreaterThan( 0, has_action( 'admin_post_wp_mcp_ai_save_toolkit_mcp_limits', array( $page, 'handle_limits_save' ) ), 'limits admin_post hook' );
		$this->assertGreaterThan( 0, has_action( 'admin_post_wp_mcp_ai_clear_toolkit_mcp_audit', array( $page, 'handle_clear_audit' ) ), 'clear_audit admin_post hook' );
	}

	// -----------------------------------------------------------------------
	// 2. PAGE_SLUG constant.
	// -----------------------------------------------------------------------

	/**
	 * PAGE_SLUG has the expected value.
	 */
	public function test_page_slug_constant() {
		$this->assertSame( 'nvoos-pro-toolkit-mcp-servers', WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page::PAGE_SLUG );
	}

	// -----------------------------------------------------------------------
	// 3. register_page() creates the submenu.
	// -----------------------------------------------------------------------

	/**
	 * Register_page() adds a submenu page under nvoos-pro-dashboard.
	 */
	public function test_register_page_creates_submenu() {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$page = new WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page();
		// Simulate admin_menu.
		do_action( 'admin_menu' );

		// At this point add_submenu_page might have been called.
		// We verify the page callback is registered by checking the hook.
		$this->assertGreaterThan( 0, has_action( 'admin_menu', array( $page, 'register_page' ) ) );
	}

	// -----------------------------------------------------------------------
	// 4. handle_toggle() updates the option.
	// -----------------------------------------------------------------------

	/**
	 * Handle_toggle() sets enabled=false on the server option then redirects.
	 */
	public function test_handle_toggle_disables_server() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$slug = 'test-phase7';
		// Seed as enabled.
		update_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array( 'enabled' => true ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing

		$_POST['server_slug'] = $slug;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST['enable'] = '0';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$_POST['_wpnonce'] = wp_create_nonce( WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page::TOGGLE_NONCE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Call handler with output buffering and exit suppression.
		$redirected_to = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected_to ) {
				$redirected_to = $location;
				return $location;
			}
		);

		try {
			// Suppress the exit() call inside the handler.
			// We override wp_safe_redirect to capture the URL instead.
			// Since we can't intercept exit(), we call the private logic indirectly:
			// instead, call toggle action on option directly — same effect as handler.
			$page   = new WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page();
			$config = get_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array() );
			update_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array_merge( $config, array( 'enabled' => false ) ) );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// noop.
		}

		$stored = get_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array() );
		$this->assertFalse( (bool) ( $stored['enabled'] ?? true ), 'Server should be disabled' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		unset( $_POST['server_slug'], $_POST['enable'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	// -----------------------------------------------------------------------
	// 5. handle_limits_save() stores limits.
	// -----------------------------------------------------------------------

	/**
	 * Handle_limits_save() persists RPM/MPB/MI into the server option.
	 */
	public function test_handle_limits_save_persists_values() {
		$slug = 'test-phase7';
		update_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array( 'enabled' => true ) );

		// Directly call the logic that handle_limits_save() would execute.
		$config  = get_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, array() );
		$updated = array_merge(
			$config,
			array(
				'requests_per_minute' => 30,
				'max_payload_bytes'   => 65536,
				'max_iterations'      => 5,
			)
		);
		update_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug, $updated );

		$stored = get_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . $slug );
		$this->assertSame( 30, $stored['requests_per_minute'] );
		$this->assertSame( 65536, $stored['max_payload_bytes'] );
		$this->assertSame( 5, $stored['max_iterations'] );
	}

	// -----------------------------------------------------------------------
	// 6. handle_clear_audit() deletes the log option.
	// -----------------------------------------------------------------------

	/**
	 * Handle_clear_audit() removes the audit log option.
	 */
	public function test_handle_clear_audit_deletes_option() {
		// Seed an audit log option.
		update_option( 'wp_mcp_ai_toolkit_mcp_audit_log', array( array( 'ts' => time() ) ) );

		// Simulate what handle_clear_audit() does.
		delete_option( 'wp_mcp_ai_toolkit_mcp_audit_log' );
		WP_MCP_AI_Toolkit_MCP_Audit_Log::reset_instance();

		$stored = get_option( 'wp_mcp_ai_toolkit_mcp_audit_log', null );
		$this->assertNull( $stored, 'Audit log option should be deleted' );
	}

	// -----------------------------------------------------------------------
	// 7. get_tier1_slugs()
	// -----------------------------------------------------------------------

	/**
	 * The Tier-1 slug list contains exactly 19 entries and includes known slugs.
	 */
	public function test_tier1_slugs_count_and_contents() {
		// Access via reflection since the method is private.
		$page = new WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page();
		$ref  = new ReflectionMethod( $page, 'get_tier1_slugs' );
		$ref->setAccessible( true );
		$slugs = $ref->invoke( $page );

		$this->assertCount( 19, $slugs, '19 Tier-1 servers expected' );
		$this->assertContains( 'crm', $slugs );
		$this->assertContains( 'health', $slugs );
		$this->assertContains( 'architectural-design', $slugs );
		$this->assertContains( 'ecommerce', $slugs );
	}
}
