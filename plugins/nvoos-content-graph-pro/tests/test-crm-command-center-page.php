<?php
/**
 * Characterization tests for the Wave F2 CRM command-center admin page —
 * the ported `WP_MCP_AI_CRM_Command_Center_Page` hook/contract surface.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   class (classmap-autoloaded); the byte-identical constants, hooks, and
 *   degrade contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/admin/`
 *   is asserted in full, including the serving source and the init wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM command-center page tests.
 */
class Test_Crm_Command_Center_Page extends WP_UnitTestCase {

	/**
	 * The serving source must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_CRM_Command_Center_Page' );
		$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php', $file );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/admin/class-wp-mcp-ai-crm-command-center-page.php', $file );
		}
	}

	/**
	 * The page constants must be byte-identical and hang under the
	 * `nvoos-crm-dashboard` parent registered by the CRM admin menu.
	 */
	public function test_page_constants(): void {
		$this->assertSame( 'nvoos-crm-command-center', WP_MCP_AI_CRM_Command_Center_Page::PAGE_SLUG );
		$this->assertSame( 'wp_mcp_ai_crm_cc', WP_MCP_AI_CRM_Command_Center_Page::NONCE_ACTION );
		$this->assertSame( 'nvoos-crm-dashboard', WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG );
	}

	/**
	 * init() must wire the admin_menu (priority 26), asset, and the seven
	 * AJAX endpoints.
	 */
	public function test_init_hooks(): void {
		WP_MCP_AI_CRM_Command_Center_Page::init();

		$this->assertSame( 26, has_action( 'admin_menu', array( 'WP_MCP_AI_CRM_Command_Center_Page', 'register_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( 'WP_MCP_AI_CRM_Command_Center_Page', 'enqueue_assets' ) ) );

		$ajax_actions = array(
			'wp_ajax_wp_mcp_ai_crm_cc_get_dashboard'       => 'ajax_get_dashboard',
			'wp_ajax_wp_mcp_ai_crm_cc_get_pipeline'        => 'ajax_get_pipeline',
			'wp_ajax_wp_mcp_ai_crm_cc_refresh_all_sources' => 'ajax_refresh_all_sources',
			'wp_ajax_wp_mcp_ai_crm_cc_hygiene_add'         => 'ajax_hygiene_add',
			'wp_ajax_wp_mcp_ai_crm_cc_hygiene_remove'      => 'ajax_hygiene_remove',
			'wp_ajax_wp_mcp_ai_crm_cc_merge_duplicate'     => 'ajax_merge_duplicate',
			'wp_ajax_wp_mcp_ai_crm_cc_lead_tags_update'    => 'ajax_lead_tags_update',
		);

		foreach ( $ajax_actions as $action => $method ) {
			$this->assertSame( 10, has_action( $action, array( 'WP_MCP_AI_CRM_Command_Center_Page', $method ) ), $action );
		}
	}

	/**
	 * Standalone only: the CRM init carries the command-center page; the
	 * init's admin block is is_admin()-gated, so in the CLI test env the
	 * page wires itself when booted explicitly.
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

		WP_MCP_AI_CRM_Command_Center_Page::init();
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_crm_cc_get_dashboard' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_crm_cc_lead_tags_update' ) );
	}
}
