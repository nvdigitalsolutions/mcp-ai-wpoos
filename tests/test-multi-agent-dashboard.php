<?php
/**
 * Test Multi-Agent Dashboard.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Multi_Agent_Dashboard
 */
class Test_Multi_Agent_Dashboard extends WP_UnitTestCase {

	/**
	 * Test that dashboard class exists.
	 */
	public function test_dashboard_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Admin_Multi_Agent_Dashboard' ) );
	}

	/**
	 * Test that menu is registered.
	 */
	public function test_menu_registered() {
		global $submenu;

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check that submenu exists.
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $submenu );

		// Find multi-agent menu item.
		$found = false;
		if ( isset( $submenu['wp-mcp-ai-dashboard'] ) ) {
			foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
				if ( isset( $item[2] ) && 'mcp-ai-multi-agent' === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Multi-Agent menu item should be registered' );
	}

	/**
	 * Test agent statistics retrieval.
	 */
	public function test_get_agent_statistics() {
		// Install default assistants.
		WP_MCP_AI_Default_Assistants::install();

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Admin_Multi_Agent_Dashboard();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_agent_statistics' );
		$method->setAccessible( true );

		$stats = $method->invoke( $dashboard );

		// Verify stats structure.
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'installed', $stats );
		$this->assertArrayHasKey( 'agents', $stats );
		$this->assertArrayHasKey( 'total_agents', $stats );
		$this->assertArrayHasKey( 'active_agents', $stats );
		$this->assertArrayHasKey( 'total_tools', $stats );
		$this->assertArrayHasKey( 'is_pro_active', $stats );

		// Verify agents are retrieved.
		$this->assertTrue( $stats['installed'] );
		$this->assertGreaterThan( 0, $stats['total_agents'] );
		$this->assertIsArray( $stats['agents'] );
	}

	/**
	 * Test AJAX stats endpoint.
	 */
	public function test_ajax_get_stats() {
		// Set up admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Install default assistants.
		WP_MCP_AI_Default_Assistants::install();

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_get_multi_agent_stats';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_multi_agent' );

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Admin_Multi_Agent_Dashboard();

		// Capture output.
		ob_start();
		try {
			$dashboard->ajax_get_stats();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception.
		}
		$response = ob_get_clean();

		// Verify response.
		$data = json_decode( $response, true );
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
	}

	/**
	 * Test AJAX reinstall endpoint with permissions.
	 */
	public function test_ajax_reinstall_agents_requires_permissions() {
		// Set up non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_reinstall_agents';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_multi_agent' );

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Admin_Multi_Agent_Dashboard();

		// Capture output.
		ob_start();
		try {
			$dashboard->ajax_reinstall_agents();
		} catch ( WPAjaxDieStopException $e ) {
			// Expected exception.
		}
		$response = ob_get_clean();

		// Verify response shows error for non-admin.
		$data = json_decode( $response, true );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Test that CSS file exists.
	 */
	public function test_css_file_exists() {
		$css_file = WP_MCP_AI_PATH . 'assets/css/admin-multi-agent-dashboard.css';
		$this->assertFileExists( $css_file );
	}

	/**
	 * Test that JS file exists.
	 */
	public function test_js_file_exists() {
		$js_file = WP_MCP_AI_PATH . 'assets/js/admin-multi-agent-dashboard.js';
		$this->assertFileExists( $js_file );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up installed assistants.
		WP_MCP_AI_Default_Assistants::uninstall();
		parent::tearDown();
	}
}
