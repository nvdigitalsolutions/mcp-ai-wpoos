<?php
/**
 * Characterization tests for the Wave F2 site-creator admin slice — the
 * toolkit settings page ported from the base Pro addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   class (classmap-autoloaded); the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/admin/`
 *   is asserted in full, including the init's file-gate target.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Site-creator admin slice tests.
 */
class Test_Site_Creator_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbol must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Site_Creator_Toolkit_Settings_Page' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php', $path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php', $path );
		}
	}

	/**
	 * The constructor hook wiring must be byte-identical.
	 */
	public function test_settings_page_hooks(): void {
		$page = new WP_MCP_AI_Site_Creator_Toolkit_Settings_Page();
		$this->assertSame( 20, has_action( 'admin_menu', array( $page, 'add_settings_page' ) ) );
	}

	/**
	 * The menu registration must produce the byte-identical top-level menu
	 * and the five submenus.
	 */
	public function test_menu_registration(): void {
		$page = new WP_MCP_AI_Site_Creator_Toolkit_Settings_Page();
		$page->add_settings_page();

		$this->assertNotFalse( menu_page_url( 'nvoos-site-creator', false ) );
		$this->assertNotFalse( menu_page_url( 'nvoos-site-creator-tools', false ) );
		$this->assertNotFalse( menu_page_url( 'nvoos-site-creator-templates', false ) );
		$this->assertNotFalse( menu_page_url( 'nvoos-site-creator-research', false ) );
		$this->assertNotFalse( menu_page_url( 'nvoos-site-creator-consolidate', false ) );
	}

	/**
	 * Standalone only: the site-creator init's file-gated settings-page
	 * target must now exist (the admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$this->assertFileExists( NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php' );
	}
}
