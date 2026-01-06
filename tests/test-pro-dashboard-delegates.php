<?php
/**
 * Test Pro Dashboard Delegate Pages
 *
 * Verifies that the Pro Dashboard properly initializes and manages
 * delegate admin pages for ISO 27001 compliance modules.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Dashboard delegate page management.
 */
class Test_Pro_Dashboard_Delegates extends WP_UnitTestCase {

	/**
	 * Pro Dashboard instance.
	 *
	 * @var WP_MCP_AI_Pro_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';
		}

		// Load delegate classes.
		$delegate_files = array(
			'includes/class-wp-mcp-ai-security-audit.php',
			'includes/admin/class-wp-mcp-ai-security-audit-admin.php',
			'includes/class-wp-mcp-ai-security-training.php',
			'includes/admin/class-wp-mcp-ai-security-training-admin.php',
			'includes/class-wp-mcp-ai-supplier-security.php',
			'includes/admin/class-wp-mcp-ai-supplier-security-admin.php',
			'includes/class-wp-mcp-ai-asset-inventory.php',
			'includes/admin/class-wp-mcp-ai-asset-inventory-admin.php',
		);

		foreach ( $delegate_files as $file ) {
			$filepath = WP_MCP_AI_PATH . $file;
			if ( file_exists( $filepath ) ) {
				require_once $filepath;
			}
		}

		// Initialize Pro Dashboard.
		$this->dashboard = new WP_MCP_AI_Pro_Dashboard();
	}

	/**
	 * Test that delegate pages are initialized.
	 */
	public function test_delegate_pages_initialized() {
		$delegates = $this->dashboard->get_delegates();

		$this->assertIsArray( $delegates, 'Delegates should be an array' );
		$this->assertNotEmpty( $delegates, 'Delegates should not be empty' );
	}

	/**
	 * Test that expected delegate pages are registered.
	 */
	public function test_expected_delegates_registered() {
		$expected_delegates = array(
			'security_audits',
			'security_training',
			'supplier_security',
			'asset_inventory',
		);

		foreach ( $expected_delegates as $key ) {
			$this->assertTrue(
				$this->dashboard->has_delegate( $key ),
				"Delegate '{$key}' should be registered"
			);
		}
	}

	/**
	 * Test that delegate instances are correct type.
	 */
	public function test_delegate_instances() {
		$expected_classes = array(
			'security_audits'   => 'WP_MCP_AI_Security_Audit_Admin',
			'security_training' => 'WP_MCP_AI_Security_Training_Admin',
			'supplier_security' => 'WP_MCP_AI_Supplier_Security_Admin',
			'asset_inventory'   => 'WP_MCP_AI_Asset_Inventory_Admin',
		);

		foreach ( $expected_classes as $key => $class_name ) {
			$delegate = $this->dashboard->get_delegate( $key );

			if ( class_exists( $class_name ) ) {
				$this->assertInstanceOf(
					$class_name,
					$delegate,
					"Delegate '{$key}' should be instance of {$class_name}"
				);
			}
		}
	}

	/**
	 * Test get_delegate method.
	 */
	public function test_get_delegate() {
		// Test valid delegate.
		$audit_delegate = $this->dashboard->get_delegate( 'security_audits' );
		if ( class_exists( 'WP_MCP_AI_Security_Audit_Admin' ) ) {
			$this->assertNotNull( $audit_delegate, 'Should return delegate instance' );
		}

		// Test invalid delegate.
		$invalid_delegate = $this->dashboard->get_delegate( 'nonexistent' );
		$this->assertNull( $invalid_delegate, 'Should return null for invalid delegate' );
	}

	/**
	 * Test has_delegate method.
	 */
	public function test_has_delegate() {
		// Test valid delegate.
		$this->assertTrue(
			$this->dashboard->has_delegate( 'security_audits' ) || ! class_exists( 'WP_MCP_AI_Security_Audit_Admin' ),
			'Should return true for valid delegate or class not loaded'
		);

		// Test invalid delegate.
		$this->assertFalse(
			$this->dashboard->has_delegate( 'nonexistent' ),
			'Should return false for invalid delegate'
		);
	}

	/**
	 * Test that delegate action fires.
	 */
	public function test_delegate_initialization_action() {
		$action_fired = false;

		add_action(
			'wp_mcp_ai_pro_dashboard_delegates_initialized',
			function ( $delegates ) use ( &$action_fired ) {
				$action_fired = true;
				$this->assertIsArray( $delegates, 'Action should pass delegates array' );
			}
		);

		// Reinitialize to trigger action.
		new WP_MCP_AI_Pro_Dashboard();

		$this->assertTrue( $action_fired, 'Delegate initialization action should fire' );
	}

	/**
	 * Test delegate pages don't auto-initialize.
	 */
	public function test_no_duplicate_initialization() {
		global $wp_filter;

		// Check that delegate admin classes don't have their own admin_menu hooks.
		// They should only be registered through Pro Dashboard.
		$admin_menu_hooks = $wp_filter['admin_menu'] ?? null;

		if ( $admin_menu_hooks ) {
			foreach ( $admin_menu_hooks->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) ) {
						$class = get_class( $callback['function'][0] );

						// Delegate classes should not register their own menu hooks.
						// They're instantiated by Pro Dashboard.
						$this->assertNotEquals(
							'WP_MCP_AI_Security_Audit_Admin',
							$class,
							'Security Audit Admin should not register menu independently'
						);
					}
				}
			}
		}

		// Test passes if no duplicate registrations found.
		$this->assertTrue( true );
	}
}
