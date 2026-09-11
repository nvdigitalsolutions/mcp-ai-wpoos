<?php
/**
 * Characterization tests for the Wave F5 eca-management admin slice — the
 * four ported admin pages (research, settings, dashboard, consolidate).
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
 * ECA admin slice tests.
 */
class Test_ECA_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The four ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_ECA_Research_Page'    => 'admin/class-wp-mcp-ai-eca-research-page.php',
			'WP_MCP_AI_ECA_Settings_Page'    => 'admin/class-wp-mcp-ai-eca-settings-page.php',
			'WP_MCP_AI_ECA_Dashboard_Page'   => 'admin/class-wp-mcp-ai-eca-dashboard-page.php',
			'WP_MCP_AI_ECA_Consolidate_Page' => 'admin/class-wp-mcp-ai-eca-consolidate-page.php',
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
	 * The settings page constructor props must be byte-identical (a
	 * cpt-settings-base child).
	 */
	public function test_settings_props(): void {
		$page    = new WP_MCP_AI_ECA_Settings_Page();
		$reflect = new ReflectionObject( $page );

		$read = static function ( string $prop ) use ( $reflect, $page ) {
			$property = $reflect->getProperty( $prop );
			$property->setAccessible( true );
			return $property->getValue( $page );
		};

		$this->assertSame( 'wp_mcp_ai_eca_settings', $read( 'option_name' ) );
		$this->assertSame( 'mcp_ai_eca', $read( 'post_type' ) );
		$this->assertSame( 'eca-settings', $read( 'page_slug' ) );
	}

	/**
	 * The research/dashboard/consolidate page constants + hook wiring must be
	 * byte-identical.
	 */
	public function test_page_wiring(): void {
		$this->assertSame( 'research-eca', WP_MCP_AI_ECA_Research_Page::PAGE_SLUG );
		$this->assertSame( 'eca-dashboard', WP_MCP_AI_ECA_Dashboard_Page::PAGE_SLUG );
		$this->assertSame( 'consolidate-eca', WP_MCP_AI_ECA_Consolidate_Page::PAGE_SLUG );

		WP_MCP_AI_ECA_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_ECA_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_eca_from_research', array( 'WP_MCP_AI_ECA_Research_Page', 'handle_create_from_research' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_import_eca', array( 'WP_MCP_AI_ECA_Research_Page', 'handle_import' ) ) );

		WP_MCP_AI_ECA_Dashboard_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_ECA_Dashboard_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_eca_dashboard_data', array( 'WP_MCP_AI_ECA_Dashboard_Page', 'ajax_get_dashboard_data' ) ) );

		WP_MCP_AI_ECA_Consolidate_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_ECA_Consolidate_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_upload_eca_document', array( 'WP_MCP_AI_ECA_Consolidate_Page', 'handle_document_upload' ) ) );
	}

	/**
	 * Standalone only: the slim init's four file-gated admin-page requires
	 * have their gate targets present. The in-admin wiring itself is asserted
	 * through the pages' own `::init()` calls (the standalone matrix boots
	 * with `is_admin()` false, so the init's admin block cannot fire here —
	 * the PM/calendar admin-slice pattern).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base ECA init wires the slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-dashboard-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-consolidate-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}
}
