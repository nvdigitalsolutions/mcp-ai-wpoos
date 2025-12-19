<?php
/**
 * Tests for Plugins Integration Settings Page
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Plugins_Integration_Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that the Plugins Integration class is properly instantiated.
	 */
	public function test_plugins_integration_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Admin_Plugins_Integration' ) );
	}

	/**
	 * Test that the admin post hook is registered.
	 */
	public function test_admin_post_hook_is_registered() {
		global $wp_filter;

		// Ensure the class is instantiated
		$container = wp_mcp_ai_container();
		$instance  = $container->get( 'admin.plugins_integration' );

		$this->assertInstanceOf( 'WP_MCP_AI_Admin_Plugins_Integration', $instance );

		// Check if the admin_post hook is registered
		$this->assertTrue(
			has_action( 'admin_post_wp_mcp_ai_save_plugins_settings' ),
			'admin_post_wp_mcp_ai_save_plugins_settings hook should be registered'
		);
	}

	/**
	 * Test that settings can be saved.
	 */
	public function test_settings_can_be_saved() {
		// Set up admin user
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Get instance
		$container = wp_mcp_ai_container();
		$instance  = $container->get( 'admin.plugins_integration' );

		// Clear existing settings
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate POST data
		$_POST['enable_jetengine_cct']     = '1';
		$_POST['enable_jetengine_tools']   = '1';
		$_POST['enable_woocommerce_tools'] = '1';
		$_POST['enable_elementor_widgets'] = '1';
		$_POST['_wpnonce']                 = wp_create_nonce( 'wp_mcp_ai_save_plugins_settings' );

		// Call the save method using reflection
		$reflection = new ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'handle_save_settings' );
		$method->setAccessible( true );

		// Capture output and redirect
		ob_start();
		try {
			$method->invoke( $instance );
		} catch ( WPDieException $e ) {
			// Expected behavior on redirect
		}
		ob_end_clean();

		// Verify settings were saved
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$this->assertTrue( $settings['enable_jetengine_cct'], 'JetEngine CCT should be enabled' );
		$this->assertTrue( $settings['enable_jetengine_tools'], 'JetEngine tools should be enabled' );
		$this->assertTrue( $settings['enable_woocommerce_tools'], 'WooCommerce tools should be enabled' );
		$this->assertTrue( $settings['enable_elementor_widgets'], 'Elementor widgets should be enabled' );

		// Clean up
		unset( $_POST['enable_jetengine_cct'] );
		unset( $_POST['enable_jetengine_tools'] );
		unset( $_POST['enable_woocommerce_tools'] );
		unset( $_POST['enable_elementor_widgets'] );
		unset( $_POST['_wpnonce'] );
	}

	/**
	 * Test that unchecked checkboxes result in false values.
	 */
	public function test_unchecked_checkboxes_save_as_false() {
		// Set up admin user
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Get instance
		$container = wp_mcp_ai_container();
		$instance  = $container->get( 'admin.plugins_integration' );

		// Set initial values to true
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_jetengine_cct'     => true,
				'enable_jetengine_tools'   => true,
				'enable_woocommerce_tools' => true,
				'enable_elementor_widgets' => true,
			)
		);

		// Simulate POST data with NO checkboxes checked
		$_POST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_save_plugins_settings' );

		// Call the save method
		$reflection = new ReflectionClass( $instance );
		$method     = $reflection->getMethod( 'handle_save_settings' );
		$method->setAccessible( true );

		ob_start();
		try {
			$method->invoke( $instance );
		} catch ( WPDieException $e ) {
			// Expected
		}
		ob_end_clean();

		// Verify settings were updated to false
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$this->assertFalse( $settings['enable_jetengine_cct'], 'JetEngine CCT should be disabled' );
		$this->assertFalse( $settings['enable_jetengine_tools'], 'JetEngine tools should be disabled' );
		$this->assertFalse( $settings['enable_woocommerce_tools'], 'WooCommerce tools should be disabled' );
		$this->assertFalse( $settings['enable_elementor_widgets'], 'Elementor widgets should be disabled' );

		// Clean up
		unset( $_POST['_wpnonce'] );
	}
}
