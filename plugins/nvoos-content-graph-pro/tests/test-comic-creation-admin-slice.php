<?php
/**
 * Characterization tests for the Wave F2 comic-creation admin slice — the
 * toolkit settings page and the Research & Add page ported from the base Pro
 * addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` are asserted in full, including the init's file-gate
 *   targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Comic-creation admin slice tests.
 */
class Test_Comic_Creation_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Comic_Settings_Page' => 'admin/class-wp-mcp-ai-comic-settings-page.php',
			'WP_MCP_AI_Comic_Research_Page' => 'admin/class-wp-mcp-ai-comic-research-page.php',
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
	 * The settings-page constructor props must be byte-identical.
	 */
	public function test_settings_page_props(): void {
		$settings = new WP_MCP_AI_Comic_Settings_Page();
		$this->assertSame( 'comic_creation', $this->read_prop( $settings, 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_comic_creation_settings', $this->read_prop( $settings, 'option_name' ) );
		$this->assertSame( 'comic-creation-settings', $this->read_prop( $settings, 'page_slug' ) );
		$this->assertTrue( $this->read_prop( $settings, 'has_research' ) );
	}

	/**
	 * The research page constants must be byte-identical.
	 */
	public function test_research_page_constants(): void {
		$this->assertSame( 'research-comic', WP_MCP_AI_Comic_Research_Page::PAGE_SLUG );
	}

	/**
	 * The research page init hook wiring must be byte-identical.
	 */
	public function test_research_page_init_hooks(): void {
		WP_MCP_AI_Comic_Research_Page::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( 'WP_MCP_AI_Comic_Research_Page', 'add_menu_page' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_create_comic_from_research', array( 'WP_MCP_AI_Comic_Research_Page', 'handle_create_from_research' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_wp_mcp_ai_import_comic', array( 'WP_MCP_AI_Comic_Research_Page', 'ajax_handle_import' ) ) );
	}

	/**
	 * Standalone only: the comic init's file-gated admin targets must now
	 * exist (the admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-comic-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-comic-research-page.php',
		);
		foreach ( $targets as $target ) {
			$this->assertFileExists( $target );
		}
	}

	/**
	 * Read a protected property for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $prop     Property name.
	 * @return mixed Property value.
	 */
	private function read_prop( object $instance, string $prop ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasProperty( $prop ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$property = $reflection->getProperty( $prop );
		$property->setAccessible( true );
		return $property->getValue( $instance );
	}
}
