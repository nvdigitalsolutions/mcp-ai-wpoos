<?php
/**
 * Characterization tests for the Wave F4 law-firm admin slice — the three
 * ported admin pages (settings, research, dashboard).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in `src/admin/`
 *   are asserted in full, including the slim init's now-firing gate targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm admin slice tests.
 */
class Test_Law_Firm_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Law_Firm_Settings_Page'  => 'admin/class-wp-mcp-ai-law-firm-settings-page.php',
			'WP_MCP_AI_Law_Firm_Research_Page'  => 'admin/class-wp-mcp-ai-law-firm-research-page.php',
			'WP_MCP_AI_Law_Firm_Dashboard_Page' => 'admin/class-wp-mcp-ai-law-firm-dashboard-page.php',
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
	 * The settings page constructor props must be byte-identical.
	 */
	public function test_settings_props(): void {
		$page    = new WP_MCP_AI_Law_Firm_Settings_Page();
		$reflect = new ReflectionObject( $page );

		$read = static function ( string $prop ) use ( $reflect, $page ) {
			$property = $reflect->getProperty( $prop );
			$property->setAccessible( true );
			return $property->getValue( $page );
		};

		$this->assertSame( 'law_firm', $read( 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_law_firm_settings', $read( 'option_name' ) );
		$this->assertSame( 'law-firm-settings', $read( 'page_slug' ) );
		$this->assertTrue( $read( 'has_research' ) );
	}

	/**
	 * The research page constants + hook wiring must be byte-identical.
	 */
	public function test_research_page_wiring(): void {
		$this->assertSame( 'research-law-firm', WP_MCP_AI_Law_Firm_Research_Page::PAGE_SLUG );

		WP_MCP_AI_Law_Firm_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Law_Firm_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_lf_matter_from_research', array( 'WP_MCP_AI_Law_Firm_Research_Page', 'handle_create_matter' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_lf_client_from_research', array( 'WP_MCP_AI_Law_Firm_Research_Page', 'handle_create_client' ) ) );
	}

	/**
	 * The dashboard page constants + hook wiring must be byte-identical.
	 */
	public function test_dashboard_page_wiring(): void {
		$this->assertSame( 'law-firm-dashboard', WP_MCP_AI_Law_Firm_Dashboard_Page::PAGE_SLUG );

		WP_MCP_AI_Law_Firm_Dashboard_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Law_Firm_Dashboard_Page', 'add_menu_page' ) ) );
	}

	/**
	 * Standalone only: the slim init's three file-gated admin-page requires
	 * have their gate targets present. The in-admin wiring itself is asserted
	 * through the pages' own `::init()` calls (the standalone matrix boots
	 * with `is_admin()` false, so the init's admin block cannot fire here —
	 * the PM/calendar admin-slice pattern).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base law-firm init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-law-firm-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-law-firm-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-law-firm-dashboard-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}
}
