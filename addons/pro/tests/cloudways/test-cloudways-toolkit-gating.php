<?php
/**
 * Cloudways Toolkit — Gating Tests
 *
 * Tests that Cloudways tools are correctly gated behind:
 * - enable_cloudways_toolkit setting
 * - Not base version
 * - Credentials configured (is_available)
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.15
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudways Toolkit Gating Tests
 */
class Test_Cloudways_Toolkit_Gating extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure base functions exist.
		if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
			// phpcs:ignore -- stub for test.
			function wp_mcp_ai_is_base_version() {
				return false;
			}
		}

		if ( ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/cloudways/class-wp-mcp-ai-cloudways-helpers.php';
		}

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/cloudways/class-wp-mcp-ai-cloudways-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php';
		}
	}

	/**
	 * Test helper function returns false when toolkit is disabled.
	 */
	public function test_toolkit_disabled_returns_false() {
		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_cloudways_toolkit'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertFalse( wp_mcp_ai_is_cloudways_toolkit_enabled() );
	}

	/**
	 * Test helper function returns true when toolkit is enabled.
	 */
	public function test_toolkit_enabled_returns_true() {
		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_cloudways_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->assertTrue( wp_mcp_ai_is_cloudways_toolkit_enabled() );
	}

	/**
	 * Test is_available returns false without credentials.
	 */
	public function test_list_servers_unavailable_without_credentials() {
		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_cloudways_toolkit'] = true;
		unset( $settings['cloudways_email'], $settings['cloudways_api_key'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Tool_Cloudways_List_Servers' ) ) {
			$this->assertFalse( WP_MCP_AI_Tool_Cloudways_List_Servers::is_available() );
			$this->assertNotEmpty( WP_MCP_AI_Tool_Cloudways_List_Servers::get_unavailable_reason() );
		}
	}

	/**
	 * Test is_available returns true with credentials and toolkit enabled.
	 */
	public function test_list_servers_available_with_credentials() {
		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_cloudways_toolkit'] = true;
		$settings['cloudways_email']          = 'test@example.com';
		$settings['cloudways_api_key']        = 'test_key';
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Tool_Cloudways_List_Servers' ) ) {
			$this->assertTrue( WP_MCP_AI_Tool_Cloudways_List_Servers::is_available() );
		}
	}

	/**
	 * Test all Cloudways tools require manage_options.
	 */
	public function test_cloudways_tools_require_manage_options() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {
			$this->markTestSkipped( 'Base class not loaded.' );
		}

		$tool_classes = array_filter(
			get_declared_classes(),
			function ( $class_name ) {
						return 0 === strpos( $class_name, 'WP_MCP_AI_Tool_Cloudways_' )
							&& 'WP_MCP_AI_Tool_Cloudways_Base' !== $class_name
							&& is_subclass_of( $class_name, 'WP_MCP_AI_Tool_Cloudways_Base' );
			}
		);

		foreach ( $tool_classes as $class_name ) {
			$tool = new $class_name();
			$this->assertSame(
				'manage_options',
				$tool->get_required_capability(),
				sprintf( '%s should require manage_options', $class_name )
			);
		}
	}

	/**
	 * Test that capability flags include 'pro'.
	 */
	public function test_cloudways_tools_have_pro_flag() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Base' ) ) {
			$this->markTestSkipped( 'Base class not loaded.' );
		}

		$tool_classes = array_filter(
			get_declared_classes(),
			function ( $class_name ) {
				return 0 === strpos( $class_name, 'WP_MCP_AI_Tool_Cloudways_' )
					&& 'WP_MCP_AI_Tool_Cloudways_Base' !== $class_name
					&& is_subclass_of( $class_name, 'WP_MCP_AI_Tool_Cloudways_Base' );
			}
		);

		foreach ( $tool_classes as $class_name ) {
			$tool  = new $class_name();
			$flags = $tool->get_capability_flags();
			$this->assertContains( 'pro', $flags, sprintf( '%s should have pro flag', $class_name ) );
		}
	}
}
