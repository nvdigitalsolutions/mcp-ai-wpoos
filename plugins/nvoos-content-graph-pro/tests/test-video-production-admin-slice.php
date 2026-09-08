<?php
/**
 * Characterization tests for the Wave F2 video-production admin slice — the
 * toolkit settings page and the Research & Add page ported from the base Pro
 * addon.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surfaces are
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/admin/` and `src/research-add/` are asserted in full, including
 *   the init's file-gate targets.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Video-production admin slice tests.
 */
class Test_Video_Production_Admin_Slice extends WP_UnitTestCase {

	/**
	 * The two ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Video_Production_Settings_Page' => 'admin/class-wp-mcp-ai-video-production-settings-page.php',
			'WP_MCP_AI_Video_Production_Research_Add'  => 'research-add/class-wp-mcp-ai-video-production-research-add.php',
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
		$settings = new WP_MCP_AI_Video_Production_Settings_Page();
		$this->assertSame( 'video_production', $this->read_prop( $settings, 'toolkit_slug' ) );
		$this->assertSame( 'wp_mcp_ai_video_production_toolkit_settings', $this->read_prop( $settings, 'option_name' ) );
		$this->assertSame( 'wp-mcp-ai-video-production-toolkit-settings', $this->read_prop( $settings, 'page_slug' ) );
	}

	/**
	 * The research-add entity types must be byte-identical.
	 */
	public function test_research_add_entity_types(): void {
		$research_add = new WP_MCP_AI_Video_Production_Research_Add();

		$entity_types = $this->invoke_protected( $research_add, 'get_entity_types' );
		$this->assertSame( array( 'projects', 'scenes', 'assets' ), array_keys( $entity_types ) );
	}

	/**
	 * Standalone only: the video init's file-gated admin targets must now
	 * exist (the admin slice has landed).
	 */
	public function test_init_gate_targets_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base init wires the admin slice at boot.' );
		}

		$targets = array(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-video-production-settings-page.php',
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-video-production-research-add.php',
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

	/**
	 * Invoke a protected method for byte-identical pinning.
	 *
	 * @param object $instance Object instance.
	 * @param string $method   Method name.
	 * @return mixed Method return value.
	 */
	private function invoke_protected( object $instance, string $method ) {
		$reflection = new ReflectionObject( $instance );
		while ( ! $reflection->hasMethod( $method ) && $reflection->getParentClass() ) {
			$reflection = $reflection->getParentClass();
		}
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );
		return $method_ref->invoke( $instance );
	}
}
