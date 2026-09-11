<?php
/**
 * Characterization tests for the Wave F2 CRM blueprints admin page — the
 * ported `WP_MCP_AI_CRM_Blueprints_Page` plus its example blueprint data.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   class (classmap-autoloaded); the byte-identical constants, hooks, and
 *   degrade contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/admin/`
 *   is asserted in full, including the serving source, the copied example
 *   blueprints, and the init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM blueprints page tests.
 */
class Test_Crm_Blueprints_Page extends WP_UnitTestCase {

	/**
	 * The serving source must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_CRM_Blueprints_Page' );
		$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/admin/class-wp-mcp-ai-crm-blueprints-page.php', $file );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/admin/class-wp-mcp-ai-crm-blueprints-page.php', $file );
		}
	}

	/**
	 * The page constants must be byte-identical.
	 */
	public function test_page_constants(): void {
		$this->assertSame( 'nvoos-crm-blueprints', WP_MCP_AI_CRM_Blueprints_Page::PAGE_SLUG );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame(
				WP_MCP_AI_PRO_PATH . 'includes/tools/crm/examples',
				WP_MCP_AI_CRM_Blueprints_Page::BLUEPRINTS_DIR
			);
		} else {
			$this->assertSame(
				NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/examples',
				WP_MCP_AI_CRM_Blueprints_Page::BLUEPRINTS_DIR
			);
		}
	}

	/**
	 * init() must wire the admin_menu (priority 27), asset, and AJAX hooks.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_CRM_Blueprints_Page::init();

		$this->assertSame( 27, has_action( 'admin_menu', array( 'WP_MCP_AI_CRM_Blueprints_Page', 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'WP_MCP_AI_CRM_Blueprints_Page', 'enqueue_assets' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_wp_mcp_ai_crm_install_blueprint', array( 'WP_MCP_AI_CRM_Blueprints_Page', 'ajax_install_blueprint' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_wp_mcp_ai_crm_get_blueprint_details', array( 'WP_MCP_AI_CRM_Blueprints_Page', 'ajax_get_blueprint_details' ) ) );
	}

	/**
	 * The eight example blueprint files must ship with the addon.
	 */
	public function test_example_blueprints_ship(): void {
		$expected = array(
			'agency-account-manager',
			'b2b-saas-sdr',
			'bespoke-concierge',
			'business-advisory',
			'career-coach',
			'luxeseek-sourcing-agent',
			'real-estate-buyer-agent',
			'wholesale-distributor',
		);

		foreach ( $expected as $slug ) {
			$file = WP_MCP_AI_CRM_Blueprints_Page::BLUEPRINTS_DIR . '/' . $slug . '.json';
			$this->assertFileExists( $file, $slug );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local blueprint data file, not a remote URL.
			$this->assertStringContainsString( '"name"', (string) file_get_contents( $file ) );
		}
	}

	/**
	 * Standalone only: the CRM init carries the blueprints page; the init's
	 * admin block is is_admin()-gated, so in the CLI test env the page
	 * wires itself when booted explicitly.
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

		WP_MCP_AI_CRM_Blueprints_Page::init();
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_crm_install_blueprint' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_crm_get_blueprint_details' ) );
	}
}
