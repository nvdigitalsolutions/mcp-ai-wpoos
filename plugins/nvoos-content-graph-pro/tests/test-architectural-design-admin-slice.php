<?php
/**
 * Characterization tests for the Wave F2 architectural-design admin slice —
 * the toolkit settings page, the three per-CPT settings pages, the three
 * metabox classes, and the three research pages ported from the base Pro
 * addon (completes the architectural-design toolkit port).
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
 * Architectural-design admin slice tests.
 */
class Test_Architectural_Design_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Architectural_Design_Settings_Page' => 'admin/class-wp-mcp-ai-architectural-design-settings-page.php',
			'WP_MCP_AI_Architectural_Project_Settings_Page' => 'admin/class-wp-mcp-ai-architectural-project-settings-page.php',
			'WP_MCP_AI_Architectural_Project_Metabox'      => 'admin/class-wp-mcp-ai-architectural-project-metabox.php',
			'WP_MCP_AI_Architectural_Project_Research_Page' => 'admin/class-wp-mcp-ai-architectural-project-research-page.php',
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
		$settings = new WP_MCP_AI_Architectural_Design_Settings_Page();
		$this->assertSame( 'architectural_design', $this->read_prop( $settings, 'toolkit_slug' ) );

		$project_settings = new WP_MCP_AI_Architectural_Project_Settings_Page();
		$this->assertSame( 'wp_mcp_ai_architectural_project_settings', $this->read_prop( $project_settings, 'option_name' ) );
	}

	/**
	 * Standalone only: the architectural-design init's file-gated admin
	 * targets must now exist (the admin slice has landed — the
	 * architectural-design toolkit port is complete).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-project-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-project-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-drawing-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-drawing-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-specification-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-specification-research-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-project-metabox.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-drawing-metabox.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-architectural-specification-metabox.php',
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
