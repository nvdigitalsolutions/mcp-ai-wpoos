<?php
/**
 * Characterization tests for the Wave F2 MCP settings-base slice — the
 * ported `WP_MCP_AI_Toolkit_Settings_Base`,
 * `WP_MCP_AI_Remote_Capabilities_Loader`, and
 * `WP_MCP_AI_CRM_Settings_Page`.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * MCP settings-base slice tests.
 */
class Test_Crm_Settings_Page extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Toolkit_Settings_Base'      => 'admin/class-wp-mcp-ai-toolkit-settings-base.php',
			'WP_MCP_AI_Remote_Capabilities_Loader' => 'admin/remote-capabilities/class-wp-mcp-ai-remote-capabilities-loader.php',
			'WP_MCP_AI_CRM_Settings_Page'          => 'admin/class-wp-mcp-ai-crm-settings-page.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The remote-capabilities loader must return per-toolkit capability
	 * descriptions and an empty array for unknown toolkits.
	 */
	public function test_remote_capabilities_loader(): void {
		$crm_caps = WP_MCP_AI_Remote_Capabilities_Loader::get_capabilities( 'crm' );
		$this->assertIsArray( $crm_caps );

		$ecommerce_caps = WP_MCP_AI_Remote_Capabilities_Loader::get_capabilities( 'ecommerce' );
		$this->assertNotEmpty( $ecommerce_caps );

		$unknown = WP_MCP_AI_Remote_Capabilities_Loader::get_capabilities( 'not_a_toolkit' );
		$this->assertSame( array(), $unknown );
	}

	/**
	 * The CRM settings page surface must be byte-identical.
	 */
	public function test_crm_settings_page_surface(): void {
		$page = new WP_MCP_AI_CRM_Settings_Page();

		// The constructor stamps the toolkit surface onto the instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_CRM_Settings_Page' );
		$expected   = array(
			'toolkit_slug' => 'crm',
			'option_name'  => 'wp_mcp_ai_crm_toolkit_settings',
			'page_slug'    => 'wp-mcp-ai-crm-toolkit-settings',
			'parent_slug'  => 'nvoos-crm-dashboard',
			'has_research' => false,
			'has_remote_sites' => false,
		);
		foreach ( $expected as $prop => $value ) {
			$rprop = $reflection->getProperty( $prop );
			$this->assertSame( $value, $rprop->getValue( $page ), $prop );
		}
	}

	/**
	 * The settings base must wire admin_menu (priority 30) + admin_init.
	 */
	public function test_settings_base_hooks(): void {
		$page = new WP_MCP_AI_CRM_Settings_Page();

		$this->assertSame( 30, has_action( 'admin_menu', array( $page, 'add_settings_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $page, 'register_settings' ) ) );
	}

	/**
	 * Standalone only: the CRM init's admin block wires the settings page
	 * (the page self-boots at file load; the init requires the file).
	 */
	public function test_init_wiring_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base CRM init wires the page at boot.' );
		}

		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Settings_Page' ) );
	}
}
