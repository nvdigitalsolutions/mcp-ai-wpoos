<?php
/**
 * Characterization tests for the Wave F2 dj-management admin slice — the
 * toolkit settings page ported from the base Pro addon (completes the
 * dj-management toolkit port).
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
 * DJ-management admin slice tests.
 */
class Test_Dj_Management_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbol must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_DJ_Management_Settings_Page' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/admin/class-wp-mcp-ai-dj-management-settings-page.php', $path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/admin/class-wp-mcp-ai-dj-management-settings-page.php', $path );
		}
	}

	/**
	 * The settings-page constructor props must be byte-identical.
	 */
	public function test_settings_page_props(): void {
		$settings = new WP_MCP_AI_DJ_Management_Settings_Page();
		$this->assertSame( 'dj_management', $this->read_prop( $settings, 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_dj_management_toolkit_settings', $this->read_prop( $settings, 'option_name' ) );
		$this->assertSame( 'wp-mcp-ai-dj-management-toolkit-settings', $this->read_prop( $settings, 'page_slug' ) );
	}

	/**
	 * Standalone only: the dj init's file-gated admin target must now exist
	 * (the admin slice has landed — dj-management toolkit port complete).
	 */
	public function test_init_gate_target_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$this->assertFileExists(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-dj-management-settings-page.php'
		);
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
